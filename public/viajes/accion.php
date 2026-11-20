<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/dispatch_advisor.php';

$action = (string)($_POST['accion'] ?? '');
$permissions = [
    'parada' => 'VIAJES_PARADAS',
    'eliminar_parada' => 'VIAJES_PARADAS',
    'asignar' => 'VIAJES_ASIGNAR',
    'asignar_lote' => 'VIAJES_ASIGNAR',
    'desasignar' => 'VIAJES_ASIGNAR',
    'iniciar' => 'VIAJES_INICIAR',
    'finalizar' => 'VIAJES_FINALIZAR',
    'cancelar' => 'VIAJES_CANCELAR',
];
if (!isset($permissions[$action])) {
    operation_error('Acción no disponible.', 404);
}
require_operation_post($permissions[$action]);
if ($action === 'asignar_lote') {
    require_permission('VIAJES_PARADAS');
}

try {
    $id = operation_id($_POST['viaje_id'] ?? null);
    if ($action === 'parada') {
        $location = operation_id($_POST['ubicacion_id'] ?? null);
        $order = operation_id($_POST['orden'] ?? null);
        $type = strtoupper((string)($_POST['tipo_parada'] ?? ''));
        if (!in_array($type, ['RECOGIDA', 'ENTREGA', 'MIXTA', 'INTERMEDIA'], true)) {
            throw new InvalidArgumentException('Selecciona un tipo de parada válido.');
        }
        $sql = 'DECLARE @parada_id INT; EXEC dbo.sp_agregar_parada @viaje_id=?,@ubicacion_id=?,@orden=?,@tipo_parada=?,@parada_id=@parada_id OUTPUT;';
        $params = [$id, $location, $order, $type];
        $success = 'Parada agregada correctamente.';
    } elseif ($action === 'eliminar_parada') {
        $paradaId = operation_id($_POST['parada_id'] ?? null);
        $sql = 'EXEC dbo.sp_eliminar_parada_viaje @viaje_id=?,@parada_id=?;';
        $params = [$id, $paradaId];
        $success = 'Parada eliminada y secuencia actualizada.';
    } elseif ($action === 'desasignar') {
        $solicitud = operation_id($_POST['solicitud_id'] ?? null);
        $sql = 'EXEC dbo.sp_desasignar_solicitud_viaje @viaje_id=?,@solicitud_id=?;';
        $params = [$id, $solicitud];
        $success = 'Solicitud desasignada y devuelta a pendiente de asignación.';
    } elseif ($action === 'asignar_lote') {
        $rawList = $_POST['solicitudes'] ?? [];
        if (!is_array($rawList) || !$rawList) {
            throw new InvalidArgumentException('Selecciona al menos una solicitud para asignar al viaje.');
        }
        $solIds = [];
        foreach ($rawList as $item) {
            $sid = operation_id($item);
            $solIds[$sid] = $sid;
        }
        if (count($solIds) > 30) {
            throw new InvalidArgumentException('Puedes asignar un máximo de 30 solicitudes por lote.');
        }
        $optimizarOrden = ((string)($_POST['optimizar_orden'] ?? '') === '1');
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmtBatch = $pdo->prepare(
                'DECLARE @viaje_solicitud_id INT, @paradas_creadas INT; EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id=?,@solicitud_id=?,@parada_recogida_id=NULL,@parada_entrega_id=NULL,@auto_crear_paradas=1,@viaje_solicitud_id=@viaje_solicitud_id OUTPUT,@paradas_creadas=@paradas_creadas OUTPUT;'
            );
            foreach ($solIds as $sid) {
                $stmtBatch->execute([$id, $sid]);
                $stmtBatch->closeCursor();
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            operation_sql_error($exception, [
                50306 => 'No tienes permiso para crear paradas automáticamente en el viaje.',
                50309 => 'El viaje no existe o ya inició.',
                50310 => 'Una de las solicitudes seleccionadas ya no está disponible.',
                50311 => 'Una de las solicitudes ya fue asignada a otro viaje.',
                50313 => 'El lote seleccionado excede la capacidad de peso del vehículo (se revirtieron todos los cambios).',
                50314 => 'El lote seleccionado excede la capacidad de volumen del vehículo (se revirtieron todos los cambios).',
                50315 => 'Una de las solicitudes no tiene mercancías registradas.',
            ]);
        }

        $optimizado = false;
        if ($optimizarOrden) {
            try {
                $optimizado = dispatch_optimize_trip_stops($pdo, $id);
            } catch (PDOException) {
                $optimizado = false;
            }
        }

        $n = count($solIds);
        $msgLote = "Se asignaron {$n} solicitud" . ($n === 1 ? '' : 'es') . ' al viaje y se actualizaron sus paradas automáticamente';
        $msgLote .= $optimizado ? ' aplicando Ruta inteligente (orden óptimo Haversine).' : '.';
        operation_success($msgLote, 'viajeActualizado', '/viajes/detalle.php?id=' . $id);
    } elseif ($action === 'asignar') {
        $solicitud = operation_id($_POST['solicitud_id'] ?? null);
        $autoStops = ((string)($_POST['auto_crear_paradas'] ?? '') === '1');
        $rawPickup = trim((string)($_POST['parada_recogida_id'] ?? ''));
        $rawDelivery = trim((string)($_POST['parada_entrega_id'] ?? ''));
        $pickup = $rawPickup !== '' ? operation_id($rawPickup) : null;
        $delivery = $rawDelivery !== '' ? operation_id($rawDelivery) : null;

        if ($pickup !== null && $delivery !== null && $pickup === $delivery) {
            throw new InvalidArgumentException('Las paradas de recogida y entrega deben ser distintas.');
        }

        if ($autoStops || $pickup === null || $delivery === null) {
            if (!$autoStops) {
                throw new InvalidArgumentException('Selecciona paradas de recogida y entrega válidas o activa la creación automática.');
            }
            $sql = 'DECLARE @viaje_solicitud_id INT, @paradas_creadas INT; EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id=?,@solicitud_id=?,@parada_recogida_id=?,@parada_entrega_id=?,@auto_crear_paradas=1,@viaje_solicitud_id=@viaje_solicitud_id OUTPUT,@paradas_creadas=@paradas_creadas OUTPUT;';
            $params = [$id, $solicitud, $pickup, $delivery];
            $success = 'Solicitud asignada correctamente (itinerario actualizado).';
        } else {
            $sql = 'DECLARE @viaje_solicitud_id INT; EXEC dbo.sp_asignar_solicitud_viaje @viaje_id=?,@solicitud_id=?,@parada_recogida_id=?,@parada_entrega_id=?,@viaje_solicitud_id=@viaje_solicitud_id OUTPUT;';
            $params = [$id, $solicitud, $pickup, $delivery];
            $success = 'Solicitud asignada correctamente.';
        }
    } elseif ($action === 'cancelar') {
        $motivo = operation_text($_POST['motivo'] ?? null, 500, false);
        $sql = 'EXEC dbo.sp_cancelar_viaje @viaje_id=?,@motivo=?;';
        $params = [$id, $motivo];
        $success = 'Viaje cancelado correctamente y recursos liberados.';
    } else {
        $sql = $action === 'iniciar' ? 'EXEC dbo.sp_iniciar_viaje @viaje_id=?;' : 'EXEC dbo.sp_finalizar_viaje @viaje_id=?;';
        $params = [$id];
        $success = $action === 'iniciar' ? 'Viaje iniciado correctamente.' : 'Viaje finalizado correctamente.';
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50306 => 'No tienes permiso para crear paradas automáticamente en el viaje.',
        50307 => 'El viaje ya no está en planificación.',
        50309 => 'El viaje no existe o ya inició.',
        50310 => 'La solicitud no está disponible.',
        50311 => 'La solicitud ya está asignada a otro viaje.',
        50312 => 'Las paradas no corresponden a la solicitud.',
        50313 => 'La capacidad de peso es insuficiente.',
        50314 => 'La capacidad de volumen es insuficiente.',
        50315 => 'La solicitud no tiene mercancías.',
        50317 => 'El viaje ya no está planificado.',
        50318 => 'No se puede iniciar un viaje sin solicitudes.',
        50321 => 'El conductor asignado ya no está habilitado legalmente para este vehículo (licencia vencida o categoría insuficiente).',
        50326 => 'El viaje ya no está en curso.',
        50327 => 'Aún hay entregas pendientes.',
        50328 => 'No tiene permiso para cancelar viajes.',
        50329 => 'El viaje especificado no existe.',
        50330 => 'Solo se pueden cancelar viajes en estado PLANIFICADO.',
        50338 => 'No se puede eliminar una parada vinculada a una solicitud asignada.',
    ]);
}

operation_success($success, 'viajeActualizado', '/viajes/detalle.php?id=' . $id);
