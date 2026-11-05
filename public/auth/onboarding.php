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
<body class="login-page">
<div class="login-panel is-flex is-justify-content-center is-align-items-center" style="width: 100%; min-height: 100vh; padding: 2rem 1rem;">
    <div class="login-box" style="max-width: 580px; width: 100%;">
        <div class="login-box-header has-text-centered mb-5">
            <strong class="is-size-4 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2">Activar cuenta de acceso</h1>
            <p class="login-subtitle">Completa tus datos personales y credenciales para comenzar.</p>
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
            <form action="/auth/onboarding_guardar.php" method="post" id="form-onboarding">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($tokenRaw) ?>">

                <div class="field mb-4">
                    <label class="label" for="onb-email">Correo electrónico de la cuenta</label>
                    <div class="control input-with-icon">
                        <?= svg_icon('envelope', '', 'input-icon') ?>
                        <input id="onb-email" class="input" type="email" value="<?= e($invitation['correo_destino']) ?>" readonly>
                    </div>
                    <p class="help">Este correo fue validado por el administrador y será tu identificador de acceso.</p>
                </div>

                <div class="box has-background-dark mb-4 p-4" style="border: 1px solid var(--tssa-border, #223247);">
                    <h2 class="title is-6 mb-3 has-text-info">Datos personales</h2>

                    <div class="field mb-3">
                        <label class="label" for="onb-identificacion">Cédula o identificación nacional <span class="required">*</span></label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input" id="onb-identificacion" name="identificacion" maxlength="30" placeholder="Ej. 101110222" autocomplete="off" required>
                            </div>
                            <div class="control">
                                <button type="button" class="button is-info" id="btn-onb-hacienda">
                                    <?= svg_icon('search') ?>
                                    <span>Consultar Hacienda</span>
                                </button>
                            </div>
                        </div>
                        <p class="help" id="onb-hacienda-help">Ingresa tu cédula física (9 dígitos) o jurídica (10 dígitos) para autocompletar tus nombres.</p>
                    </div>

                    <div class="field mb-3">
                        <label class="label" for="onb-nombre">Nombre <span class="required">*</span></label>
                        <input class="input" id="onb-nombre" name="nombre" maxlength="100" required autocomplete="given-name">
                    </div>

                    <div class="columns is-variable is-2 mb-0">
                        <div class="column">
                            <div class="field mb-3">
                                <label class="label" for="onb-primer-apellido">Primer apellido <span class="required">*</span></label>
                                <input class="input" id="onb-primer-apellido" name="primer_apellido" maxlength="100" required autocomplete="family-name">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field mb-3">
                                <label class="label" for="onb-segundo-apellido">Segundo apellido</label>
                                <input class="input" id="onb-segundo-apellido" name="segundo_apellido" maxlength="100" autocomplete="additional-name">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box has-background-dark mb-4 p-4" style="border: 1px solid var(--tssa-border, #223247);">
                    <h2 class="title is-6 mb-3 has-text-info">Credenciales de acceso</h2>

                    <div class="field mb-3">
                        <label class="label" for="onb-usuario">Nombre de usuario <span class="required">*</span></label>
                        <div class="control input-with-icon">
                            <?= svg_icon('user', '', 'input-icon') ?>
                            <input class="input" id="onb-usuario" name="nombre_usuario" maxlength="80" autocomplete="username" placeholder="Ej. carlos.mora" required>
                        </div>
                        <p class="help">Entre 3 y 80 caracteres (letras, números, punto, guion o guion bajo).</p>
                    </div>

                    <div class="field mb-3">
                        <label class="label" for="onb-password">Contraseña <span class="required">*</span></label>
                        <div class="control input-with-icon">
                            <?= svg_icon('lock', '', 'input-icon') ?>
                            <input class="input" id="onb-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Mínimo 12 caracteres" required>
                        </div>
                        <p class="help">Mínimo 12 caracteres seguros. Se almacenará únicamente como hash bcrypt.</p>
                    </div>

                    <div class="field mb-2">
                        <label class="label" for="onb-password-confirm">Confirmar contraseña <span class="required">*</span></label>
                        <div class="control input-with-icon">
                            <?= svg_icon('lock', '', 'input-icon') ?>
                            <input class="input" id="onb-password-confirm" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Repite tu contraseña" required>
                        </div>
                    </div>
                </div>

                <button class="button is-primary is-fullwidth mt-4" type="submit" id="btn-submit-onboarding">
                    <span>Activar mi cuenta y comenzar</span>
                    <?= svg_icon('chevron-right') ?>
                </button>
            </form>

            <script>
            (function() {
                var btnHacienda = document.getElementById('btn-onb-hacienda');
                var inputId = document.getElementById('onb-identificacion');
                var inputNombre = document.getElementById('onb-nombre');
                var inputApe1 = document.getElementById('onb-primer-apellido');
                var inputApe2 = document.getElementById('onb-segundo-apellido');
                var helpEl = document.getElementById('onb-hacienda-help');
                var token = <?= json_encode($tokenRaw) ?>;

                if (btnHacienda && inputId) {
                    btnHacienda.addEventListener('click', function() {
                        var cedula = (inputId.value || '').trim();
                        if (!cedula) {
                            helpEl.textContent = 'Ingrese un número de cédula para consultar.';
                            helpEl.className = 'help is-warning';
                            inputId.focus();
                            return;
                        }
                        btnHacienda.classList.add('is-loading');
                        helpEl.textContent = 'Consultando Ministerio de Hacienda de Costa Rica...';
                        helpEl.className = 'help is-info';

                        fetch('/api/hacienda.php?identificacion=' + encodeURIComponent(cedula) + '&token=' + encodeURIComponent(token))
                            .then(function(res) { return res.json(); })
                            .then(function(data) {
                                btnHacienda.classList.remove('is-loading');
                                if (data && data.status === 'encontrado' && data.datos) {
                                    var d = data.datos;
                                    var nombreCompleto = d.nombre || '';
                                    var partes = nombreCompleto.split(/\s+/).filter(Boolean);
                                    if (partes.length >= 3) {
                                        var ape2 = partes.pop();
                                        var ape1 = partes.pop();
                                        inputNombre.value = partes.join(' ');
                                        inputApe1.value = ape1;
                                        inputApe2.value = ape2;
                                    } else if (partes.length === 2) {
                                        inputNombre.value = partes[0];
                                        inputApe1.value = partes[1];
                                    } else {
                                        inputNombre.value = nombreCompleto;
                                    }
                                    helpEl.textContent = 'Datos obtenidos desde Hacienda: ' + nombreCompleto;
                                    helpEl.className = 'help is-success';
                                } else {
                                    helpEl.textContent = (data && data.mensaje) ? data.mensaje : 'Identificación no encontrada en Hacienda. Ingrese los datos manualmente.';
                                    helpEl.className = 'help is-warning';
                                }
                            })
                            .catch(function(err) {
                                btnHacienda.classList.remove('is-loading');
                                helpEl.textContent = 'No se pudo conectar con el servicio de Hacienda. Ingrese sus datos manualmente.';
                                helpEl.className = 'help is-danger';
                            });
                    });
                }
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
