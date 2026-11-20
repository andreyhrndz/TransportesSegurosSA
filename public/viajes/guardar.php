<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('VIAJES_CREAR');

try {
    $vehiculo = operation_id($_POST['vehiculo_id'] ?? null);
    $conductor = operation_id($_POST['conductor_id'] ?? null);
    $fecha = operation_future_datetime($_POST['fecha_programada'] ?? null);
    $rawInitialSol = trim((string)($_POST['solicitud_inicial_id'] ?? ''));
    $solicitudInicial = $rawInitialSol !== '' ? operation_id($rawInitialSol) : null;
    $autoCrearRuta = ((string)($_POST['auto_crear_ruta'] ?? '') === '1');
    $ruta = null;

    if ($autoCrearRuta) {
        if ($solicitudInicial === null) {
            throw new InvalidArgumentException('Selecciona una solicitud inicial para generar la ruta automáticamente.');
        }
        if (!has_permission('RUTAS_GESTIONAR')) {
            operation_error('No tienes permiso para crear rutas automáticamente.', 403);
        }
    } else {
        $ruta = operation_id($_POST['ruta_id'] ?? null);
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    if ($autoCrearRuta && $solicitudInicial !== null) {
        $routeStmt = db()->prepare('DECLARE @ruta_id INT; EXEC dbo.sp_resolver_ruta_desde_solicitud @solicitud_id=?, @ruta_id=@ruta_id OUTPUT; SELECT @ruta_id AS ruta_id;');
        $routeStmt->execute([$solicitudInicial]);
        do {
            if ($routeStmt->columnCount() > 0) {
                $rRow = $routeStmt->fetch();
                if (is_array($rRow) && isset($rRow['ruta_id'])) {
                    $ruta = (int)$rRow['ruta_id'];
                }
            }
        } while ($routeStmt->nextRowset());
        $routeStmt->closeCursor();

        if ($ruta === null || $ruta < 1) {
            throw new RuntimeException('No se pudo resolver la ruta automática para la solicitud.');
        }
    }

    // El SP dbo.sp_crear_viaje autogenera el código mediante dbo.seq_codigo_viaje al pasar @codigo = NULL
    $sql = 'DECLARE @viaje_id INT; EXEC dbo.sp_crear_viaje @codigo=NULL, @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@viaje_id OUTPUT; SELECT @viaje_id AS viaje_id;';
    $stmt = db()->prepare($sql);
    $stmt->execute([$vehiculo, $conductor, $ruta, $fecha]);
    $id = null;
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['viaje_id'])) {
                $id = (int)$row['viaje_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    if ($id === null || $id < 1) {
        throw new RuntimeException('No se recibió el identificador del viaje creado.');
    }
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50302 => 'El vehículo ya no está disponible.',
        50303 => 'El conductor no está disponible o su licencia no es válida.',
        50304 => 'La ruta no está activa.',
        50305 => 'La fecha programada no es válida.',
        50320 => 'El conductor seleccionado no está habilitado legalmente para este vehículo (compruebe categoría de licencia por PMA, articulado o vigencia según Ley 9078).',
    ]);
} catch (RuntimeException) {
    operation_error('No se pudo crear el viaje. Inténtalo nuevamente.', 500);
}

$msg = $autoCrearRuta ? 'Ruta generada y viaje creado correctamente.' : 'Viaje creado correctamente.';
if ($solicitudInicial !== null && has_permission('VIAJES_ASIGNAR') && has_permission('VIAJES_PARADAS')) {
    try {
        $assignStmt = db()->prepare(
            'DECLARE @viaje_solicitud_id INT, @paradas_creadas INT; EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id=?,@solicitud_id=?,@parada_recogida_id=NULL,@parada_entrega_id=NULL,@auto_crear_paradas=1,@viaje_solicitud_id=@viaje_solicitud_id OUTPUT,@paradas_creadas=@paradas_creadas OUTPUT;'
        );
        $assignStmt->execute([$id, $solicitudInicial]);
        $assignStmt->closeCursor();
        $msg = $autoCrearRuta
            ? 'Ruta exacta generada, viaje creado, paradas armadas y solicitud asignada en un solo paso.'
            : 'Viaje creado, paradas generadas y solicitud asignada en un solo paso.';
    } catch (PDOException) {
        $msg = 'Viaje creado correctamente, pero no se pudo asignar la solicitud inicial (verifica capacidad o estado).';
    }
}

operation_success($msg, 'viajeCreado', '/viajes/detalle.php?id=' . $id);
