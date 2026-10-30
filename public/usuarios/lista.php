<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
$currentUser = require_login();
require_permission('USUARIOS_VER');
header('Cache-Control: no-store');

$buscar = read_optional_text($_GET['buscar'] ?? '', 120);
$active = filter_var($_GET['activo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
$activo = $active === false ? null : $active;
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;

$canManageAccounts = has_permission('USUARIOS_GESTIONAR_CUENTAS');
$currentUserId = (int)$currentUser['usuario_id'];

try {
    $result = read_procedure_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_usuarios @buscar=?,@activo=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        [$buscar, $activo, $page, $perPage]
    );
} catch (PDOException) {
    read_error_response('No se pudo consultar el directorio de usuarios.');
}

$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable">
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Correo de acceso</th>
                <th>Estado</th>
                <th>Último acceso</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="5"><?= empty_state('Sin usuarios', 'No hay cuentas que coincidan con los filtros seleccionados.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $user): ?>
                <?php $userId = (int)$user['usuario_id']; ?>
                <?php $isActive = (int)$user['activo'] === 1; ?>
                <?php $isSelf = $userId === $currentUserId; ?>
                <tr>
                    <td>
                        <strong><?= e($user['nombre_usuario']) ?></strong>
                        <?php if ($isSelf): ?><span class="status-tag status-tag-info ml-2">Sesión actual</span><?php endif; ?>
                    </td>
                    <td><?= e($user['correo_acceso']) ?></td>
                    <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <td><?= e(format_utc_datetime($user['ultimo_acceso'])) ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="button is-small button-secondary" href="/usuarios/detalle.php?id=<?= $userId ?>">Ver</a>
                            <?php if ($canManageAccounts): ?>
                                <button class="button is-small button-secondary" type="button" hx-get="/usuarios/form.php?id=<?= $userId ?>" hx-target="#modal-contenido"><?= svg_icon('edit') ?><span>Editar</span></button>
                                <?php if ($isActive && !$isSelf): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/usuarios/estado_form.php?id=<?= $userId ?>&accion=desactivar" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                                <?php elseif (!$isActive): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/usuarios/estado_form.php?id=<?= $userId ?>&accion=activar" hx-target="#modal-contenido"><?= svg_icon('activate') ?><span>Activar</span></button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-wrap">
    <span><?= $total ?> usuario<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#usuarios-resultado" hx-include="#usuarios-filtros">
        <button class="button is-small" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/usuarios/lista.php?pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/usuarios/lista.php?pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
