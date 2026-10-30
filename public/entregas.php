<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
require_once __DIR__ . '/../app/operational_writes.php';

$sections = [
    'entregas' => ['Entregas', 'ENTREGAS_VER', 'deliveries'],
    'incidencias' => ['Incidencias', 'INCIDENCIAS_VER', 'alert-triangle'],
    'notificaciones' => ['Notificaciones', 'NOTIFICACIONES_VER', 'bell'],
    'documentos' => ['Documentos', 'DOCUMENTOS_VER', 'file-text'],
];
$section = (string)($_GET['seccion'] ?? 'entregas');
if (!isset($sections[$section])) {
    $section = 'entregas';
}
require_permission($sections[$section][1]);

$solicitudesDocumentos = [];
if ($section === 'documentos') {
    try {
        $solicitudesDocumentos = operation_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'
        );
    } catch (PDOException) {
        $solicitudesDocumentos = [];
    }
}

$descriptions = [
    'entregas' => 'Consulta comprobantes de recepción, destinatarios efectivos y resultados de entrega.',
    'incidencias' => 'Seguimiento y resolución de eventos operativos reportados durante el transporte.',
    'notificaciones' => 'Bitácora de avisos y comunicaciones registradas por orden de servicio.',
    'documentos' => 'Consulta y vinculación de respaldos documentales por solicitud de transporte.',
];

require_once __DIR__ . '/../app/layout.php';
layout_start('entregas', $sections[$section][0], $descriptions[$section]);
?>
<nav class="tabs-bar" aria-label="Secciones de entregas">
    <?php foreach ($sections as $key => [$label, $permission, $icon]): ?>
        <?php if (has_permission($permission)): ?>
            <a class="tab-chip <?= $section === $key ? 'active' : '' ?>" href="/entregas.php?seccion=<?= e($key) ?>">
                <?= svg_icon($icon) ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<section class="toolbar-panel" aria-label="Filtros y acciones de <?= e(strtolower($sections[$section][0])) ?>">
    <form class="filter-form" id="entregas-filtros" hx-get="/entregas/lista.php" hx-target="#entregas-resultado" hx-trigger="change, keyup changed delay:350ms from:input" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <?php if ($section === 'documentos'): ?>
            <div class="combobox-filter-wrap">
                <select id="documentos-solicitud" name="solicitud_id" data-combobox data-allow-clear="true" <?= has_permission('SOLICITUDES_VER') ? 'data-remote-url="/solicitudes/lista.php"' : '' ?> data-placeholder="Buscar solicitud por código o cliente…" aria-label="Solicitud">
                    <option value="">Selecciona una solicitud para ver sus documentos…</option>
                    <?php foreach ($solicitudesDocumentos as $sol): ?>
                        <option value="<?= (int)$sol['solicitud_id'] ?>" data-subtext="<?= e(($sol['origen_nombre'] ?? '') . ' → ' . ($sol['destino_nombre'] ?? '') . ' · ' . humanize_status($sol['estado'])) ?>">
                            <?= e($sol['codigo_solicitud'] . ' · ' . ($sol['cliente_nombre'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php elseif ($section === 'incidencias'): ?>
            <div class="select">
                <select id="incidencias-estado" name="estado" aria-label="Estado de incidencia">
                    <option value="">Todos los estados</option>
                    <option value="ABIERTA">Abierta</option>
                    <option value="EN_ATENCION">En atención</option>
                    <option value="RESUELTA">Resuelta</option>
                </select>
            </div>
        <?php else: ?>
            <div class="select">
                <select id="entregas-estado" name="estado" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <?php if ($section === 'entregas'): ?>
                        <option value="ENTREGADA">Entregada</option>
                        <option value="FALLIDA">Fallida</option>
                    <?php else: ?>
                        <option value="PENDIENTE">Pendiente</option>
                        <option value="ENVIADA">Enviada</option>
                        <option value="ERROR">Con error</option>
                    <?php endif; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($section === 'incidencias' && has_permission('INCIDENCIAS_REGISTRAR')): ?>
        <button class="button is-primary" type="button" hx-get="/entregas/incidencia_form.php?accion=registrar" hx-target="#modal-contenido">
            <?= svg_icon('plus') ?>
            <span>Nueva incidencia</span>
        </button>
    <?php elseif ($section === 'documentos' && has_permission('DOCUMENTOS_REGISTRAR')): ?>
        <button class="button is-primary" type="button" hx-get="/entregas/documento_form.php" hx-target="#modal-contenido">
            <?= svg_icon('plus') ?>
            <span>Nuevo documento</span>
        </button>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div id="entregas-resultado" hx-get="/entregas/lista.php?seccion=<?= e($section) ?>" hx-trigger="load"></div>
</section>
<?php layout_end(); ?>
