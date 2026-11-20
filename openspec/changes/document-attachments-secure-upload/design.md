# Diseño técnico: Carga segura de documentos PDF e imágenes

## 1. Modelo SQL aditivo (Migración 14)
- Sobre `dbo.documentos` (sin tocar `documento_id`, `solicitud_id`, `tipo_documento_id`, `numero_referencia`, `fecha_documento`, `archivo_url`, `observaciones`, `fecha_registro`):
  - `origen_archivo VARCHAR(10) NOT NULL CONSTRAINT DF_documentos_origen_archivo DEFAULT ('EXTERNA')`
  - `storage_key NVARCHAR(120) NULL` (formato `YYYY/MM/<64-hex>.<ext>` generado en servidor; único cuando `NOT NULL` vía índice filtrado `UQ_documentos_storage_key`)
  - `nombre_original NVARCHAR(255) NULL` (nombre saneado para presentación/descarga, jamás usado en ruta de disco)
  - `mime_type VARCHAR(100) NULL` (`application/pdf`, `image/jpeg`, `image/png`)
  - `tamano_bytes BIGINT NULL` (`1 .. 10485760`)
  - `sha256_hex CHAR(64) NULL`
- Restricciones de integridad (`CK_documentos_origen_archivo` y `CK_documentos_soporte_coherente`):
  - `EXTERNA`: `storage_key`, `nombre_original`, `mime_type`, `tamano_bytes`, `sha256_hex` deben ser `NULL` (respeta `archivo_url` legado como `'archivos/guia-demo.pdf'` y NULLs preexistentes).
  - `INTERNA`: `archivo_url IS NULL`, metadatos obligatorios completos, `mime_type IN ('application/pdf','image/jpeg','image/png')`, `tamano_bytes BETWEEN 1 AND 10485760`, `LEN(sha256_hex) = 64`.
- Procedimientos almacenados:
  - `dbo.sp_registrar_documento`: intacto para llamadas legacy/URLs (escribe `origen_archivo = 'EXTERNA'` por default y registra auditoría `INSERT` en `dbo.auditorias` sin alterar firma ni código existente).
  - `dbo.sp_registrar_documento_archivo`: exige `DOCUMENTOS_REGISTRAR` (`50406`), valida existencia de solicitud (`50431`), tipo de documento activo (`50432`), parámetros de archivo (`50433`), unicidad de `storage_key`, inserta con `origen_archivo = 'INTERNA'` y registra auditoría en `dbo.auditorias` (sin exponer rutas locales).
  - `dbo.sp_obtener_documento`: exige `DOCUMENTOS_VER` (`50103`), devuelve metadatos completos de un `documento_id` para descarga autorizada (lanza `50101` si no existe).
  - `dbo.sp_listar_documentos`: conserva firma y todas las columnas previas y agrega al final `origen_archivo`, `nombre_original`, `mime_type`, `tamano_bytes`.
- Sobre `dbo.entregas` (sin tocar columnas existentes ni disparar `tr_auditar_entregas` sobre filas históricas):
  - `comprobante_origen VARCHAR(10) NULL` (`EXTERNA` o `INTERNA` en entregas confirmadas; `NULL` en fallidas y filas previas proyectadas como `EXTERNA` cuando `estado = 'ENTREGADA'`).
  - `comprobante_storage_key NVARCHAR(120) NULL` (único filtrado `UQ_entregas_comprobante_storage_key`), `comprobante_nombre_original NVARCHAR(255) NULL`, `comprobante_mime_type VARCHAR(100) NULL`, `comprobante_tamano_bytes BIGINT NULL`, `comprobante_sha256_hex CHAR(64) NULL`.
  - Restricciones `CK_entregas_comprobante_origen` y `CK_entregas_comprobante_coherente`.
  - `dbo.sp_registrar_entrega`: conserva los 7 parámetros originales y agrega 5 parámetros opcionales (`DEFAULT NULL`) para metadatos de comprobante interno, exige exclusión mutua en `ENTREGADA` y bloquea adjunto en `FALLIDA`, manteniendo intacta la transacción `UPDLOCK/HOLDLOCK`.
  - `dbo.sp_obtener_comprobante_entrega`: exige `ENTREGAS_VER` (`50103`) y devuelve metadatos del comprobante por `entrega_id` (`50101` si no existe).
  - `dbo.sp_obtener_destinatario_asignacion`: exige `ENTREGAS_REGISTRAR` (`50319`) y devuelve el destinatario registrado en la solicitud vinculada al `viaje_solicitud_id` para precargarlo sin recaptura en el modal **Registrar entrega**.
  - `dbo.sp_listar_entregas`: agrega al final las 6 columnas aditivas del comprobante.

## 2. Almacenamiento privado y consistencia FS ↔ SQL (`app/document_storage.php`)
- **Resolución de ruta:** `TSSA_DOCUMENT_STORAGE_DIR` (si se configura debe ser ruta absoluta y fuera de `public/`; de lo contrario fallback `<repo>/storage/private/documents`). Verifica que no sea symlink/reparse point y que esté estrictamente fuera de `realpath(public/)`.
- **Validación en capas del archivo subido:**
  1. Detección temprana de descarte por `post_max_size` (`CONTENT_LENGTH > post_max_size` con `$_POST` y `$_FILES` vacíos → HTTP `413`).
  2. Comprobar `UPLOAD_ERR_OK` (`UPLOAD_ERR_INI_SIZE`/`FORM_SIZE` → `413`), `is_uploaded_file($tmpPath)` y tamaño real `1 .. effectiveMaxBytes` (`<= 10 MiB`).
  3. Inspección real de contenido con `finfo_file(FILEINFO_MIME_TYPE)` contra allowlist `{application/pdf, image/jpeg, image/png}` + firma estructural:
     - PDF: primeros bytes deben iniciar con `%PDF-` (y no contener encabezados HTML/SVG/PHP).
     - JPG/PNG: `getimagesize($tmpPath)` debe confirmar `IMAGETYPE_JPEG` o `IMAGETYPE_PNG` con dimensiones válidas (`> 0`).
  4. Coherencia de extensión del nombre original (`.pdf`, `.jpg`/`.jpeg`, `.png`; rechazar extensiones dobles peligrosas como `.php.pdf` o `.svg` o discrepancia MIME/extensión).
- **Secuencia transaccional segura (cero filas rotas / cero huérfanos):**
  1. Validar campos (`solicitud_id`, `tipo_documento_id`, `numero_referencia`, modo `archivo` vs `url`, exclusividad estricta).
  2. Calcular `sha256_file` y mover el upload a staging privado dentro del storage (`_staging/<random>.part`) o directamente al destino definitivo `YYYY/MM/<64-hex>.<ext>` tras validar permisos de escritura.
  3. Ejecutar `EXEC dbo.sp_registrar_documento_archivo ...` dentro de `try/catch`.
  4. Si SQL falla por cualquier motivo (FK, permiso, error transaccional), eliminar inmediatamente el archivo recién movido (`@unlink`) antes de responder el error HTTP.
  5. Si ocurriera un crash extremo del proceso entre disco y SQL, el runbook documenta la reconciliación periódica comparando `storage_key` en SQL vs disco sin borrar registros válidos.

## 3. Descarga segura (`public/entregas/documento_descargar.php` y `public/entregas/comprobante_descargar.php`)
- `documento_descargar.php` exige `DOCUMENTOS_VER` e invoca `dbo.sp_obtener_documento`; `comprobante_descargar.php` exige `ENTREGAS_VER` e invoca `dbo.sp_obtener_comprobante_entrega`.
- Verifican origen `'INTERNA'` y `storage_key` válido mediante regex estricta (`^[0-9]{4}/[0-9]{2}/[0-9a-f]{64}\.(pdf|jpg|png)$`), resuelven ruta dentro del directorio privado y validan `realpath` dentro de la raíz de almacenamiento (bloqueando symlinks/traversal).
- Sirven bytes con `Content-Type` de allowlist, `Content-Disposition: attachment; filename="..."; filename*=UTF-8''...` (nombre saneado sin CRLF/comillas/controles), `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Content-Length`, streaming en bloques sin buffer inflado.

## 4. UI y HTMX `multipart/form-data`
- `public/entregas/documento_form.php` y `public/entregas/registrar_form.php`: `<form hx-encoding="multipart/form-data" enctype="multipart/form-data" data-doc-upload-form ...>`.
- Selector de modo de respaldo (radios/botones segmentados): **Subir archivo local** (predeterminado) y **Vincular URL externa**. Cambiar de modo actualiza `required`/`disabled` solo en la alternativa activa sin perder los demás campos. En `registrar_form.php`, cambiar a `FALLIDA` oculta y deshabilita todo el bloque de comprobante.
- Zona drag-and-drop con botón accesible `Seleccionar archivo`, estado visible (`Sin archivo`, `Archivo seleccionado`, `Archivo inválido`, `Subiendo…`), nombre/tamaño/tipo y acción `Quitar`.
- `public/entregas/lista.php`: en `Documentos` muestra **Archivo adjunto** + `Descargar` o **Enlace externo** + `Abrir referencia`; en `Entregas` muestra **Comprobante adjunto** + `Descargar` (`/entregas/comprobante_descargar.php?id=...`) o `Ver comprobante` para URLs externas.
