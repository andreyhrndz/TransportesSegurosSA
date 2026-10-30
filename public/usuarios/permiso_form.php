<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/user_security.php';

$currentUser = require_login();
require_permission('USUARIOS_VER');
require_permission('USUARIOS_GESTIONAR_PERMISOS');
header('Cache-Control: no-store');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Usuario no encontrado.', 'danger'));
}

try {
    $codigo = validate_permission_code($_GET['codigo'] ?? null);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    exit(ui_notice($exception->getMessage(), 'danger'));
}

$accion = strtolower(trim((string)($_GET['accion'] ?? '')));
if (!in_array($accion, ['conceder', 'revocar'], true)) {
    http_response_code(422);
    exit(ui_notice('Selecciona una acción válida sobre el permiso.', 'danger'));
}

if ($accion === 'revocar' && (int)$currentUser['usuario_id'] === (int)$id && is_critical_admin_permission($codigo)) {
    http_response_code(409);
    exit(ui_notice('No puedes revocar tus propios permisos administrativos críticos.', 'warning'));
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$id]);
    $user = $sets[0][0] ?? null;
    if (!is_array($user)) {
        http_response_code(404);
        exit(ui_notice('Usuario no encontrado.', 'danger'));
    }
    $catalog = read_procedure_sets('EXEC dbo.sp_listar_permisos @solo_activos=1;', [])[0] ?? [];
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit(ui_notice('Usuario no encontrado.', 'danger'));
    }
    read_error_response('No se pudo cargar la confirmación del permiso.');
}

$permissionMeta = null;
foreach ($catalog as $item) {
    if ((string)$item['codigo'] === $codigo) {
        $permissionMeta = $item;
        break;
    }
}
if (!is_array($permissionMeta)) {
    http_response_code(404);
    exit(ui_notice('El permiso solicitado no existe o está inactivo.', 'danger'));
}

$isGrant = $accion === 'conceder';
$title = $isGrant ? 'Asignar permiso individual' : 'Revocar permiso individual';
$submitLabel = $isGrant ? 'Asignar' : 'Revocar';
$submitClass = $isGrant ? 'is-primary' : 'is-danger';
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-permiso-usuario">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-permiso-usuario', $title) ?>
        <form hx-post="/usuarios/permiso.php" hx-target="#mensaje-permiso-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                <input type="hidden" name="codigo" value="<?= e($codigo) ?>">
                <input type="hidden" name="accion" value="<?= e($accion) ?>">
                <div id="mensaje-permiso-usuario" role="alert"></div>

                <div class="readonly-summary">
                    <div>
                        <span>Usuario</span>
                        <strong><?= e($user['nombre_usuario']) ?></strong>
                    </div>
                    <div>
                        <span>Permiso</span>
                        <strong><?= e($codigo) ?></strong>
                    </div>
                </div>

                <p class="mb-2"><strong><?= e($permissionMeta['nombre']) ?>:</strong> <?= e($permissionMeta['descripcion'] ?: 'Permiso individual del sistema.') ?></p>
                <?php if ($isGrant): ?>
                    <p class="help">El permiso se asignará directamente a este usuario sin roles intermedios.</p>
                <?php else: ?>
                    <p class="help">El usuario perderá esta capacidad de inmediato. Si es el último titular activo de un permiso administrativo crítico, la revocación será bloqueada.</p>
                <?php endif; ?>
            </section>
            <?= modal_footer($submitLabel, $submitClass) ?>
        </form>
    </div>
</div>
