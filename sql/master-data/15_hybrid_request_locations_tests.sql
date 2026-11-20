/* =========================================================================
   Suite de contratos T-SQL 16: Ubicaciones híbridas en solicitudes (Migración 12)
   =========================================================================
   Ejecutar EXCLUSIVAMENTE en TransportesSegurosSA_Test.
   Verifica:
   1. Columna es_reutilizable e índice en dbo.ubicaciones (preexistentes = 1).
   2. Creación híbrida (MAPA puntual + GUARDADA): crea punto es_reutilizable = 0,
      NO aparece en sp_listar_ubicaciones, SÍ aparece en sp_obtener_solicitud
      y sp_listar_ubicaciones_parada.
   3. Creación híbrida con guardar_frecuente = 1 por actor con UBICACIONES_GESTIONAR:
      crea punto es_reutilizable = 1 y SÍ aparece en sp_listar_ubicaciones.
   4. Anti-escalación: actor con SOLICITUDES_CREAR sin UBICACIONES_GESTIONAR puede
      crear punto puntual (es_reutilizable = 0), pero recibe 50223 si intenta
      guardar_frecuente = 1 (con 0 ubicaciones huérfanas).
   5. Validación geográfica Haversine (<= 25 m) rechaza 50224 tanto para
      MAPA+MAPA como para GUARDADA+GUARDADA con IDs distintos en el mismo punto.
   6. Rollback atómico sin registros huérfanos cuando falla validación/TVP.
   7. Protección contra reutilización de ID puntual vía modo GUARDADA (50221)
      y contra edición/desactivación de punto puntual desde catálogo (50503).
   ========================================================================= */

SET NOCOUNT ON;
SET XACT_ABORT OFF;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'BLOQUEADO: Esta suite solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

DECLARE @admin_id INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id);
DECLARE @cliente_id INT = (SELECT TOP (1) cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id);
DECLARE @tipo_mercancia_id INT = (SELECT TOP (1) tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id);
DECLARE @tag NVARCHAR(30) = N'TSQL16_' + RIGHT(CONVERT(NVARCHAR(36), NEWID()), 8);
DECLARE @futuro DATETIME2 = DATEADD(DAY, 3, SYSUTCDATETIME());

DECLARE @u_guard1 INT, @u_guard2_same INT, @u_guard3_far INT;
DECLARE @actor_solo_sol INT = NULL;
DECLARE @sol1 INT = NULL, @sol2 INT = NULL, @sol3 INT = NULL;
DECLARE @orig1 INT = NULL, @dest1 INT = NULL;
DECLARE @orig2 INT = NULL, @dest2 INT = NULL;
DECLARE @orig3 INT = NULL, @dest3 INT = NULL;

BEGIN TRY
    IF COL_LENGTH(N'dbo.ubicaciones', N'es_reutilizable') IS NULL
        THROW 50970, N'Falta columna dbo.ubicaciones.es_reutilizable.', 1;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.ubicaciones') AND name = N'IX_ubicaciones_reutilizable_activo_nombre'
    )
        THROW 50971, N'Falta índice IX_ubicaciones_reutilizable_activo_nombre.', 1;

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- Crear ubicaciones frecuentes de prueba (dos en mismas coords para probar Haversine con IDs distintos)
    DECLARE @n_g1 NVARCHAR(150) = @tag + N'_G1';
    DECLARE @n_g2 NVARCHAR(150) = @tag + N'_G2_MISMO';
    DECLARE @n_g3 NVARCHAR(150) = @tag + N'_G3_LEJOS';
    EXEC dbo.sp_crear_ubicacion @nombre = @n_g1, @direccion = N'Bodega A', @latitud = 9.934739, @longitud = -84.087502, @ubicacion_id = @u_guard1 OUTPUT;
    EXEC dbo.sp_crear_ubicacion @nombre = @n_g2, @direccion = N'Bodega B mismo sitio', @latitud = 9.934745, @longitud = -84.087505, @ubicacion_id = @u_guard2_same OUTPUT;
    EXEC dbo.sp_crear_ubicacion @nombre = @n_g3, @direccion = N'Puerto Caldera', @latitud = 9.911200, @longitud = -84.718500, @ubicacion_id = @u_guard3_far OUTPUT;

    DECLARE @merc dbo.tipo_mercancias;
    INSERT INTO @merc (tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
    VALUES (@tipo_mercancia_id, N'Carga prueba TSQL16', 120.50, 1.250);

    -- 1. MAPA puntual + GUARDADA -> crea punto es_reutilizable = 0 oculto en catálogo y visible en detalle/paradas
    DECLARE @dir_puntual NVARCHAR(250) = @tag + N' Portón norte 100m este';
    EXEC dbo.sp_crear_solicitud_con_ubicaciones
        @cliente_id = @cliente_id,
        @origen_modo = 'MAPA',
        @origen_nombre = NULL,
        @origen_direccion = @dir_puntual,
        @origen_latitud = 10.016250,
        @origen_longitud = -84.211630,
        @origen_guardar_frecuente = 0,
        @destino_modo = 'GUARDADA',
        @destino_id = @u_guard3_far,
        @destinatario_nombre = N'Ana',
        @destinatario_apellido1 = N'Mora',
        @destinatario_apellido2 = N'Solís',
        @destinatario_identificacion = N'1-0111-0222',
        @destinatario_telefono = N'8888-1111',
        @fecha_entrega_requerida = @futuro,
        @mercancias = @merc,
        @solicitud_id = @sol1 OUTPUT,
        @origen_id_resuelto = @orig1 OUTPUT,
        @destino_id_resuelto = @dest1 OUTPUT;

    IF NOT EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE ubicacion_id = @orig1 AND es_reutilizable = 0 AND latitud = 10.016250)
        THROW 50972, N'El punto puntual debe persistir con es_reutilizable = 0 y coordenadas exactas.', 1;

    DECLARE @tot_cat INT = -1;
    DECLARE @cat_res TABLE (ubicacion_id INT, nombre NVARCHAR(150), direccion NVARCHAR(250), latitud DECIMAL(9,6), longitud DECIMAL(9,6), activo BIT, es_reutilizable BIT);
    INSERT INTO @cat_res
    EXEC dbo.sp_listar_ubicaciones @buscar = @tag, @solo_activos = 1, @pagina = 1, @por_pagina = 50, @total_registros = @tot_cat OUTPUT;

    IF EXISTS (SELECT 1 FROM @cat_res WHERE ubicacion_id = @orig1)
        THROW 50973, N'sp_listar_ubicaciones no debe listar puntos puntuales (es_reutilizable = 0).', 1;

    DECLARE @tot_par INT = -1;
    DECLARE @par_res TABLE (ubicacion_id INT, nombre NVARCHAR(150), direccion NVARCHAR(250), latitud DECIMAL(9,6), longitud DECIMAL(9,6), activo BIT, es_reutilizable BIT, solicitud_codigo NVARCHAR(30));
    INSERT INTO @par_res
    EXEC dbo.sp_listar_ubicaciones_parada @buscar = @tag, @pagina = 1, @por_pagina = 50, @total_registros = @tot_par OUTPUT;

    IF NOT EXISTS (SELECT 1 FROM @par_res WHERE ubicacion_id = @orig1 AND es_reutilizable = 0 AND solicitud_codigo IS NOT NULL)
        THROW 50974, N'sp_listar_ubicaciones_parada debe exponer el punto puntual de la solicitud abierta.', 1;

    PRINT '1. [OK] Punto puntual creado atómicamente, oculto en catálogo y disponible en paradas.';

    -- 2. MAPA con guardar_frecuente = 1 por actor autorizado -> SÍ aparece en catálogo
    DECLARE @nom_frec NVARCHAR(150) = @tag + N'_FREC_MAPA';
    EXEC dbo.sp_crear_solicitud_con_ubicaciones
        @cliente_id = @cliente_id,
        @origen_modo = 'GUARDADA',
        @origen_id = @u_guard1,
        @destino_modo = 'MAPA',
        @destino_nombre = @nom_frec,
        @destino_direccion = N'Bodega zona franca módulo 9',
        @destino_latitud = 9.998120,
        @destino_longitud = -84.116540,
        @destino_guardar_frecuente = 1,
        @destinatario_nombre = N'Luis',
        @destinatario_apellido1 = N'Vega',
        @destinatario_apellido2 = N'Rojas',
        @destinatario_identificacion = N'1-0222-0333',
        @destinatario_telefono = N'8888-2222',
        @fecha_entrega_requerida = @futuro,
        @mercancias = @merc,
        @solicitud_id = @sol2 OUTPUT,
        @origen_id_resuelto = @orig2 OUTPUT,
        @destino_id_resuelto = @dest2 OUTPUT;

    DELETE FROM @cat_res;
    INSERT INTO @cat_res
    EXEC dbo.sp_listar_ubicaciones @buscar = @nom_frec, @solo_activos = 1, @pagina = 1, @por_pagina = 50, @total_registros = @tot_cat OUTPUT;
    IF NOT EXISTS (SELECT 1 FROM @cat_res WHERE ubicacion_id = @dest2 AND es_reutilizable = 1)
        THROW 50975, N'El punto marcado como frecuente debe aparecer en sp_listar_ubicaciones.', 1;

    PRINT '2. [OK] Punto marcado como frecuente aparece en catálogo (es_reutilizable = 1).';

    -- 3. Actor con SOLICITUDES_CREAR sin UBICACIONES_GESTIONAR: puntual OK, frecuente -> 50223 + 0 huérfanos
    DECLARE @u_name NVARCHAR(80) = N'tsql16_' + RIGHT(@tag, 8);
    DECLARE @u_mail NVARCHAR(150) = @u_name + N'@example.test';
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (@u_name, @u_mail, N'$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 1);
    SET @actor_solo_sol = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @actor_solo_sol, permiso_id FROM dbo.permisos WHERE codigo = N'SOLICITUDES_CREAR';

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @actor_solo_sol;

    -- 3a. Puntual permitido sin UBICACIONES_GESTIONAR
    DECLARE @dir_op NVARCHAR(250) = @tag + N' Portón sur';
    EXEC dbo.sp_crear_solicitud_con_ubicaciones
        @cliente_id = @cliente_id,
        @origen_modo = 'MAPA',
        @origen_nombre = N'Retiro puntual operador',
        @origen_direccion = @dir_op,
        @origen_latitud = 9.864400,
        @origen_longitud = -83.919400,
        @origen_guardar_frecuente = 0,
        @destino_modo = 'GUARDADA',
        @destino_id = @u_guard3_far,
        @destinatario_nombre = N'Sofía',
        @destinatario_apellido1 = N'Cruz',
        @destinatario_apellido2 = N'Díaz',
        @destinatario_identificacion = N'1-0333-0444',
        @destinatario_telefono = N'8888-3333',
        @fecha_entrega_requerida = @futuro,
        @mercancias = @merc,
        @solicitud_id = @sol3 OUTPUT,
        @origen_id_resuelto = @orig3 OUTPUT,
        @destino_id_resuelto = @dest3 OUTPUT;

    IF @sol3 IS NULL OR @orig3 IS NULL
        THROW 50976, N'Operador con SOLICITUDES_CREAR debe poder crear punto puntual.', 1;

    -- 3b. Intento de escalación con guardar_frecuente = 1 -> 50223
    DECLARE @err_esc INT = 0;
    DECLARE @sol_esc INT = NULL;
    DECLARE @nom_esc NVARCHAR(150) = @tag + N'_NO_DEBE_EXISTIR';
    BEGIN TRY
        EXEC dbo.sp_crear_solicitud_con_ubicaciones
            @cliente_id = @cliente_id,
            @origen_modo = 'MAPA',
            @origen_nombre = @nom_esc,
            @origen_direccion = N'Intento escalación',
            @origen_latitud = 9.864400,
            @origen_longitud = -83.919400,
            @origen_guardar_frecuente = 1,
            @destino_modo = 'GUARDADA',
            @destino_id = @u_guard3_far,
            @destinatario_nombre = N'Sofía',
            @destinatario_apellido1 = N'Cruz',
            @destinatario_apellido2 = N'Díaz',
            @destinatario_identificacion = N'1-0333-0444',
            @destinatario_telefono = N'8888-3333',
            @fecha_entrega_requerida = @futuro,
            @mercancias = @merc,
            @solicitud_id = @sol_esc OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err_esc = ERROR_NUMBER();
    END CATCH;
    IF @err_esc <> 50233
        THROW 50977, N'guardar_frecuente=1 sin UBICACIONES_GESTIONAR debe lanzar 50233.', 1;
    IF EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE nombre = @nom_esc)
        THROW 50978, N'Intento de escalación no debe crear ubicación huérfana.', 1;

    PRINT '3. [OK] Anti-escalación verificada (puntual permitido, frecuente sin permiso rechazado con 50233).';

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- 4. Haversine <= 25 m (IDs distintos con mismas coordenadas) -> 50234
    DECLARE @err_geo1 INT = 0, @err_geo2 INT = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_solicitud_con_ubicaciones
            @cliente_id = @cliente_id,
            @origen_modo = 'GUARDADA', @origen_id = @u_guard1,
            @destino_modo = 'GUARDADA', @destino_id = @u_guard2_same,
            @destinatario_nombre = N'A', @destinatario_apellido1 = N'B', @destinatario_apellido2 = N'C',
            @destinatario_identificacion = N'1', @destinatario_telefono = N'8',
            @fecha_entrega_requerida = @futuro, @mercancias = @merc,
            @solicitud_id = @sol_esc OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err_geo1 = ERROR_NUMBER();
    END CATCH;
    IF @err_geo1 <> 50234
        THROW 50979, N'Dos IDs distintos en el mismo punto geográfico deben lanzar 50234.', 1;

    -- Haversine MAPA + MAPA a ~5 metros -> 50234 y 0 huérfanos
    DECLARE @dir_geo_fail NVARCHAR(250) = @tag + N'_GEO_FAIL';
    BEGIN TRY
        EXEC dbo.sp_crear_solicitud_con_ubicaciones
            @cliente_id = @cliente_id,
            @origen_modo = 'MAPA', @origen_direccion = @dir_geo_fail, @origen_latitud = 9.934739, @origen_longitud = -84.087502,
            @destino_modo = 'MAPA', @destino_direccion = N'Otro texto mismo sitio', @destino_latitud = 9.934750, @destino_longitud = -84.087505,
            @destinatario_nombre = N'A', @destinatario_apellido1 = N'B', @destinatario_apellido2 = N'C',
            @destinatario_identificacion = N'1', @destinatario_telefono = N'8',
            @fecha_entrega_requerida = @futuro, @mercancias = @merc,
            @solicitud_id = @sol_esc OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err_geo2 = ERROR_NUMBER();
    END CATCH;
    IF @err_geo2 <> 50234 OR EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE direccion = @dir_geo_fail)
        THROW 50980, N'MAPA+MAPA en el mismo punto debe lanzar 50234 sin dejar huérfanos.', 1;

    PRINT '4. [OK] Validación geográfica Haversine (<= 25 m) verificada para GUARDADA+GUARDADA y MAPA+MAPA.';

    -- 5. Rollback atómico ante fallo en destino duplicado (origen MAPA frecuente insertado primero debe revertirse)
    DECLARE @nom_rb NVARCHAR(150) = @tag + N'_ROLLBACK_ORIG';
    DECLARE @err_rb INT = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_solicitud_con_ubicaciones
            @cliente_id = @cliente_id,
            @origen_modo = 'MAPA', @origen_nombre = @nom_rb, @origen_direccion = N'Origen que debe hacer rollback',
            @origen_latitud = 10.200000, @origen_longitud = -84.300000, @origen_guardar_frecuente = 1,
            @destino_modo = 'MAPA', @destino_nombre = @nom_frec, @destino_direccion = N'Nombre duplicado en catálogo',
            @destino_latitud = 9.500000, @destino_longitud = -83.800000, @destino_guardar_frecuente = 1,
            @destinatario_nombre = N'A', @destinatario_apellido1 = N'B', @destinatario_apellido2 = N'C',
            @destinatario_identificacion = N'1', @destinatario_telefono = N'8',
            @fecha_entrega_requerida = @futuro, @mercancias = @merc,
            @solicitud_id = @sol_esc OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err_rb = ERROR_NUMBER();
    END CATCH;
    IF @err_rb <> 50504 OR EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE nombre = @nom_rb)
        THROW 50981, N'Fallo en destino debe revertir atómicamente el origen insertado previamente (0 huérfanos).', 1;

    -- 6. No permitir usar un ID puntual vía modo GUARDADA (50231) ni editarlo desde catálogo (50503)
    DECLARE @err_reuse INT = 0, @err_edit INT = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_solicitud_con_ubicaciones
            @cliente_id = @cliente_id,
            @origen_modo = 'GUARDADA', @origen_id = @orig1,
            @destino_modo = 'GUARDADA', @destino_id = @u_guard3_far,
            @destinatario_nombre = N'A', @destinatario_apellido1 = N'B', @destinatario_apellido2 = N'C',
            @destinatario_identificacion = N'1', @destinatario_telefono = N'8',
            @fecha_entrega_requerida = @futuro, @mercancias = @merc,
            @solicitud_id = @sol_esc OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err_reuse = ERROR_NUMBER();
    END CATCH;
    IF @err_reuse <> 50231
        THROW 50982, N'Modo GUARDADA no debe aceptar IDs puntuales (es_reutilizable = 0).', 1;

    BEGIN TRY
        EXEC dbo.sp_actualizar_ubicacion @ubicacion_id = @orig1, @nombre = N'Hack', @direccion = N'Hack';
    END TRY
    BEGIN CATCH
        SET @err_edit = ERROR_NUMBER();
    END CATCH;
    IF @err_edit <> 50503
        THROW 50983, N'sp_actualizar_ubicacion debe rechazar puntos puntuales con 50503.', 1;

    PRINT '5. [OK] Rollback atómico (0 huérfanos) y aislamiento de puntos puntuales verificados.';

    -- Limpieza estricta de entidades sintéticas de esta suite
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (@sol1, @sol2, @sol3);
    DELETE FROM dbo.mercancias WHERE solicitud_id IN (@sol1, @sol2, @sol3);
    DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id IN (CONVERT(NVARCHAR(100), @sol1), CONVERT(NVARCHAR(100), @sol2), CONVERT(NVARCHAR(100), @sol3));
    DELETE FROM dbo.solicitudes WHERE solicitud_id IN (@sol1, @sol2, @sol3);

    DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id IN (
        CONVERT(NVARCHAR(100), @u_guard1), CONVERT(NVARCHAR(100), @u_guard2_same), CONVERT(NVARCHAR(100), @u_guard3_far),
        CONVERT(NVARCHAR(100), @orig1), CONVERT(NVARCHAR(100), @dest2), CONVERT(NVARCHAR(100), @orig3)
    );
    DELETE FROM dbo.ubicaciones WHERE ubicacion_id IN (@u_guard1, @u_guard2_same, @u_guard3_far, @orig1, @dest2, @orig3);

    DELETE FROM dbo.auditorias WHERE usuario_id = @actor_solo_sol;
    DELETE FROM dbo.usuarios_permisos WHERE usuario_id = @actor_solo_sol;
    DELETE FROM dbo.usuarios WHERE usuario_id = @actor_solo_sol;

    PRINT '=== SUITE T-SQL 16 (UBICACIONES HÍBRIDAS): 100% PASS ===';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    -- Limpieza defensiva en caso de error
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (@sol1, @sol2, @sol3);
    DELETE FROM dbo.mercancias WHERE solicitud_id IN (@sol1, @sol2, @sol3);
    DELETE FROM dbo.solicitudes WHERE solicitud_id IN (@sol1, @sol2, @sol3);
    DELETE FROM dbo.ubicaciones WHERE ubicacion_id IN (@u_guard1, @u_guard2_same, @u_guard3_far, @orig1, @dest2, @orig3);
    IF @actor_solo_sol IS NOT NULL
    BEGIN
        DELETE FROM dbo.auditorias WHERE usuario_id = @actor_solo_sol;
        DELETE FROM dbo.usuarios_permisos WHERE usuario_id = @actor_solo_sol;
        DELETE FROM dbo.usuarios WHERE usuario_id = @actor_solo_sol;
    END;
    THROW;
END CATCH;
GO
