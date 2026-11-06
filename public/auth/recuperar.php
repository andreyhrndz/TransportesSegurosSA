<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/components.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
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
            <h1 class="title is-3 mt-2">Recuperar contraseña</h1>
            <p class="login-subtitle">Ingresa tu correo de acceso para recibir un enlace de restablecimiento seguro.</p>
        </div>

        <form action="/auth/recuperar_solicitar.php" method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="field mb-4">
                <label class="label" for="recuperar-correo">Correo electrónico</label>
                <div class="control input-with-icon">
                    <?= svg_icon('envelope', '', 'input-icon') ?>
                    <input id="recuperar-correo" class="input" type="email" name="correo" placeholder="correo@transportesseguros.cr" maxlength="150" required autofocus autocomplete="email">
                </div>
                <p class="help">Se enviará un enlace válido durante 2 horas si la cuenta existe en el sistema.</p>
            </div>

            <button class="button is-primary is-fullwidth mt-4" type="submit">
                <span>Enviar enlace de recuperación</span>
                <?= svg_icon('chevron-right') ?>
            </button>
        </form>

        <div class="has-text-centered mt-5">
            <a href="/auth/login.php" class="is-size-7 has-text-grey-light">← Volver al inicio de sesión</a>
        </div>
    </div>
</div>
</body>
</html>
