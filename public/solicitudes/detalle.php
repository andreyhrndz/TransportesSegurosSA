<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('SOLICITUDES_VER');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Solicitud no encontrada.');
}
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_solicitud @solicitud_id=?;', [$id]);
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Solicitud no encontrada.');
    }
    read_error_response();
}
$solicitud = $sets[0][0] ?? null;
if (!is_array($solicitud)) {
    http_response_code(404);
    exit('Solicitud no encontrada.');
}
$mercancias = $sets[1] ?? [];
$historial = $sets[2] ?? [];
require_once __DIR__ . '/../../app/layout.php';
layout_start('solicitudes', 'Solicitud ' . (string)$solicitud['codigo_solicitud'], 'Detalle operativo de la solicitud, destinatario esperado y trazabilidad de estados.');
?>
<div class="toolbar-panel">
    <a class="button button-secondary" href="/solicitudes.php">
        <?= svg_icon('arrow-left') ?>
        <span>Volver a solicitudes</span>
    </a>
    <?php if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && (has_permission('SOLICITUDES_AJUSTAR_PRIORIDAD') || has_permission('SOLICITUDES_CANCELAR'))): ?>
        <div class="buttons">
            <?php if (has_permission('SOLICITUDES_AJUSTAR_PRIORIDAD')): ?>
                <button class="button button-secondary" type="button" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=prioridad" hx-target="#modal-contenido">
                    <?= svg_icon('edit') ?>
                    <span>Ajustar prioridad</span>
                </button>
            <?php endif; ?>
            <?php if (has_permission('SOLICITUDES_CANCELAR')): ?>
                <button class="button is-danger" type="button" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=cancelar" hx-target="#modal-contenido">
                    <?= svg_icon('deactivate') ?>
                    <span>Cancelar</span>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Información general de la orden</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Estado</span><strong><?= status_tag($solicitud['estado']) ?></strong></div>
        <div class="detail-field"><span>Prioridad</span><strong><?= status_tag($solicitud['prioridad_actual']) ?></strong></div>
        <div class="detail-field"><span>Entrega requerida</span><strong><?= format_utc_datetime($solicitud['fecha_entrega_requerida']) ?></strong></div>
        <div class="detail-field"><span>Ubicación de origen</span><strong><?= e($solicitud['origen_nombre']) ?></strong></div>
        <div class="detail-field"><span>Ubicación de destino</span><strong><?= e($solicitud['destino_nombre']) ?></strong></div>
        <div class="detail-field"><span>Viaje asignado</span><strong><?= $solicitud['codigo_viaje'] ? e($solicitud['codigo_viaje']) : 'Sin asignar' ?></strong></div>
    </div>
</section>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Destinatario esperado</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Nombre completo</span><strong><?= e(trim($solicitud['destinatario_nombre'] . ' ' . $solicitud['destinatario_apellido1'] . ' ' . $solicitud['destinatario_apellido2'])) ?></strong></div>
        <div class="detail-field"><span>Identificación</span><strong><?= e($solicitud['destinatario_identificacion'] ?: 'No indicada') ?></strong></div>
        <div class="detail-field"><span>Teléfono</span><strong><?= e($solicitud['destinatario_telefono'] ?: 'No indicado') ?></strong></div>
    </div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Mercancías registradas</h2>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th>Peso</th>
                    <th>Volumen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mercancias as $item): ?>
                    <tr>
                        <td><strong><?= e($item['tipo_mercancia']) ?></strong></td>
                        <td><?= e($item['descripcion']) ?></td>
                        <td><?= e($item['peso_kg']) ?> kg</td>
                        <td><?= e($item['volumen_m3'] ?? '—') ?> m³</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($historial): ?>
<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Historial de estados</h2>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Transición</th>
                    <th>Observación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial as $item): ?>
                    <tr>
                        <td><?= format_utc_datetime($item['fecha_cambio']) ?></td>
                        <td>
                            <?= $item['estado_anterior'] ? status_tag($item['estado_anterior']) : '<span class="status-tag status-tag-info">Inicio</span>' ?>
                            <span class="mx-2">→</span>
                            <?= status_tag($item['estado_nuevo']) ?>
                        </td>
                        <td><?= e($item['observacion'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php layout_end(); ?>
