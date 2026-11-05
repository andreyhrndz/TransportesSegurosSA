<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/components.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$tokenRaw = trim((string)($_GET['token'] ?? ''));
$errorMsg = null;
$tokenInfo = null;

if ($tokenRaw === '' || strlen($tokenRaw) !== 64 || !ctype_xdigit($tokenRaw)) {
    $errorMsg = 'El enlace de restablecimiento es inválido o no contiene un token válido.';
} else {
    try {
        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = db()->prepare('EXEC dbo.sp_validar_token_recuperacion @token_hash=?;');
        $stmt->execute([$tokenHash]);
        $tokenInfo = $stmt->fetch();
        $stmt->closeCursor();

        $resultado = (string)($tokenInfo['resultado'] ?? 'invalido');
        if ($resultado !== 'valido') {
            if ($resultado === 'expirado') {
                $errorMsg = 'El enlace de recuperación ha expirado (su validez era de 2 horas). Solicita uno nuevo.';
            } else {
                $errorMsg = 'Este enlace de recuperación ya fue utilizado previamente o no es válido.';
            }
        }
    } catch (PDOException $e) {
        $errorMsg = 'Error al verificar el enlace de recuperación. Intenta más tarde.';
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
            <h1 class="title is-3 mt-2">Restablecer contraseña</h1>
            <p class="login-subtitle">Establece una nueva contraseña segura para tu cuenta.</p>
        </div>

        <?php if ($errorMsg !== null): ?>
            <div class="notification notification-danger mb-5" role="alert">
                <?= svg_icon('alert-triangle') ?>
                <span><?= e($errorMsg) ?></span>
            </div>
            <div class="has-text-centered">
                <a href="/auth/recuperar.php" class="button is-primary">Solicitar nuevo enlace</a>
            </div>
        <?php else: ?>
            <form action="/auth/reset_password_guardar.php" method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($tokenRaw) ?>">

                <div class="field mb-3">
                    <label class="label" for="reset-email">Cuenta asociada</label>
                    <div class="control input-with-icon">
                        <?= svg_icon('envelope', '', 'input-icon') ?>
                        <input id="reset-email" class="input" type="email" value="<?= e($tokenInfo['correo_acceso'] ?? '') ?>" readonly>
                    </div>
                </div>

                <div class="field mb-3">
                    <label class="label" for="reset-password">Nueva contraseña <span class="required">*</span></label>
                    <div class="control input-with-icon">
                        <?= svg_icon('lock', '', 'input-icon') ?>
                        <input id="reset-password" class="input" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Mínimo 12 caracteres" required autofocus>
                    </div>
                    <p class="help">Mínimo 12 caracteres seguros. Se almacenará únicamente su hash bcrypt.</p>
                </div>

                <div class="field mb-4">
                    <label class="label" for="reset-password-confirm">Confirmar nueva contraseña <span class="required">*</span></label>
                    <div class="control input-with-icon">
                        <?= svg_icon('lock', '', 'input-icon') ?>
                        <input id="reset-password-confirm" class="input" type="password" name="password_confirmacion" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Repite tu contraseña" required>
                    </div>
                </div>

                <button class="button is-primary is-fullwidth mt-4" type="submit">
                    <span>Guardar nueva contraseña</span>
                    <?= svg_icon('chevron-right') ?>
                </button>
            </form>
        <?php endif; ?>

        <div class="has-text-centered mt-5">
            <a href="/auth/login.php" class="is-size-7 has-text-grey-light">← Volver al inicio de sesión</a>
        </div>
    </div>
</div>
</body>
</html>
