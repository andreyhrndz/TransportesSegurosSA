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
$capacidad = null;
try {
    $capacidad = read_procedure_sets('EXEC dbo.sp_obtener_capacidad_viaje @viaje_id=?;', [$id])[0][0] ?? null;
} catch (PDOException) {
    $capacidad = null;
}

$puedeGestionarParadas = ($viaje['estado'] === 'PLANIFICADO') && has_permission('VIAJES_PARADAS');
$puedeDesasignar = ($viaje['estado'] === 'PLANIFICADO') && has_permission('VIAJES_ASIGNAR');
$paradaPorId = [];
$coordPuntos = [];
foreach ($paradas as $p) {
    $paradaPorId[(int)$p['parada_id']] = $p;
    if ($p['latitud'] !== null && $p['longitud'] !== null && $p['latitud'] !== '' && $p['longitud'] !== '') {
        $coordPuntos[] = number_format((float)$p['latitud'], 6, '.', '') . ',' . number_format((float)$p['longitud'], 6, '.', '');
    }
}

$googleRouteUrl = null;
if (count($coordPuntos) >= 2) {
    $originCoord = $coordPuntos[0];
    $destCoord = $coordPuntos[count($coordPuntos) - 1];
    $waypoints = array_slice($coordPuntos, 1, -1);
    $routeQuery = [
        'api' => '1',
        'origin' => $originCoord,
        'destination' => $destCoord,
        'travelmode' => 'driving',
    ];
    if ($waypoints) {
        $routeQuery['waypoints'] = implode('|', array_slice($waypoints, 0, 9));
    }
    $googleRouteUrl = 'https://www.google.com/maps/dir/?' . http_build_query($routeQuery);
}

$pesoUsado = (float)($capacidad['peso_usado_kg'] ?? 0);
$pesoMax = (float)($capacidad['capacidad_carga_kg'] ?? 0);
$pesoPct = $pesoMax > 0 ? min(100, (int)round(($pesoUsado / $pesoMax) * 100)) : 0;
$volUsado = (float)($capacidad['volumen_usado_m3'] ?? 0);
$volMax = $capacidad && $capacidad['capacidad_volumen_m3'] !== null ? (float)$capacidad['capacidad_volumen_m3'] : null;
$volPct = ($volMax !== null && $volMax > 0) ? min(100, (int)round(($volUsado / $volMax) * 100)) : null;

require_once __DIR__ . '/../../app/layout.php';
layout_start('viajes', 'Viaje ' . (string)$viaje['codigo_viaje'], 'Detalle de unidad, conductor, capacidad de carga, paradas en ruta y órdenes asociadas.');
?>
<div class="toolbar-panel">
    <div class="buttons">
        <a class="button button-secondary" href="/viajes.php">
            <?= svg_icon('arrow-left') ?>
            <span>Volver a viajes</span>
        </a>
        <a class="button button-secondary" href="/viajes/hoja_ruta.php?id=<?= (int)$id ?>" target="_blank" rel="noopener noreferrer">
            <?= svg_icon('file-text') ?>
            <span>Hoja de ruta (PDF)</span>
        </a>
        <?php if ($googleRouteUrl !== null): ?>
            <a class="button button-secondary" href="<?= e($googleRouteUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= svg_icon('map-pin') ?>
                <span>Ruta completa en Google Maps</span>
            </a>
        <?php endif; ?>
    </div>
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
            <?php if (has_permission('VIAJES_ASIGNAR') && has_permission('VIAJES_PARADAS')): ?>
                <button class="button button-secondary" type="button" hx-get="/viajes/accion_form.php?id=<?= (int)$id ?>&accion=asignar_lote" hx-target="#modal-contenido" title="Seleccionar y asignar varias solicitudes en un solo paso">
                    <?= svg_icon('layers') ?>
                    <span>Asignar varias</span>
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
        <?php
        $totalAsig = count($asignaciones);
        $pendientesEntrega = 0;
        foreach ($asignaciones as $asigRow) {
            if (($asigRow['estado_asignacion'] ?? '') === 'EN_TRANSITO') {
                $pendientesEntrega++;
            }
        }
        $completadasEntrega = $totalAsig - $pendientesEntrega;
        ?>
        <div class="buttons is-align-items-center">
            <span class="tag <?= $pendientesEntrega === 0 ? 'is-success' : 'is-warning' ?> is-medium">
                <?= $completadasEntrega ?> de <?= $totalAsig ?> entrega<?= $totalAsig === 1 ? '' : 's' ?> registrada<?= $completadasEntrega === 1 ? '' : 's' ?>
            </span>
            <button
                class="button is-primary"
                type="button"
                <?= $pendientesEntrega > 0 ? 'disabled title="Registra las ' . $pendientesEntrega . ' entrega(s) pendiente(s) antes de finalizar el viaje."' : 'hx-get="/viajes/accion_form.php?id=' . (int)$id . '&accion=finalizar" hx-target="#modal-contenido"' ?>
            >
                <?= svg_icon('activate') ?>
                <span>Finalizar</span>
            </button>
        </div>
    <?php endif; ?>
</div>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Recursos y capacidad del despacho</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Estado</span><strong><?= status_tag($viaje['estado']) ?></strong></div>
        <div class="detail-field"><span>Vehículo</span><strong><code><?= e($viaje['placa']) ?></code></strong></div>
        <div class="detail-field"><span>Conductor</span><strong><?= e($viaje['conductor_nombre']) ?></strong></div>
        <div class="detail-field"><span>Ruta</span><strong><?= e($viaje['ruta_nombre']) ?></strong></div>
        <div class="detail-field"><span>Salida programada</span><strong><?= format_utc_datetime($viaje['fecha_programada_salida']) ?></strong></div>
        <div class="detail-field"><span>Salida real</span><strong><?= format_utc_datetime($viaje['fecha_salida_real']) ?></strong></div>
        <?php if (is_array($capacidad)): ?>
            <div class="detail-field">
                <span>Ocupación de peso</span>
                <strong><?= e(format_cr_measure($pesoUsado)) ?> / <?= e(format_cr_measure($pesoMax)) ?> kg (<?= $pesoPct ?>%)</strong>
                <progress class="progress is-small mt-2 <?= $pesoPct >= 90 ? 'is-danger' : ($pesoPct >= 70 ? 'is-warning' : 'is-info') ?>" value="<?= $pesoPct ?>" max="100"><?= $pesoPct ?>%</progress>
            </div>
            <?php if ($volMax !== null && $volMax > 0): ?>
                <div class="detail-field">
                    <span>Ocupación de volumen</span>
                    <strong><?= e(format_cr_measure($volUsado)) ?> / <?= e(format_cr_measure($volMax)) ?> m³ (<?= $volPct ?>%)</strong>
                    <progress class="progress is-small mt-2 <?= $volPct >= 90 ? 'is-danger' : ($volPct >= 70 ? 'is-warning' : 'is-info') ?>" value="<?= $volPct ?>" max="100"><?= $volPct ?>%</progress>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title">Paradas del itinerario</h2>
            <p class="panel-box-subtitle">El estado de las paradas se actualiza automáticamente al iniciar y finalizar el despacho (no es seguimiento GPS en tiempo real).</p>
        </div>
        <?php if ($puedeGestionarParadas && count($paradas) >= 2): ?>
            <div class="buttons">
                <button class="button is-small is-primary" type="button" hx-get="/viajes/paradas_orden_form.php?id=<?= (int)$id ?>&auto_sugerir=1" hx-target="#modal-contenido" title="Calcula automáticamente el recorrido más corto respetando recogida antes que entrega">
                    <?= svg_icon('map-pin') ?>
                    <span>Ruta inteligente</span>
                </button>
                <button class="button is-small button-secondary" type="button" hx-get="/viajes/paradas_orden_form.php?id=<?= (int)$id ?>" hx-target="#modal-contenido">
                    <?= svg_icon('arrows-vertical') ?>
                    <span>Reordenar paradas</span>
                </button>
            </div>
        <?php endif; ?>
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
                    <th class="has-text-right">Mapa</th>
                    <?php if ($puedeGestionarParadas): ?>
                        <th class="has-text-right">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $colSpanParadas = $puedeGestionarParadas ? 7 : 6; ?>
                <?php if (!$paradas): ?>
                    <tr><td colspan="<?= $colSpanParadas ?>"><?= empty_state('Sin paradas registradas', 'Puedes asignar una solicitud directamente para crear sus paradas automáticamente, o agregarlas manualmente.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($paradas as $row): ?>
                    <?php $vinculada = !empty($row['tiene_solicitudes']); ?>
                    <tr>
                        <td><strong>#<?= (int)$row['orden'] ?></strong></td>
                        <td>
                            <div class="cell-stack">
                                <strong><?= e($row['ubicacion_nombre']) ?></strong>
                                <?php if (!empty($row['direccion'])): ?>
                                    <span><?= e($row['direccion']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e(humanize_status($row['tipo_parada'])) ?></td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= format_utc_datetime($row['llegada_real'] ?: $row['llegada_programada']) ?></td>
                        <td class="has-text-right"><?= google_maps_link($row['latitud'] ?? null, $row['longitud'] ?? null, 'Abrir en Google Maps', true) ?></td>
                        <?php if ($puedeGestionarParadas): ?>
                            <td class="has-text-right">
                                <div class="row-actions">
                                    <?php if (!$vinculada): ?>
                                        <button class="button is-small button-secondary" type="button" hx-get="/viajes/parada_editar_form.php?viaje_id=<?= (int)$id ?>&parada_id=<?= (int)$row['parada_id'] ?>" hx-target="#modal-contenido">
                                            <?= svg_icon('edit') ?>
                                            <span>Editar</span>
                                        </button>
                                        <form method="post" action="/viajes/accion.php" hx-post="/viajes/accion.php" hx-confirm="¿Eliminar esta parada no vinculada del itinerario?" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="viaje_id" value="<?= (int)$id ?>">
                                            <input type="hidden" name="accion" value="eliminar_parada">
                                            <input type="hidden" name="parada_id" value="<?= (int)$row['parada_id'] ?>">
                                            <button class="button is-small button-secondary is-danger" type="submit" title="Eliminar parada">
                                                <?= svg_icon('close') ?>
                                                <span>Quitar</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="is-size-7 has-text-grey" title="Parada vinculada a una solicitud asignada: no puede cambiar su ubicación ni eliminarse, pero sí puede reordenarse manteniendo recogida antes que entrega.">Vinculada a solicitud</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
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
                    <tr><td colspan="6"><?= empty_state('Sin solicitudes asociadas', 'Asigna órdenes pendientes a este viaje; el sistema puede crear las paradas automáticamente.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($asignaciones as $row): ?>
                    <?php
                    $pRec = $paradaPorId[(int)$row['parada_recogida_id']] ?? null;
                    $pEnt = $paradaPorId[(int)$row['parada_entrega_id']] ?? null;
                    $lblRec = $pRec ? '#' . (int)$pRec['orden'] . ' · ' . (string)$pRec['ubicacion_nombre'] : '#' . (int)$row['parada_recogida_id'];
                    $lblEnt = $pEnt ? '#' . (int)$pEnt['orden'] . ' · ' . (string)$pEnt['ubicacion_nombre'] : '#' . (int)$row['parada_entrega_id'];
                    ?>
                    <tr>
                        <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong><?= e($row['codigo_solicitud']) ?></strong></a></td>
                        <td><?= status_tag($row['estado_solicitud']) ?></td>
                        <td><?= status_tag($row['estado_asignacion']) ?></td>
                        <td><?= e($lblRec) ?></td>
                        <td><?= e($lblEnt) ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($puedeDesasignar): ?>
                                    <form method="post" action="/viajes/accion.php" hx-post="/viajes/accion.php" hx-confirm="¿Desasignar esta solicitud del viaje? Volverá a estado Pendiente de asignación." style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="viaje_id" value="<?= (int)$id ?>">
                                        <input type="hidden" name="accion" value="desasignar">
                                        <input type="hidden" name="solicitud_id" value="<?= (int)$row['solicitud_id'] ?>">
                                        <button class="button is-small button-secondary is-danger" type="submit">
                                            <?= svg_icon('close') ?>
                                            <span>Desasignar</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($viaje['estado'] === 'EN_CURSO' && $row['estado_asignacion'] === 'EN_TRANSITO' && has_permission('ENTREGAS_REGISTRAR')): ?>
                                    <button class="button is-small is-primary" type="button" hx-get="/entregas/registrar_form.php?viaje_solicitud_id=<?= (int)$row['viaje_solicitud_id'] ?>&return_to=<?= rawurlencode('/viajes/detalle.php?id=' . (int)$id) ?>" hx-target="#modal-contenido">
                                        <?= svg_icon('deliveries') ?>
                                        <span>Registrar entrega</span>
                                    </button>
                                <?php endif; ?>
                                <?php if (has_permission('INCIDENCIAS_REGISTRAR')): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/entregas/incidencia_form.php?accion=registrar&solicitud_id=<?= (int)$row['solicitud_id'] ?>" hx-target="#modal-contenido">
                                        <?= svg_icon('alert-triangle') ?>
                                        <span>Incidencia</span>
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
