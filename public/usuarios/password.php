<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/user_security.php';

// ASVS 3.5.1, 6.2.1-6.2.8, 8.2.1: exige USUARIOS_GESTIONAR_CUENTAS, método POST y CSRF válido.
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

try {
    $usuarioId = operation_id($_POST['usuario_id'] ?? null);
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

$targetUsername = '';
$targetEmail = '';
if (has_permission('USUARIOS_VER')) {
    try {
        $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$usuarioId]);
        $targetUser = $sets[0][0] ?? null;
        if (!is_array($targetUser)) {
            operation_error('El usuario solicitado no existe.', 404);
        }
        $targetUsername = (string)($targetUser['nombre_usuario'] ?? '');
        $targetEmail = (string)($targetUser['correo_acceso'] ?? '');
    } catch (PDOException $exception) {
        if (read_is_missing_record($exception)) {
            operation_error('El usuario solicitado no existe.', 404);
        }
    }
}

try {
    $password = (string)($_POST['password'] ?? '');
    $confirmacion = (string)($_POST['password_confirmacion'] ?? '');
    $passwordHash = validate_new_password_hash($password, $confirmacion, $targetUsername, $targetEmail);
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    $stmt = db()->prepare('EXEC dbo.sp_restablecer_password @usuario_id=?, @password_hash=?;');
    $stmt->execute([$usuarioId, $passwordHash]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50412 => 'No tienes permiso para restablecer contraseñas.',
        50413 => 'Selecciona un usuario válido.',
        50414 => 'No se pudo procesar la contraseña de forma segura.',
        50415 => 'El usuario solicitado no existe.',
    ]);
}

operation_modal_success('Contraseña restablecida correctamente.', 'usuarioActualizado', '/usuarios/detalle.php?id=' . $usuarioId);
