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
        <?= modal_header('titulo-form-usuario', $isEdit ? 'Editar usuario' : 'Invitar usuario · Nuevo usuario') ?>
        <?php if ($isEdit): ?>
            <form hx-post="/usuarios/guardar.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
                <section class="modal-card-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                    <div id="mensaje-form-usuario" role="alert"></div>

                    <div class="field">
                        <label class="label" for="usr-nombre">Nombre de usuario</label>
                        <input class="input" id="usr-nombre" name="nombre_usuario" maxlength="80" autocomplete="off" value="<?= e($user['nombre_usuario'] ?? '') ?>" readonly>
                        <p class="help">El identificador de cuenta no puede modificarse tras el alta.</p>
                    </div>

                    <div class="field">
                        <label class="label" for="usr-correo">Correo de acceso <span class="required">*</span></label>
                        <input class="input" id="usr-correo" name="correo_acceso" type="email" maxlength="150" autocomplete="off" value="<?= e($user['correo_acceso'] ?? '') ?>" required autofocus>
                    </div>
                </section>
                <?= modal_footer('Guardar cambios', 'is-primary') ?>
            </form>
        <?php else: ?>
            <form hx-post="/usuarios/invitar.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
                <section class="modal-card-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div id="mensaje-form-usuario" role="alert"></div>

                    <div class="field">
                        <label class="label" for="usr-correo-invitacion">Correo electrónico del usuario <span class="required">*</span></label>
                        <div class="control input-with-icon">
                            <?= svg_icon('envelope', '', 'input-icon') ?>
                            <input class="input" id="usr-correo-invitacion" name="correo" type="email" maxlength="150" autocomplete="off" placeholder="colaborador@transportesseguros.cr" required autofocus>
                        </div>
                        <p class="help">El colaborador recibirá un enlace seguro de uso único para completar sus datos personales y establecer su propia contraseña. La cuenta se creará sin permisos predeterminados.</p>
                    </div>
                </section>
                <?= modal_footer('Enviar invitación', 'is-primary') ?>
            </form>
        <?php endif; ?>
    </div>
</div>
