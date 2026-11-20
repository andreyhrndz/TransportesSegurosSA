<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/dispatch_advisor.php';
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

$viajeInfo = null;
$entregaInfo = null;
$docsInfo = [];
$incidenciasInfo = [];
$sugerenciasViaje = ['req' => null, 'sugeridos' => [], 'descartados_capacidad' => 0];
try {
    $sets360 = read_procedure_sets('EXEC dbo.sp_obtener_solicitud_360 @solicitud_id=?;', [$id]);
    $viajeInfo = $sets360[0][0] ?? null;
    $entregaInfo = $sets360[1][0] ?? null;
    $docsInfo = has_permission('DOCUMENTOS_VER') ? ($sets360[2] ?? []) : [];
    $incidenciasInfo = has_permission('INCIDENCIAS_VER') ? ($sets360[3] ?? []) : [];
    if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('VIAJES_VER')) {
        $sugerenciasViaje = dispatch_suggest_trips_for_request((int)$id);
    }
} catch (PDOException) {
    // fallback silencioso si algún contrato 360 no está disponible
}

$destTel = trim((string)($solicitud['destinatario_telefono'] ?? ''));
$destTelHref = $destTel !== '' ? preg_replace('/[^0-9+]/', '', $destTel) : '';

require_once __DIR__ . '/../../app/layout.php';
layout_start('solicitudes', 'Solicitud ' . (string)$solicitud['codigo_solicitud'], 'Vista 360° de la solicitud: trayecto, destinatario, viaje, entrega, documentos e incidencias.');
?>
<div class="toolbar-panel">
    <a class="button button-secondary" href="/solicitudes.php">
        <?= svg_icon('arrow-left') ?>
        <span>Volver a solicitudes</span>
    </a>
    <div class="buttons">
        <?php if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('VIAJES_CREAR')): ?>
            <button class="button is-primary" type="button" hx-get="/viajes/form.php?solicitud_id=<?= (int)$id ?>" hx-target="#modal-contenido">
                <?= svg_icon('trips') ?>
                <span>Crear viaje con esta solicitud</span>
            </button>
        <?php elseif (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('VIAJES_VER')): ?>
            <a class="button is-primary" href="/viajes.php">
                <?= svg_icon('trips') ?>
                <span>Ir a Viajes para asignar</span>
            </a>
        <?php endif; ?>
        <?php if ($solicitud['estado'] === 'ENTREGA_FALLIDA' && has_permission('SOLICITUDES_CREAR')): ?>
            <button class="button is-primary" type="button" hx-get="/solicitudes/form.php?clonar_id=<?= (int)$id ?>" hx-target="#modal-contenido">
                <?= svg_icon('plus') ?>
                <span>Reprogramar en nueva solicitud</span>
            </button>
        <?php endif; ?>
        <?php if (is_array($viajeInfo) && ($viajeInfo['estado_viaje'] ?? '') === 'EN_CURSO' && ($viajeInfo['estado_asignacion'] ?? '') === 'EN_TRANSITO' && has_permission('ENTREGAS_REGISTRAR')): ?>
            <button class="button is-primary" type="button" hx-get="/entregas/registrar_form.php?viaje_solicitud_id=<?= (int)$viajeInfo['viaje_solicitud_id'] ?>&return_to=<?= rawurlencode('/solicitudes/detalle.php?id=' . (int)$id) ?>" hx-target="#modal-contenido">
                <?= svg_icon('deliveries') ?>
                <span>Registrar entrega</span>
            </button>
        <?php endif; ?>
        <?php if (has_permission('INCIDENCIAS_REGISTRAR')): ?>
            <button class="button button-secondary" type="button" hx-get="/entregas/incidencia_form.php?accion=registrar&solicitud_id=<?= (int)$id ?>" hx-target="#modal-contenido">
                <?= svg_icon('alert-triangle') ?>
                <span>Reportar incidencia</span>
            </button>
        <?php endif; ?>
        <?php if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('SOLICITUDES_AJUSTAR_PRIORIDAD')): ?>
            <button class="button button-secondary" type="button" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=prioridad" hx-target="#modal-contenido">
                <?= svg_icon('edit') ?>
                <span>Ajustar prioridad</span>
            </button>
        <?php endif; ?>
        <?php if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('SOLICITUDES_CANCELAR')): ?>
            <button class="button is-danger" type="button" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=cancelar" hx-target="#modal-contenido">
                <?= svg_icon('deactivate') ?>
                <span>Cancelar</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Información general de la orden</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Estado</span><strong><?= status_tag($solicitud['estado']) ?></strong></div>
        <div class="detail-field"><span>Prioridad</span><strong><?= status_tag($solicitud['prioridad_actual']) ?></strong></div>
        <div class="detail-field"><span>Entrega requerida</span><strong><?= format_utc_datetime($solicitud['fecha_entrega_requerida']) ?></strong></div>
        <div class="detail-field">
            <span>Ubicación de origen</span>
            <strong><?= e($solicitud['origen_nombre']) ?></strong>
            <?php if ((int)($solicitud['origen_es_reutilizable'] ?? 1) === 0): ?>
                <p class="is-size-7 has-text-grey mt-1 mb-0">Punto puntual de esta solicitud</p>
            <?php endif; ?>
            <?php if (!empty($solicitud['origen_direccion'])): ?>
                <p class="help mt-1 mb-1"><?= e($solicitud['origen_direccion']) ?></p>
            <?php endif; ?>
            <div class="mt-2"><?= google_maps_link($solicitud['origen_latitud'] ?? null, $solicitud['origen_longitud'] ?? null) ?></div>
        </div>
        <div class="detail-field">
            <span>Ubicación de destino</span>
            <strong><?= e($solicitud['destino_nombre']) ?></strong>
            <?php if ((int)($solicitud['destino_es_reutilizable'] ?? 1) === 0): ?>
                <p class="is-size-7 has-text-grey mt-1 mb-0">Punto puntual de esta solicitud</p>
            <?php endif; ?>
            <?php if (!empty($solicitud['destino_direccion'])): ?>
                <p class="help mt-1 mb-1"><?= e($solicitud['destino_direccion']) ?></p>
            <?php endif; ?>
            <div class="mt-2"><?= google_maps_link($solicitud['destino_latitud'] ?? null, $solicitud['destino_longitud'] ?? null) ?></div>
        </div>
        <div class="detail-field">
            <span>Viaje asignado</span>
            <strong>
                <?php if (is_array($viajeInfo) && has_permission('VIAJES_VER')): ?>
                    <a class="table-link" href="/viajes/detalle.php?id=<?= (int)$viajeInfo['viaje_id'] ?>"><?= e($viajeInfo['codigo_viaje']) ?></a>
                    <span class="ml-1">(<code><?= e($viajeInfo['placa']) ?></code>)</span>
                <?php elseif (!empty($solicitud['codigo_viaje'])): ?>
                    <?= e($solicitud['codigo_viaje']) ?>
                <?php else: ?>
                    Sin asignar
                <?php endif; ?>
            </strong>
        </div>
    </div>
</section>

<?php if (in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true) && has_permission('VIAJES_VER')): ?>
    <?php
    $viajesSug = $sugerenciasViaje['sugeridos'] ?? [];
    $descartadosCap = (int)($sugerenciasViaje['descartados_capacidad'] ?? 0);
    $puedeAsignarDirecto = has_permission('VIAJES_ASIGNAR') && has_permission('VIAJES_PARADAS');
    $hayBuenMatch = false;
    foreach ($viajesSug as $vsItem) {
        if (in_array($vsItem['fit']['nivel'], ['optimo', 'compatible'], true)) {
            $hayBuenMatch = true;
            break;
        }
    }
    ?>
    <section class="panel-box table-panel">
        <div class="panel-box-header">
            <div>
                <h2 class="panel-box-title">Despacho inteligente — Viajes sugeridos</h2>
                <p class="panel-box-subtitle">Evalúa capacidad disponible, coincidencia de corredor geográfico (Haversine) y fecha compromiso para evitar desvíos ineficientes.</p>
            </div>
            <?php if (has_permission('VIAJES_CREAR')): ?>
                <button class="button is-small <?= $hayBuenMatch ? 'button-secondary' : 'is-primary' ?>" type="button" hx-get="/viajes/form.php?solicitud_id=<?= (int)$id ?>" hx-target="#modal-contenido">
                    <?= svg_icon('plus') ?>
                    <span>Crear viaje nuevo</span>
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$viajesSug): ?>
            <div class="p-4">
                <p class="mb-2">No hay viajes planificados con capacidad disponible para esta solicitud<?= $descartadosCap > 0 ? " ({$descartadosCap} viaje(s) descartado(s) por capacidad insuficiente)" : '' ?>.</p>
                <p class="help">Usa <strong>Crear viaje nuevo</strong> arriba: el sistema preseleccionará automáticamente el corredor más adecuado.</p>
            </div>
        <?php else: ?>
            <?php if (!$hayBuenMatch): ?>
                <div class="notification notification-warning py-2 px-3 mx-4 mt-3 mb-2">
                    Ningún viaje planificado actual corresponde a esta zona/corredor. Se recomienda <strong>crear un viaje nuevo</strong> para evitar desvíos largos.
                </div>
            <?php endif; ?>
            <div class="table-container">
                <table class="table is-fullwidth is-hoverable">
                    <thead>
                        <tr>
                            <th>Compatibilidad</th>
                            <th>Viaje / Unidad</th>
                            <th>Corredor / Ruta</th>
                            <th>Salida programada</th>
                            <th>Evaluación logística</th>
                            <?php if ($puedeAsignarDirecto): ?>
                                <th class="has-text-right">Acción</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viajesSug as $sug): ?>
                            <?php $fit = $sug['fit']; ?>
                            <tr>
                                <td><span class="tag <?= e($fit['tag_class']) ?>"><?= e($fit['etiqueta']) ?></span></td>
                                <td>
                                    <div class="cell-stack">
                                        <a class="table-link" href="/viajes/detalle.php?id=<?= (int)$sug['viaje_id'] ?>"><strong><?= e($sug['codigo_viaje']) ?></strong></a>
                                        <span><code><?= e($sug['placa']) ?></code> · <?= e($sug['conductor_nombre']) ?></span>
                                    </div>
                                </td>
                                <td><?= e($sug['ruta_nombre']) ?></td>
                                <td><?= format_utc_datetime($sug['fecha_programada_salida']) ?></td>
                                <td><span class="is-size-7"><?= e($fit['motivo']) ?></span></td>
                                <?php if ($puedeAsignarDirecto): ?>
                                    <td class="has-text-right">
                                        <form
                                            method="post"
                                            action="/viajes/accion.php"
                                            hx-post="/viajes/accion.php"
                                            <?= $fit['nivel'] === 'desvio' ? 'hx-confirm="Advertencia: este viaje pertenece a otro corredor (' . e($fit['etiqueta']) . '). ¿Seguro que deseas asignar esta solicitud aquí?"' : '' ?>
                                            style="display:inline;"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="viaje_id" value="<?= (int)$sug['viaje_id'] ?>">
                                            <input type="hidden" name="accion" value="asignar">
                                            <input type="hidden" name="solicitud_id" value="<?= (int)$id ?>">
                                            <input type="hidden" name="auto_crear_paradas" value="1">
                                            <button class="button is-small <?= $fit['nivel'] === 'optimo' ? 'is-primary' : 'button-secondary' ?>" type="submit">
                                                <?= svg_icon('activate') ?>
                                                <span>Asignar aquí</span>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($descartadosCap > 0): ?>
                <p class="help px-4 pb-3"><?= $descartadosCap ?> viaje(s) planificado(s) oculto(s) automáticamente porque su capacidad libre no soporta el peso/volumen de esta solicitud.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Destinatario esperado</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Nombre completo</span><strong><?= e(trim($solicitud['destinatario_nombre'] . ' ' . $solicitud['destinatario_apellido1'] . ' ' . $solicitud['destinatario_apellido2'])) ?></strong></div>
        <div class="detail-field"><span>Identificación</span><strong><?= e($solicitud['destinatario_identificacion'] ?: 'No indicada') ?></strong></div>
        <div class="detail-field">
            <span>Teléfono</span>
            <strong>
                <?php if ($destTelHref !== ''): ?>
                    <a class="table-link" href="tel:<?= e($destTelHref) ?>"><?= e($destTel) ?></a>
                <?php else: ?>
                    No indicado
                <?php endif; ?>
            </strong>
        </div>
    </div>
</section>

<?php if (is_array($entregaInfo)): ?>
<?php $compUrl = read_safe_reference_url($entregaInfo['comprobante_url'] ?? null); ?>
<section class="panel-box">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Resultado de entrega</h2>
    </div>
    <div class="detail-grid">
        <div class="detail-field"><span>Estado</span><strong><?= status_tag($entregaInfo['estado']) ?></strong></div>
        <div class="detail-field"><span>Receptor efectivo</span><strong><?= e($entregaInfo['receptor_nombre'] ?: 'No aplica') ?></strong></div>
        <div class="detail-field"><span>Fecha</span><strong><?= format_utc_datetime($entregaInfo['fecha_recepcion'] ?: $entregaInfo['fecha_resultado']) ?></strong></div>
        <div class="detail-field">
            <span>Comprobante / Motivo</span>
            <strong>
                <?php if ($compUrl): ?>
                    <a class="table-link" href="<?= e($compUrl) ?>" target="_blank" rel="noopener noreferrer">Ver comprobante <?= svg_icon('arrow-up-right') ?></a>
                <?php elseif (!empty($entregaInfo['motivo_fallo'])): ?>
                    <?= e($entregaInfo['motivo_fallo']) ?>
                <?php else: ?>
                    Sin evidencia adjunta
                <?php endif; ?>
            </strong>
        </div>
    </div>
</section>
<?php endif; ?>

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
                <?php
                $totalPesoSol = 0.0;
                $totalVolSol = 0.0;
                foreach ($mercancias as $item):
                    $totalPesoSol += (float)($item['peso_kg'] ?? 0);
                    $totalVolSol += (float)($item['volumen_m3'] ?? 0);
                ?>
                    <tr>
                        <td><strong><?= e($item['tipo_mercancia']) ?></strong></td>
                        <td><?= e($item['descripcion']) ?></td>
                        <td><?= e($item['peso_kg']) ?> kg</td>
                        <td><?= e($item['volumen_m3'] ?? '—') ?> m³</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($mercancias): ?>
                <tfoot>
                    <tr>
                        <th colspan="2" class="has-text-right">Total declarado</th>
                        <th><?= e(format_cr_measure($totalPesoSol)) ?> kg</th>
                        <th><?= $totalVolSol > 0 ? e(format_cr_measure($totalVolSol, 3)) . ' m³' : '—' ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php if (has_permission('DOCUMENTOS_VER')): ?>
<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Documentos y comprobantes (<?= count($docsInfo) ?>)</h2>
        <?php if (has_permission('DOCUMENTOS_REGISTRAR')): ?>
            <button class="button is-small button-secondary" type="button" hx-get="/entregas/documento_form.php?solicitud_id=<?= (int)$id ?>" hx-target="#modal-contenido">
                <?= svg_icon('plus') ?>
                <span>Adjuntar documento</span>
            </button>
        <?php endif; ?>
    </div>
    <?php if (!$docsInfo): ?>
        <div class="p-4 has-text-grey">Sin documentos vinculados a esta solicitud.</div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-hoverable">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Referencia</th>
                        <th>Fecha</th>
                        <th>Archivo / Enlace</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docsInfo as $doc): ?>
                        <?php $docUrl = read_safe_reference_url($doc['archivo_url'] ?? null); ?>
                        <tr>
                            <td><strong><?= e($doc['tipo_documento']) ?></strong></td>
                            <td><code><?= e($doc['numero_referencia'] ?: '—') ?></code></td>
                            <td><?= e(format_cr_date($doc['fecha_documento'])) ?></td>
                            <td>
                                <?php if ($docUrl): ?>
                                    <a class="table-link" href="<?= e($docUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($doc['nombre_archivo_original'] ?: 'Abrir documento') ?> <?= svg_icon('arrow-up-right') ?></a>
                                <?php else: ?>
                                    <span class="has-text-grey">Sin archivo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (has_permission('INCIDENCIAS_VER') && $incidenciasInfo): ?>
<section class="panel-box table-panel">
    <div class="panel-box-header">
        <h2 class="panel-box-title">Incidencias reportadas (<?= count($incidenciasInfo) ?>)</h2>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Descripción</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($incidenciasInfo as $inc): ?>
                    <tr>
                        <td><strong><?= e($inc['tipo_incidencia']) ?></strong></td>
                        <td><?= status_tag($inc['estado']) ?></td>
                        <td><?= e($inc['descripcion']) ?></td>
                        <td><?= format_utc_datetime($inc['fecha_reporte']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

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
