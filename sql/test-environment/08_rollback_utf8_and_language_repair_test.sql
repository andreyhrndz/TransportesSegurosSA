/*
  Script de reversión para 08_utf8_and_language_repair_test.sql.
  Ejecutar exclusivamente en TransportesSegurosSA_Test si se desea restituir los textos previos.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50981, N'Este script de reversión solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;

UPDATE dbo.permisos
SET nombre = N'Consultar auditoría',
    descripcion = N'Consultar metadatos de auditoría'
WHERE codigo = N'AUDITORIA_VER';

UPDATE dbo.permisos
SET descripcion = NULL
WHERE codigo IN (
    N'CLIENTES_CREAR', N'DOCUMENTOS_REGISTRAR', N'ENTREGAS_REGISTRAR',
    N'INCIDENCIAS_REGISTRAR', N'INCIDENCIAS_RESOLVER', N'REPORTES_VER',
    N'SOLICITUDES_AJUSTAR_PRIORIDAD', N'SOLICITUDES_CANCELAR', N'SOLICITUDES_CREAR',
    N'USUARIOS_GESTIONAR_PERMISOS', N'VIAJES_ASIGNAR', N'VIAJES_CREAR',
    N'VIAJES_FINALIZAR', N'VIAJES_INICIAR', N'VIAJES_PARADAS'
);

UPDATE f
SET f.primer_apellido = N'Rodriguez'
FROM dbo.clientes_fisicos AS f
JOIN dbo.clientes AS c ON c.cliente_id = f.cliente_id
WHERE c.identificacion = N'DEMO-FIS-001' AND f.primer_apellido = N'Rodríguez';

UPDATE dbo.vehiculos
SET modelo = N'Camion principal'
WHERE placa = N'DEMO-G6-001' AND modelo = N'Camión principal';

UPDATE dbo.vehiculos
SET modelo = N'Camion pequeno'
WHERE placa = N'DEMO-G6-002' AND modelo = N'Camión pequeño';

COMMIT TRANSACTION;
GO
