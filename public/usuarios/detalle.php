<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('USUARIOS_VER');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) { http_response_code(404); exit('Usuario no encontrado.'); }
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$id]);
    $user = $sets[0][0] ?? null;
    if (!is_array($user)) { http_response_code(404); exit('Usuario no encontrado.'); }
    $assigned = $sets[1] ?? [];
    $catalog = read_procedure_sets('EXEC dbo.sp_listar_permisos @solo_activos=1;', [])[0] ?? [];
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) { http_response_code(404); exit('Usuario no encontrado.'); }
    read_error_response('No se pudo consultar el usuario.');
}
$assignedById = []; foreach ($assigned as $permission) { $assignedById[(int)$permission['permiso_id']] = $permission; }
require_once __DIR__ . '/../../app/layout.php';
layout_start('usuarios', 'Detalle de usuario', 'Matriz de permisos individuales de solo lectura.');
?>
<section class="panel-white"><div class="panel-heading-custom"><div><h2 class="title is-5"><?= e($user['nombre_usuario']) ?></h2><p>La contraseña y su hash nunca se consultan ni se muestran.</p></div><a class="button button-secondary" href="/usuarios.php">Volver</a></div><div class="detail-grid"><div><span>Correo de acceso</span><strong><?= e($user['correo_acceso']) ?></strong></div><div><span>Estado</span><strong><?= status_tag((int)$user['activo'] === 1 ? 'ACTIVO' : 'INACTIVO') ?></strong></div><div><span>Creación</span><strong><?= e(format_utc_datetime($user['fecha_creacion'])) ?></strong></div><div><span>Último acceso</span><strong><?= e(format_utc_datetime($user['ultimo_acceso'])) ?></strong></div></div></section>
<section class="panel-white mt-5"><div class="panel-heading-custom"><div><h2 class="title is-5">Permisos individuales</h2><p>La matriz es informativa hasta contar con un contrato aprobado que proteja auto-revocación y último gestor.</p></div></div><div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Permiso</th><th>Descripción</th><th>Asignación</th></tr></thead><tbody><?php if (!$catalog): ?><tr><td colspan="3"><?= empty_state('Sin permisos disponibles', 'No hay permisos activos para mostrar.') ?></td></tr><?php endif; ?><?php foreach ($catalog as $permission): $grant = $assignedById[(int)$permission['permiso_id']] ?? null; ?><tr><td><strong><?= e($permission['codigo']) ?></strong><br><small><?= e($permission['nombre']) ?></small></td><td><?= e($permission['descripcion'] ?: 'Sin descripción') ?></td><td><?= $grant ? status_tag('ASIGNADO') . '<br><small>' . e(format_utc_datetime($grant['fecha_asignacion'])) . '</small>' : status_tag('NO ASIGNADO') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php layout_end(); ?>
