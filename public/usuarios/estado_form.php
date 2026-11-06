<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

$currentUser = require_login();
require_permission('USUARIOS_VER');
require_permission('USUARIOS_GESTIONAR_CUENTAS');
header('Cache-Control: no-store');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Usuario no encontrado.', 'danger'));
}

$accion = strtolower(trim((string)($_GET['accion'] ?? '')));
if (!in_array($accion, ['activar', 'desactivar'], true)) {
    http_response_code(422);
    exit(ui_notice('Selecciona una acción válida sobre la cuenta.', 'danger'));
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
    read_error_response('No se pudo cargar la confirmación.');
}

$isActivating = $accion === 'activar';
$currentlyActive = (int)$user['activo'] === 1;

if (!$isActivating && (int)$currentUser['usuario_id'] === (int)$id) {
    http_response_code(409);
    exit(ui_notice('No puedes desactivar tu propia cuenta activa.', 'warning'));
}
if ($isActivating && $currentlyActive) {
    http_response_code(409);
    exit(ui_notice('La cuenta de usuario ya se encuentra activa.', 'warning'));
}
if (!$isActivating && !$currentlyActive) {
    http_response_code(409);
    exit(ui_notice('La cuenta de usuario ya se encuentra inactiva.', 'warning'));
}

$title = $isActivating ? 'Activar usuario' : 'Desactivar usuario';
$submitLabel = $isActivating ? 'Activar' : 'Desactivar';
$submitClass = $isActivating ? 'is-primary' : 'is-danger';
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-estado-usuario">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-estado-usuario', $title) ?>
        <form hx-post="/usuarios/estado.php" hx-target="#mensaje-estado-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                <input type="hidden" name="accion" value="<?= e($accion) ?>">
                <div id="mensaje-estado-usuario" role="alert"></div>

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

                <?php if ($isActivating): ?>
                    <p>¿Confirmas la reactivación de esta cuenta de usuario?</p>
                    <p class="help mt-2">El usuario podrá iniciar sesión nuevamente con sus permisos individuales asignados.</p>
                <?php else: ?>
                    <p>¿Confirmas la desactivación de esta cuenta de usuario?</p>
                    <p class="help mt-2">El acceso quedará bloqueado de inmediato sin borrar historial ni auditoría. La operación se rechazará si dejaría al sistema sin gestores administrativos activos.</p>
                <?php endif; ?>
            </section>
            <?= modal_footer($submitLabel, $submitClass) ?>
        </form>
    </div>
</div>
