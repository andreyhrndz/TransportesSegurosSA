<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('VIAJES_PARADAS');

try {
    $viajeId = operation_id($_POST['viaje_id'] ?? null);
    $paradaId = operation_id($_POST['parada_id'] ?? null);
    $ubicacionId = operation_id($_POST['ubicacion_id'] ?? null);
    $tipo = strtoupper(trim((string)($_POST['tipo_parada'] ?? '')));
    if (!in_array($tipo, ['RECOGIDA', 'ENTREGA', 'MIXTA', 'INTERMEDIA'], true)) {
        throw new InvalidArgumentException('Selecciona un tipo de parada válido.');
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage(), 422);
}

try {
    $stmt = db()->prepare('EXEC dbo.sp_editar_parada_viaje @viaje_id = ?, @parada_id = ?, @ubicacion_id = ?, @tipo_parada = ?;');
    $stmt->execute([$viajeId, $paradaId, $ubicacionId, $tipo]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50306 => 'No tienes permiso para editar paradas del viaje.',
        50307 => 'Solo se pueden editar paradas de viajes en estado Planificado.',
        50334 => 'El tipo de parada seleccionado no es válido.',
        50335 => 'La parada indicada no pertenece a este viaje.',
        50336 => 'No se puede editar la ubicación ni el tipo de una parada vinculada a una solicitud asignada.',
        50337 => 'La ubicación seleccionada no está activa o no está disponible para este viaje.',
    ]);
}

operation_success('Parada actualizada correctamente.', 'viajeActualizado', '/viajes/detalle.php?id=' . $viajeId);
