/* =========================================================================
   Transportes Seguros S.A. — Rollback de Contratos de Licencias y PMA
   ========================================================================= */

SET NOCOUNT ON;
GO

-- 1. Eliminar procedimientos de licencias
IF OBJECT_ID(N'dbo.sp_asignar_licencia_conductor', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_asignar_licencia_conductor;
GO

IF OBJECT_ID(N'dbo.sp_remover_licencia_conductor', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_remover_licencia_conductor;
GO

IF OBJECT_ID(N'dbo.sp_listar_licencias_conductor', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_listar_licencias_conductor;
GO

-- 2. Eliminar función de compatibilidad
IF OBJECT_ID(N'dbo.fn_conductor_habilitado_vehiculo', N'IF') IS NOT NULL
    DROP FUNCTION dbo.fn_conductor_habilitado_vehiculo;
GO

-- 3. Eliminar tabla de licencias de conductor
IF OBJECT_ID(N'dbo.conductores_licencias', N'U') IS NOT NULL
    DROP TABLE dbo.conductores_licencias;
GO

-- 4. Eliminar catálogo de licencias
IF OBJECT_ID(N'dbo.cat_licencias', N'U') IS NOT NULL
    DROP TABLE dbo.cat_licencias;
GO

-- 5. Eliminar columnas y restricciones agregadas a dbo.vehiculos
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_vehiculos_clase_legal')
    ALTER TABLE dbo.vehiculos DROP CONSTRAINT CK_vehiculos_clase_legal;
GO

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_vehiculos_estado_homologacion')
    ALTER TABLE dbo.vehiculos DROP CONSTRAINT CK_vehiculos_estado_homologacion;
GO

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_vehiculos_articulado')
    ALTER TABLE dbo.vehiculos DROP CONSTRAINT DF_vehiculos_articulado;
GO

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_vehiculos_homologacion')
    ALTER TABLE dbo.vehiculos DROP CONSTRAINT DF_vehiculos_homologacion;
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'pma_kg')
    ALTER TABLE dbo.vehiculos DROP COLUMN pma_kg;
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'clase_legal')
    ALTER TABLE dbo.vehiculos DROP COLUMN clase_legal;
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'es_articulado')
    ALTER TABLE dbo.vehiculos DROP COLUMN es_articulado;
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'estado_homologacion')
    ALTER TABLE dbo.vehiculos DROP COLUMN estado_homologacion;
GO
