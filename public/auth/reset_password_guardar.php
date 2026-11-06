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
    $error = 'La sesión del formulario caducó. Vuelve a intentarlo.';
} elseif ($tokenRaw === '' || strlen($tokenRaw) !== 64 || !ctype_xdigit($tokenRaw)) {
    $error = 'El token de recuperación no es válido.';
}

$email = '';
$tokenHash = '';
if ($error === null) {
    try {
        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = db()->prepare('EXEC dbo.sp_validar_token_recuperacion @token_hash=?;');
        $stmt->execute([$tokenHash]);
        $tokenInfo = $stmt->fetch();
        $stmt->closeCursor();

        $resultado = (string)($tokenInfo['resultado'] ?? 'invalido');
        if ($resultado !== 'valido') {
            $error = 'El enlace de recuperación ya no es válido o ha expirado.';
        } else {
            $email = (string)($tokenInfo['correo_acceso'] ?? '');
        }
    } catch (PDOException $e) {
        $error = 'Error de conexión al verificar el token de recuperación.';
    }
}

if ($error === null) {
    $password = (string)($_POST['password'] ?? '');
    $confirmacion = (string)($_POST['password_confirmacion'] ?? '');

    try {
        $nuevoHash = validate_new_password_hash($password, $confirmacion, '', $email);

        $stmt = db()->prepare('EXEC dbo.sp_completar_recuperacion_password @token_hash=?, @password_hash=?;');
        $stmt->execute([$tokenHash, $nuevoHash]);
        $stmt->closeCursor();

    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'No se pudo actualizar la contraseña en el sistema. Intenta nuevamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Nueva contraseña — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="login-page">
<div class="login-panel is-flex is-justify-content-center is-align-items-center" style="width: 100%; min-height: 100vh; padding: 2rem 1rem;">
    <div class="login-box" style="max-width: 480px; width: 100%;">
        <div class="login-box-header has-text-centered mb-5">
            <strong class="is-size-4 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2"><?= $error !== null ? 'Error al restablecer' : '¡Contraseña actualizada!' ?></h1>
        </div>

        <?php if ($error !== null): ?>
            <div class="notification notification-danger mb-5" role="alert">
                <?= svg_icon('alert-triangle') ?>
                <span><?= e($error) ?></span>
            </div>
            <div class="buttons is-centered">
                <a href="/auth/reset_password.php?token=<?= urlencode($tokenRaw) ?>" class="button is-primary">Volver al formulario</a>
                <a href="/auth/recuperar.php" class="button button-secondary">Solicitar nuevo enlace</a>
            </div>
        <?php else: ?>
            <div class="notification notification-success mb-5" role="status">
                <?= svg_icon('activate') ?>
                <span>Tu contraseña ha sido actualizada con éxito. A partir de este momento puedes iniciar sesión con tu nueva credencial.</span>
            </div>
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
