/* =========================================================================
   Transportes Seguros S.A. — SQL Server 2025
   Contratos de Categorías Legales de Licencia de Conducir (CR Ley 9078/9460)
   y Peso Máximo Autorizado (PMA) en Vehículos.

   Alcance:
   1. Catálogo oficial dbo.cat_licencias (Ley 9078, art. 86 reformado por Ley 9460).
   2. Tabla normalizada dbo.conductores_licencias (soporte multicategoría, vigencia, verificación).
   3. Nuevas columnas en dbo.vehiculos (pma_kg, clase_legal, es_articulado, estado_homologacion).
   4. Función autoritativa dbo.fn_conductor_habilitado_vehiculo (@conductor_id, @vehiculo_id, @fecha_salida).
   5. Procedimientos almacenados para gestión de licencias de conductores.
   6. Endurecimiento de dbo.sp_crear_viaje y dbo.sp_iniciar_viaje con validación de licencias y PMA bajo UPDLOCK, HOLDLOCK.
   7. Actualización de procedimientos de alta, edición y listado de flota.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET NOCOUNT ON;
GO

-- -------------------------------------------------------------------------
-- 1. CATÁLOGO OFICIAL DE LICENCIAS DE COSTA RICA (dbo.cat_licencias)
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.cat_licencias', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.cat_licencias
    (
        codigo VARCHAR(10) NOT NULL CONSTRAINT PK_cat_licencias PRIMARY KEY,
        clase VARCHAR(5) NOT NULL,
        nombre NVARCHAR(100) NOT NULL,
        descripcion NVARCHAR(350) NOT NULL,
        pma_maximo_kg DECIMAL(10,2) NULL,
        permite_articulado BIT NOT NULL CONSTRAINT DF_cat_lic_art DEFAULT 0,
        permite_transporte_carga BIT NOT NULL CONSTRAINT DF_cat_lic_carga DEFAULT 1,
        activo BIT NOT NULL CONSTRAINT DF_cat_lic_act DEFAULT 1,
        fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_cat_lic_fec DEFAULT SYSUTCDATETIME()
    );
END;
GO

-- Poblar / Sincronizar catálogo con las categorías oficiales de la Ley 9078 y reformas
MERGE INTO dbo.cat_licencias AS target
USING (
    VALUES
        ('A1', 'A', N'Clase A-1 (Motos hasta 125 cc / 11 kW)', N'Bicimotos y motocicletas hasta 125 cc; motores eléctricos hasta 11 kW; cuadriciclos hasta 250 cc.', NULL, 0, 0, 1),
        ('A2', 'A', N'Clase A-2 (Motos hasta 500 cc / 35 kW)', N'Motocicletas de combustión de hasta 500 cc; motores eléctricos o híbridos hasta 35 kW.', NULL, 0, 0, 1),
        ('A3', 'A', N'Clase A-3 (Motos sin límite de potencia)', N'Motocicletas de cualquier cilindrada o potencia sin límite.', NULL, 0, 0, 1),
        ('B1', 'B', N'Clase B-1 (Vehículos livianos hasta 4.000 kg PMA)', N'Automóviles, camionetas y pickups hasta 4.000 kg de peso bruto autorizado (PMA), con remolque liviano conjunto hasta 4.000 kg.', 4000.00, 0, 1, 1),
        ('B2', 'B', N'Clase B-2 (Vehículos de carga hasta 8.000 kg PMA)', N'Todo tipo de vehículo automotor de carga de hasta 8.000 kg de peso bruto autorizado (PMA), con o sin remolque conjunto.', 8000.00, 0, 1, 1),
        ('B3', 'B', N'Clase B-3 (Pesados rígidos sin límite de peso)', N'Todo peso de vehículos pesados NO articulados (camiones rígidos de 2 o más ejes con PMA superior a 8.000 kg).', NULL, 0, 1, 1),
        ('B4', 'B', N'Clase B-4 (Pesados articulados de todo peso)', N'Todo tipo de vehículo automotor pesado, incluidos vehículos pesados articulados (cabezales con semirremolque y articulados).', NULL, 1, 1, 1),
        ('C1', 'C', N'Clase C-1 (Transporte público taxi)', N'Vehículos de transporte público en modalidad taxi.', 4000.00, 0, 0, 1),
        ('C2', 'C', N'Clase C-2 (Transporte público autobús)', N'Vehículos de transporte público colectivo (autobús, buseta, microbús).', NULL, 0, 0, 1),
        ('D1', 'D', N'Clase D-1 (Tractores de llantas)', N'Tractores de llantas de uso agrícola o de construcción.', NULL, 0, 0, 1),
        ('D2', 'D', N'Clase D-2 (Tractores de oruga)', N'Tractores de oruga de uso agrícola, construcción u obras.', NULL, 0, 0, 1),
        ('D3', 'D', N'Clase D-3 (Equipo especial de obras)', N'Otros tipos de equipo especial de obras, construcción o agrícola.', NULL, 0, 0, 1),
        ('E1', 'E', N'Clase E-1 (Universal 2 o más ejes no colectivo)', N'Conducción de vehículos comprendidos en clases B y C-1, de dos o más ejes, excepto transporte público colectivo y equipo especial.', NULL, 0, 1, 1),
        ('E2', 'E', N'Clase E-2 (Universal completo)', N'Todo tipo de vehículo comprendido en clases B, C y D, excepto transporte público colectivo. Habilita articulados.', NULL, 1, 1, 1)
) AS source (codigo, clase, nombre, descripcion, pma_maximo_kg, permite_articulado, permite_transporte_carga, activo)
ON target.codigo = source.codigo
WHEN MATCHED THEN
    UPDATE SET
        target.clase = source.clase,
        target.nombre = source.nombre,
        target.descripcion = source.descripcion,
        target.pma_maximo_kg = source.pma_maximo_kg,
        target.permite_articulado = source.permite_articulado,
        target.permite_transporte_carga = source.permite_transporte_carga,
        target.activo = source.activo
WHEN NOT MATCHED THEN
    INSERT (codigo, clase, nombre, descripcion, pma_maximo_kg, permite_articulado, permite_transporte_carga, activo)
    VALUES (source.codigo, source.clase, source.nombre, source.descripcion, source.pma_maximo_kg, source.permite_articulado, source.permite_transporte_carga, source.activo);
GO

-- -------------------------------------------------------------------------
-- 2. TABLA NORMALIZADA DE LICENCIAS DE CONDUCTORES (dbo.conductores_licencias)
-- -------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.conductores_licencias', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.conductores_licencias
    (
        conductor_id INT NOT NULL,
        codigo_licencia VARCHAR(10) NOT NULL,
        numero_licencia NVARCHAR(50) NOT NULL,
        fecha_vencimiento DATE NOT NULL,
        estado_verificacion VARCHAR(25) NOT NULL CONSTRAINT DF_cond_lic_est DEFAULT 'PENDIENTE',
        fecha_verificacion DATETIME2 NULL,
        usuario_verificador_id INT NULL,
        observaciones NVARCHAR(300) NULL,
        fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_cond_lic_fec DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_conductores_licencias PRIMARY KEY CLUSTERED (conductor_id, codigo_licencia),
        CONSTRAINT FK_cond_lic_conductor FOREIGN KEY (conductor_id) REFERENCES dbo.conductores (conductor_id) ON DELETE CASCADE,
        CONSTRAINT FK_cond_lic_codigo FOREIGN KEY (codigo_licencia) REFERENCES dbo.cat_licencias (codigo),
        CONSTRAINT FK_cond_lic_verificador FOREIGN KEY (usuario_verificador_id) REFERENCES dbo.usuarios (usuario_id),
        CONSTRAINT CK_cond_lic_estado CHECK (estado_verificacion IN ('PENDIENTE', 'VERIFICADA', 'RECHAZADA'))
    );

    CREATE NONCLUSTERED INDEX IX_conductores_licencias_vigencia
    ON dbo.conductores_licencias (conductor_id, estado_verificacion, fecha_vencimiento)
    INCLUDE (codigo_licencia);
END;
GO

-- -------------------------------------------------------------------------
-- 3. NUEVAS COLUMNAS EN dbo.vehiculos (PMA y Clasificación Legal)
-- -------------------------------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'pma_kg')
BEGIN
    ALTER TABLE dbo.vehiculos ADD pma_kg DECIMAL(10,2) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'clase_legal')
BEGIN
    ALTER TABLE dbo.vehiculos ADD clase_legal VARCHAR(30) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'es_articulado')
BEGIN
    ALTER TABLE dbo.vehiculos ADD es_articulado BIT NOT NULL CONSTRAINT DF_vehiculos_articulado DEFAULT 0;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.vehiculos') AND name = N'estado_homologacion')
BEGIN
    ALTER TABLE dbo.vehiculos ADD estado_homologacion VARCHAR(25) NOT NULL CONSTRAINT DF_vehiculos_homologacion DEFAULT 'PENDIENTE_VERIFICACION';
END;
GO

-- Agregar CHECK de clasificación legal y homologación si no existen
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_vehiculos_clase_legal')
BEGIN
    ALTER TABLE dbo.vehiculos WITH CHECK ADD CONSTRAINT CK_vehiculos_clase_legal
    CHECK (clase_legal IS NULL OR clase_legal IN ('LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'));
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_vehiculos_estado_homologacion')
BEGIN
    ALTER TABLE dbo.vehiculos WITH CHECK ADD CONSTRAINT CK_vehiculos_estado_homologacion
    CHECK (estado_homologacion IN ('PENDIENTE_VERIFICACION', 'VERIFICADO', 'NO_CONFORME'));
END;
GO

-- -------------------------------------------------------------------------
-- 4. FUNCIÓN AUTORITATIVA DE COMPATIBILIDAD CONDUCTOR-VEHÍCULO
-- -------------------------------------------------------------------------
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
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
            -- 1. Validar que el vehículo exista y esté activo
            WHEN v.vehiculo_id IS NULL THEN 0
            WHEN v.activo = 0 THEN 0
            -- 2. Validar que el vehículo tenga PMA mayor a 0 y no sea NO_CONFORME
            WHEN v.pma_kg IS NULL OR v.pma_kg <= 0 THEN 0
            WHEN v.estado_homologacion = 'NO_CONFORME' THEN 0
            -- 3. Validar que el conductor exista y esté activo
            WHEN c.conductor_id IS NULL THEN 0
            WHEN c.activo = 0 THEN 0
            -- 4. Evaluar coincidencia de licencias verificadas y no vencidas
            WHEN EXISTS (
                SELECT 1
                FROM dbo.conductores_licencias cl
                JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND (
                      -- Caso Articulado: Requiere B4 o E2
                      (v.es_articulado = 1 AND cat.permite_articulado = 1)
                      OR
                      -- Caso No Articulado:
                      (v.es_articulado = 0 AND (
                          -- > 8.000 kg PMA: requiere B3, B4, E1 o E2
                          (v.pma_kg > 8000.00 AND cat.codigo IN ('B3', 'B4', 'E1', 'E2'))
                          OR
                          -- 4.000 kg < PMA <= 8.000 kg: requiere B2, B3, B4, E1 o E2 (rechaza B1)
                          (v.pma_kg > 4000.00 AND v.pma_kg <= 8000.00 AND cat.codigo IN ('B2', 'B3', 'B4', 'E1', 'E2'))
                          OR
                          -- PMA <= 4.000 kg: requiere B1, B2, B3, B4, E1 o E2
                          (v.pma_kg <= 4000.00 AND cat.codigo IN ('B1', 'B2', 'B3', 'B4', 'E1', 'E2'))
                      ))
                  )
            ) THEN 1
            ELSE 0
        END AS es_habilitado,
        CASE
            WHEN v.vehiculo_id IS NULL THEN N'El vehículo seleccionado no existe.'
            WHEN v.activo = 0 THEN N'El vehículo seleccionado se encuentra inactivo.'
            WHEN v.pma_kg IS NULL OR v.pma_kg <= 0 THEN N'Falta verificar el peso bruto autorizado (PMA) de la unidad seleccionada.'
            WHEN v.estado_homologacion = 'NO_CONFORME' THEN N'La unidad vehicular está marcada como no conforme para operaciones.'
            WHEN c.conductor_id IS NULL THEN N'El conductor seleccionado no existe.'
            WHEN c.activo = 0 THEN N'El conductor seleccionado se encuentra inactivo.'
            WHEN NOT EXISTS (
                SELECT 1 FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = @conductor_id AND cl.estado_verificacion = 'VERIFICADA'
            ) THEN N'El conductor no posee categorías de licencia verificadas registradas.'
            WHEN NOT EXISTS (
                SELECT 1 FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
            ) THEN N'La licencia del conductor vence antes de la salida programada del viaje.'
            WHEN v.es_articulado = 1 AND NOT EXISTS (
                SELECT 1 FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B4', 'E2')
            ) THEN N'La unidad es pesada articulada y requiere licencia clase B-4 (o E-2).'
            WHEN v.pma_kg > 8000.00 AND NOT EXISTS (
                SELECT 1 FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B3', 'B4', 'E1', 'E2')
            ) THEN N'El vehículo supera 8.000 kg de peso bruto (PMA) y requiere al menos licencia clase B-3.'
            WHEN v.pma_kg > 4000.00 AND NOT EXISTS (
                SELECT 1 FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = @conductor_id
                  AND cl.estado_verificacion = 'VERIFICADA'
                  AND cl.fecha_vencimiento >= @fecha_salida
                  AND cl.codigo_licencia IN ('B2', 'B3', 'B4', 'E1', 'E2')
            ) THEN N'El vehículo supera 4.000 kg de peso bruto (PMA); la licencia clase B-1 es insuficiente (requiere clase B-2 o superior).'
            ELSE N'Conductor habilitado y compatible.'
        END AS motivo_diagnostico
    FROM (SELECT @conductor_id AS q_cond_id, @vehiculo_id AS q_veh_id) q
    LEFT JOIN dbo.conductores c ON c.conductor_id = q.q_cond_id
    LEFT JOIN dbo.vehiculos v ON v.vehiculo_id = q.q_veh_id
);
GO

-- -------------------------------------------------------------------------
-- 5. PROCEDIMIENTOS ALMACENADOS PARA LICENCIAS DE CONDUCTORES
-- -------------------------------------------------------------------------
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_asignar_licencia_conductor
    @conductor_id INT,
    @codigo_licencia VARCHAR(10),
    @numero_licencia NVARCHAR(50),
    @fecha_vencimiento DATE,
    @estado_verificacion VARCHAR(25) = 'PENDIENTE',
    @observaciones NVARCHAR(300) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @codigo_licencia = UPPER(NULLIF(LTRIM(RTRIM(@codigo_licencia)), ''));
    SET @numero_licencia = UPPER(NULLIF(LTRIM(RTRIM(@numero_licencia)), N''));
    SET @estado_verificacion = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_verificacion)), ''), 'PENDIENTE'));
    SET @observaciones = NULLIF(LTRIM(RTRIM(@observaciones)), N'');

    IF @conductor_id IS NULL OR @codigo_licencia IS NULL OR @numero_licencia IS NULL OR @fecha_vencimiento IS NULL
        THROW 50502, 'Datos de la licencia incompletos o inválidos.', 1;

    IF @fecha_vencimiento < CONVERT(DATE, SYSUTCDATETIME())
        THROW 50502, 'La fecha de vencimiento no puede ser anterior a hoy.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.cat_licencias WHERE codigo = @codigo_licencia AND activo = 1)
        THROW 50502, 'La categoría de licencia no es válida o no está activa.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.conductores WHERE conductor_id = @conductor_id AND activo = 1)
        THROW 50503, 'El conductor no existe o está inactivo.', 1;

    IF @estado_verificacion NOT IN ('PENDIENTE', 'VERIFICADA', 'RECHAZADA')
        THROW 50502, 'Estado de verificación no reconocido.', 1;

    DECLARE @fecha_verif DATETIME2 = NULL;
    DECLARE @verificador_id INT = NULL;
    IF @estado_verificacion = 'VERIFICADA'
    BEGIN
        SET @fecha_verif = SYSUTCDATETIME();
        SET @verificador_id = @actor_id;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF EXISTS (SELECT 1 FROM dbo.conductores_licencias WHERE conductor_id = @conductor_id AND codigo_licencia = @codigo_licencia)
        BEGIN
            UPDATE dbo.conductores_licencias
            SET numero_licencia = @numero_licencia,
                fecha_vencimiento = @fecha_vencimiento,
                estado_verificacion = @estado_verificacion,
                fecha_verificacion = @fecha_verif,
                usuario_verificador_id = @verificador_id,
                observaciones = @observaciones
            WHERE conductor_id = @conductor_id AND codigo_licencia = @codigo_licencia;
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
                @estado_verificacion, @fecha_verif, @verificador_id, @observaciones
            );
        END;

        -- Mantener sincronizadas las columnas legacy en dbo.conductores con la licencia de mayor rango o vigencia
        UPDATE c
        SET c.numero_licencia = ISNULL(
                (SELECT TOP 1 cl.numero_licencia FROM dbo.conductores_licencias cl WHERE cl.conductor_id = c.conductor_id ORDER BY cl.fecha_vencimiento DESC),
                c.numero_licencia
            ),
            c.fecha_vencimiento_licencia = ISNULL(
                (SELECT TOP 1 cl.fecha_vencimiento FROM dbo.conductores_licencias cl WHERE cl.conductor_id = c.conductor_id ORDER BY cl.fecha_vencimiento DESC),
                c.fecha_vencimiento_licencia
            )
        FROM dbo.conductores c
        WHERE c.conductor_id = @conductor_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores_licencias', CONCAT(@conductor_id, N':', @codigo_licencia), 'UPSERT',
            NULL, CONCAT(N'Cat: ', @codigo_licencia, N'; Lic: ', @numero_licencia, N'; Estado: ', @estado_verificacion)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_remover_licencia_conductor
    @conductor_id INT,
    @codigo_licencia VARCHAR(10)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.conductores_licencias WHERE conductor_id = @conductor_id AND codigo_licencia = @codigo_licencia)
        THROW 50503, 'La categoría de licencia no está asignada al conductor.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DELETE FROM dbo.conductores_licencias
        WHERE conductor_id = @conductor_id AND codigo_licencia = @codigo_licencia;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores_licencias', CONCAT(@conductor_id, N':', @codigo_licencia), 'DELETE',
            CONCAT(N'Cat eliminada: ', @codigo_licencia), NULL
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_licencias_conductor
    @conductor_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar flota.', 1;

    SELECT
        cl.conductor_id,
        cl.codigo_licencia,
        cat.clase,
        cat.nombre AS nombre_licencia,
        cat.pma_maximo_kg,
        cat.permite_articulado,
        cl.numero_licencia,
        cl.fecha_vencimiento,
        cl.estado_verificacion,
        cl.fecha_verificacion,
        u.nombre_usuario AS usuario_verificador,
        cl.observaciones
    FROM dbo.conductores_licencias cl
    JOIN dbo.cat_licencias cat ON cat.codigo = cl.codigo_licencia
    LEFT JOIN dbo.usuarios u ON u.usuario_id = cl.usuario_verificador_id
    WHERE cl.conductor_id = @conductor_id
    ORDER BY cat.codigo;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_catalogo_licencias
    @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        codigo,
        clase,
        nombre,
        descripcion,
        pma_maximo_kg,
        permite_articulado,
        permite_transporte_carga,
        activo
    FROM dbo.cat_licencias
    WHERE (@solo_activos IS NULL OR activo = @solo_activos)
    ORDER BY
        CASE clase
            WHEN 'B' THEN 1
            WHEN 'E' THEN 2
            WHEN 'C' THEN 3
            WHEN 'A' THEN 4
            WHEN 'D' THEN 5
            ELSE 6
        END,
        codigo ASC;
END;
GO

-- -------------------------------------------------------------------------
-- 6. ENDURECIMIENTO DE dbo.sp_crear_viaje (Validación Legal de Licencias y PMA)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_crear_viaje
    @codigo NVARCHAR(30) = NULL,
    @vehiculo_id INT,
    @conductor_id INT,
    @ruta_id INT,
    @fecha_programada DATETIME2,
    @viaje_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_CREAR') = 0
        THROW 50301, 'No tiene permiso para crear viajes.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_viaje_save;

    BEGIN TRY
        -- 1. Disponibilidad del vehículo bajo lock transaccional
        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
            WHERE vehiculo_id = @vehiculo_id AND activo = 1
              AND estado = 'DISPONIBLE'
        )
            THROW 50302, 'El vehículo no está disponible para asignación.', 1;

        -- 2. Disponibilidad del conductor bajo lock transaccional
        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE conductor_id = @conductor_id AND activo = 1
              AND estado_operativo = 'DISPONIBLE'
        )
            THROW 50303, 'El conductor no está disponible.', 1;

        -- 3. Verificación autoritativa de compatibilidad de licencia, vigencia y PMA
        DECLARE @habilitado BIT, @motivo NVARCHAR(250);
        SELECT @habilitado = es_habilitado, @motivo = motivo_diagnostico
        FROM dbo.fn_conductor_habilitado_vehiculo(@conductor_id, @vehiculo_id, CONVERT(DATE, @fecha_programada));

        IF @habilitado = 0
            THROW 50320, @motivo, 1;

        -- 4. Ruta activa
        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.rutas
            WHERE ruta_id = @ruta_id AND activo = 1
        )
            THROW 50304, 'La ruta no está activa.', 1;

        -- 5. Fecha programada futura o inmediata
        IF @fecha_programada < DATEADD(HOUR, -1, SYSUTCDATETIME())
            THROW 50305, 'La fecha programada de salida es demasiado antigua.', 1;

        -- 6. Autogenerar código secuencial si no se suministra
        IF @codigo IS NULL OR LTRIM(RTRIM(@codigo)) = N''
        BEGIN
            DECLARE @seq_via INT = NEXT VALUE FOR dbo.seq_codigo_viaje;
            SET @codigo = N'VIA-' + RIGHT(N'00000' + CAST(@seq_via AS NVARCHAR(10)), 5);
        END

        INSERT INTO dbo.viajes
        (
            codigo_viaje, vehiculo_id, conductor_id, ruta_id,
            fecha_programada_salida
        )
        VALUES
        (
            @codigo, @vehiculo_id, @conductor_id, @ruta_id,
            @fecha_programada
        );

        SET @viaje_id = CONVERT(INT, SCOPE_IDENTITY());

        UPDATE dbo.vehiculos SET estado = 'ASIGNADO'
        WHERE vehiculo_id = @vehiculo_id;

        UPDATE dbo.conductores SET estado_operativo = 'ASIGNADO'
        WHERE conductor_id = @conductor_id;

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_viaje_save;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 7. ENDURECIMIENTO DE dbo.sp_iniciar_viaje (Revalidación a Fecha Real)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_iniciar_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_INICIAR') = 0
        THROW 50316, 'No tiene permiso para iniciar viajes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @vehiculo_id INT, @conductor_id INT, @estado VARCHAR(20);

        SELECT @vehiculo_id = vehiculo_id,
               @conductor_id = conductor_id,
               @estado = estado
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id;

        IF @estado IS NULL OR @estado <> 'PLANIFICADO'
            THROW 50317, 'El viaje no está planificado o ya fue iniciado.', 1;

        IF NOT EXISTS (SELECT 1 FROM dbo.viaje_solicitudes WHERE viaje_id = @viaje_id)
            THROW 50318, 'No se puede iniciar un viaje sin solicitudes asociadas.', 1;

        -- Revalidar vigencia y compatibilidad de licencia a la fecha real de salida
        DECLARE @habilitado BIT, @motivo NVARCHAR(250);
        SELECT @habilitado = es_habilitado, @motivo = motivo_diagnostico
        FROM dbo.fn_conductor_habilitado_vehiculo(@conductor_id, @vehiculo_id, CONVERT(DATE, SYSUTCDATETIME()));

        IF @habilitado = 0
            THROW 50321, @motivo, 1;

        UPDATE dbo.viajes
        SET estado = 'EN_CURSO', fecha_salida_real = SYSUTCDATETIME()
        WHERE viaje_id = @viaje_id;

        UPDATE dbo.paradas_viaje
        SET estado = 'COMPLETADA'
        WHERE viaje_id = @viaje_id
          AND tipo_parada = 'RECOGIDA';

        UPDATE dbo.viaje_solicitudes
        SET estado = 'EN_TRANSITO'
        WHERE viaje_id = @viaje_id;

        UPDATE s
        SET s.estado = 'EN_TRANSITO', s.fecha_actualizacion = SYSUTCDATETIME()
        FROM dbo.solicitudes s
        JOIN dbo.viaje_solicitudes vs ON vs.solicitud_id = s.solicitud_id
        WHERE vs.viaje_id = @viaje_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 8. ACTUALIZACIÓN DE SPs DE ALTA Y EDICIÓN DE VEHÍCULOS (PMA y Homologación)
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
    SET @pma_kg = ISNULL(@pma_kg, ROUND(@capacidad_carga_kg * 1.25, 2));
    SET @clase_legal = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@clase_legal)), ''), CASE WHEN @es_articulado = 1 THEN 'PESADO_ARTICULADO' WHEN @pma_kg > 8000 THEN 'PESADO_RIGIDO' WHEN @pma_kg > 4000 THEN 'MEDIANO_RIGIDO' ELSE 'LIVIANO' END));
    SET @estado_homologacion = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_homologacion)), ''), 'VERIFICADO'));

    IF @placa IS NULL OR @marca IS NULL OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR @kilometraje_actual IS NULL OR @kilometraje_actual < 0
       OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
       OR (@pma_kg IS NOT NULL AND @pma_kg <= 0)
       OR (@clase_legal IS NOT NULL AND @clase_legal NOT IN ('LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'))
       OR @estado_homologacion NOT IN ('PENDIENTE_VERIFICACION', 'VERIFICADO', 'NO_CONFORME')
        THROW 50502, 'Datos del vehículo, PMA o estado operativo inválidos.', 1;

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
            @pma_kg, @clase_legal, ISNULL(@es_articulado, 0), @estado_homologacion
        );

        SET @vehiculo_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'INSERT',
            NULL, CONCAT(N'Placa: ', @placa, N'; PMA: ', ISNULL(CAST(@pma_kg AS NVARCHAR(20)), N'NULL'), N'; Articulado: ', @es_articulado, N'; Estado: ', @estado)
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
    @kilometraje_actual DECIMAL(12,1),
    @estado VARCHAR(20),
    @pma_kg DECIMAL(10,2) = NULL,
    @clase_legal VARCHAR(30) = NULL,
    @es_articulado BIT = 0,
    @estado_homologacion VARCHAR(25) = 'PENDIENTE_VERIFICACION'
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
    SET @estado_homologacion = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_homologacion)), ''), 'PENDIENTE_VERIFICACION'));

    IF @vehiculo_id IS NULL OR @vehiculo_id < 1
       OR @marca IS NULL
       OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR @kilometraje_actual IS NULL OR @kilometraje_actual < 0
       OR @estado IS NULL OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
       OR (@pma_kg IS NOT NULL AND @pma_kg <= 0)
       OR (@clase_legal IS NOT NULL AND @clase_legal NOT IN ('LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'))
       OR @estado_homologacion NOT IN ('PENDIENTE_VERIFICACION', 'VERIFICADO', 'NO_CONFORME')
        THROW 50502, 'Datos del vehículo, PMA o estado operativo inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_actualizar_vehiculo_save;

    BEGIN TRY
        DECLARE @placa NVARCHAR(20), @prev_estado VARCHAR(20), @prev_km DECIMAL(12,1), @activo BIT;
        SELECT @placa = placa,
               @prev_estado = estado,
               @prev_km = kilometraje_actual,
               @activo = activo
        FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
        WHERE vehiculo_id = @vehiculo_id;

        IF @activo IS NULL
            THROW 50503, 'Vehículo no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar un vehículo inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes
               WHERE vehiculo_id = @vehiculo_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede modificar el vehículo mientras está asignado a un viaje planificado o en curso.', 1;

        IF @kilometraje_actual < @prev_km
            THROW 50502, 'El kilometraje actual no puede ser menor al registrado previamente.', 1;

        UPDATE dbo.vehiculos
        SET marca = @marca,
            modelo = @modelo,
            anio = @anio,
            capacidad_carga_kg = @capacidad_carga_kg,
            capacidad_volumen_m3 = @capacidad_volumen_m3,
            kilometraje_actual = @kilometraje_actual,
            estado = @estado,
            pma_kg = @pma_kg,
            clase_legal = @clase_legal,
            es_articulado = ISNULL(@es_articulado, 0),
            estado_homologacion = @estado_homologacion
        WHERE vehiculo_id = @vehiculo_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'UPDATE',
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @prev_estado, N'; Km: ', @prev_km),
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @estado, N'; PMA: ', ISNULL(CAST(@pma_kg AS NVARCHAR(20)), N'NULL'), N'; Km: ', @kilometraje_actual)
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

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

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
    @codigo_licencia_inicial VARCHAR(10) = 'B4',
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

    IF @identificacion IS NULL
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
       OR @numero_licencia IS NULL
       OR @fecha_vencimiento_licencia IS NULL
       OR @fecha_vencimiento_licencia < CONVERT(DATE, SYSUTCDATETIME())
       OR (@correo IS NOT NULL AND (@correo NOT LIKE N'%_@_%._%' OR @correo LIKE N'% %'))
       OR @estado_operativo NOT IN ('DISPONIBLE', 'INACTIVO')
        THROW 50502, 'Datos del conductor, licencia o estado operativo inválidos.', 1;

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

        IF @codigo_licencia_inicial IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.conductores_licencias WHERE conductor_id = @conductor_id)
        BEGIN
            INSERT INTO dbo.conductores_licencias
            (
                conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento,
                estado_verificacion, fecha_verificacion, usuario_verificador_id, observaciones
            )
            VALUES
            (
                @conductor_id, @codigo_licencia_inicial, @numero_licencia, @fecha_vencimiento_licencia,
                'VERIFICADA', SYSUTCDATETIME(), @actor_id, N'Categoría asignada en alta'
            );
        END;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores', CONVERT(NVARCHAR(100), @conductor_id), 'INSERT',
            NULL, CONCAT(N'Licencia: ', @numero_licencia, N'; Estado: ', @estado_operativo, N'; Activo: 1')
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
-- 9. ACTUALIZACIÓN DE CONSULTAS DE LISTADO (Para exponer licencias y PMA)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_vehiculos
    @estado VARCHAR(20) = NULL,
    @solo_activos BIT = NULL,
    @buscar NVARCHAR(80) = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id, N'FLOTAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar flota.', 1;

    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Paginación inválida.', 1;

    IF @estado IS NOT NULL AND @estado NOT IN ('DISPONIBLE','ASIGNADO','MANTENIMIENTO','FUERA_SERVICIO')
        THROW 50102, N'Estado de vehículo inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.vehiculos
    WHERE (@estado IS NULL OR estado = @estado)
      AND (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR placa LIKE N'%'+@buscar+N'%' OR marca LIKE N'%'+@buscar+N'%' OR modelo LIKE N'%'+@buscar+N'%');

    SELECT
        vehiculo_id, placa, marca, modelo, anio,
        capacidad_carga_kg, capacidad_volumen_m3, kilometraje_actual,
        estado, activo,
        pma_kg, clase_legal, es_articulado, estado_homologacion
    FROM dbo.vehiculos
    WHERE (@estado IS NULL OR estado = @estado)
      AND (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR placa LIKE N'%'+@buscar+N'%' OR marca LIKE N'%'+@buscar+N'%' OR modelo LIKE N'%'+@buscar+N'%')
    ORDER BY placa, vehiculo_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_conductores
    @estado VARCHAR(20) = NULL,
    @solo_activos BIT = NULL,
    @buscar NVARCHAR(120) = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id, N'FLOTAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar flota.', 1;

    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Paginación inválida.', 1;

    IF @estado IS NOT NULL AND @estado NOT IN ('DISPONIBLE','ASIGNADO','INACTIVO')
        THROW 50102, N'Estado de conductor inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.conductores
    WHERE (@estado IS NULL OR estado_operativo = @estado)
      AND (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR identificacion LIKE N'%'+@buscar+N'%' OR nombre LIKE N'%'+@buscar+N'%' OR primer_apellido LIKE N'%'+@buscar+N'%' OR numero_licencia LIKE N'%'+@buscar+N'%');

    SELECT
        c.conductor_id, c.usuario_id, c.identificacion, c.nombre, c.primer_apellido, c.segundo_apellido,
        c.telefono, c.correo, c.numero_licencia, c.fecha_vencimiento_licencia, c.estado_operativo, c.activo,
        ISNULL(
            (
                SELECT STRING_AGG(cl.codigo_licencia, ', ') WITHIN GROUP (ORDER BY cl.codigo_licencia)
                FROM dbo.conductores_licencias cl
                WHERE cl.conductor_id = c.conductor_id AND cl.estado_verificacion = 'VERIFICADA'
            ),
            N'PENDIENTE'
        ) AS licencias_verificadas
    FROM dbo.conductores c
    WHERE (@estado IS NULL OR c.estado_operativo = @estado)
      AND (@solo_activos IS NULL OR c.activo = @solo_activos)
      AND (@buscar IS NULL OR c.identificacion LIKE N'%'+@buscar+N'%' OR c.nombre LIKE N'%'+@buscar+N'%' OR c.primer_apellido LIKE N'%'+@buscar+N'%' OR c.numero_licencia LIKE N'%'+@buscar+N'%')
    ORDER BY c.nombre, c.primer_apellido, c.conductor_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO
