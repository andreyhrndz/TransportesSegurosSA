/* =========================================================================
   Transportes Seguros S.A. — Rollback Migración 10
   Revierte el procedimiento nuevo sp_actualizar_licencia_principal_conductor.
   Para restaurar las versiones previas de fn_conductor_habilitado_vehiculo,
   sp_crear_conductor, sp_crear_vehiculo, sp_actualizar_vehiculo,
   sp_revocar_invitacion_usuario y sp_reenviar_invitacion_usuario, re-ejecute
   las migraciones 07, 08 y 09 según corresponda. No elimina tablas ni datos.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET NOCOUNT ON;
GO

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este rollback solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

IF OBJECT_ID(N'dbo.sp_actualizar_licencia_principal_conductor', N'P') IS NOT NULL
BEGIN
    DROP PROCEDURE dbo.sp_actualizar_licencia_principal_conductor;
END;
GO
