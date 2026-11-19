/* =========================================================================
   Transportes Seguros S.A. — SQL Server 2025
   Migración 10 (Aditiva e Idempotente):
   Cierre YAGNI — Licencias únicas sin B4 por defecto, coherencia PMA en vehículos,
   preservación de kilometraje y homologación, y revocación/cancelación de invitaciones.

   Alcance:
   1. dbo.fn_conductor_habilitado_vehiculo: exige categoría activa en catálogo y
      diagnostica explícitamente categorías no habilitantes de carga (A/C/D).
   2. dbo.sp_crear_conductor: elimina default implícito 'B4' (@codigo_licencia_inicial = NULL)
      y valida contra dbo.cat_licencias cuando se suministra.
   3. dbo.sp_actualizar_licencia_principal_conductor: nuevo contrato atómico que reemplaza
      categorías previas (impidiendo habilitación por filas ocultas), sincroniza
      dbo.conductores, bloquea cambios con viaje activo (50505) y registra auditoría completa.
   4. dbo.sp_crear_vehiculo y dbo.sp_actualizar_vehiculo: valida carga útil <= PMA y
      coherencia PMA / clase_legal / es_articulado; en actualización preserva kilometraje
      y estado_homologacion (incluido NO_CONFORME) cuando se omiten (NULL).
   5. dbo.sp_revocar_invitacion_usuario y dbo.sp_reenviar_invitacion_usuario:
      distingue revocación de invitación vigente (REVOCADA) de cancelación/descarte de
      invitación expirada (CANCELADA) preservando historial y auditoría.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET NOCOUNT ON;
GO

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

-- -------------------------------------------------------------------------
-- 1. FUNCIÓN AUTORITATIVA DE COMPATIBILIDAD CONDUCTOR-VEHÍCULO
-- -------------------------------------------------------------------------
CREATE OR ALTER FUNCTION dbo.fn_conductor_habilitado_vehiculo
(
    @conductor_id INT,
    @vehiculo_id INT,
    @fecha_salida DATE
)
RETURNS TABLE
AS
RETURN
(
    SELECT
        CASE
            WHEN v.vehiculo_id IS NULL THEN 0
            WHEN v.activo = 0 THEN 0
            WHEN v.pma_kg IS NULL OR v.pma_kg <= 0 THEN 0
            WHEN v.estado_homologacion = 'NO_CONFORME' THEN 0
            WHEN c.conductor_id IS NULL THEN 0
            WHEN c.activo = 0 THEN 0
            WHEN EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND (
                      (v.es_articulado = 1 AND cat.permite_articulado = 1)
                      OR
                      (v.es_articulado = 0 AND (
                          (v.pma_kg > 8000.00 AND cat.codigo IN ('B3', 'B4', 'E1', 'E2'))
                          OR
                          (v.pma_kg > 4000.00 AND v.pma_kg <= 8000.00 AND cat.codigo IN ('B2', 'B3', 'B4', 'E1', 'E2'))
                          OR
                          (v.pma_kg <= 4000.00 AND cat.codigo IN ('B1', 'B2', 'B3', 'B4', 'E1', 'E2'))
                      ))
                  )
            ) THEN 1
            ELSE 0
        END AS es_habilitado,
        CASE
            WHEN v.vehiculo_id IS NULL THEN N'El vehículo seleccionado no existe.'
            WHEN v.activo = 0 THEN N'El vehículo seleccionado se encuentra inactivo.'
            WHEN v.pma_kg IS NULL OR v.pma_kg <= 0 THEN N'Falta definir el peso bruto autorizado (PMA) de la unidad seleccionada.'
            WHEN v.estado_homologacion = 'NO_CONFORME' THEN N'La unidad vehicular está marcada como no conforme para operaciones.'
            WHEN c.conductor_id IS NULL THEN N'El conductor seleccionado no existe.'
            WHEN c.activo = 0 THEN N'El conductor seleccionado se encuentra inactivo.'
            WHEN NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
            ) THEN N'El conductor no posee una categoría de licencia habilitada registrada.'
            WHEN NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
            ) THEN N'La licencia del conductor vence antes de la salida programada del viaje.'
            WHEN v.es_articulado = 1 AND NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cat.permite_articulado = 1
            ) THEN N'La unidad es pesada articulada y requiere licencia clase B-4 (o E-2).'
            WHEN v.es_articulado = 0 AND v.pma_kg > 8000.00 AND NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B3', 'B4', 'E1', 'E2')
            ) THEN N'El vehículo supera 8.000 kg de peso bruto (PMA) y requiere al menos licencia clase B-3.'
            WHEN v.es_articulado = 0 AND v.pma_kg > 4000.00 AND NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B2', 'B3', 'B4', 'E1', 'E2')
            ) THEN N'El vehículo supera 4.000 kg de peso bruto (PMA); la licencia clase B-1 es insuficiente (requiere clase B-2 o superior).'
            WHEN v.es_articulado = 0 AND NOT EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia AND cat.activo = 1
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B1', 'B2', 'B3', 'B4', 'E1', 'E2')
            ) THEN N'La categoría de licencia del conductor no habilita vehículos de carga (requiere clase B o E).'
            ELSE N'Conductor habilitado y compatible.'
        END AS motivo_diagnostico
    FROM (SELECT @conductor_id AS q_cond_id, @vehiculo_id AS q_veh_id) q
    LEFT JOIN dbo.conductores c ON c.conductor_id = q.q_cond_id
    LEFT JOIN dbo.vehiculos v ON v.vehiculo_id = q.q_veh_id
);
GO

-- -------------------------------------------------------------------------
-- 2. CREAR CONDUCTOR SIN CATEGORÍA B4 POR DEFECTO
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_crear_conductor
    @identificacion NVARCHAR(30),
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100) = NULL,
    @telefono NVARCHAR(30) = NULL,
    @correo NVARCHAR(150) = NULL,
    @numero_licencia NVARCHAR(50),
    @fecha_vencimiento_licencia DATE,
    @estado_operativo VARCHAR(20) = 'DISPONIBLE',
    @usuario_id INT = NULL,
    @codigo_licencia_inicial VARCHAR(10) = NULL,
    @conductor_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @identificacion = NULLIF(LTRIM(RTRIM(@identificacion)), N'');
    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @primer_apellido = NULLIF(LTRIM(RTRIM(@primer_apellido)), N'');
    SET @segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');
    SET @correo = NULLIF(LTRIM(RTRIM(@correo)), N'');
    SET @numero_licencia = UPPER(NULLIF(LTRIM(RTRIM(@numero_licencia)), N''));
    SET @estado_operativo = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_operativo)), ''), 'DISPONIBLE'));
    SET @codigo_licencia_inicial = UPPER(NULLIF(LTRIM(RTRIM(@codigo_licencia_inicial)), ''));

    IF @identificacion IS NULL
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
       OR @numero_licencia IS NULL
       OR @fecha_vencimiento_licencia IS NULL
       OR @fecha_vencimiento_licencia < CONVERT(DATE, SYSUTCDATETIME())
       OR (@correo IS NOT NULL AND (@correo NOT LIKE N'%_@_%._%' OR @correo LIKE N'% %'))
       OR @estado_operativo NOT IN ('DISPONIBLE', 'INACTIVO')
        THROW 50502, 'Datos del conductor, licencia o estado operativo inválidos.', 1;

    IF @codigo_licencia_inicial IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.cat_licencias WHERE codigo = @codigo_licencia_inicial AND activo = 1)
        THROW 50502, 'La categoría de licencia inicial no es válida o está inactiva.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_cond_save;

    BEGIN TRY
        IF @usuario_id IS NOT NULL
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.usuarios WHERE usuario_id = @usuario_id AND activo = 1)
                THROW 50507, 'El usuario asociado no existe o está inactivo.', 1;
        END;

        IF EXISTS (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE identificacion = @identificacion
               OR numero_licencia = @numero_licencia
               OR (@usuario_id IS NOT NULL AND usuario_id = @usuario_id)
        )
            THROW 50504, 'Ya existe un conductor con esa identificación, número de licencia o cuenta de usuario.', 1;

        INSERT INTO dbo.conductores (
            usuario_id, identificacion, nombre, primer_apellido, segundo_apellido,
            telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo
        )
        VALUES (
            @usuario_id, @identificacion, @nombre, @primer_apellido, @segundo_apellido,
            @telefono, @correo, @numero_licencia, @fecha_vencimiento_licencia, @estado_operativo, 1
        );

        SET @conductor_id = CONVERT(INT, SCOPE_IDENTITY());

        IF @codigo_licencia_inicial IS NOT NULL
        BEGIN
            INSERT INTO dbo.conductores_licencias
            (
                conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento,
                estado_verificacion, fecha_verificacion, usuario_verificador_id, observaciones
            )
            VALUES
            (
                @conductor_id, @codigo_licencia_inicial, @numero_licencia, @fecha_vencimiento_licencia,
                'VERIFICADA', SYSUTCDATETIME(), @actor_id, N'Declaración administrativa de revisión de documento físico (alta)'
            );

            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @actor_id, N'conductores_licencias', CONCAT(@conductor_id, N':', @codigo_licencia_inicial), 'INSERT',
                NULL, CONCAT(N'Cat: ', @codigo_licencia_inicial, N'; Lic: ', @numero_licencia, N'; Vence: ', CONVERT(VARCHAR(10), @fecha_vencimiento_licencia, 23))
            );
        END;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores', CONVERT(NVARCHAR(100), @conductor_id), 'INSERT',
            NULL, CONCAT(N'Licencia: ', @numero_licencia, N'; Categoría: ', ISNULL(@codigo_licencia_inicial, N'SIN_CATEGORIA'), N'; Estado: ', @estado_operativo, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_cond_save;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 3. CONTRATO ATÓMICO DE LICENCIA PRINCIPAL ÚNICA DEL CONDUCTOR
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_actualizar_licencia_principal_conductor
    @conductor_id INT,
    @codigo_licencia VARCHAR(10),
    @numero_licencia NVARCHAR(50),
    @fecha_vencimiento DATE,
    @observaciones NVARCHAR(300) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @codigo_licencia = UPPER(NULLIF(LTRIM(RTRIM(@codigo_licencia)), ''));
    SET @numero_licencia = UPPER(NULLIF(LTRIM(RTRIM(@numero_licencia)), N''));
    SET @observaciones = NULLIF(LTRIM(RTRIM(@observaciones)), N'');

    IF @conductor_id IS NULL OR @conductor_id < 1
       OR @codigo_licencia IS NULL
       OR @numero_licencia IS NULL
       OR @fecha_vencimiento IS NULL
        THROW 50502, 'Datos de la licencia principal incompletos o inválidos.', 1;

    IF @fecha_vencimiento < CONVERT(DATE, SYSUTCDATETIME())
        THROW 50502, 'La fecha de vencimiento de la licencia no puede estar vencida.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.cat_licencias WHERE codigo = @codigo_licencia AND activo = 1)
        THROW 50502, 'La categoría de licencia seleccionada no es válida o está inactiva.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_lic_prin_save;

    BEGIN TRY
        DECLARE @activo BIT, @estado_operativo VARCHAR(20), @prev_numero NVARCHAR(50), @prev_venc DATE;

        SELECT
            @activo = activo,
            @estado_operativo = estado_operativo,
            @prev_numero = numero_licencia,
            @prev_venc = fecha_vencimiento_licencia
        FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
        WHERE conductor_id = @conductor_id;

        IF @activo IS NULL
            THROW 50503, 'El conductor especificado no existe.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede modificar la licencia de un conductor inactivo.', 1;

        IF @estado_operativo = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
               WHERE conductor_id = @conductor_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede modificar la licencia mientras el conductor participa en un viaje planificado o en curso.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE conductor_id <> @conductor_id AND numero_licencia = @numero_licencia
        )
            THROW 50504, 'Otro conductor ya utiliza ese número de licencia.', 1;

        DECLARE @prev_licencias NVARCHAR(1000);
        SELECT @prev_licencias = STRING_AGG(
            CONCAT(cl.codigo_licencia, N':', cl.estado_verificacion, N':', CONVERT(VARCHAR(10), cl.fecha_vencimiento, 23)),
            N' | '
        ) WITHIN GROUP (ORDER BY cl.codigo_licencia)
        FROM dbo.conductores_licencias cl WITH (UPDLOCK, HOLDLOCK)
        WHERE cl.conductor_id = @conductor_id;

        -- Retirar cualquier otra categoría previa para impedir habilitación por filas ocultas
        DELETE FROM dbo.conductores_licencias
        WHERE conductor_id = @conductor_id
          AND codigo_licencia <> @codigo_licencia;

        DECLARE @obs_final NVARCHAR(300) = ISNULL(@observaciones, N'Declaración administrativa de revisión de documento físico');

        IF EXISTS (
            SELECT 1 FROM dbo.conductores_licencias
            WHERE conductor_id = @conductor_id AND codigo_licencia = @codigo_licencia
        )
        BEGIN
            UPDATE dbo.conductores_licencias
            SET numero_licencia = @numero_licencia,
                fecha_vencimiento = @fecha_vencimiento,
                estado_verificacion = 'VERIFICADA',
                fecha_verificacion = SYSUTCDATETIME(),
                usuario_verificador_id = @actor_id,
                observaciones = @obs_final
            WHERE conductor_id = @conductor_id
              AND codigo_licencia = @codigo_licencia;
        END
        ELSE
        BEGIN
            INSERT INTO dbo.conductores_licencias
            (
                conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento,
                estado_verificacion, fecha_verificacion, usuario_verificador_id, observaciones
            )
            VALUES
            (
                @conductor_id, @codigo_licencia, @numero_licencia, @fecha_vencimiento,
                'VERIFICADA', SYSUTCDATETIME(), @actor_id, @obs_final
            );
        END;

        -- Sincronización atómica con dbo.conductores
        UPDATE dbo.conductores
        SET numero_licencia = @numero_licencia,
            fecha_vencimiento_licencia = @fecha_vencimiento
        WHERE conductor_id = @conductor_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id,
            N'conductores_licencias',
            CONCAT(@conductor_id, N':', @codigo_licencia),
            'ACTUALIZAR_PRINCIPAL',
            ISNULL(@prev_licencias, CONCAT(N'Sin categorías (Lic: ', ISNULL(@prev_numero, N'N/A'), N'; Vence: ', ISNULL(CONVERT(VARCHAR(10), @prev_venc, 23), N'N/A'), N')')),
            CONCAT(N'Cat: ', @codigo_licencia, N'; Lic: ', @numero_licencia, N'; Vence: ', CONVERT(VARCHAR(10), @fecha_vencimiento, 23), N'; Revisión interna')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_lic_prin_save;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 4. VEHÍCULOS: COHERENCIA PMA / CLASE / ARTICULADO Y PRESERVACIÓN DE KM / HOMOLOGACIÓN
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_crear_vehiculo
    @placa NVARCHAR(20),
    @marca NVARCHAR(80),
    @modelo NVARCHAR(80),
    @anio SMALLINT,
    @capacidad_carga_kg DECIMAL(12,2),
    @capacidad_volumen_m3 DECIMAL(12,3) = NULL,
    @kilometraje_actual DECIMAL(12,1) = 0,
    @estado VARCHAR(20) = 'DISPONIBLE',
    @pma_kg DECIMAL(10,2) = NULL,
    @clase_legal VARCHAR(30) = NULL,
    @es_articulado BIT = 0,
    @estado_homologacion VARCHAR(25) = 'PENDIENTE_VERIFICACION',
    @vehiculo_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @placa = UPPER(NULLIF(LTRIM(RTRIM(@placa)), N''));
    SET @marca = NULLIF(LTRIM(RTRIM(@marca)), N'');
    SET @modelo = NULLIF(LTRIM(RTRIM(@modelo)), N'');
    SET @estado = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado)), ''), 'DISPONIBLE'));
    SET @kilometraje_actual = ISNULL(@kilometraje_actual, 0);
    SET @es_articulado = ISNULL(@es_articulado, 0);
    SET @pma_kg = ISNULL(@pma_kg, ROUND(@capacidad_carga_kg * 1.25, 2));
    SET @clase_legal = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@clase_legal)), ''), CASE WHEN @es_articulado = 1 THEN 'PESADO_ARTICULADO' WHEN @pma_kg > 8000 THEN 'PESADO_RIGIDO' WHEN @pma_kg > 4000 THEN 'MEDIANO_RIGIDO' ELSE 'LIVIANO' END));
    SET @estado_homologacion = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_homologacion)), ''), 'PENDIENTE_VERIFICACION'));

    IF @placa IS NULL OR @marca IS NULL OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR @kilometraje_actual < 0
       OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
       OR @pma_kg IS NULL OR @pma_kg <= 0
       OR @clase_legal NOT IN ('LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO')
       OR @estado_homologacion NOT IN ('PENDIENTE_VERIFICACION', 'VERIFICADO', 'NO_CONFORME')
        THROW 50502, 'Datos del vehículo, PMA o estado operativo inválidos.', 1;

    IF @capacidad_carga_kg > @pma_kg
        THROW 50502, 'La capacidad de carga útil no puede superar el PMA del vehículo.', 1;

    IF (@es_articulado = 1 AND @clase_legal <> 'PESADO_ARTICULADO')
       OR (@es_articulado = 0 AND @clase_legal = 'PESADO_ARTICULADO')
       OR (@es_articulado = 0 AND @clase_legal = 'LIVIANO' AND @pma_kg > 4000.00)
       OR (@es_articulado = 0 AND @clase_legal = 'MEDIANO_RIGIDO' AND (@pma_kg <= 4000.00 OR @pma_kg > 8000.00))
       OR (@es_articulado = 0 AND @clase_legal = 'PESADO_RIGIDO' AND @pma_kg <= 8000.00)
        THROW 50502, 'La clasificación legal contradice el PMA o la condición de vehículo articulado.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_vehiculo_save;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK) WHERE placa = @placa)
            THROW 50504, 'Ya existe un vehículo registrado con esa placa.', 1;

        INSERT INTO dbo.vehiculos (
            placa, marca, modelo, anio, capacidad_carga_kg,
            capacidad_volumen_m3, kilometraje_actual, estado, activo,
            pma_kg, clase_legal, es_articulado, estado_homologacion
        )
        VALUES (
            @placa, @marca, @modelo, @anio, @capacidad_carga_kg,
            @capacidad_volumen_m3, @kilometraje_actual, @estado, 1,
            @pma_kg, @clase_legal, @es_articulado, @estado_homologacion
        );

        SET @vehiculo_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'INSERT',
            NULL, CONCAT(N'Placa: ', @placa, N'; PMA: ', CAST(@pma_kg AS NVARCHAR(20)), N'; Clase: ', @clase_legal, N'; Articulado: ', @es_articulado, N'; Estado: ', @estado, N'; Control: ', @estado_homologacion)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_vehiculo_save;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_vehiculo
    @vehiculo_id INT,
    @marca NVARCHAR(80),
    @modelo NVARCHAR(80),
    @anio SMALLINT,
    @capacidad_carga_kg DECIMAL(12,2),
    @capacidad_volumen_m3 DECIMAL(12,3) = NULL,
    @kilometraje_actual DECIMAL(12,1) = NULL,
    @estado VARCHAR(20),
    @pma_kg DECIMAL(10,2) = NULL,
    @clase_legal VARCHAR(30) = NULL,
    @es_articulado BIT = NULL,
    @estado_homologacion VARCHAR(25) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @marca = NULLIF(LTRIM(RTRIM(@marca)), N'');
    SET @modelo = NULLIF(LTRIM(RTRIM(@modelo)), N'');
    SET @estado = UPPER(NULLIF(LTRIM(RTRIM(@estado)), ''));
    SET @clase_legal = UPPER(NULLIF(LTRIM(RTRIM(@clase_legal)), ''));
    SET @estado_homologacion = UPPER(NULLIF(LTRIM(RTRIM(@estado_homologacion)), ''));

    IF @vehiculo_id IS NULL OR @vehiculo_id < 1
       OR @marca IS NULL
       OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR (@kilometraje_actual IS NOT NULL AND @kilometraje_actual < 0)
       OR @estado IS NULL OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
       OR (@pma_kg IS NOT NULL AND @pma_kg <= 0)
       OR (@clase_legal IS NOT NULL AND @clase_legal NOT IN ('LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'))
       OR (@estado_homologacion IS NOT NULL AND @estado_homologacion NOT IN ('PENDIENTE_VERIFICACION', 'VERIFICADO', 'NO_CONFORME'))
        THROW 50502, 'Datos del vehículo, PMA o estado operativo inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_actualizar_vehiculo_save;

    BEGIN TRY
        DECLARE
            @placa NVARCHAR(20),
            @prev_estado VARCHAR(20),
            @prev_km DECIMAL(12,1),
            @prev_pma DECIMAL(10,2),
            @prev_clase VARCHAR(30),
            @prev_articulado BIT,
            @prev_homologacion VARCHAR(25),
            @activo BIT;

        SELECT
            @placa = placa,
            @prev_estado = estado,
            @prev_km = kilometraje_actual,
            @prev_pma = pma_kg,
            @prev_clase = clase_legal,
            @prev_articulado = es_articulado,
            @prev_homologacion = estado_homologacion,
            @activo = activo
        FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
        WHERE vehiculo_id = @vehiculo_id;

        IF @activo IS NULL
            THROW 50503, 'Vehículo no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar un vehículo inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
               WHERE vehiculo_id = @vehiculo_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede modificar el vehículo mientras está asignado a un viaje planificado o en curso.', 1;

        IF @kilometraje_actual IS NOT NULL AND @kilometraje_actual < @prev_km
            THROW 50502, 'El kilometraje actual no puede ser menor al registrado previamente.', 1;

        DECLARE @eff_km DECIMAL(12,1) = ISNULL(@kilometraje_actual, @prev_km);
        DECLARE @eff_pma DECIMAL(10,2) = ISNULL(@pma_kg, @prev_pma);
        DECLARE @eff_articulado BIT = ISNULL(@es_articulado, @prev_articulado);
        DECLARE @eff_clase VARCHAR(30) = ISNULL(@clase_legal, @prev_clase);
        DECLARE @eff_homologacion VARCHAR(25) = ISNULL(@estado_homologacion, @prev_homologacion);

        IF @eff_pma IS NOT NULL AND @capacidad_carga_kg > @eff_pma
            THROW 50502, 'La capacidad de carga útil no puede superar el PMA del vehículo.', 1;

        IF @eff_pma IS NOT NULL AND @eff_clase IS NOT NULL
        BEGIN
            IF (@eff_articulado = 1 AND @eff_clase <> 'PESADO_ARTICULADO')
               OR (@eff_articulado = 0 AND @eff_clase = 'PESADO_ARTICULADO')
               OR (@eff_articulado = 0 AND @eff_clase = 'LIVIANO' AND @eff_pma > 4000.00)
               OR (@eff_articulado = 0 AND @eff_clase = 'MEDIANO_RIGIDO' AND (@eff_pma <= 4000.00 OR @eff_pma > 8000.00))
               OR (@eff_articulado = 0 AND @eff_clase = 'PESADO_RIGIDO' AND @eff_pma <= 8000.00)
                THROW 50502, 'La clasificación legal contradice el PMA o la condición de vehículo articulado.', 1;
        END;

        UPDATE dbo.vehiculos
        SET marca = @marca,
            modelo = @modelo,
            anio = @anio,
            capacidad_carga_kg = @capacidad_carga_kg,
            capacidad_volumen_m3 = @capacidad_volumen_m3,
            kilometraje_actual = @eff_km,
            estado = @estado,
            pma_kg = @eff_pma,
            clase_legal = @eff_clase,
            es_articulado = @eff_articulado,
            estado_homologacion = @eff_homologacion
        WHERE vehiculo_id = @vehiculo_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'UPDATE',
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @prev_estado, N'; PMA: ', ISNULL(CAST(@prev_pma AS NVARCHAR(20)), N'NULL'), N'; Km: ', @prev_km, N'; Control: ', @prev_homologacion),
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @estado, N'; PMA: ', ISNULL(CAST(@eff_pma AS NVARCHAR(20)), N'NULL'), N'; Clase: ', ISNULL(@eff_clase, N'NULL'), N'; Km: ', @eff_km, N'; Control: ', @eff_homologacion)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_actualizar_vehiculo_save;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 5. INVITACIONES: REVOCAR VIGENTE VS CANCELAR EXPIRADA CON AUDITORÍA
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_revocar_invitacion_usuario
    @invitacion_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_rev_inv_save;

    BEGIN TRY
        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;

        SELECT
            @correo = correo_destino,
            @estado = estado,
            @expiracion = fecha_expiracion
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo IS NULL
            THROW 50436, 'La invitación no existe.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser revocada ni cancelada.', 1;

        IF @estado IN ('REVOCADA', 'CANCELADA')
            THROW 50442, 'La invitación ya se encuentra revocada o cancelada.', 1;

        IF @estado = 'EXPIRADA' OR @expiracion <= SYSUTCDATETIME()
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'CANCELADA'
            WHERE invitacion_id = @invitacion_id;

            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @invitacion_id), 'CANCEL_EXPIRED',
                CONCAT(N'Estado: ', @estado),
                CONCAT(N'Invitación expirada cancelada/descartada para: ', @correo)
            );
        END
        ELSE
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'REVOCADA'
            WHERE invitacion_id = @invitacion_id;

            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @invitacion_id), 'REVOKE',
                N'Estado: PENDIENTE',
                CONCAT(N'Invitación vigente revocada para: ', @correo)
            );
        END;

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_rev_inv_save;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_reenviar_invitacion_usuario
    @invitacion_id INT,
    @nuevo_token_hash VARCHAR(64),
    @horas_vigencia INT = 24,
    @nueva_invitacion_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @nuevo_token_hash IS NULL OR LEN(@nuevo_token_hash) <> 64
        THROW 50435, 'El hash del nuevo token de invitación no es válido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_reenv_inv_save;

    BEGIN TRY
        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;
        DECLARE @ultimo_envio DATETIME2;
        DECLARE @envios INT;

        SELECT
            @correo = correo_destino,
            @estado = estado,
            @expiracion = fecha_expiracion,
            @ultimo_envio = ultimo_envio_en,
            @envios = envios_contador
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo IS NULL
            THROW 50437, 'La invitación original no fue encontrada.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser reenviada.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_acceso) = LOWER(@correo) AND activo = 1
        )
            THROW 50430, 'El correo ya pertenece a una cuenta activa.', 1;

        DECLARE @envios_24h INT = 0;
        SELECT @envios_24h = COUNT(*)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = LOWER(@correo)
          AND fecha_creacion >= DATEADD(HOUR, -24, SYSUTCDATETIME());

        IF @envios_24h >= 5
            THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 1;

        IF @ultimo_envio IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio, SYSUTCDATETIME()) < 60
            THROW 50440, 'Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 1;

        -- Cerrar la invitación previa con semántica precisa (REVOCADA si estaba vigente, CANCELADA si ya había vencido)
        IF @estado = 'PENDIENTE' AND @expiracion > SYSUTCDATETIME()
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'REVOCADA'
            WHERE invitacion_id = @invitacion_id;
        END
        ELSE IF @estado IN ('PENDIENTE', 'EXPIRADA')
        BEGIN
            UPDATE dbo.invitaciones_usuario
            SET estado = 'CANCELADA'
            WHERE invitacion_id = @invitacion_id;
        END;

        INSERT INTO dbo.invitaciones_usuario
        (
            correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id,
            fecha_creacion, ultimo_envio_en, envios_contador, estado_envio
        )
        VALUES
        (
            @correo, @nuevo_token_hash, 'PENDIENTE',
            DATEADD(HOUR, ISNULL(@horas_vigencia, 24), SYSUTCDATETIME()),
            @actor_id,
            SYSUTCDATETIME(),
            SYSUTCDATETIME(),
            ISNULL(@envios, 0) + 1,
            'PENDIENTE'
        );

        SET @nueva_invitacion_id = CONVERT(INT, SCOPE_IDENTITY());

        -- Copiar permisos planificados activos de la invitación previa
        INSERT INTO dbo.invitaciones_permisos (invitacion_id, permiso_id, creado_en)
        SELECT @nueva_invitacion_id, ip.permiso_id, SYSUTCDATETIME()
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = @invitacion_id
          AND p.activo = 1;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @nueva_invitacion_id), 'RESEND',
            CONCAT(N'Invitación previa ID ', @invitacion_id, N' (', @estado, N')'),
            CONCAT(N'Invitación reenviada a correo: ', @correo)
        );

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
