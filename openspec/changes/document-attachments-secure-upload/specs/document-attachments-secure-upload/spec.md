# Especificación delta: Carga segura de documentos PDF e imágenes y compatibilidad con URL

## ADDED Requirements

### Requirement: Registro de respaldo documental mediante archivo local o URL externa mutuamente excluyentes
En `Entregas → Documentos → Nuevo documento`, un usuario con `DOCUMENTOS_REGISTRAR` SHALL poder registrar un respaldo documental eligiendo exactamente una alternativa: subir un archivo local (`PDF`, `JPG/JPEG` o `PNG`, hasta 10 MiB o el límite efectivo real del runtime PHP) o vincular una URL externa `HTTP/HTTPS`.

#### Scenario: Subida válida de PDF, JPG o PNG con almacenamiento privado fuera de public/
- **WHEN** un usuario con `DOCUMENTOS_REGISTRAR` selecciona o arrastra un archivo válido (`PDF`, `JPG/JPEG` o `PNG` dentro del tamaño máximo) en modo archivo y envía el formulario con CSRF válido
- **THEN** el servidor valida el archivo real con `finfo_file`, firma estructural (cabecera `%PDF-` o `getimagesize`), extensión y tamaño, guarda los bytes fuera de `public/` bajo una clave aleatoria opaca, registra los metadatos y `sha256_hex` mediante `dbo.sp_registrar_documento_archivo`, cierra el modal, muestra notificación de éxito y actualiza la tabla mostrando **Archivo adjunto** con acción `Descargar`

#### Scenario: Compatibilidad completa con URL externa sin fetch remoto
- **WHEN** un usuario selecciona el modo URL externa e ingresa una URL `HTTP/HTTPS` sin credenciales (o consulta documentos históricos ya registrados)
- **THEN** el sistema persiste la referencia vía `dbo.sp_registrar_documento` sin realizar solicitudes HTTP salientes desde el servidor, preserva todos los documentos previos intactos y muestra **Enlace externo** con `Abrir referencia` (`target="_blank" rel="noopener noreferrer"`)

#### Scenario: Rechazo seguro de entradas inválidas o ambiguas sin huérfanos ni filas rotas
- **WHEN** una petición envía simultáneamente archivo y URL, omite ambos, excede el tamaño máximo (`post_max_size` o límite de 10 MiB), adjunta un archivo prohibido/fingido (`.php`, `.svg`, HTML, MIME falsificado, traversal), carece de CSRF o permiso `DOCUMENTOS_REGISTRAR`, o falla el almacenamiento/SQL
- **THEN** el servidor responde `403`, `413`, `422` o `500` con mensaje claro en español sin filtrar rutas ni SQL, mantiene el modal abierto preservando los campos seguros y garantiza cero filas rotas y cero archivos huérfanos en disco

### Requirement: Descarga autenticada y aislamiento estricto de archivos privados
Los bytes almacenados SHALL residir exclusivamente fuera del document root `public/` y SHALL entregarse únicamente a través del endpoint autenticado `/entregas/documento_descargar.php` validando sesión y `DOCUMENTOS_VER`.

#### Scenario: Descarga autorizada byte a byte con cabeceras seguras
- **WHEN** un usuario con `DOCUMENTOS_VER` solicita `/entregas/documento_descargar.php?id=<documento_id>` para un adjunto interno existente
- **THEN** el servidor consulta `dbo.sp_obtener_documento` con contexto de sesión, valida la clave opaca y contención dentro del directorio privado, y entrega exactamente los mismos bytes (idéntico SHA-256) con `Content-Disposition: attachment` (nombre saneado), MIME de lista blanca, `X-Content-Type-Options: nosniff` y `Cache-Control: private, no-store`

#### Scenario: Bloqueo de acceso no autorizado, traversal o acceso directo HTTP
- **WHEN** un usuario no autenticado, sin `DOCUMENTOS_VER`, con ID inexistente/URL externa o intentando acceder directamente a rutas de almacenamiento vía HTTP solicita un archivo
- **THEN** el sistema responde `302/401`, `403` o `404` sin exponer rutas físicas, nombres internos ni contenido del archivo

### Requirement: Registro de comprobante de entrega mediante archivo local o URL externa mutuamente excluyentes
En el modal **Registrar entrega** (`/entregas/registrar_form.php`, invocado desde `Viajes → Detalle`), un usuario con `ENTREGAS_REGISTRAR` SHALL poder confirmar una entrega `ENTREGADA` adjuntando un comprobante local (`PDF`, `JPG/JPEG` o `PNG`, hasta 10 MiB / límite efectivo PHP) o vinculando una URL `HTTP/HTTPS`, y al marcar `FALLIDA` SHALL omitir el comprobante.

#### Scenario: Confirmación de entrega con comprobante local y descarga con ENTREGAS_VER
- **WHEN** un usuario con `ENTREGAS_REGISTRAR` confirma una entrega `ENTREGADA` adjuntando un archivo `PDF`, `JPG/JPEG` o `PNG` válido
- **THEN** el servidor valida y almacena el archivo fuera de `public/` reutilizando el mismo servicio seguro, registra la entrega y actualiza `viaje_solicitudes`/`solicitudes` de forma atómica en `dbo.sp_registrar_entrega` (eliminando el archivo si SQL falla), y un usuario con `ENTREGAS_VER` puede descargarlo byte a byte desde `/entregas/comprobante_descargar.php?id=<entrega_id>` con cabeceras seguras

#### Scenario: Compatibilidad de entregas con URL externa y entregas fallidas
- **WHEN** un usuario registra una entrega `ENTREGADA` en modo URL externa o registra una entrega `FALLIDA` con motivo de fallo, o consulta entregas históricas
- **THEN** el sistema preserva el flujo retrocompatible de `comprobante_url` (sin fetch remoto) y de `FALLIDA` sin exigir archivo, mostrando `Ver comprobante` para URLs y el motivo de fallo en entregas fallidas

### Requirement: Portabilidad y documentación de respaldo/restauración separada de archivos
El sistema SHALL excluir los archivos subidos de Git y SHALL documentar en `docs/ops/document-attachments-storage-backup-restore.md` que los backups FULL/DIFF de SQL Server no respaldan los adjuntos en disco, junto con un procedimiento reproducible de respaldo conjunto, verificación SHA-256 y restauración en entorno de prueba.

#### Scenario: Verificación reproducible de respaldo y restauración en ambiente de pruebas
- **WHEN** se respalda el directorio privado de pruebas a una carpeta temporal y se verifica su manifiesto SHA-256
- **THEN** los archivos restaurados coinciden byte a byte con los hashes registrados en `TransportesSegurosSA_Test` y permanecen excluidos del repositorio Git
