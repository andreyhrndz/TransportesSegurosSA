<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

require_permission('USUARIOS_GESTIONAR_CUENTAS');
header('Cache-Control: no-store');

$idParam = $_GET['id'] ?? null;
$isEdit = $idParam !== null && $idParam !== '';
$user = null;

if ($isEdit) {
    require_permission('USUARIOS_VER');
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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
        read_error_response('No se pudo cargar la cuenta de usuario.');
    }
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-form-usuario">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-form-usuario', $isEdit ? 'Editar usuario' : 'Nuevo usuario') ?>
        <form hx-post="/usuarios/guardar.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                <?php endif; ?>
                <div id="mensaje-form-usuario" role="alert"></div>

                <div class="field">
                    <label class="label" for="usr-nombre">Nombre de usuario <span class="required">*</span></label>
                    <input class="input" id="usr-nombre" name="nombre_usuario" maxlength="80" autocomplete="off" value="<?= e($user['nombre_usuario'] ?? '') ?>" required autofocus>
                    <p class="help">Entre 3 y 80 caracteres (letras, números, punto, guion o guion bajo).</p>
                </div>

                <div class="field">
                    <label class="label" for="usr-correo">Correo de acceso <span class="required">*</span></label>
                    <input class="input" id="usr-correo" name="correo_acceso" type="email" maxlength="150" autocomplete="off" value="<?= e($user['correo_acceso'] ?? '') ?>" required>
                </div>

                <?php if (!$isEdit): ?>
                    <div class="field">
                        <label class="label" for="usr-password">Contraseña inicial <span class="required">*</span></label>
                        <input class="input" id="usr-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                        <p class="help">Mínimo 12 caracteres. Se almacena únicamente su hash bcrypt y no volverá a mostrarse.</p>
                    </div>

                    <div class="field">
                        <label class="label" for="usr-password-confirm">Confirmar contraseña <span class="required">*</span></label>
                        <input class="input" id="usr-password-confirm" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                    </div>
                <?php endif; ?>
            </section>
            <?= modal_footer('Guardar', 'is-primary') ?>
        </form>
    </div>
</div>
