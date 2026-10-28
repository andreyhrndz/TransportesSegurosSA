## Why

La aplicación inicial no tiene una estrategia de calidad/release consolidada y su acceso real a la base no pudo verificarse en este entorno por incompatibilidad de cifrado ODBC. Antes de producción se requieren pruebas, accesibilidad y límites operativos explícitos.

## What Changes

- Definir matriz de pruebas PHP/SQL/UI, smoke de login/dashboard/clientes y regresión HTMX/CSRF/permisos.
- Definir criterios WCAG AA, responsive, teclado, foco, mensajes y contraste para todos los módulos.
- Documentar configuración local frente a producción, despliegue seguro y evidencia de release.

## Alcance, exclusiones y riesgos

Excluye implementar módulos, desplegar producción, cambiar TLS/ODBC, migrar BD o automatizar Jobs. Riesgo: no poder probar contra SQL local; se registra como bloqueo con pasos de ambiente, nunca como éxito funcional.

## Capabilities

### New Capabilities
- `release-quality-accessibility`: Criterios verificables de calidad, accesibilidad y salida.

### Modified Capabilities
- Ninguna.

## Impact

Orquesta la validación de todos los changes y actualizará README/guías operativas, sin introducir framework de pruebas no aprobado.
