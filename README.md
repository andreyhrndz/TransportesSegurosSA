# Transportes Seguros S.A.

Aplicación administrativa de transporte y logística en PHP 8.4, SQL Server (PDO_SQLSRV), Bulma y HTMX.

## Estado auditado

El frontend inicial implementa inicio/cierre de sesión, sesión PHP/CSRF, autorización por permisos existentes, dashboard de lectura y consulta/alta de clientes físicos y jurídicos. No están implementados los módulos operativos, ni edición/desactivación de clientes.

Los scripts SQL de referencia están en `C:\AdmBD2026\BDTransportesSegurosSA`; no se modifican desde este repositorio. La replicación sigue pendiente.

## Rutas locales

- Proyecto: `C:\AdmBD2026\ProyectoTransportesSegurosSA`
- PHP: `C:\AdmBD2026\Herramientas\PHP\8.4\php.exe`
- Raíz web: `public\`

```powershell
cd C:\AdmBD2026\ProyectoTransportesSegurosSA
& "C:\AdmBD2026\Herramientas\PHP\8.4\php.exe" -S 127.0.0.1:8000 -t public
```

La conexión del equipo principal ya funciona con PHP 8.4, PDO_SQLSRV y ODBC Driver 18. La autenticación integrada de Windows y `TrustServerCertificate=yes` son sólo para laboratorio; producción exige TLS verificable, secretos fuera de Git/public y cuenta SQL de mínimo privilegio.

## OpenSpec

Los changes activos están en `openspec/changes/`. La secuencia, dependencias SQL, riesgos y cierre están en [docs/frontend-roadmap.md](docs/frontend-roadmap.md). La validación OpenSpec confirma estructura, no implementación.
