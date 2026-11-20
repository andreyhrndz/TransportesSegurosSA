-- ============================================================================
-- Rollback no destructivo de Migración 14
-- Restaura los contratos previos sin eliminar datos ni columnas en dbo.documentos.
-- ============================================================================

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.sp_asignar_solicitud_con_paradas', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_asignar_solicitud_con_paradas;
GO

IF OBJECT_ID(N'dbo.sp_obtener_contexto_entrega', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_obtener_contexto_entrega;
GO

IF OBJECT_ID(N'dbo.sp_listar_entregas_pendientes', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_listar_entregas_pendientes;
GO

IF OBJECT_ID(N'dbo.sp_obtener_documento_archivo', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_obtener_documento_archivo;
GO

IF OBJECT_ID(N'dbo.sp_desasignar_solicitud_viaje', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_desasignar_solicitud_viaje;
GO

IF OBJECT_ID(N'dbo.sp_eliminar_parada_viaje', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_eliminar_parada_viaje;
GO

IF OBJECT_ID(N'dbo.sp_obtener_capacidad_viaje', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_obtener_capacidad_viaje;
GO

IF OBJECT_ID(N'dbo.sp_obtener_solicitud_360', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_obtener_solicitud_360;
GO

IF OBJECT_ID(N'dbo.sp_obtener_alertas_panel', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_obtener_alertas_panel;
GO

IF OBJECT_ID(N'dbo.sp_listar_pesos_solicitudes_disponibles', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_listar_pesos_solicitudes_disponibles;
GO

IF OBJECT_ID(N'dbo.sp_sugerir_viajes_para_solicitud', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_sugerir_viajes_para_solicitud;
GO

IF OBJECT_ID(N'dbo.sp_listar_respaldos_y_jobs', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_listar_respaldos_y_jobs;
GO

IF OBJECT_ID(N'dbo.sp_ejecutar_respaldo_operativo', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ejecutar_respaldo_operativo;
GO

IF OBJECT_ID(N'dbo.sp_resolver_ruta_desde_solicitud', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_resolver_ruta_desde_solicitud;
GO
