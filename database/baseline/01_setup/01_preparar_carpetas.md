# 01. Preparar las carpetas del servidor

Esta guia parte de **SQL Server y SSMS ya instalados**. No instala programas.

1. En Windows, crear estas carpetas si todavia no existen:

   - `C:\SQL\SQLData` — archivo `.mdf` de la base principal y de la restauracion.
   - `C:\SQL\SQLLog` — archivos `.ldf`.
   - `C:\SQL\Backups` — archivos `.bak` FULL y diferenciales.

2. **SQL Server escribe los archivos**, no SSMS. Debe disponer de permisos de escritura la cuenta del servicio **SQL Server (MSSQLSERVER)**, y para respaldos/restauraciones la misma cuenta. Para comprobar la cuenta, ejecutar `02_verificar_servidor.sql`.
3. En Propiedades > Seguridad de cada carpeta, dar permiso **Modificar** a la cuenta de SQL Server, preferentemente a su SID de servicio `NT SERVICE\MSSQLSERVER` en instancia predeterminada. Usar el nombre real que revele la consulta. No dar permisos a `Todos` ni desactivar seguridad.
4. En pruebas con instancia nombrada, las cuentas y nombres de servicio cambian; ajustar permisos.
5. Si las carpetas no existen o el servicio no puede escribir, `CREATE DATABASE`/`BACKUP DATABASE` fallaran. No cambiar las rutas del proyecto de manera parcial.

**Importante:** el nombre definitivo es `TransportesSegurosSA`, SIN el prefijo `BD` ni `DB`. Los datos se almacenan en `C:\SQL\SQLData\TransportesSegurosSA.mdf`, el log en `C:\SQL\SQLLog\TransportesSegurosSA.ldf`.
