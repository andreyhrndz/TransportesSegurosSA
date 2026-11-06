<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('VIAJES_VER');
header('Cache-Control: no-store');

$allowed = ['PLANIFICADO', 'EN_CURSO', 'FINALIZADO', 'CANCELADO'];
$status = strtoupper((string)($_GET['estado'] ?? ''));
$status = in_array($status, $allowed, true) ? $status : null;
$from = read_optional_date($_GET['desde'] ?? null);
$to = read_optional_date($_GET['hasta'] ?? null);
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;

try {
    $result = read_procedure_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=?, @desde=?, @hasta=?, @pagina=?, @por_pagina=?, @total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        [$status, $from, $to, $page, $perPage]
    );
} catch (PDOException) {
    read_error_response();
}
$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable">
        <thead>
            <tr>
                <th>Código</th>
                <th>Estado</th>
                <th>Vehículo</th>
                <th>Conductor</th>
                <th>Ruta</th>
                <th>Salida programada</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="6"><?= empty_state('Sin viajes', 'No hay viajes que coincidan con los filtros seleccionados.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td>
                        <a class="table-link" href="/viajes/detalle.php?id=<?= (int)$row['viaje_id'] ?>">
                            <strong><?= e($row['codigo_viaje']) ?></strong>
                        </a>
                    </td>
                    <td><?= status_tag($row['estado']) ?></td>
                    <td><code><?= e($row['placa']) ?></code></td>
                    <td><?= e($row['conductor_nombre']) ?></td>
                    <td><?= e($row['ruta_nombre']) ?></td>
                    <td><?= format_utc_datetime($row['fecha_programada_salida']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-bar">
    <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#viajes-resultado" hx-include="#viajes-filtros">
        <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/viajes/lista.php?pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/viajes/lista.php?pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
