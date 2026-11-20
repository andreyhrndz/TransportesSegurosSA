<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/reports_service.php';
header('Cache-Control: no-store');

$section = strtolower(trim((string)($_GET['seccion'] ?? '')));
if ($section === 'auditoria') {
    if (has_permission('AUDITORIA_VER')) {
        if (is_htmx_request()) {
            header('HX-Redirect: /auditoria.php');
            exit;
        }
        header('Location: /auditoria.php', true, 302);
        exit;
    }
    http_response_code(403);
    exit(ui_notice('No tienes permiso para acceder al historial de actividad.', 'danger'));
}

require_permission('REPORTES_VER');
$sections = report_available_sections();
if (!isset($sections[$section])) {
    http_response_code(404);
    exit(ui_notice('El reporte solicitado no existe o no tienes permiso para consultarlo.', 'danger'));
}

$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 20;
$filters = report_parse_filters($section, $_GET);
if ($filters['error'] !== null) {
    http_response_code(422);
    exit('<section class="panel-box p-4">' . ui_notice($filters['error'], 'warning') . '</section>');
}

$periodLabel = report_period_label($filters['desde'], $filters['hasta']);

try {
    if ($section === 'solicitudes') {
        $data = report_solicitudes_data($filters, $page, $perPage, false);
    } elseif ($section === 'entregas') {
        $data = report_entregas_data($filters, $page, $perPage, false);
    } else {
        $data = report_dw_historic_data($filters, $page, $perPage, false);
    }
} catch (PDOException) {
    read_error_response('No se pudo consultar el reporte.');
}

$total = (int)$data['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>

<?php if ($section === 'solicitudes'): ?>
    <?php
    $k = $data['kpis'];
    $statusLabels = [];
    $statusCounts = [];
    $statusColors = [];
    $palette = [
        'REGISTRADA' => '#60A5FA',
        'PENDIENTE_ASIGNACION' => '#FBBF24',
        'ASIGNADA' => '#38BDF8',
        'EN_TRANSITO' => '#818CF8',
        'ENTREGADA' => '#34D399',
        'ENTREGA_FALLIDA' => '#F87171',
        'CANCELADA' => '#94A3B8',
    ];
    foreach ($data['by_status'] as $st) {
        $code = (string)$st['estado'];
        $statusLabels[] = humanize_status($code);
        $statusCounts[] = (int)$st['cantidad'];
        $statusColors[] = $palette[$code] ?? '#64748B';
    }

    $prioLabels = [];
    $prioCounts = [];
    $prioColors = [];
    $prioPalette = ['URGENTE' => '#F87171', 'ALTA' => '#FBBF24', 'NORMAL' => '#38BDF8'];
    foreach ($data['by_priority'] as $pr) {
        $code = (string)$pr['prioridad'];
        $prioLabels[] = humanize_status($code);
        $prioCounts[] = (int)$pr['cantidad'];
        $prioColors[] = $prioPalette[$code] ?? '#64748B';
    }

    $statusChartCfg = json_encode([
        'type' => 'doughnut',
        'data' => [
            'labels' => $statusLabels,
            'datasets' => [[
                'label' => 'Solicitudes',
                'data' => $statusCounts,
                'backgroundColor' => $statusColors,
                'borderWidth' => 0,
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $prioChartCfg = json_encode([
        'type' => 'bar',
        'data' => [
            'labels' => $prioLabels,
            'datasets' => [[
                'label' => 'Solicitudes por prioridad',
                'data' => $prioCounts,
                'backgroundColor' => $prioColors,
                'borderRadius' => 6,
                'maxBarThickness' => 56,
            ]],
        ],
        'options' => ['plugins' => ['legend' => ['display' => false]]],
    ], JSON_UNESCAPED_UNICODE);
    ?>
    <div class="report-source-banner">
        <?= svg_icon('requests') ?>
        <span><strong>Fuente:</strong> Base operacional (datos en tiempo real) · <strong>Período:</strong> <?= e($periodLabel) ?></span>
    </div>

    <section class="stats-grid report-kpi-grid" aria-label="Indicadores de solicitudes">
        <article class="kpi-card">
            <span class="kpi-label">Total solicitudes</span>
            <strong class="kpi-value"><?= (int)$k['total_solicitudes'] ?></strong>
            <span class="kpi-meta">Según filtros activos</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Pendientes / registradas</span>
            <strong class="kpi-value"><?= (int)$k['pendientes_asignacion'] ?></strong>
            <span class="kpi-meta">Aún sin despacho asignado</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">En operación</span>
            <strong class="kpi-value"><?= (int)$k['en_operacion'] ?></strong>
            <span class="kpi-meta">Asignadas o en tránsito</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Entregadas / fallidas</span>
            <strong class="kpi-value"><?= (int)$k['entregadas'] ?> / <?= (int)$k['fallidas'] ?></strong>
            <span class="kpi-meta">Canceladas: <?= (int)$k['canceladas'] ?></span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Peso total declarado</span>
            <strong class="kpi-value"><?= e(format_cr_measure($k['peso_total_kg'])) ?> kg</strong>
            <span class="kpi-meta">Suma de mercancías filtradas</span>
        </article>
    </section>

    <?php if ($statusLabels || $prioLabels): ?>
        <div class="report-charts-grid">
            <section class="panel-box">
                <div class="panel-box-header">
                    <h3 class="panel-box-title">Distribución por estado</h3>
                </div>
                <div class="report-chart-box">
                    <canvas data-chart-config="<?= e($statusChartCfg) ?>" aria-label="Gráfico de solicitudes por estado"></canvas>
                </div>
            </section>
            <section class="panel-box">
                <div class="panel-box-header">
                    <h3 class="panel-box-title">Distribución por prioridad actual</h3>
                </div>
                <div class="report-chart-box">
                    <canvas data-chart-config="<?= e($prioChartCfg) ?>" aria-label="Gráfico de solicitudes por prioridad"></canvas>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <section class="panel-box table-panel">
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title">Detalle de solicitudes</h2>
                <p class="panel-box-subtitle">Listado ordenado de la solicitud más reciente a la más antigua.</p>
            </div>
            <span class="status-tag status-tag-info"><?= $total ?> registro<?= $total === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-container">
            <table class="table is-fullwidth is-hoverable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Cliente</th>
                        <th>Origen → Destino</th>
                        <th>Estado</th>
                        <th>Prioridad</th>
                        <th>Fecha solicitud</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['rows']): ?>
                        <tr><td colspan="6"><?= empty_state('Sin solicitudes en el período', 'No se encontraron solicitudes que coincidan con los filtros indicados.') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['rows'] as $row): ?>
                        <tr>
                            <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong><?= e($row['codigo_solicitud']) ?></strong></a></td>
                            <td><?= e($row['cliente_nombre']) ?></td>
                            <td><?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '')) ?></td>
                            <td><?= status_tag($row['estado']) ?></td>
                            <td><?= status_tag($row['prioridad_actual']) ?></td>
                            <td class="is-nowrap"><?= e(format_utc_datetime($row['fecha_solicitud'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-bar">
            <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
            <div class="buttons" hx-target="#reportes-resultado" hx-include="#reportes-filtros">
                <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=solicitudes&pagina=<?= $page - 1 ?>">Anterior</button>
                <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
                <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=solicitudes&pagina=<?= $page + 1 ?>">Siguiente</button>
            </div>
        </div>
    </section>

<?php elseif ($section === 'entregas'): ?>
    <?php
    $k = $data['kpis'];
    $tasaLabel = $k['tasa_exito_pct'] !== null ? format_cr_measure($k['tasa_exito_pct'], 1) . ' %' : 'Sin datos';
    $dailyLabels = [];
    $dailyEnt = [];
    $dailyFal = [];
    foreach ($data['daily'] as $d) {
        $dailyLabels[] = format_cr_date($d['fecha_dia']);
        $dailyEnt[] = (int)$d['entregadas'];
        $dailyFal[] = (int)$d['fallidas'];
    }
    $dailyChartCfg = json_encode([
        'type' => 'bar',
        'data' => [
            'labels' => $dailyLabels,
            'datasets' => [
                ['label' => 'Entregadas', 'data' => $dailyEnt, 'backgroundColor' => '#34D399', 'borderRadius' => 5, 'maxBarThickness' => 56],
                ['label' => 'Fallidas', 'data' => $dailyFal, 'backgroundColor' => '#F87171', 'borderRadius' => 5, 'maxBarThickness' => 56],
            ],
        ],
        'options' => ['plugins' => ['legend' => ['display' => true]]],
    ], JSON_UNESCAPED_UNICODE);
    ?>
    <div class="report-source-banner">
        <?= svg_icon('deliveries') ?>
        <span><strong>Fuente:</strong> Base operacional (datos en tiempo real) · <strong>Período:</strong> <?= e($periodLabel) ?></span>
    </div>

    <section class="stats-grid report-kpi-grid" aria-label="Indicadores de entregas">
        <article class="kpi-card">
            <span class="kpi-label">Resultados registrados</span>
            <strong class="kpi-value"><?= (int)$k['total_resultados'] ?></strong>
            <span class="kpi-meta">Entregadas + fallidas en el período</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Entregas exitosas</span>
            <strong class="kpi-value"><?= (int)$k['entregadas'] ?></strong>
            <span class="kpi-meta">Con recepción confirmada</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Entregas fallidas</span>
            <strong class="kpi-value"><?= (int)$k['fallidas'] ?></strong>
            <span class="kpi-meta">Requieren seguimiento o reprogramación</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Tasa de éxito</span>
            <strong class="kpi-value"><?= e($tasaLabel) ?></strong>
            <span class="kpi-meta">Entregadas ÷ (Entregadas + Fallidas)</span>
        </article>
        <article class="kpi-card">
            <span class="kpi-label">Entregas a tiempo</span>
            <strong class="kpi-value"><?= (int)$k['entregadas_a_tiempo'] ?></strong>
            <span class="kpi-meta">Recibidas ≤ fecha requerida</span>
        </article>
    </section>

    <?php if ($dailyLabels): ?>
        <section class="panel-box mb-4">
            <div class="panel-box-header">
                <h3 class="panel-box-title">Resultados diarios de entrega</h3>
            </div>
            <div class="report-chart-box">
                <canvas data-chart-config="<?= e($dailyChartCfg) ?>" aria-label="Gráfico diario de entregas exitosas y fallidas"></canvas>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel-box table-panel">
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title">Detalle de entregas</h2>
                <p class="panel-box-subtitle">Resultados operativos registrados por viaje y solicitud.</p>
            </div>
            <span class="status-tag status-tag-info"><?= $total ?> registro<?= $total === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-container">
            <table class="table is-fullwidth is-hoverable">
                <thead>
                    <tr>
                        <th>Solicitud</th>
                        <th>Viaje</th>
                        <th>Resultado</th>
                        <th>Receptor efectivo</th>
                        <th>Fecha de resultado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['rows']): ?>
                        <tr><td colspan="5"><?= empty_state('Sin entregas en el período', 'No hay resultados de entrega registrados para los filtros seleccionados.') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['rows'] as $row): ?>
                        <tr>
                            <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong><?= e($row['codigo_solicitud']) ?></strong></a></td>
                            <td><a class="table-link" href="/viajes/detalle.php?id=<?= (int)$row['viaje_id'] ?>">Viaje #<?= (int)$row['viaje_id'] ?></a></td>
                            <td><?= status_tag($row['estado']) ?></td>
                            <td><?= e($row['receptor_nombre'] ?: 'No aplica') ?></td>
                            <td class="is-nowrap"><?= e(format_utc_datetime($row['fecha_resultado'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-bar">
            <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
            <div class="buttons" hx-target="#reportes-resultado" hx-include="#reportes-filtros">
                <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=entregas&pagina=<?= $page - 1 ?>">Anterior</button>
                <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
                <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=entregas&pagina=<?= $page + 1 ?>">Siguiente</button>
            </div>
        </div>
    </section>

<?php else: ?>
    <?php if (empty($data['available'])): ?>
        <section class="panel-box p-4">
            <?= ui_notice((string)$data['error'], 'warning') ?>
        </section>
    <?php else: ?>
        <?php
        $k = $data['kpis'];
        $ultimaCargaTexto = $data['ultima_carga_utc'] ? format_utc_datetime($data['ultima_carga_utc']) : 'Sin cargas ETL registradas';
        $tasaExitoDw = $k['tasa_exito_pct'] !== null ? format_cr_measure($k['tasa_exito_pct'], 1) . ' %' : 'Sin datos';
        $tasaPuntDw = $k['tasa_puntualidad_pct'] !== null ? format_cr_measure($k['tasa_puntualidad_pct'], 1) . ' %' : 'Sin datos';

        $routeLabels = [];
        $routeEnt = [];
        $routeFal = [];
        foreach ($data['by_route'] as $r) {
            $routeLabels[] = (string)$r['ruta_nombre'];
            $routeEnt[] = (int)$r['entregadas'];
            $routeFal[] = (int)$r['fallidas'];
        }
        $routeChartCfg = json_encode([
            'type' => 'bar',
            'data' => [
                'labels' => $routeLabels,
                'datasets' => [
                    ['label' => 'Entregadas', 'data' => $routeEnt, 'backgroundColor' => '#34D399', 'borderRadius' => 5, 'maxBarThickness' => 56],
                    ['label' => 'Fallidas', 'data' => $routeFal, 'backgroundColor' => '#F87171', 'borderRadius' => 5, 'maxBarThickness' => 56],
                ],
            ],
            'options' => ['plugins' => ['legend' => ['display' => true]]],
        ], JSON_UNESCAPED_UNICODE);

        $trendLabels = [];
        $trendKg = [];
        foreach ($data['trend'] as $t) {
            $trendLabels[] = format_cr_date($t['fecha']);
            $trendKg[] = (float)$t['peso_kg'];
        }
        $trendChartCfg = json_encode([
            'type' => 'bar',
            'data' => [
                'labels' => $trendLabels,
                'datasets' => [[
                    'label' => 'Peso movido (kg)',
                    'data' => $trendKg,
                    'backgroundColor' => '#38BDF8',
                    'borderRadius' => 5,
                    'maxBarThickness' => 56,
                ]],
            ],
            'options' => ['plugins' => ['legend' => ['display' => false]]],
        ], JSON_UNESCAPED_UNICODE);
        ?>
        <div class="report-source-banner report-source-dw" data-dw-source="<?= e($data['dw_db_name']) ?>">
            <?= svg_icon('database') ?>
            <div>
                <strong>Data Warehouse de solo lectura (última carga conocida: <?= e($ultimaCargaTexto) ?>)</strong>
                <span> · Datos históricos consolidados por ETL en <code><?= e($data['dw_db_name']) ?></code> (<?= (int)$data['total_hechos_dw'] ?> hecho(s)); puede no coincidir con cambios operativos recientes. Período filtrado: <?= e($periodLabel) ?>.</span>
            </div>
        </div>

        <section class="stats-grid report-kpi-grid" aria-label="Indicadores históricos del Data Warehouse">
            <article class="kpi-card">
                <span class="kpi-label">Entregas históricas</span>
                <strong class="kpi-value"><?= (int)$k['total_entregas'] ?></strong>
                <span class="kpi-meta">Entregadas: <?= (int)$k['entregadas'] ?> · Fallidas: <?= (int)$k['fallidas'] ?></span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Tasa de éxito histórica</span>
                <strong class="kpi-value"><?= e($tasaExitoDw) ?></strong>
                <span class="kpi-meta">Entregadas ÷ (Entregadas + Fallidas)</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Puntualidad histórica</span>
                <strong class="kpi-value"><?= e($tasaPuntDw) ?></strong>
                <span class="kpi-meta"><?= (int)$k['entregadas_puntuales'] ?> entrega(s) puntual(es)</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Carga consolidada</span>
                <strong class="kpi-value"><?= e(format_cr_measure($k['peso_total_kg'])) ?> kg</strong>
                <span class="kpi-meta">Volumen: <?= e(format_cr_measure($k['volumen_total_m3'])) ?> m³</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Incidencias registradas</span>
                <strong class="kpi-value"><?= (int)$k['total_incidencias'] ?></strong>
                <span class="kpi-meta">Asociadas a entregas en DW</span>
            </article>
        </section>

        <?php if ($routeLabels || $trendLabels): ?>
            <div class="report-charts-grid">
                <section class="panel-box">
                    <div class="panel-box-header">
                        <h3 class="panel-box-title">Desempeño por ruta (DW)</h3>
                    </div>
                    <div class="report-chart-box">
                        <canvas data-chart-config="<?= e($routeChartCfg) ?>" aria-label="Gráfico histórico por ruta"></canvas>
                    </div>
                </section>
                <section class="panel-box">
                    <div class="panel-box-header">
                        <h3 class="panel-box-title">Volumen de carga histórica por fecha (kg)</h3>
                    </div>
                    <div class="report-chart-box">
                        <canvas data-chart-config="<?= e($trendChartCfg) ?>" aria-label="Gráfico histórico de peso transportado"></canvas>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <section class="panel-box table-panel">
            <div class="panel-box-header">
                <div>
                    <h2 class="panel-box-title">Hechos históricos de entrega (esquema en estrella)</h2>
                    <p class="panel-box-subtitle">Datos consultados directamente desde <code>fact_entregas</code> y sus cinco dimensiones en modo solo lectura.</p>
                </div>
                <span class="status-tag status-tag-info"><?= $total ?> hecho<?= $total === 1 ? '' : 's' ?></span>
            </div>
            <div class="table-container">
                <table class="table is-fullwidth is-hoverable">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Ruta</th>
                            <th>Vehículo / Conductor</th>
                            <th>Cliente (ID)</th>
                            <th>Resultado</th>
                            <th>Puntual</th>
                            <th class="has-text-right">Peso / Incidencias</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$data['rows']): ?>
                            <tr><td colspan="7"><?= empty_state('Sin hechos históricos para este filtro', 'El Data Warehouse no registra entregas que coincidan con los filtros actuales o aún no tiene cargas para este rango.') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($data['rows'] as $row): ?>
                            <tr>
                                <td class="is-nowrap"><?= e(format_cr_date($row['fecha'])) ?></td>
                                <td><strong><?= e($row['ruta_nombre']) ?></strong></td>
                                <td>
                                    <div class="cell-stack">
                                        <strong><code><?= e($row['vehiculo_placa']) ?></code></strong>
                                        <span><?= e($row['conductor_nombre']) ?></span>
                                    </div>
                                </td>
                                <td><code><?= e($row['cliente_identificacion']) ?></code></td>
                                <td><?= status_tag($row['resultado']) ?></td>
                                <td><?= !empty($row['entrega_puntual']) ? '<span class="status-tag status-tag-success">Puntual</span>' : '<span class="status-tag status-tag-neutral">No puntual</span>' ?></td>
                                <td class="has-text-right">
                                    <div class="cell-stack">
                                        <strong><?= e(format_cr_measure($row['peso_kg'])) ?> kg</strong>
                                        <span><?= (int)$row['incidencias'] ?> incidencia<?= (int)$row['incidencias'] === 1 ? '' : 's' ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-bar">
                <span><?= $total ?> hecho<?= $total === 1 ? '' : 's' ?></span>
                <div class="buttons" hx-target="#reportes-resultado" hx-include="#reportes-filtros">
                    <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=historico_dw&pagina=<?= $page - 1 ?>">Anterior</button>
                    <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
                    <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=historico_dw&pagina=<?= $page + 1 ?>">Siguiente</button>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
