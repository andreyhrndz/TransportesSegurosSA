<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/components.php';
require_once __DIR__ . '/../../app/mail/mail_service.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /auth/recuperar.php');
    exit;
}

$error = null;
if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    $error = 'La sesión del formulario caducó. Vuelve a intentarlo.';
}

$correoRaw = trim((string)($_POST['correo'] ?? ''));
if ($error === null && ($correoRaw === '' || !filter_var($correoRaw, FILTER_VALIDATE_EMAIL))) {
    $error = 'Ingrese una dirección de correo válida.';
}

if ($error === null) {
    $email = strtolower($correoRaw);
    try {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $sql = 'DECLARE @uid INT, @nom NVARCHAR(80); EXEC dbo.sp_solicitar_recuperacion_password @correo=?, @token_hash=?, @minutos_vigencia=120, @usuario_id=@uid OUTPUT, @nombre_usuario=@nom OUTPUT; SELECT @uid AS usuario_id;';
        $stmt = db()->prepare($sql);
        $stmt->execute([$email, $tokenHash]);
        $targetUserId = 0;
        do {
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['usuario_id'])) {
                    $targetUserId = (int)$row['usuario_id'];
                }
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();

        // Si el usuario existe y está activo, enviar el correo
        if ($targetUserId > 0) {
            $resetLink = get_app_base_url() . '/auth/reset_password.php?token=' . urlencode($token);
            $mailSender = get_mail_sender();
            $mailHtml = render_password_reset_email($resetLink, $email);
            $sent = $mailSender->send($email, 'Restablecer contraseña — Transportes Seguros S.A.', $mailHtml);
            if (!$sent && $mailSender instanceof BrevoApiSender) {
                error_log(sprintf('[recuperar_solicitar] Error de entrega Brevo [categoría: %s, HTTP: %d]', $mailSender->getLastCategory(), $mailSender->getLastHttpCode()));
            }
        }
    } catch (Throwable $e) {
        error_log('TSSA password recovery error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Recuperar contraseña — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="login-page">
<div class="login-panel is-flex is-justify-content-center is-align-items-center" style="width: 100%; min-height: 100vh; padding: 2rem 1rem;">
    <div class="login-box" style="max-width: 480px; width: 100%;">
        <div class="login-box-header has-text-centered mb-5">
            <strong class="is-size-4 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2">Enlace de recuperación</h1>
        </div>

        <?php if ($error !== null): ?>
            <div class="notification notification-danger mb-4" role="alert">
                <?= svg_icon('alert-triangle') ?>
                <span><?= e($error) ?></span>
            </div>
            <div class="has-text-centered">
                <a href="/auth/recuperar.php" class="button is-primary">Intentar nuevamente</a>
            </div>
        <?php else: ?>
            <div class="notification notification-info mb-4" role="status">
                <?= svg_icon('shield') ?>
                <span>Si la dirección <strong><?= e($correoRaw) ?></strong> corresponde a una cuenta activa en el sistema, hemos enviado un correo con las instrucciones para restablecer tu contraseña.</span>
            </div>
            <p class="has-text-grey-light mb-5 has-text-centered is-size-7">El enlace tendrá una validez de 2 horas. Revisa tu carpeta de correo no deseado o spam si no lo recibes en unos minutos.</p>
            <div class="has-text-centered">
                <a href="/auth/login.php" class="button is-primary is-fullwidth">
                    <span>Volver al inicio de sesión</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
