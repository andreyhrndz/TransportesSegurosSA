<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('SOLICITUDES_VER');
header('Cache-Control: no-store');

$isJson = ($_GET['formato'] ?? '') === 'json';
$states = ['REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA'];
$search = read_optional_text($_GET['buscar'] ?? null, 120);
$state = strtoupper((string)($_GET['estado'] ?? ''));
$state = in_array($state, $states, true) ? $state : null;
$rawAlerta = strtolower(trim((string)($_GET['alerta'] ?? '')));
$alerta = (!$isJson && in_array($rawAlerta, ['atrasadas', 'sin_asignar'], true)) ? $rawAlerta : '';
$from = read_optional_date($_GET['desde'] ?? null);
$to = read_optional_date($_GET['hasta'] ?? null);
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = $isJson ? 25 : 10;

try {
    if ($alerta !== '') {
        // Consultar conjunto amplio para aplicar el filtro de alerta operativa con exactitud
        $rawRes = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=?, @estado=?, @cliente_id=NULL, @desde=?, @hasta=?, @pagina=1, @por_pagina=100, @total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$search, $state, $from, $to]
        );
        $nowTs = time();
        $filtered = array_values(array_filter($rawRes['rows'], static function (array $row) use ($alerta, $nowTs): bool {
            $st = (string)($row['estado'] ?? '');
            if ($alerta === 'sin_asignar') {
                return in_array($st, ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true);
            }
            if ($alerta === 'atrasadas') {
                if (!in_array($st, ['REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO'], true)) {
                    return false;
                }
                $ts = !empty($row['fecha_entrega_requerida']) ? strtotime((string)$row['fecha_entrega_requerida'] . ' UTC') : false;
                return $ts !== false && $ts < $nowTs;
            }
            return true;
        }));
        $totalFiltered = count($filtered);
        $pagesTmp = max(1, (int)ceil($totalFiltered / $perPage));
        $pageSafe = min($page, $pagesTmp);
        $result = [
            'rows' => array_slice($filtered, ($pageSafe - 1) * $perPage, $perPage),
            'total' => $totalFiltered,
        ];
    } else {
        $result = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=?, @estado=?, @cliente_id=NULL, @desde=?, @hasta=?, @pagina=?, @por_pagina=?, @total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$search, $state, $from, $to, $page, $perPage]
        );
    }
} catch (PDOException) {
    if ($isJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo '[]';
        exit;
    }
    read_error_response();
}

if ($isJson) {
    header('Content-Type: application/json; charset=UTF-8');
    $options = [];
    foreach ($result['rows'] as $row) {
        $options[] = [
            'value' => (string)(int)$row['solicitud_id'],
            'text' => (string)$row['codigo_solicitud'] . ' · ' . (string)$row['cliente_nombre'],
            'subtext' => (string)$row['origen_nombre'] . ' → ' . (string)$row['destino_nombre'] . ' · ' . humanize_status($row['estado']),
        ];
    }
    echo json_encode($options, JSON_UNESCAPED_UNICODE);
    exit;
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
                <th>Cliente</th>
                <th>Ruta operativa</th>
                <th>Estado</th>
                <th>Prioridad</th>
                <th>Entrega requerida</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="6"><?= empty_state('Sin solicitudes', 'Ajusta los filtros para consultar otro período o estado.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td>
                        <a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>">
                            <strong><?= e($row['codigo_solicitud']) ?></strong>
                        </a>
                    </td>
                    <td>
                        <div class="cell-stack">
                            <strong><?= e($row['cliente_nombre']) ?></strong>
                            <span><?= e($row['cliente_identificacion']) ?></span>
                        </div>
                    </td>
                    <td><?= e($row['origen_nombre']) ?> → <?= e($row['destino_nombre']) ?></td>
                    <td><?= status_tag($row['estado']) ?></td>
                    <td><?= status_tag($row['prioridad_actual']) ?></td>
                    <td>
                        <?= format_utc_datetime($row['fecha_entrega_requerida']) ?>
                        <?php
                        $esAbierta = in_array($row['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO'], true);
                        $tsReq = !empty($row['fecha_entrega_requerida']) ? strtotime((string)$row['fecha_entrega_requerida'] . ' UTC') : false;
                        if ($esAbierta && $tsReq !== false && $tsReq < time()):
                        ?>
                            <span class="tag is-danger is-light ml-1">Atrasada</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-bar">
    <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#solicitudes-resultado" hx-swap="innerHTML" hx-include="#solicitudes-filtros">
        <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/solicitudes/lista.php?pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/solicitudes/lista.php?pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
