<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('VIAJES_PARADAS');

try {
    $viajeId = operation_id($_POST['viaje_id'] ?? null);
    $rawIds = $_POST['orden_paradas'] ?? null;
    if (!is_array($rawIds) || count($rawIds) < 2 || count($rawIds) > 200) {
        throw new InvalidArgumentException('Envía al menos dos paradas válidas para reordenar el itinerario.');
    }

    $orderedIds = [];
    $seen = [];
    foreach (array_values($rawIds) as $rawId) {
        $paradaId = operation_id($rawId);
        if (isset($seen[$paradaId])) {
            throw new InvalidArgumentException('No se permiten paradas duplicadas en la secuencia.');
        }
        $seen[$paradaId] = true;
        $orderedIds[] = $paradaId;
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage(), 422);
}

try {
    $placeholders = implode(',', array_fill(0, count($orderedIds), '(?,?)'));
    $sql = 'DECLARE @paradas dbo.tipo_orden_paradas; '
        . 'INSERT INTO @paradas (parada_id, orden) VALUES ' . $placeholders . '; '
        . 'EXEC dbo.sp_reordenar_paradas_viaje @viaje_id = ?, @paradas = @paradas;';

    $params = [];
    foreach ($orderedIds as $idx => $paradaId) {
        $params[] = $paradaId;
        $params[] = $idx + 1;
    }
    $params[] = $viajeId;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50306 => 'No tienes permiso para gestionar paradas del viaje.',
        50307 => 'Solo se pueden reordenar paradas de viajes en estado Planificado.',
        50331 => 'El listado de paradas cambió o no coincide con el viaje actual. Recarga la página e inténtalo de nuevo.',
        50332 => 'La secuencia de órdenes debe ser consecutiva desde el 1 hasta el total de paradas.',
        50333 => 'No se puede guardar este orden porque deja una entrega antes o en la misma posición que su recogida.',
    ]);
}

operation_success('Orden de paradas actualizado correctamente.', 'viajeActualizado', '/viajes/detalle.php?id=' . $viajeId);
