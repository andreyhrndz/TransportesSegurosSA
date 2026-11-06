# 02. Generar bcrypt para el usuario administrador

**Nunca guardes la contrasena en el archivo SQL o el ZIP.**

Si tienes PHP instalado, ejecuta en una terminal (PowerShell):

```powershell
php -r "echo password_hash(readline('Clave de administrador: '), PASSWORD_BCRYPT), PHP_EOL;"
```

La consola mostrara una cadena que empieza con `$2y$` o `$2b$`. Copiala y pegala **solo** en el valor `@hash` del archivo `03_crear_administrador.sql`. No incluyas las comillas propias de PHP. Conserva las comillas simples del valor SQL.

Si el comando no funciona porque tu version de PHP no admite `readline`, usa temporalmente:

```powershell
php -r "echo password_hash('CAMBIAR_AQUI_POR_TU_CLAVE', PASSWORD_BCRYPT), PHP_EOL;"
```

Ten presente que esta ultima variante deja la clave visible en el historial de la terminal. Prefiere la primera. No compartas capturas que revelen el hash o la contrasena.

**Importante:** SQL Server NO valida bcrypt por si mismo. El hash se genera y verifica desde la aplicacion (por ejemplo, `password_verify` en PHP). En este laboratorio solo almacenamos y verificamos el formato del hash.
