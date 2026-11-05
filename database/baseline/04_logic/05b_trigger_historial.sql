USE TransportesSegurosSA;
GO


CREATE OR ALTER TRIGGER dbo.tr_historial_solicitudes
ON dbo.solicitudes
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.historial_estados_solicitud
    (
        solicitud_id, usuario_id, estado_anterior, estado_nuevo, observacion
    )
    SELECT
        i.solicitud_id,
        TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')),
        d.estado,
        i.estado,
        N'Cambio registrado automaticamente'
    FROM inserted i
    LEFT JOIN deleted d ON d.solicitud_id = i.solicitud_id
    WHERE d.solicitud_id IS NULL OR d.estado <> i.estado;
END;
GO
