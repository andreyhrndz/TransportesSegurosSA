USE TransportesSegurosSA;
GO

-- Auditar cada alta y modificacion de solicitudes.
CREATE OR ALTER TRIGGER dbo.tr_auditar_solicitudes
ON dbo.solicitudes
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.auditorias
    (
        usuario_id, entidad, registro_id, accion,
        valores_anteriores, valores_nuevos
    )
    SELECT
        TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')),
        N'solicitudes',
        CONVERT(NVARCHAR(100), i.solicitud_id),
        CASE WHEN d.solicitud_id IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
        CASE WHEN d.solicitud_id IS NULL THEN NULL ELSE N'Estado: ' + d.estado END,
        N'Estado: ' + i.estado
    FROM inserted i
    LEFT JOIN deleted d ON d.solicitud_id = i.solicitud_id;
END;
GO

-- Auditar cada alta y modificacion de viajes.
CREATE OR ALTER TRIGGER dbo.tr_auditar_viajes
ON dbo.viajes
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.auditorias
    (
        usuario_id, entidad, registro_id, accion,
        valores_anteriores, valores_nuevos
    )
    SELECT
        TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')),
        N'viajes',
        CONVERT(NVARCHAR(100), i.viaje_id),
        CASE WHEN d.viaje_id IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
        CASE WHEN d.viaje_id IS NULL THEN NULL ELSE N'Estado: ' + d.estado END,
        N'Estado: ' + i.estado
    FROM inserted i
    LEFT JOIN deleted d ON d.viaje_id = i.viaje_id;
END;
GO

-- Auditar cada alta y modificacion de viaje_solicitudes.
CREATE OR ALTER TRIGGER dbo.tr_auditar_viaje_solicitudes
ON dbo.viaje_solicitudes
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.auditorias
    (
        usuario_id, entidad, registro_id, accion,
        valores_anteriores, valores_nuevos
    )
    SELECT
        TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')),
        N'viaje_solicitudes',
        CONVERT(NVARCHAR(100), i.viaje_solicitud_id),
        CASE WHEN d.viaje_solicitud_id IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
        CASE WHEN d.viaje_solicitud_id IS NULL THEN NULL ELSE N'Estado: ' + d.estado END,
        N'Estado: ' + i.estado
    FROM inserted i
    LEFT JOIN deleted d ON d.viaje_solicitud_id = i.viaje_solicitud_id;
END;
GO

-- Auditar cada alta y modificacion de entregas.
CREATE OR ALTER TRIGGER dbo.tr_auditar_entregas
ON dbo.entregas
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.auditorias
    (
        usuario_id, entidad, registro_id, accion,
        valores_anteriores, valores_nuevos
    )
    SELECT
        TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')),
        N'entregas',
        CONVERT(NVARCHAR(100), i.entrega_id),
        CASE WHEN d.entrega_id IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
        CASE WHEN d.entrega_id IS NULL THEN NULL ELSE N'Estado: ' + d.estado END,
        N'Estado: ' + i.estado
    FROM inserted i
    LEFT JOIN deleted d ON d.entrega_id = i.entrega_id;
END;
GO
