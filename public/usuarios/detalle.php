<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/user_security.php';

$currentUser = require_login();
require_permission('USUARIOS_VER');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Usuario no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$id]);
    $user = $sets[0][0] ?? null;
    if (!is_array($user)) {
        http_response_code(404);
        exit('Usuario no encontrado.');
    }
    $assigned = $sets[1] ?? [];
    $catalog = read_procedure_sets('EXEC dbo.sp_listar_permisos @solo_activos=1;', [])[0] ?? [];
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Usuario no encontrado.');
    }
    read_error_response('No se pudo consultar el usuario.');
}

$assignedById = [];
foreach ($assigned as $permission) {
    $assignedById[(int)$permission['permiso_id']] = $permission;
}

$canManageAccounts = has_permission('USUARIOS_GESTIONAR_CUENTAS');
$canManagePermissions = has_permission('USUARIOS_GESTIONAR_PERMISOS');
$isSelf = (int)$currentUser['usuario_id'] === (int)$id;
$isActive = (int)$user['activo'] === 1;

function render_user_detail_panels(
    array $user,
    int $id,
    bool $isActive,
    bool $isSelf,
    bool $canManageAccounts,
    bool $canManagePermissions,
    array $catalog,
    array $assignedById
): void {
?>
<section class="panel-box">
    <div class="panel-box-header">
        <div>
            <h2 class="title is-5 mb-1">
                <?= e($user['nombre_usuario']) ?>
                <?php if ($isSelf): ?><span class="status-tag status-tag-info ml-2">Sesión actual</span><?php endif; ?>
            </h2>
            <p class="panel-box-subtitle">Información de la cuenta y estado de acceso.</p>
        </div>
        <div class="buttons">
            <?php if ($canManageAccounts): ?>
                <button class="button is-primary" type="button" hx-get="/usuarios/form.php?id=<?= $id ?>" hx-target="#modal-contenido"><?= svg_icon('edit') ?><span>Editar</span></button>
                <button class="button button-secondary" type="button" hx-get="/usuarios/password_form.php?id=<?= $id ?>" hx-target="#modal-contenido"><?= svg_icon('key') ?><span>Restablecer</span></button>
                <?php if ($isActive && !$isSelf): ?>
                    <button class="button is-danger" type="button" hx-get="/usuarios/estado_form.php?id=<?= $id ?>&accion=desactivar" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                <?php elseif (!$isActive): ?>
                    <button class="button is-primary" type="button" hx-get="/usuarios/estado_form.php?id=<?= $id ?>&accion=activar" hx-target="#modal-contenido"><?= svg_icon('activate') ?><span>Activar</span></button>
                <?php endif; ?>
            <?php endif; ?>
            <a class="button button-secondary" href="/usuarios.php"><?= svg_icon('arrow-left') ?><span>Volver</span></a>
        </div>
    </div>
    <dl class="detail-grid">
        <div class="detail-field"><dt>Correo de acceso</dt><dd><strong><?= e($user['correo_acceso']) ?></strong></dd></div>
        <div class="detail-field"><dt>Estado</dt><dd><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></dd></div>
        <div class="detail-field"><dt>Creación</dt><dd><strong><?= e(format_utc_datetime($user['fecha_creacion'])) ?></strong></dd></div>
        <div class="detail-field"><dt>Último acceso</dt><dd><strong><?= e(format_utc_datetime($user['ultimo_acceso'])) ?></strong></dd></div>
    </dl>
    <?php if ($isSelf && $canManageAccounts): ?>
        <p class="help mt-4">Tu propia cuenta activa está protegida contra auto-desactivación para evitar bloqueos administrativos.</p>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title">Permisos individuales</h2>
            <p class="panel-box-subtitle">Asignación directa de capacidades operativas y administrativas.</p>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Permiso</th>
                    <th>Descripción</th>
                    <th>Estado</th>
                    <?php if ($canManagePermissions): ?><th class="has-text-right">Acciones</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$catalog): ?>
                    <tr>
                        <td colspan="<?= $canManagePermissions ? 4 : 3 ?>"><?= empty_state('Sin permisos disponibles', 'No hay permisos activos para mostrar.') ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($catalog as $permission): ?>
                    <?php
                    $permId = (int)$permission['permiso_id'];
                    $code = (string)$permission['codigo'];
                    $grant = $assignedById[$permId] ?? null;
                    $selfProtected = $isSelf && is_critical_admin_permission($code);
                    ?>
                    <tr>
                        <td>
                            <div class="cell-stack">
                                <strong><?= e(normalize_spanish_text($permission['nombre'])) ?></strong>
                                <span><code><?= e($code) ?></code></span>
                            </div>
                        </td>
                        <td><?= e(normalize_spanish_text($permission['descripcion'] ?: 'Sin descripción')) ?></td>
                        <td>
                            <?php if ($grant): ?>
                                <div class="cell-stack">
                                    <div><?= status_tag('ASIGNADO') ?></div>
                                    <span><?= e(format_utc_datetime($grant['fecha_asignacion'])) ?></span>
                                </div>
                            <?php else: ?>
                                <?= status_tag('SIN_ASIGNAR') ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManagePermissions): ?>
                            <td>
                                <div class="row-actions">
                                    <?php if (!$grant): ?>
                                        <button class="button is-small button-secondary" type="button" hx-get="/usuarios/permiso_form.php?id=<?= $id ?>&codigo=<?= urlencode($code) ?>&accion=conceder" hx-target="#modal-contenido"><?= svg_icon('shield') ?><span>Asignar</span></button>
                                    <?php elseif ($selfProtected): ?>
                                        <span class="status-tag status-tag-info">Protegido</span>
                                    <?php else: ?>
                                        <button class="button is-small button-secondary" type="button" hx-get="/usuarios/permiso_form.php?id=<?= $id ?>&codigo=<?= urlencode($code) ?>&accion=revocar" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Revocar</span></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
}

if (($_GET['parcial'] ?? '') === '1' && is_htmx_request()) {
    header('Cache-Control: no-store');
    render_user_detail_panels($user, (int)$id, $isActive, $isSelf, $canManageAccounts, $canManagePermissions, $catalog, $assignedById);
    exit;
}

require_once __DIR__ . '/../../app/layout.php';
layout_start('usuarios', 'Detalle de usuario', 'Administración de cuenta, estado, credenciales y permisos individuales.');
?>
<div id="usuario-detalle-panel" hx-get="/usuarios/detalle.php?id=<?= (int)$id ?>&parcial=1" hx-trigger="usuarioActualizado from:body, permisoActualizado from:body" hx-swap="innerHTML">
    <?php render_user_detail_panels($user, (int)$id, $isActive, $isSelf, $canManageAccounts, $canManagePermissions, $catalog, $assignedById); ?>
</div>
<?php layout_end(); ?>
