# Informe final de QA — Carga segura de documentos PDF/JPG/PNG y compatibilidad con URL

**Fecha de ejecución:** 2026-11-20 (America/Costa_Rica)  
**Rama:** `feat/document-attachments-secure-upload` → base `development` (PR independiente)  
**Base de pruebas (única con escrituras):** `TransportesSegurosSA_Test` (Migraciones `01` a `14`)  
**Bases protegidas (estrictamente solo lectura / intactas):** `TransportesSegurosSA` (Migración `13`, 0 escrituras) y `TransportesSegurosSA_DW` (`fact_entregas = 2`, 0 ETL)  
**Entorno ejecutado:** PHP `8.4` NTS x64 (`pdo_sqlsrv`, `fileinfo`, `getimagesize`), SQL Server 2025 (`localhost`), Playwright + Chromium headless en 6 resoluciones.

---

## Veredicto de QA

> [!TIP]
> **VEREDICTO: `APTO PARA MERGE` (hacia `development`)**  
> Todas las suites T-SQL, HTTP/seguridad, almacenamiento/respaldo-restauración, Playwright E2E multi-resolución y regresiones existentes pasaron al **100%** sobre `TransportesSegurosSA_Test`, dejando **0 residuos sintéticos** en BD y **0 archivos residuales** en `storage/private/documents/`.  
> **Requisito previo obligatorio para usar en `TransportesSegurosSA` (Original):** aplicar la Migración `14` tras respaldo FULL verificado, según [document-attachments-storage-backup-restore.md](../ops/document-attachments-storage-backup-restore.md#3-requisitos-obligatorios-antes-de-usar-este-pr-sobre-transportessegurossa-base-original).

---

## 1. Matriz de pruebas ejecutadas (evidencia real)

| Caso | Área / Descripción | Resultado esperado | Resultado observado | Estado | Fuente BD | Duración |
|---|---|---|---|---|---|---:|
| **QA-01** | Lint sintáctico PHP (`11` archivos) y JS (`app.js`, Playwright) | 0 errores de sintaxis | `No syntax errors detected` en todos los archivos PHP y exit `0` en `node --check` | **PASS** | N/A | < 1 s |
| **QA-02** | Validación OpenSpec (`openspec validate --all`) | 100% de especificaciones y changes válidos | `23 passed, 0 failed` (incluye `document-attachments-secure-upload`) | **PASS** | N/A | < 2 s |
| **QA-03** | Migración `14` (idempotencia 2× + suite T-SQL `18`) | Esquema aditivo, `GUIA-DEMO-001` preservado como `EXTERNA`, alta interna/externa, auditoría, RBAC `50406`/`50103` y rechazos `50433`/`50434`/`50101` | Re-ejecución idempotente limpia; `18_contract_tests_document_attachments.sql` 100% PASS | **PASS** | `TransportesSegurosSA_Test` | 0.49 s |
| **QA-04** | Rollback `14` no destructivo (2 rutas en BD efímera aislada) | Con `0` internos: remueve columnas/índices; con `>=1` internos: revierte SPs y **conserva columnas y datos** | Ambos escenarios verificados en BD temporal aislada y eliminada tras el ensayo | **PASS** | BD efímera descartada | < 2 s |
| **QA-05** | Unitarias de storage, anti-traversal y saneamiento de cabeceras | Rechaza rutas dentro de `public/`, UNC, symlinks, `..` en `name`/`full_path`; sanea `Content-Disposition` | 100% de aserciones unitarias en `document_attachments_http_security_test.php` superadas | **PASS** | N/A | (en QA-06) |
| **QA-06** | Suite HTTP/Seguridad integral (`tests/document_attachments_http_security_test.php`) | Subidas válidas PDF/PNG/JPG/10 MiB exactos + descarga byte a byte SHA-256 + cabeceras seguras + URL externa + negativos (`401`/`302`, `403`, `405`, `413`, `422`, `404`, fallo SQL) + respaldo/restauración SHA-256 | 5 bloques `OK`, 0 warnings, 0 filas rotas, 0 huérfanos en disco, 4/4 archivos íntegros tras respaldo+restauración | **PASS** | `TransportesSegurosSA_Test` | 9.58 s |
| **QA-07** | E2E navegador real Playwright (`tests/document_attachments_playwright.js`) | Modo dual sin perder campos, Drag & Drop + `Seleccionar archivo` + `Quitar`, rechazo 422 sin cerrar modal, limpieza de error al elegir válido, subida PDF/PNG/URL, descarga SHA-256, 1 único POST, control visual `DOCUMENTOS_VER` vs `DOCUMENTOS_REGISTRAR`, 6 viewports sin overflow desktop | E2E 1, E2E 2 y E2E 3 100% PASS; `0` `pageerror`, `0` respuestas `5xx`, 7 capturas inspeccionadas directamente | **PASS** | `TransportesSegurosSA_Test` | 27.68 s |
| **QA-08** | Script de respaldo/verificación/restauración (`tools/scripts/backup-document-storage.ps1`) | `-DryRun` no escribe; respaldo crea manifiesto SHA-256; `-VerifyOnly` y `-Restore` validan 100%; alteración de 1 byte devuelve exit != 0 | `-DryRun`, Backup, `-VerifyOnly`, `-Restore` 100% PASS; detección de alteración exit `2`; carpeta temporal eliminada | **PASS** | Temp local (sin SQL) | 5.42 s |
| **QA-09** | Regresión UTF-8 y lenguaje (`tests/utf8_encoding_and_language_test.php`) | 0 regresiones de codificación ni textos rotos | `OK: suite de codificación UTF-8 de extremo a extremo y lenguaje de interfaz superada` | **PASS** | `TransportesSegurosSA_Test` | 6.92 s |
| **QA-10** | Regresión de rutas operativas (`tests/operational_routes_regression.php`) | Rutas operativas y administrativas responden sin errores | `OK: rutas operativas y administrativas consultadas con sesión temporal` | **PASS** | `TransportesSegurosSA_Test` | 3.77 s |
| **QA-11** | Regresión sesión/CSRF (`tests/session_csrf_regression.php`) | Ciclo cookie/CSRF intacto sin escrituras | `OK: ciclo GET/cookie/POST CSRF verificado` | **PASS** | `TransportesSegurosSA_Test` | 1.45 s |
| **QA-12** | Regresión flota/datos maestros (`tests/master_data_http_functional_test.php`) | Flujos de flota y catálogos sin regresiones | `OK: pruebas funcionales HTTP de Flota y Datos Maestros completadas` | **PASS** | `TransportesSegurosSA_Test` | 3.60 s |
| **QA-13** | Regresión paradas, bitácora y reportes/DW (`tests/stops_audit_reports_http_test.php`) | Matrices A/B/C, DW solo lectura (0 ETL), CSV seguro e impresión intactos | `=== SUITE HTTP STOPS + AUDIT + REPORTS + DW: 100% PASS ===` | **PASS** | `TransportesSegurosSA_Test` + `DW` (RO) | 3.42 s |
| **QA-14** | Regresión JS `descomponerNombreCR` (`tests/test_descomponer_nombre.js`) | 6 casos de nombres de Costa Rica válidos | `TODAS LAS PRUEBAS DE descomponerNombreCR PASARON CON ÉXITO.` | **PASS** | N/A | 0.15 s |
| **QA-15** | Limpieza de residuos en Test y storage local | 0 adjuntos/usuarios sintéticos en Test; solo `.gitkeep` en `storage/private/documents/` | `total_documentos = 1` (`GUIA-DEMO-001`), `docs_internos = 0`, `usuarios_efimeros = 0`, solo `.gitkeep` en disco | **PASS** | `TransportesSegurosSA_Test` | < 1 s |
| **QA-16** | Postflight estrictamente solo lectura en Original y DW | `TransportesSegurosSA` intacta en Mig 13 y `TransportesSegurosSA_DW` intacta (`fact_entregas = 2`) | `col_origen_archivo = NULL`, `documentos = 1`, `auditorias = 34`, `fact_entregas = 2` (`2026-10-28 04:53:20.5254153`) | **PASS** | `TransportesSegurosSA` + `DW` (`ReadOnly`) | < 1 s |

---

## 2. Cobertura explícita de pruebas negativas y seguridad

Verificadas en `tests/document_attachments_http_security_test.php`, `database/tests/18_contract_tests_document_attachments.sql` y `tests/document_attachments_playwright.js`:

- **Autenticación y RBAC:**
  - Descarga sin sesión (`/entregas/documento_descargar.php?id=...`) → redirección `302` a `/auth/login.php` (nunca entrega bytes).
  - Usuario sin `DOCUMENTOS_VER` intentando descargar → `403`.
  - Usuario con `DOCUMENTOS_VER` pero sin `DOCUMENTOS_REGISTRAR`: botón `Nuevo documento` oculto en UI, `GET /entregas/documento_form.php` → `403`, `POST /entregas/documento.php` → `403`.
  - Validación de principio de mínimo privilegio: usuario con `DOCUMENTOS_REGISTRAR` sin `CATALOGOS_VER` sí puede abrir `documento_form.php` (`sp_listar_tipos_documento` actualizado en Migración 14).
- **CSRF y métodos HTTP:**
  - `POST /entregas/documento.php` sin token CSRF o token inválido → `403`.
  - `GET /entregas/documento.php` → `405 Method Not Allowed`.
  - `POST /entregas/documento_descargar.php` → `405 Method Not Allowed`.
- **Validación estricta de tamaño (`413`) y contenido (`422`):**
  - Archivo mayor a 10 MiB (`10 MiB + 1 byte`) → `413 Payload Too Large`, 0 filas y 0 archivos en disco.
  - Cuerpo multipart superior a `post_max_size` (`12 MiB` en `start-dev.ps1`) → `413` explícito (sin error genérico ni falso `403` de CSRF).
  - Archivos rechazados con `422` y 0 huérfanos: archivo vacío (0 bytes), PDF fingido (`simulado.pdf` sin `%PDF-`), PHP disfrazado de JPG, SVG malicioso, extensión doble (`payload.php.pdf`), desajuste extensión/contenido (PNG renombrado a `.pdf`), traversal en `name` y `full_path` (`../../evil.pdf` y `..\..\evil.pdf`).
  - Exclusión mutua estricta: enviar archivo + URL simultáneamente o ninguno de los dos → `422`.
  - URL externa con esquema no permitido (`javascript:`, `file://`, `ftp://`, credenciales incrustadas) → `422`; nunca realiza peticiones remotas salientes (anti-SSRF).
- **Aislamiento de almacenamiento, atomicidad y cabeceras de descarga:**
  - Acceso HTTP directo a `/storage/private/documents/<storage_key>` → `404` limpio (sin exponer bytes ni iniciar descarga).
  - Fallo SQL (solicitud inexistente) tras recibir un archivo válido → `422` y limpieza inmediata del archivo físico (0 huérfanos).
  - Descarga autenticada de archivo interno verifica: `Content-Type` exacto, `Content-Disposition: attachment` con saneamiento ASCII + `filename*`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store, max-age=0` y coincidencia SHA-256 byte a byte.
  - Descarga solicitada sobre documento `EXTERNA` o ID inexistente → `404`.

---

## 3. Validación visual (Playwright Chromium — 6 resoluciones)

Todas las capturas fueron generadas exclusivamente contra `TransportesSegurosSA_Test` usando usuario efímero (`__pw_doc_*`), sin datos reales ni secretos, e inspeccionadas visualmente:

| Captura | Viewport | Qué valida |
|---|---|---|
| [01_modal_documento_archivo_1440.png](../screenshots/document_attachments/01_modal_documento_archivo_1440.png) | `1440×900` | Modal abierto con scroll lock global, modo predeterminado **Subir archivo local**, dropzone accesible Dark Logistics, límite efectivo `10 MB` y 1 archivo por documento. |
| [02_modal_documento_url_1440.png](../screenshots/document_attachments/02_modal_documento_url_1440.png) | `1440×900` | Cambio al modo **Vincular URL externa** preservando `Solicitud`, `Tipo de documento` y `Número de referencia`. |
| [03_modal_documento_seleccionado_1440.png](../screenshots/document_attachments/03_modal_documento_seleccionado_1440.png) | `1440×900` | Selección por Drag & Drop real de PDF válido (`factura_comercial_demo.pdf · Documento PDF · 269 B`), tarjeta de vista previa con botón `Quitar`, borde verde y limpieza automática del error previo. |
| [04_tabla_documentos_mixta_1440.png](../screenshots/document_attachments/04_tabla_documentos_mixta_1440.png) | `1440×900` | Convivencia limpia en tabla de `Archivo adjunto` (PDF y PNG con metadatos y botón `Descargar`), `Enlace externo` (`Abrir referencia`) y registro histórico `GUIA-DEMO-001`. |
| [05_documentos_1366x768.png](../screenshots/document_attachments/05_documentos_1366x768.png) | `1366×768` | Disposición desktop estándar sin overflow horizontal ni solapamientos. |
| [06_documentos_768x1024.png](../screenshots/document_attachments/06_documentos_768x1024.png) | `768×1024` | Disposición tablet con las 5 columnas visibles sin recortar `Descargar` ni partir `Abrir referencia`. |
| [07_documentos_375x667.png](../screenshots/document_attachments/07_documentos_375x667.png) | `375×667` | Disposición móvil responsive con controles táctiles apilados y tabla con scroll contenedor sin desbordar el viewport. |

### Mejoras UX/visuales detectadas y corregidas durante la inspección

1. **Error stale en `#mensaje-documento` tras corregir archivo:** al seleccionar un archivo válido después de un rechazo `422`, el banner superior de error permanecía visible. Corregido en `public/assets/js/app.js` (limpieza inmediata al seleccionar archivo válido o pulsar `Quitar`) y verificado en `03_modal_documento_seleccionado_1440.png`.
2. **Recorte leve en `768×1024` y salto de línea en `Abrir referencia`:** ajustado `.is-nowrap` en enlaces de acción y `.doc-support-filename` responsive (`34ch` en desktop / `16ch` bajo `960px` con `title` completo) en `public/entregas/lista.php` y `public/assets/css/estilos.css`; re-verificado en `04` y `06`.
3. **Mínimo privilegio en `documento_form.php`:** detectado durante E2E que `dbo.sp_listar_tipos_documento` exigía `CATALOGOS_VER`, bloqueando a usuarios con solo `DOCUMENTOS_REGISTRAR`. Corregido en Migración `14` aceptando `CATALOGOS_VER OR DOCUMENTOS_REGISTRAR`.

---

## 4. Reconciliación de integridad (solo lectura) en `TransportesSegurosSA` y `TransportesSegurosSA_DW`

| Base de datos | Verificación ejecutada | Resultado postflight | Estado |
|---|---|---|---|
| `TransportesSegurosSA` (Original) | `sqlcmd -K ReadOnly` sobre catálogo y conteos | `col_origen_archivo = NULL`, `sp_registrar_documento_archivo = NULL`, `sp_obtener_documento = NULL`; `usuarios = 2`, `clientes = 2`, `solicitudes = 3`, `viajes = 1`, `entregas = 2`, `documentos = 1` (`GUIA-DEMO-001`), `auditorias = 34` | **INTACTA (0 escrituras)** |
| `TransportesSegurosSA_DW` | `sqlcmd -K ReadOnly` sobre `dbo.fact_entregas` | `fact_entregas = 2`, `MAX(fecha_carga_utc) = 2026-10-28 04:53:20.5254153` | **INTACTA (0 ETL)** |
| `TransportesSegurosSA_Test` | Verificación de residuos y disco | `total_documentos = 1` (`GUIA-DEMO-001`, `EXTERNA`), `docs_internos = 0`, `usuarios_efimeros = 0`; `storage/private/documents/` contiene solo `.gitkeep` | **LIMPIA** |
