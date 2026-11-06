<?php
declare(strict_types=1);

/**
 * Prueba de integración PDO_SQLSRV para sql-master-data-contracts en TransportesSegurosSA_Test.
 * Cubre permisos individuales (50501), validaciones (50502), inexistentes (50503),
 * duplicados (50504), conflictos por operaciones activas (50505), inactivos (50506),
 * referencias inactivas (50507) y concurrencia real entre dos sesiones PDO
 * (desactivación de flota vs sp_crear_viaje bajo UPDLOCK, HOLDLOCK).
 */
putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

function md_fail(string $message): never
{
    fwrite(STDERR, "FALLO: {$message}\n");
    exit(1);
}

function md_assert(bool $condition, string $message): void
{
    if (!$condition) {
        md_fail($message);
    }
}

function md_new_pdo(): PDO
{
    $server = getenv('TSSA_DB_SERVER') ?: 'localhost';
    $pdo = new PDO(
        "sqlsrv:Server={$server};Database=TransportesSegurosSA_Test;Encrypt=yes;TrustServerCertificate=yes",
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    require_test_database($pdo);
    return $pdo;
}

function md_expect_sql_code(callable $fn, int $expectedCode, string $context): void
{
    try {
        $fn();
        md_fail("{$context}: se esperaba código SQL {$expectedCode}, pero la operación tuvo éxito.");
    } catch (PDOException $e) {
        $actual = (int)($e->errorInfo[1] ?? 0);
        md_assert($actual === $expectedCode, "{$context}: se esperaba {$expectedCode} y se recibió {$actual} ({$e->getMessage()}).");
    }
}

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    md_fail('Se requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

$pdo = db();
require_test_database($pdo);
$suffix = bin2hex(random_bytes(4));

// =========================================================================
// FASE 1: Pruebas de contrato con ROLLBACK transaccional (cero residuos)
// =========================================================================
$pdo->beginTransaction();
try {
    // Usuario sin permisos de gestión maestra
    $insUser = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
    $insUser->execute(['__md_noperm_' . $suffix, "__md_noperm_{$suffix}@example.test", password_hash('ClaveSeguraPrueba2026!', PASSWORD_BCRYPT)]);
    $noPermId = (int)$insUser->fetchColumn();
    $insUser->closeCursor();

    $ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?");
    $ctx->execute([$noPermId]);
    $ctx->closeCursor();

    // 1. Verificar rechazo 50501 en los 4 dominios de permisos
    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec("DECLARE @id INT; EXEC dbo.sp_crear_ubicacion @nombre=N'X', @direccion=N'Y', @ubicacion_id=@id OUTPUT;");
    }, 50501, 'sp_crear_ubicacion sin UBICACIONES_GESTIONAR');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec("DECLARE @id INT; EXEC dbo.sp_crear_tipo_mercancia @nombre=N'X', @tipo_mercancia_id=@id OUTPUT;");
    }, 50501, 'sp_crear_tipo_mercancia sin CATALOGOS_GESTIONAR');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec("DECLARE @id INT; EXEC dbo.sp_crear_vehiculo @placa=N'X1', @marca=N'M', @modelo=N'M', @anio=2024, @capacidad_carga_kg=1000, @vehiculo_id=@id OUTPUT;");
    }, 50501, 'sp_crear_vehiculo sin FLOTAS_GESTIONAR');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec("DECLARE @id INT; EXEC dbo.sp_crear_ruta @nombre=N'R', @origen_id=1, @destino_id=2, @distancia_km=10, @duracion_estimada_min=15, @ruta_id=@id OUTPUT;");
    }, 50501, 'sp_crear_ruta sin RUTAS_GESTIONAR');

    // Cambiar a admin_proyecto (usuario_id = 1)
    $ctx->execute([1]);
    $ctx->closeCursor();

    // 2. Registros inexistentes -> 50503
    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec('EXEC dbo.sp_desactivar_ubicacion @ubicacion_id=99999999;');
    }, 50503, 'sp_desactivar_ubicacion inexistente');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=99999999;');
    }, 50503, 'sp_desactivar_vehiculo inexistente');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec('EXEC dbo.sp_desactivar_conductor @conductor_id=99999999;');
    }, 50503, 'sp_desactivar_conductor inexistente');

    md_expect_sql_code(static function () use ($pdo): void {
        $pdo->exec('EXEC dbo.sp_desactivar_ruta @ruta_id=99999999;');
    }, 50503, 'sp_desactivar_ruta inexistente');

    // 3. Kilometraje regresivo -> 50502 y re-desactivación -> 50506
    $vehStmt = $pdo->prepare(
        "DECLARE @id INT;
         EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Hino', @modelo=N'Serie 300', @anio=2024,
              @capacidad_carga_kg=4500.00, @capacidad_volumen_m3=22.000, @kilometraje_actual=5000.0,
              @estado='DISPONIBLE', @vehiculo_id=@id OUTPUT;
         SELECT @id AS vehiculo_id;"
    );
    $vehStmt->execute(['__MDV' . substr($suffix, 0, 6)]);
    $vehId = 0;
    do {
        if ($vehStmt->columnCount() > 0) {
            $r = $vehStmt->fetch();
            if (is_array($r) && isset($r['vehiculo_id'])) {
                $vehId = (int)$r['vehiculo_id'];
            }
        }
    } while ($vehStmt->nextRowset());
    $vehStmt->closeCursor();
    md_assert($vehId > 0, 'Debe crear vehículo en transacción.');

    // Placa duplicada -> 50504
    md_expect_sql_code(static function () use ($pdo, $suffix): void {
        $st = $pdo->prepare("DECLARE @id INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Hino', @modelo=N'300', @anio=2024, @capacidad_carga_kg=4000, @vehiculo_id=@id OUTPUT;");
        $st->execute(['__MDV' . substr($suffix, 0, 6)]);
    }, 50504, 'sp_crear_vehiculo placa duplicada');

    // Kilometraje regresivo -> 50502
    md_expect_sql_code(static function () use ($pdo, $vehId): void {
        $st = $pdo->prepare("EXEC dbo.sp_actualizar_vehiculo @vehiculo_id=?, @marca=N'Hino', @modelo=N'Serie 300', @anio=2024, @capacidad_carga_kg=4500, @capacidad_volumen_m3=22, @kilometraje_actual=4999.9, @estado='DISPONIBLE';");
        $st->execute([$vehId]);
    }, 50502, 'sp_actualizar_vehiculo kilometraje regresivo');

    // Desactivar vehículo y verificar 50506 al intentar re-desactivar o editar
    $desVeh = $pdo->prepare('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;');
    $desVeh->execute([$vehId]);
    $desVeh->closeCursor();

    md_expect_sql_code(static function () use ($pdo, $vehId): void {
        $st = $pdo->prepare('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;');
        $st->execute([$vehId]);
    }, 50506, 'sp_desactivar_vehiculo ya inactivo');

    // 4. Ruta hacia ubicación inactiva -> 50507
    $ubiStmt = $pdo->prepare("DECLARE @u1 INT, @u2 INT; EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=N'Dir 1', @ubicacion_id=@u1 OUTPUT; EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=N'Dir 2', @ubicacion_id=@u2 OUTPUT; EXEC dbo.sp_desactivar_ubicacion @ubicacion_id=@u2; SELECT @u1 AS u1, @u2 AS u2;");
    $ubiStmt->execute(['__UBI1_' . $suffix, '__UBI2_' . $suffix]);
    $u1 = $u2 = 0;
    do {
        if ($ubiStmt->columnCount() > 0) {
            $r = $ubiStmt->fetch();
            if (is_array($r) && isset($r['u1'])) {
                $u1 = (int)$r['u1'];
                $u2 = (int)$r['u2'];
            }
        }
    } while ($ubiStmt->nextRowset());
    $ubiStmt->closeCursor();

    md_expect_sql_code(static function () use ($pdo, $u1, $u2, $suffix): void {
        $st = $pdo->prepare('DECLARE @rid INT; EXEC dbo.sp_crear_ruta @nombre=?, @origen_id=?, @destino_id=?, @distancia_km=15, @duracion_estimada_min=30, @ruta_id=@rid OUTPUT;');
        $st->execute(['__RUTA_' . $suffix, $u1, $u2]);
    }, 50507, 'sp_crear_ruta con destino inactivo');

    // 5. Bloqueo de tipo_incidencia y tipo_documento referenciados en solicitud/incidencia activa -> 50505
    $catStmt = $pdo->prepare(
        "DECLARE @cli INT, @sol INT, @ti INT, @td INT, @inc INT, @doc INT;
         DECLARE @m dbo.tipo_mercancias;
         INSERT INTO @m (tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (1, N'Caja', 10, 0.5);
         EXEC dbo.sp_crear_cliente_fisico @identificacion=?, @nombre=N'Ana', @primer_apellido=N'Mora', @segundo_apellido=NULL, @correo=?, @telefono=NULL, @cliente_id=@cli OUTPUT;
         EXEC dbo.sp_crear_tipo_incidencia @nombre=?, @descripcion=N'Prueba', @tipo_incidencia_id=@ti OUTPUT;
         EXEC dbo.sp_crear_tipo_documento @nombre=?, @descripcion=N'Prueba', @tipo_documento_id=@td OUTPUT;
         DECLARE @f DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());
         EXEC dbo.sp_crear_solicitud @codigo=?, @cliente_id=@cli, @origen_id=1, @destino_id=2, @destinatario_nombre=N'Mario', @destinatario_apellido1=N'Rojas', @destinatario_apellido2=N'Soto', @destinatario_identificacion=NULL, @destinatario_telefono=NULL, @fecha_entrega_requerida=@f, @mercancias=@m, @solicitud_id=@sol OUTPUT;
         EXEC dbo.sp_registrar_incidencia @solicitud_id=@sol, @tipo_incidencia_id=@ti, @descripcion=N'Incidencia abierta', @incidencia_id=@inc OUTPUT;
         EXEC dbo.sp_registrar_documento @solicitud_id=@sol, @tipo_documento_id=@td, @numero_referencia=N'REF-1', @archivo_url=N'https://example.test/doc.pdf', @documento_id=@doc OUTPUT;
         SELECT @ti AS ti, @td AS td;"
    );
    $catStmt->execute([
        '__CLI_CAT_' . $suffix,
        "clicat.{$suffix}@example.test",
        '__TI_' . $suffix,
        '__TD_' . $suffix,
        '__SOL_CAT_' . $suffix,
    ]);
    $tiId = $tdId = 0;
    do {
        if ($catStmt->columnCount() > 0) {
            $r = $catStmt->fetch();
            if (is_array($r) && isset($r['ti'])) {
                $tiId = (int)$r['ti'];
                $tdId = (int)$r['td'];
            }
        }
    } while ($catStmt->nextRowset());
    $catStmt->closeCursor();

    md_expect_sql_code(static function () use ($pdo, $tiId): void {
        $st = $pdo->prepare('EXEC dbo.sp_desactivar_tipo_incidencia @tipo_incidencia_id=?;');
        $st->execute([$tiId]);
    }, 50505, 'sp_desactivar_tipo_incidencia con incidencia abierta');

    md_expect_sql_code(static function () use ($pdo, $tdId): void {
        $st = $pdo->prepare('EXEC dbo.sp_desactivar_tipo_documento @tipo_documento_id=?;');
        $st->execute([$tdId]);
    }, 50505, 'sp_desactivar_tipo_documento en solicitud activa');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// =========================================================================
// FASE 2: Concurrencia real entre 2 conexiones PDO (UPDLOCK, HOLDLOCK)
// =========================================================================
$pdo1 = md_new_pdo();
$pdo2 = md_new_pdo();
$concIds = [
    'viaje_id' => null,
    'ruta_id' => null,
    'veh1_id' => null,
    'veh2_id' => null,
    'cond_id' => null,
    'ubi1_id' => null,
    'ubi2_id' => null,
];

try {
    foreach ([$pdo1, $pdo2] as $conn) {
        $c = $conn->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
        $c->execute();
        $c->closeCursor();
    }

    // Sembrar recursos efímeros aislados en Test
    $seed = $pdo1->prepare(
        "DECLARE @u1 INT, @u2 INT, @r INT, @v1 INT, @v2 INT, @c INT;
         DECLARE @lic DATE = DATEADD(YEAR, 2, CONVERT(DATE, SYSUTCDATETIME()));
         EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=N'Origen Conc', @ubicacion_id=@u1 OUTPUT;
         EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=N'Destino Conc', @ubicacion_id=@u2 OUTPUT;
         EXEC dbo.sp_crear_ruta @nombre=?, @origen_id=@u1, @destino_id=@u2, @distancia_km=18.5, @duracion_estimada_min=35, @ruta_id=@r OUTPUT;
         EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Mack', @modelo=N'Anthem', @anio=2025, @capacidad_carga_kg=12000, @vehiculo_id=@v1 OUTPUT;
         EXEC dbo.sp_crear_vehiculo @placa=?, @marca=N'Mack', @modelo=N'MD6', @anio=2025, @capacidad_carga_kg=8000, @vehiculo_id=@v2 OUTPUT;
         EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=N'Jorge', @primer_apellido=N'Solano', @numero_licencia=?, @fecha_vencimiento_licencia=@lic, @conductor_id=@c OUTPUT;
         SELECT @u1 AS ubi1_id, @u2 AS ubi2_id, @r AS ruta_id, @v1 AS veh1_id, @v2 AS veh2_id, @c AS cond_id;"
    );
    $seed->execute([
        '__CONC_U1_' . $suffix,
        '__CONC_U2_' . $suffix,
        '__CONC_R_' . $suffix,
        '__CV1' . substr($suffix, 0, 5),
        '__CV2' . substr($suffix, 0, 5),
        '__CC_' . $suffix,
        '__CL_' . $suffix,
    ]);
    do {
        if ($seed->columnCount() > 0) {
            $row = $seed->fetch();
            if (is_array($row) && isset($row['veh1_id'])) {
                foreach ($row as $k => $v) {
                    $concIds[$k] = (int)$v;
                }
            }
        }
    } while ($seed->nextRowset());
    $seed->closeCursor();

    $pdo2->exec('SET LOCK_TIMEOUT 400;');
    $futureDeparture = gmdate('Y-m-d H:i:s', time() + 86400);

    // Caso A: Sesión 1 desactiva veh1 en transacción abierta -> Sesión 2 intenta crear viaje con veh1 -> lock timeout (1222); tras commit -> 50302
    $pdo1->beginTransaction();
    $st1 = $pdo1->prepare('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;');
    $st1->execute([$concIds['veh1_id']]);
    $st1->closeCursor();

    md_expect_sql_code(static function () use ($pdo2, $concIds, $suffix, $futureDeparture): void {
        $st2 = $pdo2->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT;');
        $st2->execute(['__VJ_CA_' . $suffix, $concIds['veh1_id'], $concIds['cond_id'], $concIds['ruta_id'], $futureDeparture]);
    }, 1222, 'Concurrencia A: sp_crear_viaje debe bloquearse ante desactivación en curso');

    $pdo1->commit();

    md_expect_sql_code(static function () use ($pdo2, $concIds, $suffix, $futureDeparture): void {
        $st2 = $pdo2->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT;');
        $st2->execute(['__VJ_CA_' . $suffix, $concIds['veh1_id'], $concIds['cond_id'], $concIds['ruta_id'], $futureDeparture]);
    }, 50302, 'Concurrencia A post-commit: sp_crear_viaje debe rechazar vehículo ya desactivado con 50302');

    // Caso B: Sesión 1 crea viaje con veh2 en transacción abierta -> Sesión 2 intenta desactivar veh2 -> lock timeout (1222); tras commit -> 50505
    $pdo1->beginTransaction();
    $vjStmt = $pdo1->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT; SELECT @vid AS viaje_id;');
    $vjStmt->execute(['__VJ_CB_' . $suffix, $concIds['veh2_id'], $concIds['cond_id'], $concIds['ruta_id'], $futureDeparture]);
    do {
        if ($vjStmt->columnCount() > 0) {
            $r = $vjStmt->fetch();
            if (is_array($r) && isset($r['viaje_id'])) {
                $concIds['viaje_id'] = (int)$r['viaje_id'];
            }
        }
    } while ($vjStmt->nextRowset());
    $vjStmt->closeCursor();

    md_expect_sql_code(static function () use ($pdo2, $concIds): void {
        $st2 = $pdo2->prepare('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;');
        $st2->execute([$concIds['veh2_id']]);
    }, 1222, 'Concurrencia B: sp_desactivar_vehiculo debe bloquearse ante asignación de viaje en curso');

    $pdo1->commit();

    md_expect_sql_code(static function () use ($pdo2, $concIds): void {
        $st2 = $pdo2->prepare('EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;');
        $st2->execute([$concIds['veh2_id']]);
    }, 50505, 'Concurrencia B post-commit: sp_desactivar_vehiculo debe rechazar vehículo asignado con 50505');

    fwrite(STDOUT, "OK: pruebas PDO de integración y concurrencia de sql-master-data-contracts completadas en TransportesSegurosSA_Test.\n");
} finally {
    if ($pdo1->inTransaction()) {
        $pdo1->rollBack();
    }
    if ($pdo2->inTransaction()) {
        $pdo2->rollBack();
    }

    require_test_database($pdo);
    if ($concIds['viaje_id'] !== null) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'viajes' AND registro_id = ?")->execute([(string)$concIds['viaje_id']]);
        $pdo->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$concIds['viaje_id']]);
    }
    if ($concIds['ruta_id'] !== null) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'rutas' AND registro_id = ?")->execute([(string)$concIds['ruta_id']]);
        $pdo->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$concIds['ruta_id']]);
    }
    foreach (['veh1_id', 'veh2_id'] as $vk) {
        if ($concIds[$vk] !== null) {
            $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$concIds[$vk]]);
            $pdo->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$concIds[$vk]]);
        }
    }
    if ($concIds['cond_id'] !== null) {
        $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'conductores' AND registro_id = ?")->execute([(string)$concIds['cond_id']]);
        $pdo->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$concIds['cond_id']]);
    }
    foreach (['ubi1_id', 'ubi2_id'] as $uk) {
        if ($concIds[$uk] !== null) {
            $pdo->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$concIds[$uk]]);
            $pdo->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$concIds[$uk]]);
        }
    }
}
