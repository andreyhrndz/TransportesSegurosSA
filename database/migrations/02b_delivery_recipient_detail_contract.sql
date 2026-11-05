/*
  Propuesta pendiente de aprobación y despliegue: detalle de una entrega.
  No ejecutar fuera de TransportesSegurosSA_Test ni tratar este archivo como
  contrato disponible hasta su revisión SQL y despliegue controlado.
*/
-- Contexto establecido por conexion cliente

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_entrega @entrega_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar entregas.', 1;
    IF @entrega_id IS NULL OR @entrega_id < 1
        THROW 50102, 'Identificador de entrega inválido.', 1;

    SELECT
        e.entrega_id, e.estado AS resultado_terminal, e.fecha_resultado,
        e.receptor_nombre, e.receptor_identificacion, e.receptor_telefono,
        e.fecha_recepcion, e.comprobante_url, e.motivo_fallo, e.observaciones,
        vs.viaje_solicitud_id, vs.viaje_id,
        s.solicitud_id, s.codigo_solicitud,
        s.destinatario_nombre AS destinatario_esperado_nombre,
        s.destinatario_apellido1 AS destinatario_esperado_apellido1,
        s.destinatario_apellido2 AS destinatario_esperado_apellido2,
        s.destinatario_identificacion AS destinatario_esperado_identificacion,
        s.destinatario_telefono AS destinatario_esperado_telefono
    FROM dbo.entregas AS e
    JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE e.entrega_id = @entrega_id;

    IF @@ROWCOUNT = 0
        THROW 50101, 'Entrega no encontrada.', 1;
END;
GO
