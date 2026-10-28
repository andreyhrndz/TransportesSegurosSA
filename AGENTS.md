# Instrucciones de desarrollo — Transportes Seguros S.A.

## Contexto

- Stack obligatorio: PHP 8.4, Microsoft SQL Server 2025 (PDO_SQLSRV), Bulma y HTMX. No introducir frameworks sin autorización.
- Bases: `TransportesSegurosSA` y `TransportesSegurosSA_DW`. Hay 24 tablas de referencia; verificar scripts e instancia antes de afirmar objetos desplegados.
- Sólo están implementados login, dashboard y consulta/alta de clientes. Replicación pendiente. No mostrar referencias académicas, grupos o códigos internos.

## Git y OpenSpec

- `main` es estable y no se modifica directamente. `development` recibe cambios exclusivamente por PR revisado, sin commits directos.
- Todo trabajo parte de `development` en una rama propia. Con autorización explícita se puede crear rama, commit, push y PR hacia `development`.
- Prohibidos merge, auto-merge, force-push, autoaprobación, borrado de ramas remotas y cambios directos a `main`/`development`.
- OpenSpec es obligatorio para cambios no triviales: propuesta, specs delta, diseño, tareas, validación, implementación, pruebas y archivo en ejecuciones separadas.

## Seguridad y SQL

- No guardar credenciales, hashes ni configuración local en Git o `public/`.
- Mantener sesiones PHP, CSRF, permisos individuales, parametrización y escape HTML.
- `SESSION_CONTEXT('usuario_id')` deriva sólo de sesión PHP validada; no autentica de forma independiente.
- Usar SP existentes. Si falta SP o permiso, crear primero un change SQL aprobado. No ejecutar DELETE, migraciones, datos, Jobs, DW, backups o replicación sin autorización.
- El entorno Windows Integrated Auth/TLS de laboratorio no es producción.

## UX y cierre

- Tokens CSS centrales, superficies desaturadas, controles de alto contraste y SVG accesibles; sin iconos Unicode improvisados.
- Componentes consistentes y responsive; teclado, foco, roles, etiquetas, carga/error/vacío y controles táctiles.
- Ocultar módulos no implementados; no crear acciones ficticias.
- Validar OpenSpec y distinguirlo de pruebas funcionales. Como mínimo comprobar login, dashboard y clientes cuando el ambiente lo permita y documentar bloqueos.
