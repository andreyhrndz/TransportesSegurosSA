# Informe final de QA — Carga segura de documentos, comprobantes de entrega y compatibilidad con URL

**Fecha de ejecución:** 2026-11-20 (America/Costa_Rica)  
**Rama:** `feat/document-attachments-secure-upload` → base `development` (PR #6)  
**Base de pruebas (única con escrituras):** `TransportesSegurosSA_Test` (Migraciones `01` a `14`)  
**Bases protegidas (estrictamente solo lectura / intactas):** `TransportesSegurosSA` (Migración `13`, 0 escrituras) y `TransportesSegurosSA_DW` (`fact_entregas = 2`, 0 ETL)  
**Entorno ejecutado:** PHP `8.4` NTS x64 (`pdo_sqlsrv`, `fileinfo`, `getimagesize`), SQL Server 2025 (`localhost`), Playwright + Chromium headless en 6 resoluciones.

---

## Veredicto de QA

> [!TIP]
> **VEREDICTO: `APTO PARA MERGE` (hacia `development`)**  
> Todas las suites T-SQL, HTTP/seguridad (tanto en `Entregas → Documentos` como en `Registrar entrega` desde `Viajes → Detalle`), almacenamiento/respaldo-restauración, Playwright E2E multi-resolución y regresiones existentes pasaron al **100%** sobre `TransportesSegurosSA_Test`, dejando **0 residuos sintéticos** en BD y sin versionar archivos adjuntos en Git.  
> **Requisito previo obligatorio para usar en `TransportesSegurosSA` (Original):** aplicar la Migración `14` tras respaldo FULL verificado, según [document-attachments-storage-backup-restore.md](../ops/document-attachments-storage-backup-restore.md#3-requisitos-obligatorios-antes-de-usar-este-pr-sobre-transportessegurossa-base-original).

---

## 1. Matriz de pruebas ejecutadas (evidencia real)

| Caso | Área / Descripción | Resultado esperado | Resultado observado | Estado | Fuente BD | Duración |
|---|---|---|---|---|---|---:|
| **QA-01** | Lint sintáctico PHP (`14` archivos) y JS (`app.js`, Playwright) | 0 errores de sintaxis | `No syntax errors detected` en todos los archivos PHP y exit `0` en `node --check` | **PASS** | N/A | < 1 s |
| **QA-02** | Validación OpenSpec (`openspec validate --all`) | 100% de especificaciones y changes válidos | `23 passed, 0 failed` (incluye `document-attachments-secure-upload` ampliado a entregas) | **PASS** | N/A | < 2 s |
| **QA-03** | Migración `14` (idempotencia 2× + suite T-SQL `18` en `documentos` y `entregas`) | Esquema aditivo en `dbo.documentos` y `dbo.entregas`, históricos preservados sin disparar `tr_auditar_entregas`, alta interna/externa/fallida, RBAC `50406`/`50103` y rechazos `50321`/`50433`/`50434`/`50101` | Re-ejecución idempotente limpia; `18_contract_tests_document_attachments.sql` 100% PASS (4 bloques) | **PASS** | `TransportesSegurosSA_Test` | 0.62 s |
| **QA-04** | Rollback `14` no destructivo (2 rutas en BD efímera aislada para `documentos` y `entregas`) | Con `0` internos: remueve columnas/índices en ambas tablas; con `>=1` interno: revierte SPs y **conserva columnas y datos** | Ambos escenarios verificados en `TransportesSegurosSA_Mig14EntRollback` y BD temporal eliminada | **PASS** | BD efímera descartada | < 2 s |
| **QA-05** | Unitarias de storage, anti-traversal y saneamiento de cabeceras | Rechaza rutas dentro de `public/`, UNC, symlinks, `..` en `name`/`full_path`; sanea `Content-Disposition` | 100% de aserciones unitarias en `document_attachments_http_security_test.php` superadas | **PASS** | N/A | (en QA-06) |
| **QA-06** | Suite HTTP/Seguridad integral (`tests/document_attachments_http_security_test.php`) | Documentos (PDF/PNG/JPG/10 MiB + URL + negativos + backup/restore SHA-256) **y** Comprobantes de entrega (`registrar_form.php`, `registrar.php`, `comprobante_descargar.php`: PDF/PNG/URL/FALLIDA, RBAC `ENTREGAS_VER`/`ENTREGAS_REGISTRAR`, `413`/`422`/`404`/`50323`/`50324`, SHA-256) | 6 bloques `OK`, 0 warnings, 0 filas rotas, 0 huérfanos en disco, 4/4 archivos íntegros tras respaldo+restauración | **PASS** | `TransportesSegurosSA_Test` | 13.36 s |
| **QA-07** | E2E navegador real Playwright (`tests/document_attachments_playwright.js`) | E2E 1 Documentos (Drag & Drop, `Seleccionar archivo`, `Quitar`, `422`, PDF/PNG/URL, SHA-256); E2E 2 6 viewports; E2E 3 RBAC visual; **E2E 4 Registrar entrega desde `Viajes → Detalle`** (toggle `Fallida`/`Entregada`, modo dual, Drag & Drop PDF, 1 POST, tabla `Entregas`, descarga SHA-256) | E2E 1, 2, 3 y 4 100% PASS; `0` `pageerror`, `0` respuestas `5xx`, 9 capturas sanitizadas inspeccionadas directamente | **PASS** | `TransportesSegurosSA_Test` | 25.09 s |
| **QA-08** | Script de respaldo/verificación/restauración (`tools/scripts/backup-document-storage.ps1`) | `-DryRun` no escribe; respaldo crea manifiesto SHA-256; `-VerifyOnly` y `-Restore` validan 100%; alteración de 1 byte devuelve exit != 0 | `-DryRun`, Backup, `-VerifyOnly`, `-Restore` 100% PASS; detección de alteración exit `2`; carpeta temporal eliminada | **PASS** | Temp local (sin SQL) | 5.42 s |
| **QA-09** | Regresiones existentes (`utf8`, `operational_routes`, `session_csrf`, `master_data`, `stops_audit_reports`, `descomponerNombreCR`) | 0 regresiones en el resto del sistema ni en DW solo lectura | 6/6 suites PASS (0 errores, 0 ETL) | **PASS** | `TransportesSegurosSA_Test` + `DW` (RO) | < 20 s |
| **QA-10** | Postflight estrictamente solo lectura en Original y DW | `TransportesSegurosSA` intacta en Mig 13 (`doc_col=NULL`, `ent_col=NULL`) y `TransportesSegurosSA_DW` intacta (`fact_entregas=2`) | `doc_col = NULL`, `ent_col = NULL`, `sp_doc = NULL`, `sp_ent = NULL`, `entregas = 2`, `documentos = 1`, `auditorias = 34`, `fact_entregas = 2` | **PASS** | `TransportesSegurosSA` + `DW` (`ReadOnly`) | < 1 s |

---

## 2. Cobertura explícita de pruebas negativas y seguridad

Verificadas en `tests/document_attachments_http_security_test.php`, `database/tests/18_contract_tests_document_attachments.sql` y `tests/document_attachments_playwright.js`:

- **Autenticación y separación estricta de permisos RBAC:**
  - Descargas sin sesión (`/entregas/documento_descargar.php` y `/entregas/comprobante_descargar.php`) → `302` a `/auth/login.php`.
  - Documentos exige `DOCUMENTOS_REGISTRAR` / `DOCUMENTOS_VER`; Comprobantes de entrega exige `ENTREGAS_REGISTRAR` / `ENTREGAS_VER` (sin mezclar permisos).
  - Mínimo privilegio en `documento_form.php`: `dbo.sp_listar_tipos_documento` permite `CATALOGOS_VER OR DOCUMENTOS_REGISTRAR`.
- **CSRF y métodos HTTP:**
  - `POST` sin token CSRF o inválido en `documento.php` y `registrar.php` → `403`.
  - `GET` en `documento.php` / `registrar.php` y `POST` en `documento_descargar.php` / `comprobante_descargar.php` → `405 Method Not Allowed`.
- **Validación de tamaño (`413`) y contenido (`422`):**
  - Archivo `> 10 MiB` (`10 MiB + 1 byte`) y cuerpo multipart `> post_max_size` (`12 MiB` en `start-dev.ps1`) → `413` explícito en ambos endpoints (`documento.php` y `registrar.php`).
  - Rechazos `422` con **0 huérfanos en disco**: archivo vacío, PDF fingido, PHP como JPG, SVG, doble extensión (`payload.php.pdf`), desajuste extensión/MIME, traversal en `name` y `full_path` (`../../` y `..\..\`), ambos respaldos simultáneos (archivo + URL), ninguno, URL con esquema no permitido (`javascript:`, `file://`, credenciales) y entrega `FALLIDA` con comprobante adjunto.
- **Atomicidad SQL ↔ disco y aislamiento HTTP:**
  - Fallos SQL (`solicitud_id` inexistente, `viaje_solicitud_id` inexistente `50323` o entrega ya registrada `50324`) tras subir un archivo válido eliminan inmediatamente el archivo del almacenamiento privado (`0` huérfanos).
  - Acceso HTTP directo a `/storage/private/documents/<storage_key>` → `404` limpio sin filtrar bytes.
  - Descarga sobre registro `EXTERNA`, entrega `FALLIDA` o ID inexistente → `404`.
  - Descarga autorizada entrega exactamente los mismos bytes (SHA-256 idéntico) con `Content-Type` estricto, `Content-Disposition: attachment` (ASCII + RFC 5987), `X-Content-Type-Options: nosniff` y `Cache-Control: private, no-store, max-age=0`.

---

## 3. Validación visual (Playwright Chromium — 6 resoluciones, 9 capturas sanitizadas)

| Captura | Viewport | Qué valida |
|---|---|---|
| [01_modal_documento_archivo_1440.png](../screenshots/document_attachments/01_modal_documento_archivo_1440.png) | `1440×900` | Modal **Registrar documento** en modo **Subir archivo local**, scroll lock global y dropzone accesible. |
| [02_modal_documento_url_1440.png](../screenshots/document_attachments/02_modal_documento_url_1440.png) | `1440×900` | Cambio a **Vincular URL externa** preservando los campos del formulario. |
| [03_modal_documento_seleccionado_1440.png](../screenshots/document_attachments/03_modal_documento_seleccionado_1440.png) | `1440×900` | Selección por Drag & Drop de PDF válido, tarjeta de vista previa con `Quitar` y limpieza automática de error previo. |
| [04_tabla_documentos_mixta_1440.png](../screenshots/document_attachments/04_tabla_documentos_mixta_1440.png) | `1440×900` | Convivencia en `Documentos` de `Archivo adjunto` (`Descargar`), `Enlace externo` (`Abrir referencia`) e histórico `GUIA-DEMO-001`. |
| [05_documentos_1366x768.png](../screenshots/document_attachments/05_documentos_1366x768.png) | `1366×768` | Vista desktop estándar sin overflow horizontal ni solapamientos. |
| [06_documentos_768x1024.png](../screenshots/document_attachments/06_documentos_768x1024.png) | `768×1024` | Vista tablet con las 5 columnas completas sin recorte en `Descargar` ni salto en `Abrir referencia`. |
| [07_documentos_375x667.png](../screenshots/document_attachments/07_documentos_375x667.png) | `375×667` | Vista móvil responsive con controles táctiles apilados y tabla con scroll contenedor. |
| [08_modal_entrega_comprobante_archivo_1440.png](../screenshots/document_attachments/08_modal_entrega_comprobante_archivo_1440.png) | `1440×900` | Modal **Registrar entrega** (desde `Viajes → Detalle`) con el mismo selector dual (**Subir archivo local** / **Vincular URL externa**) y dropzone PDF/JPG/PNG. |
| [09_tabla_entregas_comprobante_1440.png](../screenshots/document_attachments/09_tabla_entregas_comprobante_1440.png) | `1440×900` | Tabla `Entregas` mostrando convivencia limpia de **Descargar comprobante** (adjunto PDF), entrega `Fallida` con motivo e histórico `Ver comprobante ↗`. |

---

## 4. Reconciliación de integridad (solo lectura) en `TransportesSegurosSA` y `TransportesSegurosSA_DW`

| Base de datos | Verificación ejecutada | Resultado postflight | Estado |
|---|---|---|---|
| `TransportesSegurosSA` (Original) | `sqlcmd -K ReadOnly` sobre catálogo y conteos | `doc_col = NULL`, `ent_col = NULL`, `sp_doc = NULL`, `sp_ent = NULL`; `usuarios = 2`, `solicitudes = 3`, `viajes = 1`, `entregas = 2`, `documentos = 1`, `auditorias = 34` | **INTACTA (0 escrituras)** |
| `TransportesSegurosSA_DW` | `sqlcmd -K ReadOnly` sobre `dbo.fact_entregas` | `fact_entregas = 2`, `MAX(fecha_carga_utc) = 2026-10-28 04:53:20.5254153` | **INTACTA (0 ETL)** |
| `TransportesSegurosSA_Test` | Verificación de residuos sintéticos de pruebas | `0` usuarios efímeros (`__doc_%`/`__pw_doc_%`), `0` solicitudes/viajes/entregas/documentos sintéticos de suites; datos manuales del propietario preservados intactos | **LIMPIA** |
