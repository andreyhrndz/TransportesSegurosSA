# Replicacion — PENDIENTE DE VALIDACION

No se ha implementado ni probado la replicacion entre dos laptops.

## Plan recomendado cuando dispongan de ambas laptops

1. Verificar la edicion de SQL Server en ambos equipos y habilitar SQL Server Agent.
2. Configurar conectividad de red, puertos TCP de SQL Server, cortafuegos, autenticacion y nombre estable de los equipos.
3. Elegir el tipo de replicacion con el profesor (para este ejercicio se propone replicacion transaccional SQL Server).
4. Definir servidor publicador y distribuidor; el segundo sera suscriptor.
5. Elegir **tablas concretas** de `TransportesSegurosSA`; es mejor empezar con un subconjunto sin relaciones complejas y ampliar despues.
6. Configurar distribucion, publicacion, instantanea inicial, suscripcion y credenciales de los agentes con el asistente de SSMS.
7. Insertar un dato de demostracion nuevo en la base publicadora y consultar que aparezca en el suscriptor.
8. Documentar hora, equipo, consulta, resultado y capturas de ambos lados.

No se incluyen comandos de configuracion listos para ejecutar porque dependen de los nombres reales de los servidores, cuentas, redes, permisos y del esquema de articulos a publicar. Inventarlos seria arriesgado.

La copia por RESTORE no equivale a replicacion continua. Tampoco confundir ETL al Data Warehouse con replicacion SQL Server.
