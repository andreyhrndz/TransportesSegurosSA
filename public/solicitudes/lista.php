<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('SOLICITUDES_VER');
header('Cache-Control: no-store');

$states = ['REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA'];
$search = read_optional_text($_GET['buscar'] ?? null, 120);
$state = strtoupper((string)($_GET['estado'] ?? ''));
$state = in_array($state, $states, true) ? $state : null;
$from = read_optional_date($_GET['desde'] ?? null);
$to = read_optional_date($_GET['hasta'] ?? null);
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;

try {
    $result = read_procedure_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=?, @estado=?, @cliente_id=NULL, @desde=?, @hasta=?, @pagina=?, @por_pagina=?, @total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        [$search, $state, $from, $to, $page, $perPage]
    );
} catch (PDOException) {
    read_error_response();
}
$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>
<div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr><th>Código</th><th>Cliente</th><th>Ruta</th><th>Estado</th><th>Prioridad</th><th>Entrega requerida</th></tr></thead><tbody>
<?php if (!$result['rows']): ?><tr><td colspan="6"><?= empty_state('Sin solicitudes', 'Ajusta los filtros para consultar otro período o estado.') ?></td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $row): ?><tr>
    <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><?= e($row['codigo_solicitud']) ?></a></td>
    <td><?= e($row['cliente_nombre']) ?><br><small><?= e($row['cliente_identificacion']) ?></small></td>
    <td><?= e($row['origen_nombre']) ?> → <?= e($row['destino_nombre']) ?></td>
    <td><?= status_tag($row['estado']) ?></td><td><?= status_tag($row['prioridad_actual']) ?></td><td><?= format_utc_datetime($row['fecha_entrega_requerida']) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<div class="pagination-wrap"><span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span><div class="buttons" hx-target="#solicitudes-resultado" hx-swap="innerHTML" hx-include="#solicitudes-filtros"><button class="button is-small" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/solicitudes/lista.php?pagina=<?= $page - 1 ?>">Anterior</button><span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span><button class="button is-small" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/solicitudes/lista.php?pagina=<?= $page + 1 ?>">Siguiente</button></div></div>
