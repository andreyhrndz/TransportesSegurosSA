<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
require_once __DIR__ . '/../app/reports_service.php';

// Compatibilidad segura para marcadores o enlaces previos a la separación de bitácora.
if (strtolower(trim((string)($_GET['seccion'] ?? ''))) === 'auditoria') {
    if (has_permission('AUDITORIA_VER')) {
        header('Location: /auditoria.php', true, 302);
        exit;
    }
    http_response_code(403);
    exit('No tienes permiso para acceder al historial de actividad.');
}

require_permission('REPORTES_VER');

$sections = report_available_sections();
if (!$sections) {
    http_response_code(403);
    exit('No tienes permisos habilitados para consultar reportes.');
}

$section = (string)($_GET['seccion'] ?? array_key_first($sections));
if (!isset($sections[$section])) {
    $section = (string)array_key_first($sections);
}

$dwRoutes = $section === 'historico_dw' ? report_dw_routes() : [];

require_once __DIR__ . '/../app/layout.php';
layout_start(
    'reportes',
    'Reportes',
    'Consultas operacionales en tiempo real, análisis histórico del Data Warehouse y exportación controlada.'
);
?>
<nav class="tabs-bar" aria-label="Tipos de reporte">
    <?php foreach ($sections as $key => $meta): ?>
        <a class="tab-chip <?= $section === $key ? 'active' : '' ?>" href="/reportes.php?seccion=<?= e($key) ?>">
            <?= svg_icon($meta['icon']) ?>
            <span><?= e($meta['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="toolbar-panel report-toolbar" aria-label="Filtros y exportación del reporte">
    <form
        class="filter-form"
        id="reportes-filtros"
        hx-get="/reportes/lista.php"
        hx-target="#reportes-resultado"
        hx-trigger="submit, input changed delay:350ms from:input[type=search], change from:select, change from:input[data-datepicker]"
        hx-sync="this:abort"
        hx-include="this"
        hx-indicator="#reportes-cargando"
    >
        <input type="hidden" name="seccion" value="<?= e($section) ?>">

        <?php if ($section === 'solicitudes'): ?>
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="reporte-buscar" type="search" name="buscar" maxlength="120" placeholder="Código, cliente o destinatario" aria-label="Buscar solicitudes">
            </div>
            <div class="select">
                <select id="reporte-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado de solicitud">
                    <option value="">Todos los estados</option>
                    <option value="REGISTRADA">Registrada</option>
                    <option value="PENDIENTE_ASIGNACION">Pendiente de asignación</option>
                    <option value="ASIGNADA">Asignada</option>
                    <option value="EN_TRANSITO">En tránsito</option>
                    <option value="ENTREGADA">Entregada</option>
                    <option value="ENTREGA_FALLIDA">Entrega fallida</option>
                    <option value="CANCELADA">Cancelada</option>
                </select>
            </div>
            <div class="select">
                <select id="reporte-prioridad" name="prioridad" data-combobox data-allow-clear="false" data-placeholder="Todas las prioridades" aria-label="Prioridad">
                    <option value="">Todas las prioridades</option>
                    <option value="NORMAL">Normal</option>
                    <option value="ALTA">Alta</option>
                    <option value="URGENTE">Urgente</option>
                </select>
            </div>
        <?php elseif ($section === 'entregas'): ?>
            <div class="select">
                <select id="reporte-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los resultados" aria-label="Resultado de entrega">
                    <option value="">Todos los resultados</option>
                    <option value="ENTREGADA">Entregada</option>
                    <option value="FALLIDA">Fallida</option>
                </select>
            </div>
        <?php else: ?>
            <div class="select">
                <select id="reporte-resultado-dw" name="resultado" data-combobox data-allow-clear="false" data-placeholder="Todos los resultados" aria-label="Resultado histórico">
                    <option value="">Todos los resultados</option>
                    <option value="ENTREGADA">Entregada</option>
                    <option value="FALLIDA">Fallida</option>
                </select>
            </div>
            <div class="select">
                <select id="reporte-ruta-dw" name="ruta_key" data-combobox data-allow-clear="false" data-placeholder="Todas las rutas" aria-label="Ruta histórica">
                    <option value="">Todas las rutas</option>
                    <?php foreach ($dwRoutes as $r): ?>
                        <option value="<?= (int)$r['ruta_key'] ?>"><?= e($r['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <input class="input" id="reporte-desde" type="text" name="desde" data-datepicker="date" placeholder="Desde (dd/mm/aaaa)" aria-label="Fecha desde">
        <input class="input" id="reporte-hasta" type="text" name="hasta" data-datepicker="date" placeholder="Hasta (dd/mm/aaaa)" aria-label="Fecha hasta">

        <a class="button button-secondary filter-clear-btn" href="/reportes.php?seccion=<?= e($section) ?>">Limpiar</a>
    </form>

    <div class="report-export-actions">
        <a
            class="button button-secondary"
            href="/reportes/exportar.php?seccion=<?= e($section) ?>&formato=csv"
            data-report-export-link
            data-export-base="/reportes/exportar.php"
            data-export-format="csv"
            data-export-form="#reportes-filtros"
        >
            <?= svg_icon('download') ?>
            <span>Exportar CSV</span>
        </a>
        <a
            class="button button-secondary"
            href="/reportes/exportar.php?seccion=<?= e($section) ?>&formato=imprimir"
            target="_blank"
            rel="noopener"
            data-report-export-link
            data-export-base="/reportes/exportar.php"
            data-export-format="imprimir"
            data-export-form="#reportes-filtros"
        >
            <?= svg_icon('printer') ?>
            <span>Imprimir / PDF</span>
        </a>
    </div>
</section>

<div id="reportes-cargando" class="htmx-indicator mb-2">Calculando indicadores y datos del reporte…</div>
<div id="reportes-resultado" hx-get="/reportes/lista.php?seccion=<?= e($section) ?>" hx-trigger="load" hx-indicator="#reportes-cargando" aria-live="polite">
    <section class="panel-box p-4">Cargando reporte…</section>
</div>
<?php layout_end(); ?>
