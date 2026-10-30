<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

// ASVS 3.5.1, 8.2.1: requiere USUARIOS_GESTIONAR_CUENTAS, método POST y CSRF válido.
$currentUser = require_login();
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

try {
    $usuarioId = operation_id($_POST['usuario_id'] ?? null);
    $accion = strtolower(trim((string)($_POST['accion'] ?? '')));
    if (!in_array($accion, ['activar', 'desactivar'], true)) {
        throw new InvalidArgumentException('Selecciona una acción válida sobre la cuenta.');
    }
    $activo = $accion === 'activar' ? 1 : 0;
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

if ($activo === 0 && (int)$currentUser['usuario_id'] === $usuarioId) {
    operation_error('No puedes desactivar tu propia cuenta activa.', 409);
}

try {
    $stmt = db()->prepare('EXEC dbo.sp_cambiar_estado_usuario @usuario_id=?, @activo=?;');
    $stmt->execute([$usuarioId, $activo]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50412 => 'No tienes permiso para gestionar cuentas de usuario.',
        50413 => 'Selecciona un estado válido para la cuenta.',
        50415 => 'El usuario solicitado no existe.',
        50417 => 'No puedes desactivar tu propia cuenta activa.',
        50418 => 'No se puede desactivar el último gestor administrativo activo del sistema.',
    ]);
}

$message = $activo === 1 ? 'Cuenta de usuario activada correctamente.' : 'Cuenta de usuario desactivada correctamente.';
operation_modal_success($message, 'usuarioActualizado', '/usuarios/detalle.php?id=' . $usuarioId);
