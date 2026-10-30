/*
  Script de corrección controlada de codificación UTF-8 y lenguaje de permisos.
  Ejecutar exclusivamente en TransportesSegurosSA_Test con codificación UTF-8 (-f 65001 o vía PDO_SQLSRV UTF-8).
  Precondición: conectado a TransportesSegurosSA_Test. Prohibido ejecutar en TransportesSegurosSA.
  Reversible mediante sql/test-environment/08_rollback_utf8_and_language_repair_test.sql.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50980, N'Este script de corrección solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;

-- 1. Estandarizar nombres y descripciones de permisos en español natural (UTF-8 limpio)
MERGE dbo.permisos AS target
USING (VALUES
    (N'AUDITORIA_VER', N'Consultar historial de actividad', N'Ver historial de actividad'),
    (N'CATALOGOS_GESTIONAR', N'Gestionar catálogos', N'Crear, actualizar y desactivar tipos de mercancía, incidencia y documento'),
    (N'CATALOGOS_VER', N'Consultar catálogos', N'Consultar tipos de mercancía, incidencia y documento'),
    (N'CLIENTES_CREAR', N'Registrar clientes', N'Registrar nuevos clientes físicos y empresas en el directorio'),
    (N'CLIENTES_DESACTIVAR', N'Desactivar clientes', N'Desactivar clientes preservando su historial de solicitudes'),
    (N'CLIENTES_EDITAR', N'Editar clientes', N'Actualizar datos de contacto e información del cliente'),
    (N'CLIENTES_VER', N'Consultar clientes', N'Consultar directorio y detalle de clientes'),
    (N'DOCUMENTOS_REGISTRAR', N'Registrar documentos', N'Vincular respaldos documentales a las solicitudes de transporte'),
    (N'DOCUMENTOS_VER', N'Consultar documentos', N'Consultar documentos asociados a solicitudes'),
    (N'ENTREGAS_REGISTRAR', N'Registrar entregas', N'Confirmar entregas en destino o reportar intentos fallidos'),
    (N'ENTREGAS_VER', N'Consultar entregas', N'Consultar entregas y receptor efectivo'),
    (N'FLOTAS_GESTIONAR', N'Gestionar flota', N'Crear, actualizar y desactivar vehículos y conductores'),
    (N'FLOTAS_VER', N'Consultar flota', N'Consultar vehículos y conductores'),
    (N'INCIDENCIAS_REGISTRAR', N'Registrar incidencias', N'Reportar eventos o novedades operativas durante el servicio'),
    (N'INCIDENCIAS_RESOLVER', N'Resolver incidencias', N'Documentar la atención y cierre de incidencias operativas'),
    (N'INCIDENCIAS_VER', N'Consultar incidencias', N'Consultar incidencias registradas'),
    (N'NOTIFICACIONES_VER', N'Consultar notificaciones', N'Consultar historial de envío de notificaciones'),
    (N'REPORTES_VER', N'Consultar reportes', N'Consultar indicadores operativos y reportes históricos'),
    (N'RUTAS_GESTIONAR', N'Gestionar rutas', N'Crear, actualizar y desactivar rutas logísticas'),
    (N'RUTAS_VER', N'Consultar rutas', N'Consultar rutas y ubicaciones relacionadas'),
    (N'SOLICITUDES_AJUSTAR_PRIORIDAD', N'Ajustar prioridad', N'Modificar la prioridad operativa de una solicitud activa'),
    (N'SOLICITUDES_CANCELAR', N'Cancelar solicitudes', N'Cancelar solicitudes de transporte antes de su entrega'),
    (N'SOLICITUDES_CREAR', N'Crear solicitudes', N'Registrar nuevas solicitudes de transporte y sus mercancías'),
    (N'SOLICITUDES_VER', N'Consultar solicitudes', N'Consultar solicitudes y mercancías asociadas'),
    (N'UBICACIONES_GESTIONAR', N'Gestionar ubicaciones', N'Crear, actualizar y desactivar ubicaciones logísticas'),
    (N'UBICACIONES_VER', N'Consultar ubicaciones', N'Consultar ubicaciones activas y su detalle'),
    (N'USUARIOS_GESTIONAR_CUENTAS', N'Gestionar cuentas de usuario', N'Crear, editar, activar o desactivar cuentas y restablecer contraseñas'),
    (N'USUARIOS_GESTIONAR_PERMISOS', N'Conceder y revocar permisos', N'Asignar o retirar permisos individuales a las cuentas de usuario'),
    (N'USUARIOS_VER', N'Consultar usuarios', N'Consultar cuentas de usuario y sus permisos asignados'),
    (N'VIAJES_ASIGNAR', N'Asignar solicitudes a viajes', N'Vincular solicitudes pendientes a un viaje planificado con sus paradas'),
    (N'VIAJES_CREAR', N'Crear viajes', N'Planificar nuevos viajes asignando vehículo, conductor y ruta'),
    (N'VIAJES_FINALIZAR', N'Finalizar viajes', N'Registrar la conclusión operativa de viajes en curso'),
    (N'VIAJES_INICIAR', N'Iniciar viajes', N'Registrar la salida en ruta de viajes planificados'),
    (N'VIAJES_PARADAS', N'Crear paradas de viaje', N'Agregar paradas intermedias de recogida, entrega o escala en un viaje'),
    (N'VIAJES_VER', N'Consultar viajes', N'Consultar viajes, paradas y asignaciones')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET
    nombre = source.nombre,
    descripcion = source.descripcion;

-- 2. Corregir acentuación en registros de demostración preexistentes de TransportesSegurosSA_Test
UPDATE f
SET f.primer_apellido = N'Rodríguez'
FROM dbo.clientes_fisicos AS f
JOIN dbo.clientes AS c ON c.cliente_id = f.cliente_id
WHERE c.identificacion = N'DEMO-FIS-001' AND f.primer_apellido = N'Rodriguez';

UPDATE dbo.vehiculos
SET modelo = N'Camión principal'
WHERE placa = N'DEMO-G6-001' AND modelo = N'Camion principal';

UPDATE dbo.vehiculos
SET modelo = N'Camión pequeño'
WHERE placa = N'DEMO-G6-002' AND modelo = N'Camion pequeno';

COMMIT TRANSACTION;
GO
