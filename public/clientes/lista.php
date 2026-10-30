<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('CLIENTES_VER');
header('Cache-Control: no-store');

$buscar = read_optional_text($_GET['buscar'] ?? '', 120);
$active = filter_var($_GET['activo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
$soloActivos = $active === false ? null : $active;
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;

$canEdit = has_permission('CLIENTES_EDITAR');
$canDeactivate = has_permission('CLIENTES_DESACTIVAR');

try {
    $result = read_procedure_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_clientes @buscar=?,@solo_activos=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        [$buscar, $soloActivos, $page, $perPage]
    );
} catch (PDOException) {
    read_error_response('No se pudo consultar el directorio de clientes.');
}

$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Identificación</th>
                <th>Tipo</th>
                <th>Correo</th>
                <th>Estado</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="6"><?= empty_state('Sin clientes', 'No hay clientes que coincidan con los filtros seleccionados.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $customer): ?>
                <?php $customerId = (int)$customer['cliente_id']; ?>
                <?php $isActive = (int)$customer['activo'] === 1; ?>
                <tr>
                    <td><strong><?= e(trim((string)$customer['nombre'])) ?></strong></td>
                    <td><?= e($customer['identificacion']) ?></td>
                    <td><?= e($customer['tipo_cliente'] === 'FISICA' ? 'Física' : 'Jurídica') ?></td>
                    <td><?= e($customer['correo']) ?></td>
                    <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="button is-small button-secondary" href="/clientes/detalle.php?id=<?= $customerId ?>">Ver</a>
                            <?php if ($canEdit): ?>
                                <button class="button is-small button-secondary" type="button" hx-get="/clientes/editar_form.php?id=<?= $customerId ?>" hx-target="#modal-contenido"><?= svg_icon('edit') ?><span>Editar</span></button>
                            <?php endif; ?>
                            <?php if ($canDeactivate && $isActive): ?>
                                <button class="button is-small button-secondary" type="button" hx-get="/clientes/desactivar_form.php?id=<?= $customerId ?>" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-wrap">
    <span><?= $total ?> cliente<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#clientes-resultado" hx-include="#clientes-filtros">
        <button class="button is-small" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/clientes/lista.php?pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/clientes/lista.php?pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
