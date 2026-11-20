<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/reports_service.php';

require_permission('REPORTES_VER');

$section = strtolower(trim((string)($_GET['seccion'] ?? '')));
$format = strtolower(trim((string)($_GET['formato'] ?? 'csv')));
$sections = report_available_sections();
if (!isset($sections[$section]) || !in_array($format, ['csv', 'imprimir'], true)) {
    http_response_code(404);
    exit('Reporte o formato de exportación no válido.');
}

$filters = report_parse_filters($section, $_GET);
if ($filters['error'] !== null) {
    http_response_code(422);
    exit($filters['error']);
}

$periodLabel = report_period_label($filters['desde'], $filters['hasta']);
$generatedAt = format_utc_datetime(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$meta = $sections[$section];

try {
    if ($section === 'solicitudes') {
        $data = report_solicitudes_data($filters, 1, REPORT_EXPORT_MAX_ROWS, true);
    } elseif ($section === 'entregas') {
        $data = report_entregas_data($filters, 1, REPORT_EXPORT_MAX_ROWS, true);
    } else {
        $data = report_dw_historic_data($filters, 1, REPORT_EXPORT_MAX_ROWS, true);
        if (empty($data['available'])) {
            http_response_code(503);
            exit((string)$data['error']);
        }
    }
} catch (PDOException) {
    http_response_code(500);
    exit('No se pudo generar la exportación del reporte.');
}

// Preparar cabeceras y filas tabulares comunes para CSV e Impresión/PDF
$headers = [];
$tableRows = [];
$summaryItems = [];
$sourceText = $meta['source_label'];

if ($section === 'solicitudes') {
    $k = $data['kpis'];
    $headers = ['Código', 'Cliente', 'Identificación', 'Origen', 'Destino', 'Estado', 'Prioridad', 'Fecha solicitud', 'Fecha entrega requerida'];
    foreach ($data['rows'] as $r) {
        $tableRows[] = [
            (string)($r['codigo_solicitud'] ?? ''),
            (string)($r['cliente_nombre'] ?? ''),
            (string)($r['cliente_identificacion'] ?? ''),
            (string)($r['origen_nombre'] ?? ''),
            (string)($r['destino_nombre'] ?? ''),
            humanize_status($r['estado'] ?? ''),
            humanize_status($r['prioridad_actual'] ?? ''),
            format_utc_datetime($r['fecha_solicitud'] ?? null),
            format_utc_datetime($r['fecha_entrega_requerida'] ?? null),
        ];
    }
    $summaryItems = [
        'Total solicitudes' => (string)$k['total_solicitudes'],
        'Pendientes / registradas' => (string)$k['pendientes_asignacion'],
        'En operación' => (string)$k['en_operacion'],
        'Entregadas' => (string)$k['entregadas'],
        'Fallidas' => (string)$k['fallidas'],
        'Canceladas' => (string)$k['canceladas'],
        'Peso total (kg)' => format_cr_measure($k['peso_total_kg']) . ' kg',
    ];
} elseif ($section === 'entregas') {
    $k = $data['kpis'];
    $headers = ['Solicitud', 'Viaje', 'Resultado', 'Receptor efectivo', 'Fecha de resultado'];
    foreach ($data['rows'] as $r) {
        $tableRows[] = [
            (string)($r['codigo_solicitud'] ?? ''),
            'Viaje #' . (int)($r['viaje_id'] ?? 0),
            humanize_status($r['estado'] ?? ''),
            (string)($r['receptor_nombre'] ?: 'No aplica'),
            format_utc_datetime($r['fecha_resultado'] ?? null),
        ];
    }
    $summaryItems = [
        'Resultados registrados' => (string)$k['total_resultados'],
        'Entregadas' => (string)$k['entregadas'],
        'Fallidas' => (string)$k['fallidas'],
        'Tasa de éxito (Entregadas ÷ concluidas)' => $k['tasa_exito_pct'] !== null ? format_cr_measure($k['tasa_exito_pct'], 1) . ' %' : 'Sin datos',
        'Entregas a tiempo' => (string)$k['entregadas_a_tiempo'],
    ];
} else {
    $k = $data['kpis'];
    $ultimaCargaTexto = $data['ultima_carga_utc'] ? format_utc_datetime($data['ultima_carga_utc']) : 'Sin cargas ETL registradas';
    $sourceText = 'Data Warehouse (TransportesSegurosSA_DW · última carga conocida: ' . $ultimaCargaTexto . ')';
    $headers = ['Fecha', 'Ruta', 'Placa vehículo', 'Conductor', 'Cliente (ID)', 'Resultado', 'Puntual', 'Peso (kg)', 'Volumen (m³)', 'Incidencias'];
    foreach ($data['rows'] as $r) {
        $tableRows[] = [
            format_cr_date($r['fecha'] ?? null),
            (string)($r['ruta_nombre'] ?? ''),
            (string)($r['vehiculo_placa'] ?? ''),
            (string)($r['conductor_nombre'] ?? ''),
            (string)($r['cliente_identificacion'] ?? ''),
            humanize_status($r['resultado'] ?? ''),
            !empty($r['entrega_puntual']) ? 'Sí' : 'No',
            format_cr_measure($r['peso_kg'] ?? 0),
            format_cr_measure($r['volumen_m3'] ?? 0),
            (string)(int)($r['incidencias'] ?? 0),
        ];
    }
    $summaryItems = [
        'Entregas históricas' => (string)$k['total_entregas'],
        'Entregadas / Fallidas' => $k['entregadas'] . ' / ' . $k['fallidas'],
        'Tasa de éxito histórica' => $k['tasa_exito_pct'] !== null ? format_cr_measure($k['tasa_exito_pct'], 1) . ' %' : 'Sin datos',
        'Puntualidad histórica' => $k['tasa_puntualidad_pct'] !== null ? format_cr_measure($k['tasa_puntualidad_pct'], 1) . ' %' : 'Sin datos',
        'Peso consolidado' => format_cr_measure($k['peso_total_kg']) . ' kg',
        'Incidencias' => (string)$k['total_incidencias'],
    ];
}

if ($format === 'csv') {
    $stamp = (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Ymd_His');
    $filename = "reporte_{$section}_{$stamp}.csv";
    report_send_csv($filename, $headers, $tableRows);
}

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($meta['label']) ?> — Reporte imprimible · Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/estilos.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body class="print-report-page">
    <div class="print-toolbar no-print">
        <div class="print-toolbar-info">
            <strong>Vista preparada para impresión o Guardar como PDF</strong>
            <span>Sin menús laterales ni elementos interactivos al imprimir.</span>
        </div>
        <div class="buttons">
            <button class="button is-primary" type="button" data-print-report>
                <?= svg_icon('printer') ?>
                <span>Imprimir / Guardar como PDF</span>
            </button>
            <a class="button button-secondary" href="/reportes.php?seccion=<?= e($section) ?>">Volver a reportes</a>
        </div>
    </div>

    <article class="print-sheet">
        <header class="print-sheet-header">
            <div>
                <p class="print-sheet-brand">Transportes Seguros S.A.</p>
                <h1 class="print-sheet-title"><?= e($meta['label']) ?></h1>
                <p class="print-sheet-subtitle"><?= e($meta['subtitle']) ?></p>
            </div>
            <dl class="print-sheet-meta">
                <div><dt>Fuente</dt><dd><?= e($sourceText) ?></dd></div>
                <div><dt>Período</dt><dd><?= e($periodLabel) ?></dd></div>
                <div><dt>Generado</dt><dd><?= e($generatedAt) ?></dd></div>
                <div><dt>Registros</dt><dd><?= count($tableRows) ?> de <?= (int)$data['total'] ?></dd></div>
            </dl>
        </header>

        <?php if (!empty($data['truncated'])): ?>
            <div class="print-limit-note">
                Aviso: esta vista incluye hasta <?= REPORT_EXPORT_MAX_ROWS ?> registros por límite seguro del servidor (total encontrado: <?= (int)$data['total'] ?>). Aplica filtros de fecha para acotar el reporte.
            </div>
        <?php endif; ?>

        <section class="print-kpi-strip" aria-label="Resumen de indicadores">
            <?php foreach ($summaryItems as $kLabel => $kVal): ?>
                <div class="print-kpi-item">
                    <span><?= e($kLabel) ?></span>
                    <strong><?= e($kVal) ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="print-table-wrap">
            <table class="print-table">
                <thead>
                    <tr>
                        <?php foreach ($headers as $h): ?>
                            <th><?= e($h) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$tableRows): ?>
                        <tr>
                            <td colspan="<?= count($headers) ?>" class="print-empty-cell">No hay registros que coincidan con los filtros seleccionados.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($tableRows as $r): ?>
                        <tr>
                            <?php foreach ($r as $cell): ?>
                                <td><?= e($cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </article>
</body>
</html>
