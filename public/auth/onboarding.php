<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/components.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$tokenRaw = trim((string)($_GET['token'] ?? ''));
$errorMsg = null;
$invitation = null;

if ($tokenRaw === '' || strlen($tokenRaw) !== 64 || !ctype_xdigit($tokenRaw)) {
    $errorMsg = 'El enlace de activación es inválido o no contiene un token válido.';
} else {
    try {
        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = db()->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
        $stmt->execute([$tokenHash]);
        $invitation = $stmt->fetch();
        $stmt->closeCursor();

        $resultado = (string)($invitation['resultado'] ?? 'invalido');
        if ($resultado !== 'valida') {
            if ($resultado === 'expirada') {
                $errorMsg = 'Este enlace de invitación ha expirado (su validez era de 24 horas). Solicita a un administrador que te reenvíe la invitación.';
            } elseif ($resultado === 'revocada') {
                $errorMsg = 'Este enlace de invitación ha sido revocado por el administrador. Solicita una nueva invitación si requieres acceso.';
            } elseif ($resultado === 'completada') {
                $errorMsg = 'Esta invitación ya fue completada previamente.';
            } else {
                $errorMsg = 'El enlace de invitación no es válido o ya no existe en el sistema.';
            }
        }
    } catch (PDOException $e) {
        $errorMsg = 'Error al verificar la invitación. Por favor intenta más tarde.';
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
            <h1 class="title is-3 mt-2 mb-1">Activar cuenta de acceso</h1>
            <p class="has-text-grey-light is-size-6">Completa tus datos personales y credenciales para comenzar en el sistema.</p>
        </div>

        <?php if ($errorMsg !== null): ?>
            <div class="notification notification-danger mb-5" role="alert">
                <?= svg_icon('alert-triangle') ?>
                <span><?= e($errorMsg) ?></span>
            </div>
            <div class="has-text-centered">
                <a href="/auth/login.php" class="button button-secondary">Ir al inicio de sesión</a>
            </div>
        <?php else: ?>
            <div class="onboarding-step-bar" aria-label="Progreso de activación">
                <div class="onboarding-step-pill is-active" id="pill-step-1">
                    <span class="onboarding-step-badge" id="badge-step-1">1</span>
                    <span id="text-step-1">Paso 1 de 2 — Datos personales</span>
                </div>
                <div class="onboarding-step-divider"></div>
                <div class="onboarding-step-pill" id="pill-step-2">
                    <span class="onboarding-step-badge" id="badge-step-2">2</span>
                    <span id="text-step-2">Paso 2 de 2 — Acceso</span>
                </div>
            </div>

            <div id="onb-general-error" class="notification notification-danger mb-4" role="alert" hidden></div>

            <form action="/auth/onboarding_guardar.php" method="post" id="form-onboarding">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($tokenRaw) ?>">

                <!-- ==========================================
                     PASO 1: DATOS PERSONALES
                     ========================================== -->
                <div id="onb-paso-1">
                    <div class="field mb-4">
                        <label class="label" for="onb-email">Correo electrónico verificado</label>
                        <div class="control input-with-icon">
                            <?= svg_icon('envelope', '', 'input-icon') ?>
                            <input id="onb-email" class="input" type="email" value="<?= e($invitation['correo_destino']) ?>" readonly tabindex="-1">
                        </div>
                        <p class="help">Este correo fue validado por el administrador y será tu identificador de acceso principal.</p>
                    </div>

                    <div class="box onboarding-section-box mb-4 p-4">
                        <h2 class="title is-6 mb-3 has-text-info">Identificación y nombre oficial</h2>

                        <div class="field mb-3">
                            <label class="label" for="onb-identificacion">Cédula o identificación nacional <span class="required">*</span></label>
                            <div class="field has-addons">
                                <div class="control is-expanded">
                                    <input class="input" id="onb-identificacion" name="identificacion" maxlength="30" placeholder="Ej. 208820984" autocomplete="off" required>
                                </div>
                                <div class="control">
                                    <button type="button" class="button is-info" id="btn-onb-hacienda">
                                        <?= svg_icon('search') ?>
                                        <span>Consultar Hacienda</span>
                                    </button>
                                </div>
                            </div>
                            <p class="help" id="onb-hacienda-help">Ingresa tu cédula física (9 dígitos) o jurídica (10 dígitos) para autocompletar tus nombres oficiales.</p>
                        </div>

                        <div class="field mb-3">
                            <label class="label" for="onb-nombre">Nombre(s) <span class="required">*</span></label>
                            <input class="input" id="onb-nombre" name="nombre" maxlength="100" required autocomplete="given-name" placeholder="Ej. Andrey Josué">
                        </div>

                        <div class="columns is-variable is-2 mb-0">
                            <div class="column">
                                <div class="field mb-3">
                                    <label class="label" for="onb-primer-apellido">Primer apellido <span class="required">*</span></label>
                                    <input class="input" id="onb-primer-apellido" name="primer_apellido" maxlength="100" required autocomplete="family-name" placeholder="Ej. Tijerino">
                                </div>
                            </div>
                            <div class="column">
                                <div class="field mb-3">
                                    <label class="label" for="onb-segundo-apellido">Segundo apellido</label>
                                    <input class="input" id="onb-segundo-apellido" name="segundo_apellido" maxlength="100" autocomplete="additional-name" placeholder="Ej. Hernández">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <button class="button is-primary is-medium" type="button" id="btn-onb-continuar">
                            <span>Continuar</span>
                            <?= svg_icon('chevron-right') ?>
                        </button>
                    </div>
                </div>

                <!-- ==========================================
                     PASO 2: CREDENCIALES Y ACTIVACIÓN
                     ========================================== -->
                <div id="onb-paso-2" hidden>
                    <div class="box onboarding-section-box mb-4 p-4">
                        <h2 class="title is-6 mb-3 has-text-info">Credenciales de inicio de sesión</h2>

                        <div class="field mb-3">
                            <label class="label" for="onb-usuario">Nombre de usuario <span class="required">*</span></label>
                            <div class="control input-with-icon">
                                <?= svg_icon('user', '', 'input-icon') ?>
                                <input class="input" id="onb-usuario" name="nombre_usuario" maxlength="80" autocomplete="username" placeholder="Ej. andrey.tijerino">
                            </div>
                            <p class="help">Entre 3 y 80 caracteres (letras, números, punto, guion o guion bajo).</p>
                        </div>

                        <div class="field mb-3">
                            <label class="label" for="onb-password">Contraseña <span class="required">*</span></label>
                            <div class="control input-with-icon">
                                <?= svg_icon('lock', '', 'input-icon') ?>
                                <input class="input" id="onb-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Mínimo 12 caracteres seguros">
                            </div>
                            <p class="help">Mínimo 12 caracteres. Se almacenará de forma segura como hash bcrypt.</p>
                        </div>

                        <div class="field mb-2">
                            <label class="label" for="onb-password-confirm">Confirmar contraseña <span class="required">*</span></label>
                            <div class="control input-with-icon">
                                <?= svg_icon('lock', '', 'input-icon') ?>
                                <input class="input" id="onb-password-confirm" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Repite tu contraseña exactamente">
                            </div>
                        </div>
                    </div>

                    <div class="onboarding-actions mt-5">
                        <button class="button is-primary is-medium" type="submit" id="btn-submit-onboarding">
                            <span>Activar mi cuenta</span>
                            <?= svg_icon('check') ?>
                        </button>
                        <button class="button button-secondary is-medium" type="button" id="btn-onb-volver">
                            <?= svg_icon('chevron-left') ?>
                            <span>Volver</span>
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
