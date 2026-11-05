<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('VIAJES_CREAR');

try {
    $vehiculo = operation_id($_POST['vehiculo_id'] ?? null);
    $conductor = operation_id($_POST['conductor_id'] ?? null);
    $ruta = operation_id($_POST['ruta_id'] ?? null);
    $fecha = operation_future_datetime($_POST['fecha_programada'] ?? null);
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
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
    ]);
} catch (RuntimeException) {
    operation_error('No se pudo crear el viaje. Inténtalo nuevamente.', 500);
}

operation_success('Viaje creado correctamente.', 'viajeCreado', '/viajes/detalle.php?id=' . $id);
