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

## Seguridad de acceso

La aplicación admite únicamente autenticación local con usuario/correo y contraseña verificada con `password_verify()`. Los errores de acceso son deliberadamente genéricos, se limitan a ocho intentos por sesión cada cinco minutos y se registran sin contraseñas ni hashes. La sesión vence tras 30 minutos de inactividad o 8 horas absolutas y se invalida al cerrar sesión o cuando el usuario deja de estar activo.

En desarrollo HTTP la cookie `TSSA_TRANSPORTES_SESSION` no puede usar `Secure`; en producción HTTPS se emite como `__Host-TSSA_TRANSPORTES_SESSION`, `Secure`, `HttpOnly` y `SameSite=Lax`. El nombre específico evita colisiones con otras aplicaciones locales que compartan `localhost` o `127.0.0.1`. Producción debe terminar TLS antes de PHP, usar un certificado verificable, proporcionar `TSSA_DB_SERVER` fuera de Git y sustituir la autenticación integrada por una cuenta SQL de mínimo privilegio. No se deben colocar credenciales, DSN con secretos ni hashes en `public/`.

Para trabajar sobre una copia aislada, configurar antes de iniciar PHP:

```powershell
$env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
```

Sin esa variable se mantiene la compatibilidad local con `TransportesSegurosSA`. La
aplicación sólo acepta esos dos nombres; las pruebas de escritura deben llamar
`require_test_database(db())` y fallan cerradas si la conexión efectiva no es la base
de pruebas.

Los encabezados de navegador restringen el contenido a la propia aplicación y a Bulma desde jsDelivr. Antes de producción, el CSS externo debe fijarse con SRI o alojarse como activo aprobado localmente; no se deben añadir otros orígenes sin revisar su CSP.

## OpenSpec

Los changes activos están en `openspec/changes/`. La secuencia, dependencias SQL, riesgos y cierre están en [docs/frontend-roadmap.md](docs/frontend-roadmap.md). La validación OpenSpec confirma estructura, no implementación.
