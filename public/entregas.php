<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
require_once __DIR__ . '/../app/operational_writes.php';

$sections = [
    'pendientes' => ['En tránsito (por entregar)', 'ENTREGAS_VER', 'trips'],
    'entregas' => ['Entregas registradas', 'ENTREGAS_VER', 'deliveries'],
    'incidencias' => ['Incidencias', 'INCIDENCIAS_VER', 'alert-triangle'],
    'notificaciones' => ['Notificaciones', 'NOTIFICACIONES_VER', 'bell'],
    'documentos' => ['Documentos', 'DOCUMENTOS_VER', 'file-text'],
];
$section = (string)($_GET['seccion'] ?? 'entregas');
if (!isset($sections[$section])) {
    $section = 'entregas';
}
require_permission($sections[$section][1]);

$pendientesCount = 0;
if (has_permission('ENTREGAS_VER')) {
    try {
        $pendientesCount = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas_pendientes @buscar=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            []
        )['total'];
    } catch (PDOException) {
        $pendientesCount = 0;
    }
}

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

$initialEstado = strtoupper(trim((string)($_GET['estado'] ?? '')));
$descriptions = [
    'pendientes' => 'Órdenes en viajes en curso pendientes de confirmar entrega o reportar fallo.',
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
                <?php if ($key === 'pendientes' && $pendientesCount > 0): ?>
                    <span class="tag is-warning is-rounded ml-1"><?= $pendientesCount ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<section class="toolbar-panel" aria-label="Filtros y acciones de <?= e(utf8_strtolower($sections[$section][0])) ?>">
    <form class="filter-form" id="entregas-filtros" hx-get="/entregas/lista.php" hx-target="#entregas-resultado" hx-trigger="change, keyup changed delay:350ms from:input" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <?php if ($section === 'pendientes'): ?>
            <div class="field mb-0">
                <input class="input" type="search" name="buscar" placeholder="Buscar por solicitud, viaje, placa o destino…" aria-label="Buscar entregas pendientes">
            </div>
        <?php elseif ($section === 'documentos'): ?>
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
                <select id="incidencias-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado de incidencia">
                    <option value="">Todos los estados</option>
                    <option value="ABIERTA" <?= $initialEstado === 'ABIERTA' ? 'selected' : '' ?>>Abierta</option>
                    <option value="EN_ATENCION" <?= $initialEstado === 'EN_ATENCION' ? 'selected' : '' ?>>En atención</option>
                    <option value="RESUELTA" <?= $initialEstado === 'RESUELTA' ? 'selected' : '' ?>>Resuelta</option>
                </select>
            </div>
        <?php else: ?>
            <div class="select">
                <select id="entregas-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <?php if ($section === 'entregas'): ?>
                        <option value="ENTREGADA" <?= $initialEstado === 'ENTREGADA' ? 'selected' : '' ?>>Entregada</option>
                        <option value="FALLIDA" <?= $initialEstado === 'FALLIDA' ? 'selected' : '' ?>>Fallida</option>
                    <?php else: ?>
                        <option value="PENDIENTE" <?= $initialEstado === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="ENVIADA" <?= $initialEstado === 'ENVIADA' ? 'selected' : '' ?>>Enviada</option>
                        <option value="ERROR" <?= $initialEstado === 'ERROR' ? 'selected' : '' ?>>Con error</option>
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
    <div id="entregas-resultado" hx-get="/entregas/lista.php?seccion=<?= e($section) ?>" hx-include="#entregas-filtros" hx-trigger="load"></div>
</section>
<?php layout_end(); ?>
