# Propuesta: Carga segura de documentos PDF e imágenes y compatibilidad con URL externa

## Problema y motivación
Actualmente en `Entregas → Documentos` (`/entregas.php?seccion=documentos`), un usuario con `DOCUMENTOS_REGISTRAR` solo puede registrar respaldos documentales mediante una URL externa obligatoria (`archivo_url`). En la operación diaria y durante la exposición, el usuario necesita adjuntar directamente archivos PDF o imágenes (JPG/PNG) de hasta 10 MiB desde su equipo, sin romper los registros históricos basados en URL ni exponer los archivos subidos dentro de `public/`.

## Alcance (YAGNI)
1. **Modal de alta dual excluyente (`public/entregas/documento_form.php` y `public/entregas/documento.php`):**
   - Opción principal **Subir archivo local**: zona drag-and-drop accesible + botón `Seleccionar archivo`, límite efectivo hasta 10 MiB (reflejando honestamente el límite del runtime PHP), tipos estrictos `PDF`, `JPG/JPEG` y `PNG`, un archivo por documento, posibilidad de quitar/reemplazar antes de guardar.
   - Opción alternativa **Vincular URL externa**: mantiene validación HTTP/HTTPS sin credenciales ni fetch remoto (anti-SSRF).
   - El servidor rechaza solicitudes ambiguas (ambos respaldos activos o ninguno) y cierra el modal solo tras éxito confirmado.
2. **Almacenamiento privado fuera de `public/` (`app/document_storage.php`) y descarga autenticada (`public/entregas/documento_descargar.php`):**
   - Directorio configurable por `TSSA_DOCUMENT_STORAGE_DIR` con fallback portable `<repo>/storage/private/documents/` excluido en `.gitignore`.
   - Clave de almacenamiento aleatoria opaca (sin usar el nombre original del cliente como ruta, bloqueando traversal/symlinks).
   - Validación multicapa en servidor (`UPLOAD_ERR_OK`, `is_uploaded_file`, `finfo_file`, firma de cabecera PDF / `getimagesize` para imágenes, coherencia MIME/extensión, tamaño real, SHA-256).
   - Descarga segura con `DOCUMENTOS_VER`, consulta SQL parametrizada por `documento_id`, `Content-Disposition: attachment` con nombre saneado, `X-Content-Type-Options: nosniff` y `Cache-Control: private, no-store`.
3. **Migración 14 aditiva e idempotente (`TransportesSegurosSA_Test` únicamente en este PR):**
   - Columnas aditivas en `dbo.documentos` (`origen_archivo`, `storage_key`, `nombre_original`, `mime_type`, `tamano_bytes`, `sha256_hex`) preservando filas previas (`origen_archivo = 'EXTERNA'`, sin migración destructiva).
   - Procedimientos `dbo.sp_registrar_documento_archivo`, `dbo.sp_obtener_documento` y extensión retrocompatible de `dbo.sp_listar_documentos` manteniendo intacto `dbo.sp_registrar_documento` para URLs.
4. **Respaldo separado y documentación operativa/QA:**
   - Runbook `docs/ops/document-attachments-storage-backup-restore.md` + matriz `docs/qa/document-attachments-final.md` aclarando que los backups FULL/DIFF de SQL Server no respaldan los blobs en disco y documentando la dependencia de Migración 14 antes de promover a `TransportesSegurosSA` Original.

## Fuera de alcance
Múltiples adjuntos por documento, OCR, miniaturas, PDF embebido, edición/borrado administrativo, almacenamiento en nube/S3, antivirus externo, cambios a `TransportesSegurosSA` Original o `TransportesSegurosSA_DW`, ETL o Jobs de SQL Server Agent.
