<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/user_security.php';

// ASVS 3.5.1, 8.2.1: exige USUARIOS_GESTIONAR_CUENTAS, método POST y CSRF válido.
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$idParam = $_POST['usuario_id'] ?? null;
$isEdit = $idParam !== null && $idParam !== '';

try {
    $nombreUsuario = validate_username($_POST['nombre_usuario'] ?? null);
    $correoAcceso = (string)operation_email($_POST['correo_acceso'] ?? null, 150);

    if ($isEdit) {
        $usuarioId = operation_id($idParam);
        $sql = 'EXEC dbo.sp_actualizar_usuario @usuario_id=?, @nombre_usuario=?, @correo_acceso=?;';
        $params = [$usuarioId, $nombreUsuario, $correoAcceso];
    } else {
        // ASVS 6.2.1-6.2.8: genera hash bcrypt compatible con password_verify(); nunca persiste ni registra el texto plano.
        $password = (string)($_POST['password'] ?? '');
        $confirmacion = (string)($_POST['password_confirmacion'] ?? '');
        $passwordHash = validate_new_password_hash($password, $confirmacion, $nombreUsuario, $correoAcceso);
        $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_usuario @nombre_usuario=?, @correo_acceso=?, @password_hash=?, @usuario_id=@nuevo_id OUTPUT; SELECT @nuevo_id AS usuario_id;';
        $params = [$nombreUsuario, $correoAcceso, $passwordHash];
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $targetId = $isEdit ? $usuarioId : 0;
    if (!$isEdit) {
        do {
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['usuario_id'])) {
                    $targetId = (int)$row['usuario_id'];
                }
            }
        } while ($stmt->nextRowset());
    }
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50412 => 'No tienes permiso para gestionar cuentas de usuario.',
        50413 => 'Revisa el nombre de usuario y el correo de acceso.',
        50414 => 'No se pudo procesar la contraseña de forma segura.',
        50415 => 'El usuario solicitado no existe.',
        50416 => 'El nombre de usuario o el correo de acceso ya está registrado.',
    ]);
}

$redirect = $targetId > 0 ? '/usuarios/detalle.php?id=' . $targetId : '/usuarios.php';
$message = $isEdit ? 'Cuenta de usuario actualizada correctamente.' : 'Usuario creado correctamente.';
operation_modal_success($message, 'usuarioActualizado', $redirect);
