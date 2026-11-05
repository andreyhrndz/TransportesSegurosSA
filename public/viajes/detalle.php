<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('VIAJES_VER');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Viaje no encontrado.');
}
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [$id]);
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Viaje no encontrado.');
    }
    read_error_response();
}
$viaje = $sets[0][0] ?? null;
if (!is_array($viaje)) {
    http_response_code(404);
    exit('Viaje no encontrado.');
}
$paradas = $sets[1] ?? [];
$asignaciones = $sets[2] ?? [];
require_once __DIR__ . '/../../app/layout.php';
layout_start('viajes', 'Viaje ' . (string)$viaje['codigo_viaje'], 'Detalle de unidad, conductor, paradas en ruta y órdenes asociadas.');
?>
<div class="toolbar-panel">
    <a class="button button-secondary" href="/viajes.php">
        <?= svg_icon('arrow-left') ?>
        <span>Volver a viajes</span>
    </a>
    <?php if ($viaje['estado'] === 'PLANIFICADO' && (has_permission('VIAJES_PARADAS') || has_permission('VIAJES_ASIGNAR') || has_permission('VIAJES_INICIAR') || has_permission('VIAJES_CANCELAR'))): ?>
        <div class="buttons">
            <?php if (has_permission('VIAJES_CANCELAR')): ?>
                <button class="button button-secondary is-danger" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=cancelar" hx-target="#modal-contenido">
                    <?= svg_icon('close') ?>
                    <span>Cancelar viaje</span>
                </button>
            <?php endif; ?>
            <?php if (has_permission('VIAJES_PARADAS')): ?>
                <button class="button button-secondary" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=parada" hx-target="#modal-contenido">
                    <?= svg_icon('map-pin') ?>
                    <span>Agregar parada</span>
                </button>
            <?php endif; ?>
            <?php if (has_permission('VIAJES_ASIGNAR')): ?>
                <button class="button button-secondary" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=asignar" hx-target="#modal-contenido">
                    <?= svg_icon('plus') ?>
                    <span>Asignar solicitud</span>
                </button>
            <?php endif; ?>
            <?php if (has_permission('VIAJES_INICIAR')): ?>
                <button class="button is-primary" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=iniciar" hx-target="#modal-contenido">
                    <?= svg_icon('activate') ?>
                    <span>Iniciar</span>
                </button>
            <?php endif; ?>
        </div>
    <?php elseif ($viaje['estado'] === 'EN_CURSO' && has_permission('VIAJES_FINALIZAR')): ?>
        <div class="buttons">
            <button class="button is-primary" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=finalizar" hx-target="#modal-contenido">
                <?= svg_icon('activate') ?>
                <span>Finalizar</span>
            </button>
        </div>
    <?php endif; ?>
</div>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Recursos asignados al despacho</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Estado</span><strong><?= status_tag($viaje['estado']) ?></strong></div>
        <div class="detail-field"><span>Vehículo</span><strong><code><?= e($viaje['placa']) ?></code></strong></div>
        <div class="detail-field"><span>Conductor</span><strong><?= e($viaje['conductor_nombre']) ?></strong></div>
        <div class="detail-field"><span>Ruta</span><strong><?= e($viaje['ruta_nombre']) ?></strong></div>
        <div class="detail-field"><span>Salida programada</span><strong><?= format_utc_datetime($viaje['fecha_programada_salida']) ?></strong></div>
        <div class="detail-field"><span>Salida real</span><strong><?= format_utc_datetime($viaje['fecha_salida_real']) ?></strong></div>
    </div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Paradas del itinerario</h2>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Orden</th>
                    <th>Ubicación</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Llegada</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$paradas): ?>
                    <tr><td colspan="5"><?= empty_state('Sin paradas registradas', 'Agrega paradas al viaje antes de asignar solicitudes.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($paradas as $row): ?>
                    <tr>
                        <td><strong>#<?= (int)$row['orden'] ?></strong></td>
                        <td><?= e($row['ubicacion_nombre']) ?></td>
                        <td><?= e(humanize_status($row['tipo_parada'])) ?></td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= format_utc_datetime($row['llegada_real'] ?: $row['llegada_programada']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Solicitudes asociadas</h2>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Solicitud</th>
                    <th>Estado solicitud</th>
                    <th>Estado asignación</th>
                    <th>Parada de recogida</th>
                    <th>Parada de entrega</th>
                    <th class="has-text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$asignaciones): ?>
                    <tr><td colspan="6"><?= empty_state('Sin solicitudes asociadas', 'Asigna órdenes pendientes a las paradas configuradas en este viaje.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($asignaciones as $row): ?>
                    <tr>
                        <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong><?= e($row['codigo_solicitud']) ?></strong></a></td>
                        <td><?= status_tag($row['estado_solicitud']) ?></td>
                        <td><?= status_tag($row['estado_asignacion']) ?></td>
                        <td>#<?= (int)$row['parada_recogida_id'] ?></td>
                        <td>#<?= (int)$row['parada_entrega_id'] ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($viaje['estado'] === 'EN_CURSO' && $row['estado_asignacion'] === 'EN_TRANSITO' && has_permission('ENTREGAS_REGISTRAR')): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/entregas/registrar_form.php?viaje_solicitud_id=<?= (int)$row['viaje_solicitud_id'] ?>" hx-target="#modal-contenido">
                                        <?= svg_icon('deliveries') ?>
                                        <span>Registrar entrega</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php layout_end(); ?>
