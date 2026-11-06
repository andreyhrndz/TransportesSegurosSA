<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/master_data.php';

try {
    $entity = strtolower(trim((string)($_POST['entidad'] ?? '')));
    $meta = master_entity_meta($entity);
} catch (InvalidArgumentException $exception) {
    require_login();
    operation_error($exception->getMessage(), 422);
}

// ASVS 3.5.1, 8.2.1: exige permiso individual específico, método POST y CSRF válido.
require_operation_post($meta['write_permission']);

try {
    $id = operation_id($_POST['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

$sqlMap = [
    'vehiculo' => 'EXEC dbo.sp_desactivar_vehiculo @vehiculo_id=?;',
    'conductor' => 'EXEC dbo.sp_desactivar_conductor @conductor_id=?;',
    'ruta' => 'EXEC dbo.sp_desactivar_ruta @ruta_id=?;',
    'ubicacion' => 'EXEC dbo.sp_desactivar_ubicacion @ubicacion_id=?;',
    'tipo_mercancia' => 'EXEC dbo.sp_desactivar_tipo_mercancia @tipo_mercancia_id=?;',
    'tipo_incidencia' => 'EXEC dbo.sp_desactivar_tipo_incidencia @tipo_incidencia_id=?;',
    'tipo_documento' => 'EXEC dbo.sp_desactivar_tipo_documento @tipo_documento_id=?;',
];

try {
    $stmt = db()->prepare($sqlMap[$entity]);
    $stmt->execute([$id]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50501 => 'No tienes permiso para desactivar este registro.',
        50502 => 'Selecciona un registro válido.',
        50503 => 'El registro solicitado no existe.',
        50505 => 'No se puede desactivar porque está comprometido en viajes activos, rutas activas, solicitudes abiertas o incidencias pendientes.',
        50506 => 'El registro ya se encuentra inactivo.',
    ]);
}

$label = utf8_ucfirst($meta['label']);
operation_modal_success("{$label} desactivado correctamente.", 'maestroActualizado', '/flota.php?seccion=' . $meta['section']);
