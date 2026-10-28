## Why

La aplicación inicial no tiene una estrategia de calidad/release consolidada. La conexión del equipo principal con PHP 8.4, PDO_SQLSRV y ODBC Driver 18 sí verificó las 24 tablas; cualquier fallo del entorno aislado de Codex no representa un defecto del proyecto.

## What Changes

- Definir matriz de pruebas PHP/SQL/UI, smoke de login/dashboard/clientes y regresión HTMX/CSRF/permisos.
- Definir criterios WCAG AA, responsive, teclado, foco, mensajes y contraste para todos los módulos.
- Documentar configuración local frente a producción, despliegue seguro y evidencia de release.

## Alcance, exclusiones y riesgos

Excluye implementar módulos, desplegar producción, cambiar TLS/ODBC, migrar BD o automatizar Jobs. Riesgo: ejecutar pruebas en un ambiente distinto al principal; se registra el driver y evidencia real, sin presentar el resultado aislado de Codex como fallo del proyecto.

## Capabilities

### New Capabilities
- `release-quality-accessibility`: Criterios verificables de calidad, accesibilidad y salida.

### Modified Capabilities
- Ninguna.

## Impact

Orquesta la validación de todos los changes y actualizará README/guías operativas, sin introducir framework de pruebas no aprobado.
