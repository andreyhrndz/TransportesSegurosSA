# Transportes Seguros S.A. — Primera entrega web

Aplicación académica en **PHP 8.4 + Bulma 1.0.4 + HTMX 2.0.10 + Microsoft SQL Server**, adaptada del ejemplo CRUD del profesor y de los scripts originales del proyecto de 24 tablas.

## Incluye

- Autenticación de `dbo.usuarios` con `password_verify()` y el hash bcrypt ya guardado en SQL Server.
- Sesión PHP, cierre de sesión, token CSRF y comprobación de cuenta activa.
- Dashboard con indicadores reales de `clientes`, `solicitudes`, `viajes` y `entregas`.
- Listado de clientes físicos y jurídicos con búsqueda, paginación y HTMX.
- Registro de clientes mediante los procedimientos **existentes** `dbo.sp_crear_cliente_fisico` y `dbo.sp_crear_cliente_juridico`.
- Comprobación del permiso `CLIENTES_CREAR` y establecimiento de `SESSION_CONTEXT('usuario_id')` en la conexión a SQL Server después de autenticar la sesión PHP.
- Archivos internos en `app/` **fuera de la raíz web**, y recursos públicos en `public/`.

**Alcance de esta entrega:** consultar y registrar clientes. No incluye todavía modificar, desactivar, eliminar clientes ni los módulos operativos restantes. No hemos creado permisos o procedimientos adicionales en la base de datos. El esquema actual tiene `CLIENTES_CREAR`, pero no un permiso independiente `CLIENTES_VER`; temporalmente lo usamos también para permitir visualizar el directorio.

## Requisitos ya comprobados en este equipo

- PHP 8.4.27, NTS, x64: `C:\Herramientas\PHP\8.4\php.exe`.
- ODBC Driver 18 para SQL Server, x64.
- Extensiones `php_sqlsrv_84_nts_x64.dll` y `php_pdo_sqlsrv_84_nts_x64.dll` cargadas.
- Base de datos `TransportesSegurosSA` con las 24 tablas y procedimientos.
- Usuario `admin_proyecto` activo con su contraseña bcrypt y 15 permisos.

## Instalar en Windows (sin afectar C:\serverweb)

1. Conserva cualquier archivo que ya tengas en `C:\Proyectos\TransportesSegurosSA` (en particular `pruebas\probar_conexion.php`).
2. Extrae este ZIP y **copia el contenido** de la carpeta `TransportesSegurosSA_Frontend_Inicial` a `C:\Proyectos\TransportesSegurosSA`. Deben quedar las carpetas `app`, `public` y este `README.md`. No hace falta copiar PHP ni ODBC al proyecto.
3. Abre PowerShell y ejecuta:

   ```powershell
   cd C:\Proyectos\TransportesSegurosSA
   & "C:\Herramientas\PHP\8.4\php.exe" -S 127.0.0.1:8000 -t public
   ```

4. En Chrome, visita **http://127.0.0.1:8000**.
5. Inicia sesión con `admin_proyecto` y la contraseña que estableciste al ejecutar los scripts. **No** uses el hash bcrypt como contraseña: escribe el texto original.
6. Haz clic en **Clientes**. Debes ver los dos clientes de demostración (`DEMO-FIS-001` y `DEMO-JUR-001`), siempre que sigan activos y no hayan sido eliminados durante alguna prueba.
7. Prueba **+ Cliente físico** y **+ Empresa** con identificaciones nuevas, por ejemplo `WEB-FIS-001` y `WEB-JUR-001`. Comprueba que aparezcan en el listado sin recargar toda la página.
8. Para detener el servidor local, usa **Ctrl+C** en la misma terminal.

**No uses** `php -S` solo, porque `php` sin la ruta completa todavía puede invocar PHP 7.4 de `C:\serverweb`.

**Importante:** la carpeta que exponemos como raíz del sitio es `public`, **nunca** la carpeta completa del proyecto. La hoja de estilos Bulma se carga desde CDN y requiere acceso a Internet; HTMX se incluye localmente.

## Comprobación SQL del registro

Ejecuta en SSMS:

```sql
USE TransportesSegurosSA;
GO
SELECT TOP (20)
    c.cliente_id,
    c.identificacion,
    c.correo,
    c.activo,
    f.nombre,
    f.primer_apellido,
    j.razon_social
FROM dbo.clientes c
LEFT JOIN dbo.clientes_fisicos f ON f.cliente_id = c.cliente_id
LEFT JOIN dbo.clientes_juridicos j ON j.cliente_id = c.cliente_id
ORDER BY c.cliente_id DESC;
GO
```

## Resolución de problemas

- **Error al conectar:** confirma que el servicio de SQL Server esté activo y que el usuario de Windows que ejecuta el servidor PHP tenga acceso a `TransportesSegurosSA`. La prueba anterior con PHP se hizo mediante autenticación integrada de Windows.
- **SQL Server en una instancia distinta de localhost:** desde la misma terminal PowerShell donde levantarás el servidor web, ejecuta `$env:TSSA_DB_SERVER='NOMBREPC\INSTANCIA'` antes de `php.exe -S ...`; esa variable de entorno se leerá en `app/bootstrap.php`.
- **El navegador muestra la página sin formato:** Bulma utiliza una URL de CDN. Revisa tu conexión a Internet.
- **No aparecen clientes de ejemplo:** revisa que el usuario tenga el permiso `CLIENTES_CREAR`; verifica en SSMS que existan los registros `DEMO-FIS-001` y `DEMO-JUR-001`.
- **Error al guardar cliente:** revisa la salida de la terminal donde corre `php.exe -S`; el servidor registra los errores internos sin mostrarlos en la respuesta del formulario.
- **Sesión PHP no funciona:** comprueba que PHP puede escribir en la ruta temporal de sesiones (`session.save_path`).
- **Login no funciona:** recuerda usar la contraseña original del administrador y no el hash; `password_verify()` debe aceptar el hash bcrypt generado con PHP 7.4.

## Seguridad y próximos pasos

- Esta entrega es **para desarrollo local**, no un despliegue de producción.
- La cadena de conexión usa autenticación integrada de Windows, `Encrypt=yes` y `TrustServerCertificate=yes` para el laboratorio local. Para producción se debe validar el certificado TLS y utilizar una cuenta de base de datos con permisos mínimos, sin dependencia de la cuenta Windows de desarrollo.
- No almacenes contraseñas ni hashes reales en el repositorio, ni hagas pública la carpeta `app/`.
- El contexto SQL `SESSION_CONTEXT('usuario_id')` se establece **después** de comprobar una sesión PHP autenticada. El contexto por sí solo no constituye autenticación SQL Server y no sustituye los permisos reales de la cuenta que conecta a la base.
- Los módulos de **edición y desactivación** de clientes requieren procedimientos y autorización específicos, así como reglas sobre solicitudes relacionadas. No se implementaron como `DELETE` directo.
- El siguiente avance recomendado es completar la seguridad de los módulos y agregar el formulario de **solicitudes**, incluyendo mercancías y su tipo de tabla TVP (`dbo.tipo_mercancias`).
