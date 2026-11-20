/* =========================================================================
   Transportes Seguros S.A. — SQL Server 2025
   Migración 11 (Aditiva e Idempotente — posterior a Migración 10):
   Paginación/búsqueda real SQL en invitaciones, reenvío con corrección atómica
   de correo sin bypass ni escalamiento, y lectura de coordenadas en solicitudes
   y paradas de viaje para enlaces externos de mapa.

   Alcance:
   1. dbo.sp_listar_invitaciones_paginadas: nuevo SP con búsqueda parcial por
      correo, filtro por estado efectivo UTC y paginación OFFSET/FETCH real.
      (Conserva dbo.sp_listar_invitaciones intacto para compatibilidad).
   2. dbo.sp_reenviar_invitacion_usuario: agrega @nuevo_correo NVARCHAR(150) = NULL
      con bloqueo transaccional sp_getapplock, prevención de duplicados/carreras,
      rate limit sin bypass por cambio de correo, preservación inmutable del
      registro histórico original, copia de permisos autorizados sin escalamiento
      y auditoría RESEND / RESEND_CORRECT_EMAIL.
   3. dbo.sp_obtener_solicitud: agrega columnas aditivas de dirección y
      coordenadas (latitud/longitud) de origen y destino.
   4. dbo.sp_obtener_viaje: agrega columnas aditivas de coordenadas
      (latitud/longitud) en el listado de paradas del viaje.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET NOCOUNT ON;
GO

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

-- -------------------------------------------------------------------------
-- 1. LISTADO PAGINADO Y FILTRADO REAL DE INVITACIONES EN SQL SERVER
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_invitaciones_paginadas
    @buscar NVARCHAR(120) = NULL,
    @estado VARCHAR(20) = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_VER') = 0
        THROW 50410, 'No tiene permiso para consultar usuarios.', 1;

    IF @pagina IS NULL OR @pagina < 1 OR @por_pagina IS NULL OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @estado = NULLIF(UPPER(LTRIM(RTRIM(@estado))), '');

    IF @estado IS NOT NULL AND @estado NOT IN ('PENDIENTE', 'EXPIRADA', 'REVOCADA', 'COMPLETADA', 'CANCELADA')
        THROW 50102, N'Estado de invitación no válido.', 1;

    DECLARE @buscar_like NVARCHAR(260) = NULL;
    IF @buscar IS NOT NULL
    BEGIN
        SET @buscar_like = LOWER(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(@buscar, N'\', N'\\'),
                        N'%', N'\%'
                    ),
                    N'_', N'\_'
                ),
                N'[', N'\['
            )
        );
    END;

    DECLARE @ahora DATETIME2 = SYSUTCDATETIME();
    DECLARE @offset BIGINT = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    ;WITH InvBase AS (
        SELECT
            i.invitacion_id,
            i.correo_destino,
            CASE
                WHEN i.estado = 'PENDIENTE' AND i.fecha_expiracion <= @ahora THEN 'EXPIRADA'
                ELSE i.estado
            END AS estado_efectivo,
            i.estado AS estado_original,
            i.fecha_expiracion,
            i.fecha_creacion,
            i.fecha_completada,
            i.ultimo_envio_en,
            i.envios_contador,
            i.estado_envio,
            i.creado_por_usuario_id,
            i.completado_usuario_id
        FROM dbo.invitaciones_usuario AS i
        WHERE (@buscar_like IS NULL OR LOWER(i.correo_destino) LIKE N'%' + @buscar_like + N'%' ESCAPE N'\')
    ),
    InvFiltradas AS (
        SELECT *
        FROM InvBase
        WHERE (@estado IS NULL OR estado_efectivo = @estado)
    )
    SELECT @total_registros = COUNT(*)
    FROM InvFiltradas;

    ;WITH InvBase AS (
        SELECT
            i.invitacion_id,
            i.correo_destino,
            CASE
                WHEN i.estado = 'PENDIENTE' AND i.fecha_expiracion <= @ahora THEN 'EXPIRADA'
                ELSE i.estado
            END AS estado_efectivo,
            i.estado AS estado_original,
            i.fecha_expiracion,
            i.fecha_creacion,
            i.fecha_completada,
            i.ultimo_envio_en,
            i.envios_contador,
            i.estado_envio,
            i.creado_por_usuario_id,
            i.completado_usuario_id
        FROM dbo.invitaciones_usuario AS i
        WHERE (@buscar_like IS NULL OR LOWER(i.correo_destino) LIKE N'%' + @buscar_like + N'%' ESCAPE N'\')
    ),
    InvFiltradas AS (
        SELECT *
        FROM InvBase
        WHERE (@estado IS NULL OR estado_efectivo = @estado)
    )
    SELECT
        f.invitacion_id,
        f.correo_destino,
        f.estado_efectivo AS estado,
        f.estado_original,
        f.fecha_expiracion,
        f.fecha_creacion,
        f.fecha_completada,
        f.ultimo_envio_en,
        f.envios_contador,
        f.estado_envio,
        u_crea.nombre_usuario AS creado_por_nombre,
        u_comp.nombre_usuario AS completado_por_nombre,
        ISNULL(perm_agg.total_permisos, 0) AS total_permisos,
        perm_agg.permisos_codigos
    FROM InvFiltradas AS f
    JOIN dbo.usuarios AS u_crea ON u_crea.usuario_id = f.creado_por_usuario_id
    LEFT JOIN dbo.usuarios AS u_comp ON u_comp.usuario_id = f.completado_usuario_id
    OUTER APPLY (
        SELECT
            COUNT(*) AS total_permisos,
            STRING_AGG(p.codigo, ', ') WITHIN GROUP (ORDER BY p.codigo) AS permisos_codigos
        FROM dbo.invitaciones_permisos AS ip
        JOIN dbo.permisos AS p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = f.invitacion_id
    ) AS perm_agg
    ORDER BY f.fecha_creacion DESC, f.invitacion_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 2. REENVIAR INVITACIÓN CON CONFIRMACIÓN O CORRECCIÓN ATÓMICA DE CORREO
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_reenviar_invitacion_usuario
    @invitacion_id INT,
    @nuevo_token_hash VARCHAR(64),
    @horas_vigencia INT = 24,
    @nueva_invitacion_id INT OUTPUT,
    @nuevo_correo NVARCHAR(150) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @invitacion_id IS NULL OR @invitacion_id < 1
        THROW 50437, 'La invitación original no fue encontrada.', 1;

    IF @nuevo_token_hash IS NULL OR LEN(@nuevo_token_hash) <> 64
        THROW 50435, 'El hash del nuevo token de invitación no es válido.', 1;

    DECLARE @correo_param NVARCHAR(150) = NULLIF(LOWER(LTRIM(RTRIM(@nuevo_correo))), N'');
    IF @correo_param IS NOT NULL
       AND (
           @correo_param NOT LIKE N'%_@_%._%'
           OR @correo_param LIKE N'% %'
           OR LEN(@correo_param) > 150
       )
        THROW 50413, 'El correo electrónico proporcionado no tiene un formato válido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_reenv_inv_save;

    BEGIN TRY
        DECLARE @lock_res INT;
        DECLARE @lock_inv NVARCHAR(255) = CONCAT(N'tssa_inv_id_', @invitacion_id);
        EXEC @lock_res = sys.sp_getapplock
            @Resource = @lock_inv,
            @LockMode = 'Exclusive',
            @LockOwner = 'Transaction',
            @LockTimeout = 5000;
        IF @lock_res < 0
            THROW 50443, 'Otra operación sobre esta invitación está en curso. Inténtalo nuevamente.', 1;

        DECLARE @correo_origen NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;
        DECLARE @ultimo_envio DATETIME2;
        DECLARE @envios INT;

        SELECT
            @correo_origen = LOWER(LTRIM(RTRIM(correo_destino))),
            @estado = estado,
            @expiracion = fecha_expiracion,
            @ultimo_envio = ultimo_envio_en,
            @envios = envios_contador
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo_origen IS NULL
            THROW 50437, 'La invitación original no fue encontrada.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser reenviada.', 1;

        DECLARE @correo_objetivo NVARCHAR(150) = COALESCE(@correo_param, @correo_origen);
        DECLARE @cambio_correo BIT = CASE WHEN @correo_objetivo <> @correo_origen THEN 1 ELSE 0 END;

        -- Adquirir locks por correo en orden determinístico para serializar reenvíos concurrentes sin deadlocks
        DECLARE @mail_a NVARCHAR(150) = CASE WHEN @correo_origen <= @correo_objetivo THEN @correo_origen ELSE @correo_objetivo END;
        DECLARE @mail_b NVARCHAR(150) = CASE WHEN @correo_origen <= @correo_objetivo THEN @correo_objetivo ELSE @correo_origen END;
        DECLARE @lock_mail_a NVARCHAR(255) = CONCAT(N'tssa_inv_mail_', @mail_a);
        EXEC @lock_res = sys.sp_getapplock
            @Resource = @lock_mail_a,
            @LockMode = 'Exclusive',
            @LockOwner = 'Transaction',
            @LockTimeout = 5000;
        IF @lock_res < 0
            THROW 50443, 'Otra operación sobre este correo está en curso. Inténtalo nuevamente.', 1;

        IF @mail_b <> @mail_a
        BEGIN
            DECLARE @lock_mail_b NVARCHAR(255) = CONCAT(N'tssa_inv_mail_', @mail_b);
            EXEC @lock_res = sys.sp_getapplock
                @Resource = @lock_mail_b,
                @LockMode = 'Exclusive',
                @LockOwner = 'Transaction',
                @LockTimeout = 5000;
            IF @lock_res < 0
                THROW 50443, 'Otra operación sobre este correo está en curso. Inténtalo nuevamente.', 1;
        END;

        -- Re-verificar que el destino no pertenece a una cuenta activa
        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_acceso) = @correo_objetivo AND activo = 1
        )
            THROW 50430, 'El correo ya pertenece a una cuenta activa.', 1;

        DECLARE @ahora DATETIME2 = SYSUTCDATETIME();

        -- Impedir duplicación de invitaciones vigentes simultáneas (incluye carrera concurrente y corrección de correo)
        IF EXISTS (
            SELECT 1 FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_destino) = @correo_objetivo
              AND estado = 'PENDIENTE'
              AND fecha_expiracion > @ahora
              AND invitacion_id <> @invitacion_id
        )
            THROW 50431, 'Ya existe una invitación pendiente vigente para este correo electrónico.', 1;

        -- Límite de 5 envíos / 24h en correo destino y, si se corrige, también en correo origen (sin bypass)
        DECLARE @envios_24h_destino INT = 0;
        SELECT @envios_24h_destino = COUNT(*)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = @correo_objetivo
          AND fecha_creacion >= DATEADD(HOUR, -24, @ahora);

        IF @envios_24h_destino >= 5
            THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 1;

        IF @cambio_correo = 1
        BEGIN
            DECLARE @envios_24h_origen INT = 0;
            SELECT @envios_24h_origen = COUNT(*)
            FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_destino) = @correo_origen
              AND fecha_creacion >= DATEADD(HOUR, -24, @ahora);

            IF @envios_24h_origen >= 5
                THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones en 24 horas para esta invitación.', 1;
        END;

        -- Cooldown de 60 segundos tanto en la invitación origen como en el correo destino
        DECLARE @ultimo_envio_destino DATETIME2 = NULL;
        SELECT @ultimo_envio_destino = MAX(ultimo_envio_en)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = @correo_objetivo;

        IF (@ultimo_envio IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio, @ahora) < 60)
           OR (@ultimo_envio_destino IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio_destino, @ahora) < 60)
            THROW 50440, 'Debes esperar al menos 60 segundos antes de reenviar una invitación.', 1;

        -- Invalidar el enlace previo si seguía vigente, preservando intacto su correo original histórico
        IF @estado = 'PENDIENTE' AND @expiracion > @ahora
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'REVOCADA'
            WHERE invitacion_id = @invitacion_id;
        END
        ELSE IF @estado = 'PENDIENTE' AND @expiracion <= @ahora
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'EXPIRADA'
            WHERE invitacion_id = @invitacion_id;
        END;

        INSERT INTO dbo.invitaciones_usuario
        (
            correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id,
            fecha_creacion, ultimo_envio_en, envios_contador, estado_envio
        )
        VALUES
        (
            @correo_objetivo,
            @nuevo_token_hash,
            'PENDIENTE',
            DATEADD(HOUR, ISNULL(@horas_vigencia, 24), @ahora),
            @actor_id,
            @ahora,
            @ahora,
            CASE WHEN @cambio_correo = 1 THEN 1 ELSE ISNULL(@envios, 0) + 1 END,
            'PENDIENTE'
        );

        SET @nueva_invitacion_id = CONVERT(INT, SCOPE_IDENTITY());

        -- Copiar únicamente permisos planificados activos que el actor actual puede conceder (sin escalamiento)
        IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_PERMISOS') = 1
        BEGIN
            INSERT INTO dbo.invitaciones_permisos (invitacion_id, permiso_id, creado_en)
            SELECT @nueva_invitacion_id, ip.permiso_id, @ahora
            FROM dbo.invitaciones_permisos AS ip
            JOIN dbo.permisos AS p ON p.permiso_id = ip.permiso_id
            WHERE ip.invitacion_id = @invitacion_id
              AND p.activo = 1
              AND dbo.fn_tiene_permiso(@actor_id, p.codigo) = 1;
        END;

        IF @cambio_correo = 1
        BEGIN
            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @actor_id,
                N'invitaciones_usuario',
                CONVERT(NVARCHAR(100), @nueva_invitacion_id),
                'RESEND_CORRECT_EMAIL',
                CONCAT(N'Invitación previa ID ', @invitacion_id, N' (', @estado, N') correo original: ', @correo_origen),
                CONCAT(N'Nueva invitación por corrección de destinatario a correo: ', @correo_objetivo)
            );
        END
        ELSE
        BEGIN
            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @actor_id,
                N'invitaciones_usuario',
                CONVERT(NVARCHAR(100), @nueva_invitacion_id),
                'RESEND',
                CONCAT(N'Invitación previa ID ', @invitacion_id, N' (', @estado, N')'),
                CONCAT(N'Invitación reenviada a correo: ', @correo_objetivo)
            );
        END;

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_reenv_inv_save;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 3. EXPONER COORDENADAS ADITIVAS DE ORIGEN/DESTINO EN sp_obtener_solicitud
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_obtener_solicitud
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar solicitudes.', 1;
    IF @solicitud_id IS NULL OR @solicitud_id < 1
        THROW 50102, N'Identificador de solicitud inválido.', 1;

    SELECT
        s.solicitud_id, s.codigo_solicitud, s.estado, s.prioridad_calculada, s.prioridad_actual,
        s.justificacion_prioridad, s.fecha_solicitud, s.fecha_entrega_requerida,
        s.destinatario_nombre, s.destinatario_apellido1, s.destinatario_apellido2,
        s.destinatario_identificacion, s.destinatario_telefono, s.instrucciones_entrega, s.observaciones,
        s.cliente_id, s.origen_id, origen.nombre AS origen_nombre, s.destino_id, destino.nombre AS destino_nombre,
        vs.viaje_solicitud_id, vs.viaje_id, v.codigo_viaje, vs.estado AS estado_asignacion,
        origen.direccion AS origen_direccion, origen.latitud AS origen_latitud, origen.longitud AS origen_longitud,
        destino.direccion AS destino_direccion, destino.latitud AS destino_latitud, destino.longitud AS destino_longitud
    FROM dbo.solicitudes AS s
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE s.solicitud_id = @solicitud_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Solicitud no encontrada.', 1;

    SELECT m.mercancia_id, m.tipo_mercancia_id, tm.nombre AS tipo_mercancia, m.descripcion, m.peso_kg, m.volumen_m3, m.observaciones
    FROM dbo.mercancias AS m
    JOIN dbo.tipos_mercancia AS tm ON tm.tipo_mercancia_id = m.tipo_mercancia_id
    WHERE m.solicitud_id = @solicitud_id
    ORDER BY m.mercancia_id;

    SELECT h.historial_estado_id, h.estado_anterior, h.estado_nuevo, h.fecha_cambio, h.observacion,
           h.usuario_id, u.nombre_usuario
    FROM dbo.historial_estados_solicitud AS h
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = h.usuario_id
    WHERE h.solicitud_id = @solicitud_id
    ORDER BY h.fecha_cambio, h.historial_estado_id;
END;
GO

-- -------------------------------------------------------------------------
-- 4. EXPONER COORDENADAS ADITIVAS DE PARADAS EN sp_obtener_viaje
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_obtener_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar viajes.', 1;
    IF @viaje_id IS NULL OR @viaje_id < 1
        THROW 50102, N'Identificador de viaje inválido.', 1;

    SELECT
        v.viaje_id, v.codigo_viaje, v.estado, v.fecha_programada_salida, v.fecha_salida_real,
        v.fecha_llegada_real, v.observaciones, ve.vehiculo_id, ve.placa,
        c.conductor_id, CONCAT(c.nombre, N' ', c.primer_apellido) AS conductor_nombre,
        r.ruta_id, r.nombre AS ruta_nombre
    FROM dbo.viajes AS v
    JOIN dbo.vehiculos AS ve ON ve.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas AS r ON r.ruta_id = v.ruta_id
    WHERE v.viaje_id = @viaje_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Viaje no encontrado.', 1;

    SELECT
        p.parada_id, p.orden, p.tipo_parada, p.estado, p.llegada_programada, p.llegada_real,
        p.salida_real, p.observaciones, u.ubicacion_id, u.nombre AS ubicacion_nombre, u.direccion,
        u.latitud, u.longitud
    FROM dbo.paradas_viaje AS p
    JOIN dbo.ubicaciones AS u ON u.ubicacion_id = p.ubicacion_id
    WHERE p.viaje_id = @viaje_id
    ORDER BY p.orden;

    SELECT
        vs.viaje_solicitud_id, vs.solicitud_id, s.codigo_solicitud,
        s.estado AS estado_solicitud, vs.estado AS estado_asignacion,
        vs.parada_recogida_id, vs.parada_entrega_id
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE vs.viaje_id = @viaje_id
    ORDER BY vs.viaje_solicitud_id;
END;
GO
