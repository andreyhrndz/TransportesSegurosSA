<?php
// El servidor integrado puede derivar recursos inexistentes a index.php.
// Estos nunca deben iniciar sesión ni redirigir a la pantalla de acceso.
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestedPath) && preg_match('/\.(?:avif|bmp|css|gif|ico|jpe?g|js|map|png|svg|webmanifest|webp|woff2?)$/i', $requestedPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
require_login();
require_once __DIR__ . '/../app/layout.php';

$stats = [];
$recientes = [];
$solicitudesPorEstado = [];
$panoramaOperativo = [];
$alertasResumen = [];
$urgentesSinAsignar = [];
$licenciasAlerta = [];

try {
    if (has_permission('CLIENTES_VER')) {
        $stats['clientes'] = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_clientes @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            []
        )['total'];
    }
    if (has_permission('SOLICITUDES_VER')) {
        $solicitudes = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=6,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            []
        );
        $stats['solicitudes'] = $solicitudes['total'];
        $recientes = $solicitudes['rows'];

        $estadosConsulta = [
            'REGISTRADA' => 'Registradas',
            'PENDIENTE_ASIGNACION' => 'Pendientes',
            'ASIGNADA' => 'Asignadas',
            'EN_TRANSITO' => 'En tránsito',
            'ENTREGADA' => 'Entregadas',
            'ENTREGA_FALLIDA' => 'Fallidas',
        ];
        foreach ($estadosConsulta as $codigoEstado => $etiquetaEstado) {
            $solicitudesPorEstado[$etiquetaEstado] = read_procedure_list(
                'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
                [$codigoEstado]
            )['total'];
        }
    }
    if (has_permission('VIAJES_VER')) {
        $planificados = read_procedure_list(
            "DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=N'PLANIFICADO',@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;",
            []
        )['total'];
        $enCurso = read_procedure_list(
            "DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=N'EN_CURSO',@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;",
            []
        )['total'];
        $finalizados = read_procedure_list(
            "DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=N'FINALIZADO',@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;",
            []
        )['total'];
        $stats['viajes'] = $planificados + $enCurso;
        $panoramaOperativo['Viajes planificados'] = $planificados;
        $panoramaOperativo['Viajes en curso'] = $enCurso;
        $panoramaOperativo['Viajes finalizados'] = $finalizados;
    }
    if (has_permission('ENTREGAS_VER')) {
        $entregadas = read_procedure_list(
            "DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas @estado=N'ENTREGADA',@viaje_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;",
            []
        )['total'];
        $stats['entregas'] = $entregadas;
        $panoramaOperativo['Entregas exitosas'] = $entregadas;
    }
    if (has_permission('FLOTAS_VER')) {
        $vehiculosDisponibles = read_procedure_list(
            "DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=N'DISPONIBLE',@solo_activos=1,@buscar=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;",
            []
        )['total'];
        $panoramaOperativo['Unidades disponibles'] = $vehiculosDisponibles;
    }
    try {
        $alertaSets = read_procedure_sets('EXEC dbo.sp_obtener_alertas_panel;', []);
        $alertasResumen = $alertaSets[0][0] ?? [];
        if (has_permission('SOLICITUDES_VER')) {
            $urgentesSinAsignar = $alertaSets[1] ?? [];
        }
        if (has_permission('FLOTAS_VER')) {
            $licenciasAlerta = $alertaSets[2] ?? [];
        }
    } catch (PDOException) {
        $alertasResumen = [];
    }
} catch (PDOException) {
    read_error_response('No se pudo actualizar el resumen de operaciones.');
}

$chartSolicitudesConfig = json_encode([
    'type' => 'doughnut',
    'data' => [
        'labels' => array_keys($solicitudesPorEstado),
        'datasets' => [[
            'data' => array_values($solicitudesPorEstado),
            'backgroundColor' => ['#648FFF', '#F59E0B', '#38BDF8', '#818CF8', '#36C5B2', '#EF4444'],
            'borderColor' => '#1A2636',
            'borderWidth' => 2,
        ]],
    ],
], JSON_UNESCAPED_UNICODE);

$chartPanoramaConfig = json_encode([
    'type' => 'bar',
    'data' => [
        'labels' => array_keys($panoramaOperativo),
        'datasets' => [[
            'label' => 'Registros activos',
            'data' => array_values($panoramaOperativo),
            'backgroundColor' => ['#648FFF', '#38BDF8', '#36C5B2', '#4CD6C3', '#818CF8'],
            'borderRadius' => 6,
        ]],
    ],
    'options' => [
        'plugins' => [
            'legend' => ['display' => false],
        ],
    ],
], JSON_UNESCAPED_UNICODE);

layout_start('dashboard', 'Panel general', 'Resumen de operaciones, estado del servicio y actividad logística reciente.');
?>
<section aria-labelledby="resumen-operativo">
    <h2 id="resumen-operativo" class="is-sr-only">Indicadores operativos</h2>
    <div class="summary-grid">
        <article class="kpi-card">
            <div class="kpi-card-top">
                <p class="kpi-label">Clientes activos</p>
                <span class="kpi-icon-badge"><?= svg_icon('clients') ?></span>
            </div>
            <p class="kpi-value"><?= array_key_exists('clientes', $stats) ? (int)$stats['clientes'] : '—' ?></p>
            <div class="kpi-meta">
                <span>Personas y empresas habilitadas</span>
                <?php if (has_permission('CLIENTES_VER')): ?>
                    <a class="kpi-link" href="/clientes.php"><span>Directorio</span><?= svg_icon('arrow-up-right') ?></a>
                <?php endif; ?>
            </div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card-top">
                <p class="kpi-label">Solicitudes registradas</p>
                <span class="kpi-icon-badge"><?= svg_icon('requests') ?></span>
            </div>
            <p class="kpi-value"><?= array_key_exists('solicitudes', $stats) ? (int)$stats['solicitudes'] : '—' ?></p>
            <div class="kpi-meta">
                <span>Órdenes de transporte totales</span>
                <?php if (has_permission('SOLICITUDES_VER')): ?>
                    <a class="kpi-link" href="/solicitudes.php"><span>Gestionar</span><?= svg_icon('arrow-up-right') ?></a>
                <?php endif; ?>
            </div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card-top">
                <p class="kpi-label">Viajes activos</p>
                <span class="kpi-icon-badge is-warning"><?= svg_icon('trips') ?></span>
            </div>
            <p class="kpi-value"><?= array_key_exists('viajes', $stats) ? (int)$stats['viajes'] : '—' ?></p>
            <div class="kpi-meta">
                <span>Planificados o en ruta actual</span>
                <?php if (has_permission('VIAJES_VER')): ?>
                    <a class="kpi-link" href="/viajes.php"><span>Ver despachos</span><?= svg_icon('arrow-up-right') ?></a>
                <?php endif; ?>
            </div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card-top">
                <p class="kpi-label">Entregas exitosas</p>
                <span class="kpi-icon-badge is-accent"><?= svg_icon('deliveries') ?></span>
            </div>
            <p class="kpi-value"><?= array_key_exists('entregas', $stats) ? (int)$stats['entregas'] : '—' ?></p>
            <div class="kpi-meta">
                <span>Resultados verificados en destino</span>
                <?php if (has_permission('ENTREGAS_VER')): ?>
                    <a class="kpi-link" href="/entregas.php"><span>Consultar</span><?= svg_icon('arrow-up-right') ?></a>
                <?php endif; ?>
            </div>
        </article>
    </div>
</section>

<?php
$pendientesEnt = has_permission('ENTREGAS_VER') ? (int)($alertasResumen['entregas_pendientes'] ?? 0) : 0;
$sinAsignarCnt = has_permission('SOLICITUDES_VER') ? (int)($alertasResumen['solicitudes_sin_asignar'] ?? 0) : 0;
$atrasadasCnt = has_permission('SOLICITUDES_VER') ? (int)($alertasResumen['solicitudes_atrasadas'] ?? 0) : 0;
$incAbiertasCnt = has_permission('INCIDENCIAS_VER') ? (int)($alertasResumen['incidencias_abiertas'] ?? 0) : 0;
$licAlertaCnt = has_permission('FLOTAS_VER') ? (int)($alertasResumen['licencias_alerta'] ?? 0) : 0;
$hayAlertas = ($pendientesEnt + $sinAsignarCnt + $atrasadasCnt + $incAbiertasCnt + $licAlertaCnt) > 0;
?>
<?php if ($hayAlertas): ?>
<section class="panel-box" aria-labelledby="centro-atencion-hoy">
    <div class="panel-box-header">
        <div>
            <h2 id="centro-atencion-hoy" class="panel-box-title">Centro de atención operativa</h2>
            <p class="panel-box-subtitle">Pendientes que requieren acción inmediata del equipo de despacho y flota.</p>
        </div>
    </div>
    <div class="buttons mb-3" style="flex-wrap: wrap;">
        <?php if ($atrasadasCnt > 0): ?>
            <a class="button is-small is-danger" href="/solicitudes.php?alerta=atrasadas">
                <?= svg_icon('alert-triangle') ?>
                <span><?= $atrasadasCnt ?> solicitud<?= $atrasadasCnt === 1 ? '' : 'es' ?> atrasada<?= $atrasadasCnt === 1 ? '' : 's' ?></span>
            </a>
        <?php endif; ?>
        <?php if ($sinAsignarCnt > 0): ?>
            <a class="button is-small is-warning" href="/solicitudes.php?alerta=sin_asignar">
                <?= svg_icon('requests') ?>
                <span><?= $sinAsignarCnt ?> por asignar a viaje</span>
            </a>
        <?php endif; ?>
        <?php if ($pendientesEnt > 0): ?>
            <a class="button is-small is-primary" href="/entregas.php?seccion=pendientes">
                <?= svg_icon('deliveries') ?>
                <span><?= $pendientesEnt ?> entrega<?= $pendientesEnt === 1 ? '' : 's' ?> en tránsito</span>
            </a>
        <?php endif; ?>
        <?php if ($incAbiertasCnt > 0): ?>
            <a class="button is-small button-secondary" href="/entregas.php?seccion=incidencias&estado=ABIERTA">
                <?= svg_icon('alert-triangle') ?>
                <span><?= $incAbiertasCnt ?> incidencia<?= $incAbiertasCnt === 1 ? '' : 's' ?> abierta<?= $incAbiertasCnt === 1 ? '' : 's' ?></span>
            </a>
        <?php endif; ?>
        <?php if ($licAlertaCnt > 0): ?>
            <a class="button is-small is-warning" href="/flota.php?seccion=conductores&licencia_alerta=1">
                <?= svg_icon('shield') ?>
                <span><?= $licAlertaCnt ?> licencia<?= $licAlertaCnt === 1 ? '' : 's' ?> vencida<?= $licAlertaCnt === 1 ? '' : 's' ?> o por vencer (≤30d)</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($urgentesSinAsignar): ?>
        <div class="table-container mb-3">
            <table class="table is-fullwidth is-hoverable is-narrow">
                <thead>
                    <tr>
                        <th>Solicitud sin asignar</th>
                        <th>Cliente</th>
                        <th>Trayecto</th>
                        <th>Prioridad</th>
                        <th>Entrega requerida</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($urgentesSinAsignar as $u): ?>
                        <tr>
                            <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$u['solicitud_id'] ?>"><strong><?= e($u['codigo_solicitud']) ?></strong></a></td>
                            <td><?= e($u['cliente_nombre']) ?></td>
                            <td><?= e($u['origen_nombre'] . ' → ' . $u['destino_nombre']) ?></td>
                            <td>
                                <?= status_tag($u['prioridad_actual']) ?>
                                <?php if (!empty($u['esta_atrasada'])): ?>
                                    <span class="tag is-danger is-light ml-1">Atrasada</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_utc_datetime($u['fecha_entrega_requerida'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($licenciasAlerta): ?>
        <div class="notification notification-warning py-2 px-3 mb-0">
            <strong>Alertas de licencias de conductores (Ley 9078):</strong>
            <?php
            $licItems = [];
            foreach ($licenciasAlerta as $la) {
                $dias = (int)$la['dias_restantes'];
                $estadoLic = $dias < 0 ? 'VENCIDA hace ' . abs($dias) . ' d' : ($dias === 0 ? 'vence HOY' : 'vence en ' . $dias . ' d');
                $licItems[] = e($la['conductor_nombre']) . ' (' . e($la['categoria_codigo']) . ' · ' . $estadoLic . ')';
            }
            echo implode(' · ', $licItems);
            ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($solicitudesPorEstado || $panoramaOperativo): ?>
<section class="dashboard-charts-grid" aria-label="Analítica operativa">
    <?php if ($solicitudesPorEstado): ?>
    <article class="chart-card">
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title">Distribución de solicitudes por estado</h2>
                <p class="panel-box-subtitle">Proporción actual de órdenes según su etapa logística.</p>
            </div>
            <?= svg_icon('requests') ?>
        </div>
        <div class="chart-container">
            <canvas id="chart-solicitudes-estado" aria-label="Gráfica de solicitudes por estado" role="img" data-chart-config="<?= e((string)$chartSolicitudesConfig) ?>"></canvas>
        </div>
    </article>
    <?php endif; ?>
    <?php if ($panoramaOperativo): ?>
    <article class="chart-card">
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title">Capacidad y ejecución operativa</h2>
                <p class="panel-box-subtitle">Balance entre viajes programados, entregas y unidades disponibles.</p>
            </div>
            <?= svg_icon('reports') ?>
        </div>
        <div class="chart-container">
            <canvas id="chart-panorama-operativo" aria-label="Gráfica de capacidad y ejecución operativa" role="img" data-chart-config="<?= e((string)$chartPanoramaConfig) ?>"></canvas>
        </div>
    </article>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="quick-actions-grid" aria-label="Accesos directos">
    <?php if (has_permission('SOLICITUDES_VER')): ?>
        <a class="quick-action-card" href="/solicitudes.php">
            <span class="kpi-icon-badge"><?= svg_icon('requests') ?></span>
            <div>
                <strong>Solicitudes de transporte</strong>
                <span>Registrar carga, prioridad y ruta</span>
            </div>
        </a>
    <?php endif; ?>
    <?php if (has_permission('VIAJES_VER')): ?>
        <a class="quick-action-card" href="/viajes.php">
            <span class="kpi-icon-badge is-warning"><?= svg_icon('trips') ?></span>
            <div>
                <strong>Despacho de viajes</strong>
                <span>Asignar vehículo, conductor y paradas</span>
            </div>
        </a>
    <?php endif; ?>
    <?php if (has_any_permission(['ENTREGAS_VER', 'INCIDENCIAS_VER', 'DOCUMENTOS_VER'])): ?>
        <a class="quick-action-card" href="/entregas.php">
            <span class="kpi-icon-badge is-accent"><?= svg_icon('deliveries') ?></span>
            <div>
                <strong>Entregas e incidencias</strong>
                <span>Confirmar recepción y respaldos</span>
            </div>
        </a>
    <?php endif; ?>
    <?php if (has_any_permission(['FLOTAS_VER', 'RUTAS_VER', 'UBICACIONES_VER'])): ?>
        <a class="quick-action-card" href="/flota.php">
            <span class="kpi-icon-badge"><?= svg_icon('fleet') ?></span>
            <div>
                <strong>Flota y catálogos</strong>
                <span>Unidades, conductores, rutas y sedes</span>
            </div>
        </a>
    <?php endif; ?>
</section>

<section class="panel-box table-panel" aria-labelledby="solicitudes-recientes">
    <div class="panel-box-header">
        <div>
            <h2 id="solicitudes-recientes" class="panel-box-title">Solicitudes recientes</h2>
            <p class="panel-box-subtitle">Últimos movimientos registrados en la operación.</p>
        </div>
        <?php if (has_permission('SOLICITUDES_VER')): ?>
            <a class="button is-small button-secondary" href="/solicitudes.php">
                <span>Ver todas</span>
                <?= svg_icon('chevron-right') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php if (!$recientes): ?>
        <?= empty_state('Aún no hay solicitudes', 'Las solicitudes recientes aparecerán aquí cuando existan registros.') ?>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-hoverable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Cliente</th>
                        <th>Ruta</th>
                        <th>Estado</th>
                        <th>Prioridad</th>
                        <th>Fecha de solicitud</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recientes as $s): ?>
                        <tr>
                            <td>
                                <a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$s['solicitud_id'] ?>">
                                    <strong><?= e($s['codigo_solicitud']) ?></strong>
                                </a>
                            </td>
                            <td><?= e($s['cliente_nombre'] ?? 'Cliente registrado') ?></td>
                            <td><?= e(($s['origen_nombre'] ?? 'Origen') . ' → ' . ($s['destino_nombre'] ?? 'Destino')) ?></td>
                            <td><?= status_tag($s['estado']) ?></td>
                            <td><?= status_tag($s['prioridad_actual']) ?></td>
                            <td><?= e(format_utc_datetime($s['fecha_solicitud'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
