<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/user_security.php';
require_once __DIR__ . '/../../app/components.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /auth/login.php');
    exit;
}

$error = null;
$tokenRaw = trim((string)($_POST['token'] ?? ''));

if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    $error = 'La sesión del formulario caducó o es inválida. Vuelve a intentarlo.';
} elseif ($tokenRaw === '' || strlen($tokenRaw) !== 64 || !ctype_xdigit($tokenRaw)) {
    $error = 'El token de activación no es válido.';
}

$email = '';
$tokenHash = '';
if ($error === null) {
    try {
        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = db()->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
        $stmt->execute([$tokenHash]);
        $inv = $stmt->fetch();
        $stmt->closeCursor();

        $resultado = (string)($inv['resultado'] ?? 'invalido');
        if ($resultado !== 'valida') {
            $error = 'El enlace de invitación ya no es válido o ha expirado.';
        } else {
            $email = (string)($inv['correo_destino'] ?? '');
        }
    } catch (PDOException $e) {
        $error = 'Error de conexión al validar la invitación.';
    }
}

$targetUserId = 0;
if ($error === null) {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $primerApellido = trim((string)($_POST['primer_apellido'] ?? ''));
    $segundoApellido = trim((string)($_POST['segundo_apellido'] ?? '')) ?: null;
    $identificacion = trim((string)($_POST['identificacion'] ?? ''));
    $rawUsername = (string)($_POST['nombre_usuario'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirmacion = (string)($_POST['password_confirmacion'] ?? '');

    if ($identificacion === '' || strlen($identificacion) > 30) {
        $error = 'El número de cédula o identificación es obligatorio (máximo 30 caracteres).';
    } elseif ($nombre === '' || strlen($nombre) > 100) {
        $error = 'El nombre es obligatorio (máximo 100 caracteres).';
    } elseif ($primerApellido === '' || strlen($primerApellido) > 100) {
        $error = 'El primer apellido es obligatorio (máximo 100 caracteres).';
    } else {
        try {
            $nombreUsuario = validate_username($rawUsername);
            $passwordHash = validate_new_password_hash($password, $confirmacion, $nombreUsuario, $email);

            $sql = 'DECLARE @usr_id INT; EXEC dbo.sp_completar_invitacion_usuario @token_hash=?, @identificacion=?, @tipo_identificacion=\'01\', @nombre=?, @primer_apellido=?, @segundo_apellido=?, @nombre_usuario=?, @password_hash=?, @telefono=NULL, @nuevo_usuario_id=@usr_id OUTPUT; SELECT @usr_id AS usuario_id;';
            $stmt = db()->prepare($sql);
            $stmt->execute([
                $tokenHash,
                $identificacion,
                $nombre,
                $primerApellido,
                $segundoApellido,
                $nombreUsuario,
                $passwordHash,
            ]);

            do {
                if ($stmt->columnCount() > 0) {
                    $row = $stmt->fetch();
                    if (is_array($row) && isset($row['usuario_id'])) {
                        $targetUserId = (int)$row['usuario_id'];
                    }
                }
            } while ($stmt->nextRowset());
            $stmt->closeCursor();

        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            $sqlMsg = (string)($e->errorInfo[2] ?? $e->getMessage());

            if ($driverCode === 50432 || str_contains($sqlMsg, '50432')) {
                $error = 'La invitación ya fue completada o no está disponible.';
            } elseif ($driverCode === 50433 || str_contains($sqlMsg, '50433')) {
                $error = 'El número de identificación ya está asociado a otra cuenta de usuario.';
            } elseif ($driverCode === 50434 || str_contains($sqlMsg, '50434')) {
                $error = 'El nombre de usuario o correo ya está en uso. Elige un nombre de usuario diferente.';
            } else {
                $error = 'Ocurrió un error al registrar los datos en el sistema. Verifica que el nombre de usuario o identificación no estén duplicados.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Activar cuenta — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="onboarding-page">
<div class="onboarding-shell">
    <div class="onboarding-box">
        <div class="has-text-centered mb-5">
            <strong class="is-size-5 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2"><?= $error !== null ? 'Error de activación' : '¡Cuenta activada!' ?></h1>
        </div>

        <?php if ($error !== null): ?>
            <div class="notification notification-danger mb-5" role="alert">
                <?= svg_icon('alert-triangle') ?>
                <span><?= e($error) ?></span>
            </div>
            <div class="is-flex is-justify-content-center" style="gap: 1rem;">
                <a href="/auth/onboarding.php?token=<?= urlencode($tokenRaw) ?>" class="button is-primary">Volver al formulario</a>
                <a href="/auth/login.php" class="button button-secondary">Ir a inicio de sesión</a>
            </div>
        <?php else: ?>
            <div class="notification notification-success mb-5" role="status">
                <?= svg_icon('activate') ?>
                <span>Tu cuenta ha sido activada exitosamente. Ya puedes iniciar sesión con tu nombre de usuario o correo y la contraseña que acabas de configurar.</span>
            </div>
            <p class="has-text-grey-light mb-5 has-text-centered">Por motivos de seguridad, tu cuenta inicia con cero permisos operativos hasta que un administrador asigne los roles correspondientes a tus funciones.</p>
            <div class="has-text-centered">
                <a href="/auth/login.php" class="button is-primary is-medium is-fullwidth">
                    <span>Iniciar sesión ahora</span>
                    <?= svg_icon('chevron-right') ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
