# Almacenamiento privado, respaldo y restauración de documentos adjuntos

**Módulo:** `Entregas → Documentos` (`/entregas.php?seccion=documentos`)  
**Migración SQL asociada:** `database/migrations/14_document_attachments_contracts.sql`  
**Script auxiliar opcional (manual / `-DryRun` / `-WhatIf`):** `tools/scripts/backup-document-storage.ps1`  

> [!IMPORTANT]
> **Los respaldos `FULL` y `DIFF` de Microsoft SQL Server NO respaldan los archivos físicos (PDF, JPG, PNG) guardados en disco.**  
> La base de datos almacena únicamente los metadatos (`origen_archivo`, `storage_key`, `nombre_original`, `mime_type`, `tamano_bytes`, `sha256_hex`) y la auditoría en `dbo.auditorias`. Respaldar únicamente archivos `.bak` sin respaldar el almacén privado provoca pérdida irrecuperable de los documentos adjuntos ante una restauración completa.

---

## 1. Arquitectura de almacenamiento portable y seguridad en disco

### 1.1 Ubicación portable fuera de `public/`

La aplicación resuelve el directorio raíz del almacén privado mediante `app/document_storage.php` (`document_storage_root_dir()`):

1. Si la variable de entorno `TSSA_DOCUMENT_STORAGE_DIR` (en el proceso o en `.env`) está definida y no vacía, se utiliza esa ruta.
2. Si `TSSA_DOCUMENT_STORAGE_DIR` está vacía o ausente, se usa el respaldo portable:
   `<raiz_del_repositorio>/storage/private/documents/`

Controles de aislamiento aplicados en tiempo de ejecución:
- Se rechazan rutas ubicadas dentro de `public/` (`public/index.php` tampoco expone `storage/` ni archivos `.pdf` inexistentes).
- Se rechazan rutas UNC/red (`\\servidor\recurso`), segmentos `..` y enlaces simbólicos / reparse points (`is_link()`).
- El directorio queda excluido de Git mediante `.gitignore` (`/storage/private/documents/*`, conservando únicamente `.gitkeep`). **Nunca versionar documentos reales ni de prueba en Git.**

### 1.2 Estructura interna y nombres opacos

Cada archivo subido se guarda bajo una clave relativa opaca validada por regex estricto `^\d{4}/\d{2}/[0-9a-f]{64}\.(pdf|jpg|png)$`:

```text
<storage_root>/
└── 2026/
    └── 11/
        └── 9f4c...<64 hex aleatorios>...a1.pdf
```

- Nunca se usa el nombre original del cliente como nombre físico en disco.
- El nombre original saneado (`nombre_original`) se guarda solo en SQL Server para visualización y cabecera `Content-Disposition: attachment` en descarga.
- Antes de persistir, PHP valida `UPLOAD_ERR_OK`, `is_uploaded_file()`, límite efectivo de tamaño (`<= 10 MiB`), ruta/traversal en `name` y `full_path`, extensión permitida sin doble extensión sospechosa, detección MIME real con `finfo_file()`, firma binaria `%PDF-` o estructura válida con `getimagesize()`, y calcula `sha256_hex`.
- Si falla la escritura en SQL Server (`sp_registrar_documento_archivo`), el archivo físico se elimina de inmediato (cero archivos huérfanos y cero filas rotas).

### 1.3 Permisos NTFS recomendados en Windows (producción / exposición)

Para un despliegue productivo en Windows Server o estación dedicada:

1. Ubicar `TSSA_DOCUMENT_STORAGE_DIR` en una carpeta dedicada fuera del repositorio y fuera de `public/` (por ejemplo `C:\DatosTSSA\DocumentosPrivados`).
2. Aplicar **mínimo privilegio NTFS** sobre esa carpeta:
   - **Cuenta de servicio de PHP / aplicación web:** `Modificar` (lectura + escritura + borrado controlado para limpieza ante fallo), **sin permiso de ejecución (`Traverse/Execute` denegado sobre archivos)** y **sin acceso web directo** (no crear alias virtuales ni directorios publicados en IIS/Apache/Nginx).
   - **Administradores / cuenta de respaldo:** `Lectura y ejecución` o `Control total` según política de respaldo.
   - **Usuarios generales (`Users` / `IIS_IUSRS` no autorizados):** sin acceso.
3. Ejemplo orientativo con `icacls` (ajustar usuario/servicio real; **no ejecutado automáticamente por este PR**):
   ```powershell
   New-Item -ItemType Directory -Path "C:\DatosTSSA\DocumentosPrivados" -Force
   icacls "C:\DatosTSSA\DocumentosPrivados" /inheritance:r
   icacls "C:\DatosTSSA\DocumentosPrivados" /grant "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" "<CUENTA_PHP>:(OI)(CI)(R,W,M)"
   ```

### 1.4 Descarga autenticada y cabeceras HTTP

Los archivos adjuntos solo se entregan mediante `GET /entregas/documento_descargar.php?id=<documento_id>`:
- Exige sesión PHP autenticada y permiso granular `DOCUMENTOS_VER`.
- Consulta `dbo.sp_obtener_documento` (revalida RBAC en SQL Server y verifica `origen_archivo = 'INTERNA'`).
- Revalida `storage_key` y contención `realpath()` dentro del almacén privado.
- Envía cabeceras seguras: `Content-Type` estricto (`application/pdf`, `image/jpeg` o `image/png`), `Content-Disposition: attachment` con nombre ASCII saneado + `filename*` RFC 5987, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store, max-age=0` y `Pragma: no-cache`.

---

## 2. Estrategia conjunta de respaldo y restauración (SQL + Archivos)

### 2.1 Por qué el respaldo debe ser conjunto

El sistema tiene dos fuentes de verdad acopladas para respaldos locales:
- **SQL Server (`dbo.documentos`):** guarda la referencia lógica (`solicitud_id`, `tipo_documento_id`, `numero_referencia`, `storage_key`, `tamano_bytes`, `sha256_hex`).
- **Sistema de archivos (`TSSA_DOCUMENT_STORAGE_DIR`):** guarda los bytes del archivo identificado por `storage_key`.

Los Jobs existentes de SQL Server Agent (`G6_FULL_TransportesSegurosSA`, `G6_DIFF_TransportesSegurosSA`) respaldan únicamente la base de datos SQL Server. **Este PR no instala tareas programadas en Windows, no crea ni activa SQL Server Agent Jobs y no configura sincronización en la nube.**

### 2.2 Punto de consistencia y orden recomendado de respaldo

Para minimizar ventanas de inconsistencia sin detener consultas de lectura:

1. **Primero:** ejecutar el respaldo `FULL` (o `DIFF`) de SQL Server con `CHECKSUM` y validarlo con `RESTORE VERIFYONLY WITH CHECKSUM`.
2. **Inmediatamente después:** ejecutar el respaldo del almacén privado de archivos con manifiesto SHA-256 hacia una ruta local/externa fuera del repositorio (por ejemplo `C:\SQL\Backups\Documentos`).
   - *Por qué en este orden:* cualquier documento registrado justo antes del respaldo SQL estará presente en ambos respaldos; si algún archivo se subiera durante la ventana posterior al `.bak`, simplemente quedaría como archivo adicional en disco (huérfano inocuo que puede reconciliarse) en lugar de una fila SQL apuntando a un archivo faltante.
3. **Retención sugerida:** alinear la retención de carpetas `documents_<yyyyMMdd_HHmmss>` con la política de retención de respaldos `FULL` de SQL Server (al menos 2 ciclos completos verificados) y almacenar una copia fuera del equipo principal antes de la exposición o en producción.

### 2.3 Procedimiento reproducible con `tools/scripts/backup-document-storage.ps1`

El script `tools/scripts/backup-document-storage.ps1` es manual, portable y seguro (`-DryRun` y `-WhatIf` soportados):

1. **Simulación sin escrituras (`-DryRun`):**
   ```powershell
   .\tools\scripts\backup-document-storage.ps1 -DestinationRoot "C:\SQL\Backups\Documentos" -DryRun
   ```
2. **Respaldo real + generación y verificación automática de manifiesto SHA-256:**
   ```powershell
   .\tools\scripts\backup-document-storage.ps1 -DestinationRoot "C:\SQL\Backups\Documentos"
   ```
   Crea `documents_<yyyyMMdd_HHmmss>\files\...`, `manifest.sha256.json` y `manifest.sha256.txt`, y valida inmediatamente todos los hashes SHA-256.
3. **Verificación posterior de integridad del respaldo (`-VerifyOnly`):**
   ```powershell
   .\tools\scripts\backup-document-storage.ps1 -VerifyOnly -BackupDir "C:\SQL\Backups\Documentos\documents_20261120_170000"
   ```
4. **Restauración controlada en directorio objetivo + verificación SHA-256 automática:**
   ```powershell
   .\tools\scripts\backup-document-storage.ps1 -Restore `
     -BackupDir "C:\SQL\Backups\Documentos\documents_20261120_170000" `
     -RestoreTargetDir "C:\DatosTSSA\DocumentosRestaurados"
   ```
   *(El script rechaza sobrescribir una carpeta no vacía salvo que se indique explícitamente `-Force`.)*

### 2.4 Reconciliación SQL ↔ disco tras un fallo o restauración

Para auditar que todo registro `INTERNA` en SQL tiene su archivo íntegro en disco (consulta estrictamente de solo lectura):

```sql
SELECT documento_id, solicitud_id, numero_referencia, storage_key, tamano_bytes, sha256_hex, fecha_documento
FROM dbo.documentos
WHERE origen_archivo = N'INTERNA'
ORDER BY documento_id;
```

Cada `storage_key` devuelto debe existir bajo `<storage_root>/<storage_key>` con exactamente `tamano_bytes` y el mismo hash `sha256_hex` (`Get-FileHash -Algorithm SHA256`).
- Si existen archivos físicos en disco que no aparecen en esta consulta (por ejemplo tras un reinicio abrupto del servidor durante una carga), son huérfanos sin referencia SQL y pueden moverse a cuarentena tras confirmar que no hay una carga en curso.
- Si existe un registro SQL cuyo archivo falta en disco, restaurar ese `storage_key` desde el respaldo verificado más reciente y revalidar su `sha256_hex`.

---

## 3. Requisitos obligatorios antes de usar este PR sobre `TransportesSegurosSA` (Base Original)

> [!WARNING]
> **En este PR NO se ha migrado `TransportesSegurosSA` (base original) ni se ha modificado `TransportesSegurosSA_DW`.**  
> La base original permanece intacta en Migración `13`. Ejecutar el código nuevo de `Entregas → Documentos` contra `TransportesSegurosSA` **antes** de aplicar la Migración `14` provocará errores al intentar invocar `dbo.sp_registrar_documento_archivo`, `dbo.sp_obtener_documento` o leer las columnas aditivas de `dbo.sp_listar_documentos`.

### 3.1 Secuencia exacta de despliegue controlada por el propietario (post-merge)

1. **Respaldo previo obligatorio de `TransportesSegurosSA` (`WITH CHECKSUM` + `RESTORE VERIFYONLY`):**
   ```sql
   BACKUP DATABASE [TransportesSegurosSA]
   TO DISK = N'C:\SQL\Backups\TransportesSegurosSA_PRE_MIG14.bak'
   WITH INIT, CHECKSUM, STATS = 10;
   RESTORE VERIFYONLY FROM DISK = N'C:\SQL\Backups\TransportesSegurosSA_PRE_MIG14.bak' WITH CHECKSUM;
   ```
2. **Ensayo opcional en copia aislada (`TransportesSegurosSA_Staging`)** restaurando el `.bak` con `WITH MOVE` y aplicando la Migración 14.
3. **Aplicar la Migración 14 sobre `TransportesSegurosSA`:**
   ```powershell
   sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\14_document_attachments_contracts.sql
   ```
   - La migración es aditiva e idempotente: preserva íntegro el registro previo `documento_id = 1` (`GUIA-DEMO-001`, `origen_archivo = 'EXTERNA'`) y todos los datos existentes.
4. **Preparar directorio de almacenamiento y límites PHP:**
   - Opcionalmente definir `TSSA_DOCUMENT_STORAGE_DIR` en `.env` apuntando a una carpeta persistente fuera de `public/` (si se deja vacío usará `<repo>/storage/private/documents/`).
   - Arrancar la aplicación mediante `.\tools\scripts\start-dev.ps1`, que aplica `-d upload_max_filesize=10M -d post_max_size=12M` por proceso **sin modificar el `php.ini` global** del equipo. *(Nota: si se inicia `php.exe -S` manualmente sin `start-dev.ps1` ni flags `-d`, la aplicación detectará honestamente el límite del `php.ini` local —p. ej. 2 MB— y lo mostrará en la UI y validará en servidor).*
5. **Verificación post-despliegue:**
   - Abrir `/entregas.php?seccion=documentos`, confirmar que los documentos previos aparecen como **Enlace externo**, subir un PDF de prueba y confirmar su descarga autenticada (o verificar primero en `TransportesSegurosSA_Test`).

### 3.2 Plan de rollback no destructivo

Si se necesita revertir la Migración 14:

```powershell
sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\rollbacks\rollback_14_document_attachments.sql
```

Comportamiento del rollback verificado en base efímera:
- Siempre revierte `dbo.sp_listar_documentos`, `dbo.sp_registrar_documento` y `dbo.sp_listar_tipos_documento` a sus contratos previos y elimina `dbo.sp_registrar_documento_archivo` y `dbo.sp_obtener_documento`.
- **Si existen 0 registros con `origen_archivo = 'INTERNA'`:** elimina limpiamente índices, checks y las 6 columnas nuevas de `dbo.documentos`.
- **Si existe al menos 1 registro con `origen_archivo = 'INTERNA'`:** **NO elimina columnas ni datos** (rollback estrictamente no destructivo) y deja un aviso explícito para preservar los metadatos de adjuntos existentes.
