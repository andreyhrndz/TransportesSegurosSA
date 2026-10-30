<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';

$sections = [];
if (has_permission('REPORTES_VER') && has_permission('SOLICITUDES_VER')) {
    $sections['solicitudes'] = ['Solicitudes', 'SOLICITUDES_VER', 'requests'];
}
if (has_permission('REPORTES_VER') && has_permission('FLOTAS_VER')) {
    $sections['flota'] = ['Flota vehicular', 'FLOTAS_VER', 'fleet'];
}
if (has_permission('REPORTES_VER') && has_permission('VIAJES_VER')) {
    $sections['viajes'] = ['Viajes', 'VIAJES_VER', 'trips'];
}
if (has_permission('REPORTES_VER') && has_permission('ENTREGAS_VER')) {
    $sections['entregas'] = ['Entregas', 'ENTREGAS_VER', 'deliveries'];
}
if (has_permission('AUDITORIA_VER')) {
    $sections['auditoria'] = ['Historial de actividad', 'AUDITORIA_VER', 'shield'];
}
require_any_permission(array_column($sections, 1));

$section = (string)($_GET['seccion'] ?? array_key_first($sections));
if (!isset($sections[$section])) {
    $section = (string)array_key_first($sections);
}
require_permission($sections[$section][1]);
if ($section !== 'auditoria') {
    require_permission('REPORTES_VER');
}
require_once __DIR__ . '/../app/layout.php';
layout_start('reportes', 'Reportes', 'Consulta de operaciones, indicadores históricos y registro de actividad del sistema.');
?>
<nav class="tabs-bar" aria-label="Tipos de reporte">
    <?php foreach ($sections as $key => [$label, , $icon]): ?>
        <a class="tab-chip <?= $section === $key ? 'active' : '' ?>" href="/reportes.php?seccion=<?= e($key) ?>">
            <?= svg_icon($icon) ?>
            <span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="toolbar-panel" aria-label="Filtros del reporte">
    <form class="filter-form" id="reportes-filtros" hx-get="/reportes/lista.php" hx-target="#reportes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <?php if ($section === 'solicitudes'): ?>
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="reporte-buscar" type="search" name="buscar" maxlength="120" placeholder="Código, cliente o destinatario" aria-label="Buscar solicitudes">
            </div>
            <div class="select">
                <select id="reporte-estado" name="estado" aria-label="Estado de solicitud">
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
        <?php elseif ($section === 'flota'): ?>
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="reporte-buscar" type="search" name="buscar" maxlength="80" placeholder="Placa, marca o modelo" aria-label="Buscar vehículo">
            </div>
            <div class="select">
                <select id="reporte-estado" name="estado" aria-label="Estado del vehículo">
                    <option value="">Todos los estados</option>
                    <option value="DISPONIBLE">Disponible</option>
                    <option value="ASIGNADO">Asignado</option>
                    <option value="MANTENIMIENTO">En mantenimiento</option>
                    <option value="FUERA_SERVICIO">Fuera de servicio</option>
                </select>
            </div>
        <?php elseif ($section === 'auditoria'): ?>
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="reporte-entidad" type="search" name="entidad" maxlength="100" placeholder="Módulo o entidad (ej. solicitudes)" aria-label="Entidad">
            </div>
            <div class="input-with-icon">
                <?= svg_icon('filter', '', 'input-icon') ?>
                <input class="input" id="reporte-registro" type="search" name="registro_id" maxlength="100" placeholder="Identificador del registro" aria-label="Identificador de registro">
            </div>
        <?php else: ?>
            <div class="select">
                <select id="reporte-estado" name="estado" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <?php if ($section === 'viajes'): ?>
                        <option value="PLANIFICADO">Planificado</option>
                        <option value="EN_CURSO">En curso</option>
                        <option value="FINALIZADO">Finalizado</option>
                        <option value="CANCELADO">Cancelado</option>
                    <?php else: ?>
                        <option value="ENTREGADA">Entregada</option>
                        <option value="FALLIDA">Fallida</option>
                    <?php endif; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($section !== 'flota'): ?>
            <input class="input" id="reporte-desde" type="text" name="desde" data-datepicker="date" placeholder="Desde (dd/mm/aaaa)" aria-label="Fecha desde">
            <input class="input" id="reporte-hasta" type="text" name="hasta" data-datepicker="date" placeholder="Hasta (dd/mm/aaaa)" aria-label="Fecha hasta">
        <?php endif; ?>
    </form>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title"><?= e($sections[$section][0]) ?></h2>
            <p class="panel-box-subtitle">
                <?= $section === 'auditoria'
                    ? 'Registro cronológico de eventos realizados en la plataforma.'
                    : 'Detalle operativo actualizado según los filtros seleccionados.' ?>
            </p>
        </div>
    </div>
    <div id="reportes-resultado" hx-get="/reportes/lista.php?seccion=<?= e($section) ?>" hx-trigger="load"></div>
</section>
<?php layout_end(); ?>
