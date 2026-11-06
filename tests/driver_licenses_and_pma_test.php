<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/master_data.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    throw new RuntimeException('Esta prueba requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

$conn = db();
require_test_database($conn);

echo "Iniciando suite de pruebas de licencias y PMA (Ley 9078)...\n";

// Establecer sesión de operador administrativo (usuario_id = 1)
$context = $conn->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?");
$context->execute([1]);
$context->closeCursor();

// 1. Catálogo de licencias oficial (14 categorías)
$catalog = master_license_catalog();
test_assert(count($catalog) === 14, 'El catálogo oficial de licencias debe contener exactamente 14 categorías.');
$codigos = array_column($catalog, 'codigo');
foreach (['A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'D1', 'D2', 'D3', 'E1', 'E2'] as $reqCode) {
    test_assert(in_array($reqCode, $codigos, true), "La categoría {$reqCode} debe existir en el catálogo.");
}
echo "✓ Paso 1: Catálogo oficial de 14 categorías verificado con éxito.\n";

$conn->beginTransaction();
try {
    $p1 = 'P' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $p2 = 'P' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $p3 = 'P' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $idCond = 'C' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $licCond = 'L' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $suffix = bin2hex(random_bytes(4));

    // 2. Crear vehículo de prueba con PMA 3.500 kg (Liviano)
    $stmtVeh1 = $conn->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=NULL, @kilometraje_actual=100.0, @estado=N\'DISPONIBLE\', @pma_kg=3500.00, @clase_legal=N\'LIVIANO\', @es_articulado=0, @estado_homologacion=N\'VERIFICADO\', @vehiculo_id=@vid OUTPUT; SELECT @vid AS vid;');
    $stmtVeh1->execute([$p1, 'Toyota', 'Hilux', 2023, '1200.00']);
    $veh1Id = (int)$stmtVeh1->fetch(PDO::FETCH_ASSOC)['vid'];
    $stmtVeh1->closeCursor();
    test_assert($veh1Id > 0, 'No se pudo crear el vehículo liviano.');

    // 3. Crear vehículo de prueba con PMA 7.500 kg (Mediano Rígido)
    $stmtVeh2 = $conn->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=NULL, @kilometraje_actual=200.0, @estado=N\'DISPONIBLE\', @pma_kg=7500.00, @clase_legal=N\'MEDIANO_RIGIDO\', @es_articulado=0, @estado_homologacion=N\'VERIFICADO\', @vehiculo_id=@vid OUTPUT; SELECT @vid AS vid;');
    $stmtVeh2->execute([$p2, 'Isuzu', 'Forward', 2022, '4500.00']);
    $veh2Id = (int)$stmtVeh2->fetch(PDO::FETCH_ASSOC)['vid'];
    $stmtVeh2->closeCursor();
    test_assert($veh2Id > 0, 'No se pudo crear el vehículo mediano.');

    // 4. Crear vehículo de prueba articulado PMA 28.000 kg (Pesado Articulado)
    $stmtVeh3 = $conn->prepare('DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=NULL, @kilometraje_actual=500.0, @estado=N\'DISPONIBLE\', @pma_kg=28000.00, @clase_legal=N\'PESADO_ARTICULADO\', @es_articulado=1, @estado_homologacion=N\'VERIFICADO\', @vehiculo_id=@vid OUTPUT; SELECT @vid AS vid;');
    $stmtVeh3->execute([$p3, 'Freightliner', 'Cascadia', 2024, '20000.00']);
    $veh3Id = (int)$stmtVeh3->fetch(PDO::FETCH_ASSOC)['vid'];
    $stmtVeh3->closeCursor();
    test_assert($veh3Id > 0, 'No se pudo crear el vehículo articulado.');

    echo "✓ Paso 2: Creación de vehículos con PMA, clase legal, condición articulada y homologación verificada.\n";

    // 5. Crear conductor con solo licencia B1
    $stmtCond1 = $conn->prepare('DECLARE @cid INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=NULL, @telefono=NULL, @correo=NULL, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=N\'DISPONIBLE\', @usuario_id=NULL, @codigo_licencia_inicial=N\'B1\', @conductor_id=@cid OUTPUT; SELECT @cid AS cid;');
    $stmtCond1->execute([$idCond, 'Carlos', 'Gómez', $licCond, '2029-12-31']);
    $cond1Id = (int)$stmtCond1->fetch(PDO::FETCH_ASSOC)['cid'];
    $stmtCond1->closeCursor();
    test_assert($cond1Id > 0, 'No se pudo crear el conductor de prueba.');

    // Asignar categoría B1 verificada
    $stmtLicB1 = $conn->prepare('EXEC dbo.sp_asignar_licencia_conductor @conductor_id=?, @codigo_licencia=N\'B1\', @numero_licencia=?, @fecha_vencimiento=?, @estado_verificacion=N\'VERIFICADA\';');
    $stmtLicB1->execute([$cond1Id, $licCond, '2029-12-31']);
    $stmtLicB1->closeCursor();

    // 6. Validar fn_conductor_habilitado_vehiculo con conductor B1:
    // a) En veh1 (PMA 3.500 kg): debe ser HABILITADO
    $stmtCheck1 = $conn->prepare('SELECT es_habilitado, motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(?, ?, ?);');
    $stmtCheck1->execute([$cond1Id, $veh1Id, '2027-01-01']);
    $res1 = $stmtCheck1->fetch(PDO::FETCH_ASSOC);
    $stmtCheck1->closeCursor();
    test_assert((int)$res1['es_habilitado'] === 1, 'Conductor con B1 debe estar habilitado para vehículo de 3.500 kg.');

    // b) En veh2 (PMA 7.500 kg): debe ser NO HABILITADO
    $stmtCheck2 = $conn->prepare('SELECT es_habilitado, motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(?, ?, ?);');
    $stmtCheck2->execute([$cond1Id, $veh2Id, '2027-01-01']);
    $res2 = $stmtCheck2->fetch(PDO::FETCH_ASSOC);
    $stmtCheck2->closeCursor();
    test_assert((int)$res2['es_habilitado'] === 0, 'Conductor con B1 NO debe estar habilitado para vehículo de 7.500 kg.');
    test_assert(strpos($res2['motivo_diagnostico'], '4.000 kg') !== false, 'El motivo de rechazo debe mencionar el límite legal de 4.000 kg.');

    // c) En veh3 (Articulado): debe ser NO HABILITADO
    $stmtCheck3 = $conn->prepare('SELECT es_habilitado, motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(?, ?, ?);');
    $stmtCheck3->execute([$cond1Id, $veh3Id, '2027-01-01']);
    $res3 = $stmtCheck3->fetch(PDO::FETCH_ASSOC);
    $stmtCheck3->closeCursor();
    test_assert((int)$res3['es_habilitado'] === 0, 'Conductor con B1 NO debe estar habilitado para articulado.');
    test_assert(stripos($res3['motivo_diagnostico'], 'articulad') !== false, 'El motivo de rechazo debe mencionar que es articulado.');

    echo "✓ Paso 3: Función autoritativa fn_conductor_habilitado_vehiculo validada para B1, PMA y articulado.\n";

    // 7. Rechazo transaccional en dbo.sp_crear_viaje (Error 50320)
    $dateFuture = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $rejectedTrip = false;
    try {
        $stmtBadTrip = $conn->prepare('DECLARE @viaje_id INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@viaje_id OUTPUT;');
        $stmtBadTrip->execute(['VIA-BAD-' . $suffix, $veh2Id, $cond1Id, 1, $dateFuture]);
        $stmtBadTrip->closeCursor();
    } catch (PDOException $ex) {
        if ((int)($ex->errorInfo[1] ?? 0) === 50320) {
            $rejectedTrip = true;
        } else {
            throw $ex;
        }
    }
    test_assert($rejectedTrip, 'sp_crear_viaje debe arrojar error 50320 si el conductor no tiene categoría legal suficiente.');

    echo "✓ Paso 4: Error 50320 disparado correctamente ante incompatibilidad de licencia.\n";

    // 8. Asignar categoría B4 al conductor y comprobar que ahora sí puede operar el articulado
    $stmtLicB4 = $conn->prepare('EXEC dbo.sp_asignar_licencia_conductor @conductor_id=?, @codigo_licencia=N\'B4\', @numero_licencia=?, @fecha_vencimiento=?, @estado_verificacion=N\'VERIFICADA\';');
    $stmtLicB4->execute([$cond1Id, 'LIC-' . $suffix, '2029-12-31']);
    $stmtLicB4->closeCursor();

    $stmtCheck4 = $conn->prepare('SELECT es_habilitado, motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(?, ?, ?);');
    $stmtCheck4->execute([$cond1Id, $veh3Id, '2027-01-01']);
    $res4 = $stmtCheck4->fetch(PDO::FETCH_ASSOC);
    $stmtCheck4->closeCursor();
    test_assert((int)$res4['es_habilitado'] === 1, 'Conductor con B4 debe estar habilitado para vehículo articulado.');

    // 9. Crear viaje con éxito ahora que está habilitado
    $stmtGoodTrip = $conn->prepare('DECLARE @viaje_id INT; EXEC dbo.sp_crear_viaje @codigo=?, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@viaje_id OUTPUT; SELECT @viaje_id AS viaje_id;');
    $stmtGoodTrip->execute(['VIA-OK-' . $suffix, $veh3Id, $cond1Id, 1, $dateFuture]);
    $goodTripId = (int)$stmtGoodTrip->fetch(PDO::FETCH_ASSOC)['viaje_id'];
    $stmtGoodTrip->closeCursor();
    test_assert($goodTripId > 0, 'El viaje debe crearse exitosamente con conductor legalmente habilitado.');

    echo "✓ Paso 5: Creación exitosa de viaje con conductor B4 y vehículo articulado validada.\n";

    $conn->rollBack();
    echo "✓ Transacción de prueba revertida limpiamente.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $e;
}

echo "SUITE COMPLETADA: Todas las pruebas de licencias y PMA pasaron al 100%.\n";
