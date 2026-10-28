## Context

Stack: PHP 8.4, Bulma, HTMX y PDO_SQLSRV. La aplicación local usa Windows Integrated Auth, `Encrypt=yes;TrustServerCertificate=yes`. La prueba existente falló porque el cliente informó ODBC Driver 17 sin soporte del cifrado configurado; por tanto no hay evidencia de smoke SQL en esta ejecución.

## Goals / Non-Goals

**Goals:** pruebas proporcionales al riesgo, checklist de release, accesibilidad revisable y separación local/producción.

**Non-Goals:** publicar el sitio, cambiar el servidor, almacenar credenciales, certificar formalmente WCAG o instalar herramientas sin aprobación.

## Decisions

- Cada change aportará pruebas unitarias/HTTP/SQL/manuales que correspondan a su riesgo y no marcará tareas sin evidencia.
- Se verificará 320 px, tablet y escritorio; teclado, foco, contraste, nombres accesibles, regiones de estado y movimiento reducido.
- Producción exigirá TLS verificable, cuenta SQL de mínimo privilegio, secretos fuera de `public/`/Git, logs seguros y plan de rollback.

## Risks / Trade-offs

- [Ambiente no conectable] → resolver driver/certificado con administrador antes de certificar flujos SQL.
- [Cobertura superficial] → matriz por permisos, estados y errores, más pruebas exploratorias.

## Migration Plan

Aplicar gates antes de cada PR y release; si falla un gate, no promover ni fusionar y documentar el hallazgo.
