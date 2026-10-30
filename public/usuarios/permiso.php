<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/user_security.php';

// ASVS 3.5.1, 8.2.1: exige USUARIOS_GESTIONAR_PERMISOS, método POST y CSRF válido.
$currentUser = require_login();
require_operation_post('USUARIOS_GESTIONAR_PERMISOS');

try {
    $usuarioId = operation_id($_POST['usuario_id'] ?? null);
    $codigo = validate_permission_code($_POST['codigo'] ?? null);
    $accion = strtolower(trim((string)($_POST['accion'] ?? '')));
    if (!in_array($accion, ['conceder', 'revocar'], true)) {
        throw new InvalidArgumentException('Selecciona una acción válida sobre el permiso.');
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

$isGrant = $accion === 'conceder';
if (!$isGrant && (int)$currentUser['usuario_id'] === $usuarioId && is_critical_admin_permission($codigo)) {
    operation_error('No puedes revocar tus propios permisos administrativos críticos.', 409);
}

try {
    if ($isGrant) {
        $stmt = db()->prepare('EXEC dbo.sp_conceder_permiso @usuario_destino=?, @codigo=?;');
    } else {
        $stmt = db()->prepare('EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=?, @codigo=?;');
    }
    $stmt->execute([$usuarioId, $codigo]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50407 => 'No tienes permiso para conceder permisos individuales.',
        50408 => 'El usuario o el código de permiso indicado no es válido o está inactivo.',
        50409 => 'El usuario ya tiene asignado ese permiso.',
        50410 => 'No tienes permiso para revocar permisos individuales.',
        50411 => 'El código de permiso indicado no existe.',
        50415 => 'El usuario solicitado no existe.',
        50419 => 'No puedes revocar tus propios permisos administrativos críticos.',
        50420 => 'No se puede revocar el último titular activo de un permiso administrativo crítico.',
    ]);
}

$message = $isGrant
    ? 'Permiso ' . $codigo . ' asignado correctamente.'
    : 'Permiso ' . $codigo . ' revocado correctamente.';
operation_modal_success($message, 'permisoActualizado', '/usuarios/detalle.php?id=' . $usuarioId);
