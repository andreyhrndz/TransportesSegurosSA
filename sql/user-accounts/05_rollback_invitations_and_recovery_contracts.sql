/*
    Transportes Seguros S.A.
    Rollback de contratos de invitaciones y recuperación.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

IF OBJECT_ID('dbo.sp_completar_recuperacion_password', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_completar_recuperacion_password;
GO
IF OBJECT_ID('dbo.sp_validar_token_recuperacion', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_validar_token_recuperacion;
GO
IF OBJECT_ID('dbo.sp_solicitar_recuperacion_password', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_solicitar_recuperacion_password;
GO
IF OBJECT_ID('dbo.sp_listar_invitaciones', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_listar_invitaciones;
GO
IF OBJECT_ID('dbo.sp_actualizar_estado_envio_invitacion', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_actualizar_estado_envio_invitacion;
GO
IF OBJECT_ID('dbo.sp_revocar_invitacion_usuario', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_revocar_invitacion_usuario;
GO
IF OBJECT_ID('dbo.sp_reenviar_invitacion_usuario', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_reenviar_invitacion_usuario;
GO
IF OBJECT_ID('dbo.sp_cancelar_invitacion_usuario', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cancelar_invitacion_usuario;
GO
IF OBJECT_ID('dbo.sp_completar_invitacion_usuario', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_completar_invitacion_usuario;
GO
IF OBJECT_ID('dbo.sp_validar_token_invitacion', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_validar_token_invitacion;
GO
IF OBJECT_ID('dbo.sp_crear_invitacion_usuario', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_crear_invitacion_usuario;
GO

IF OBJECT_ID('dbo.tokens_recuperacion', 'U') IS NOT NULL
    DROP TABLE dbo.tokens_recuperacion;
GO

IF OBJECT_ID('dbo.invitaciones_usuario', 'U') IS NOT NULL
    DROP TABLE dbo.invitaciones_usuario;
GO

IF OBJECT_ID('dbo.usuarios_perfil', 'U') IS NOT NULL
    DROP TABLE dbo.usuarios_perfil;
GO
