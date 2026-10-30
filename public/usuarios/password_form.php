<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

require_permission('USUARIOS_VER');
require_permission('USUARIOS_GESTIONAR_CUENTAS');
header('Cache-Control: no-store');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Usuario no encontrado.', 'danger'));
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$id]);
    $user = $sets[0][0] ?? null;
    if (!is_array($user)) {
        http_response_code(404);
        exit(ui_notice('Usuario no encontrado.', 'danger'));
    }
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit(ui_notice('Usuario no encontrado.', 'danger'));
    }
    read_error_response('No se pudo cargar el formulario de restablecimiento.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-password-usuario">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-password-usuario', 'Restablecer contraseña') ?>
        <form hx-post="/usuarios/password.php" hx-target="#mensaje-password-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                <div id="mensaje-password-usuario" role="alert"></div>

                <div class="readonly-summary">
                    <div>
                        <span>Usuario</span>
                        <strong><?= e($user['nombre_usuario']) ?></strong>
                    </div>
                    <div>
                        <span>Correo de acceso</span>
                        <strong><?= e($user['correo_acceso']) ?></strong>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="pwd-nueva">Nueva contraseña <span class="required">*</span></label>
                    <input class="input" id="pwd-nueva" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required autofocus>
                    <p class="help">Mínimo 12 caracteres (máximo 72 bytes). No se almacena en texto plano ni se muestra tras guardar.</p>
                </div>

                <div class="field">
                    <label class="label" for="pwd-confirmar">Confirmar contraseña <span class="required">*</span></label>
                    <input class="input" id="pwd-confirmar" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                </div>
            </section>
            <?= modal_footer('Guardar', 'is-primary') ?>
        </form>
    </div>
</div>
