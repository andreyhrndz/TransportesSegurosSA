## Context

Stack: PHP 8.4, Bulma, HTMX y PDO_SQLSRV. La aplicación local usa Windows Integrated Auth, `Encrypt=yes;TrustServerCertificate=yes`. El equipo principal verificó conexión y 24 tablas con ODBC Driver 18. Un intento aislado en el entorno de Codex informó ODBC Driver 17 sin soporte de cifrado; es una limitación de ese entorno y no invalida la evidencia principal.

## Goals / Non-Goals

**Goals:** pruebas proporcionales al riesgo, checklist de release, accesibilidad revisable y separación local/producción.

**Non-Goals:** publicar el sitio, cambiar el servidor, almacenar credenciales, certificar formalmente WCAG o instalar herramientas sin aprobación.

## Decisions

- Cada change aportará pruebas unitarias/HTTP/SQL/manuales que correspondan a su riesgo y no marcará tareas sin evidencia.
- Se verificará 320 px, tablet y escritorio; teclado, foco, contraste, nombres accesibles, regiones de estado y movimiento reducido.
- Producción exigirá TLS verificable, cuenta SQL de mínimo privilegio, secretos fuera de `public/`/Git, logs seguros y plan de rollback.

## Risks / Trade-offs

- [Ambiente Codex distinto] → ejecutar smoke funcional en el equipo principal con ODBC 18 y registrar ambiente/driver; no degradar TLS para adaptar Codex.
- [Cobertura superficial] → matriz por permisos, estados y errores, más pruebas exploratorias.

## Migration Plan

Aplicar gates antes de cada PR y release; si falla un gate, no promover ni fusionar y documentar el hallazgo.
