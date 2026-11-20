<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/dispatch_advisor.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string)($_GET['accion'] ?? '');
$permissions = [
    'parada' => 'VIAJES_PARADAS',
    'asignar' => 'VIAJES_ASIGNAR',
    'asignar_lote' => 'VIAJES_ASIGNAR',
    'iniciar' => 'VIAJES_INICIAR',
    'finalizar' => 'VIAJES_FINALIZAR',
    'cancelar' => 'VIAJES_CANCELAR',
];
if ($id === false || !isset($permissions[$action])) {
    http_response_code(404);
    exit('Acción no disponible.');
}
require_permission($permissions[$action]);
if ($action === 'asignar_lote') {
    require_permission('VIAJES_PARADAS');
}
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [(int)$id]);
    $viaje = $sets[0][0] ?? null;
    $paradas = $sets[1] ?? [];
} catch (PDOException) {
    read_error_response();
}
if (!is_array($viaje)) {
    http_response_code(404);
    exit('Viaje no encontrado.');
}
$valid = ($action === 'finalizar' ? $viaje['estado'] === 'EN_CURSO' : $viaje['estado'] === 'PLANIFICADO');
if (!$valid) {
    http_response_code(409);
    exit(ui_notice('El viaje ya no admite esta acción.', 'warning'));
}

if ($action === 'parada') {
    try {
        $ubicaciones = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones_parada @buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    } catch (PDOException) {
        read_error_response();
    }
}
$capacidadViaje = null;
$pesosSolicitudes = [];
if (in_array($action, ['asignar', 'asignar_lote'], true)) {
    try {
        $registradas = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['REGISTRADA']);
        $pendientes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['PENDIENTE_ASIGNACION']);
        $solicitudes = array_merge($registradas, $pendientes);
        $capacidadViaje = read_procedure_sets('EXEC dbo.sp_obtener_capacidad_viaje @viaje_id=?;', [(int)$id])[0][0] ?? null;
        $pesosRows = read_procedure_sets('EXEC dbo.sp_listar_pesos_solicitudes_disponibles;', [])[0] ?? [];
        foreach ($pesosRows as $pr) {
            $pesosSolicitudes[(int)$pr['solicitud_id']] = [
                'peso' => (float)($pr['peso_total_kg'] ?? 0),
                'vol' => (float)($pr['volumen_total_m3'] ?? 0),
                'origen_lat' => $pr['origen_lat'] ?? null,
                'origen_lon' => $pr['origen_lon'] ?? null,
                'destino_lat' => $pr['destino_lat'] ?? null,
                'destino_lon' => $pr['destino_lon'] ?? null,
                'fecha_entrega_requerida' => $pr['fecha_entrega_requerida'] ?? null,
            ];
        }
        if (is_array($capacidadViaje)) {
            $tripEvalCtx = array_merge($capacidadViaje, [
                'fecha_programada_salida' => $viaje['fecha_programada_salida'] ?? null,
            ]);
            foreach ($solicitudes as &$solRow) {
                $sId = (int)$solRow['solicitud_id'];
                $pm = $pesosSolicitudes[$sId] ?? [];
                $reqCtx = [
                    'origen_id' => (int)($solRow['origen_id'] ?? 0),
                    'destino_id' => (int)($solRow['destino_id'] ?? 0),
                    'origen_lat' => $pm['origen_lat'] ?? null,
                    'origen_lon' => $pm['origen_lon'] ?? null,
                    'destino_lat' => $pm['destino_lat'] ?? null,
                    'destino_lon' => $pm['destino_lon'] ?? null,
                    'peso_solicitud_kg' => (float)($pm['peso'] ?? 0),
                    'volumen_solicitud_m3' => (float)($pm['vol'] ?? 0),
                    'fecha_entrega_requerida' => $pm['fecha_entrega_requerida'] ?? ($solRow['fecha_entrega_requerida'] ?? null),
                ];
                $solRow['_fit'] = dispatch_evaluate_fit($reqCtx, $tripEvalCtx, $paradas);
            }
            unset($solRow);
            usort($solicitudes, static fn(array $a, array $b): int => ($b['_fit']['puntaje'] ?? 0) <=> ($a['_fit']['puntaje'] ?? 0));
        }
    } catch (PDOException) {
        read_error_response();
    }
}

$titles = [
    'parada' => 'Agregar parada',
    'asignar' => 'Asignar solicitud',
    'asignar_lote' => 'Asignar varias solicitudes al viaje',
    'iniciar' => 'Iniciar viaje',
    'finalizar' => 'Finalizar viaje',
    'cancelar' => 'Cancelar viaje',
];
$submitLabels = [
    'parada' => 'Agregar',
    'asignar' => 'Asignar',
    'asignar_lote' => 'Asignar seleccionadas',
    'iniciar' => 'Iniciar',
    'finalizar' => 'Finalizar',
    'cancelar' => 'Confirmar cancelación',
];
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-viaje-accion">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card <?= $action === 'asignar_lote' ? 'modal-card-wide' : '' ?>">
        <?= modal_header('titulo-viaje-accion', $titles[$action]) ?>
        <form hx-post="/viajes/accion.php" hx-target="#mensaje-viaje-accion" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_id" value="<?= (int)$id ?>">
                <input type="hidden" name="accion" value="<?= e($action) ?>">
                <div id="mensaje-viaje-accion" role="alert"></div>

                <div class="detail-grid mb-4">
                    <div>
                        <span>Viaje</span>
                        <strong><?= e($viaje['codigo_viaje']) ?></strong>
                    </div>
                    <div>
                        <span>Estado actual</span>
                        <div><?= status_tag($viaje['estado']) ?></div>
                    </div>
                </div>

                <?php if ($action === 'parada'): ?>
                    <div class="field">
                        <label class="label" for="parada-ubicacion">Ubicación <span class="required">*</span></label>
                        <select id="parada-ubicacion" name="ubicacion_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones&contexto=parada" data-placeholder="Buscar ubicación frecuente o de solicitud abierta…" required>
                            <option value="">Selecciona ubicación…</option>
                            <?php foreach ($ubicaciones as $row): ?>
                                <?php
                                $sub = (string)($row['direccion'] ?? '');
                                if (isset($row['es_reutilizable']) && (int)$row['es_reutilizable'] === 0) {
                                    $solCode = trim((string)($row['solicitud_codigo'] ?? ''));
                                    $prefix = $solCode !== '' ? "Solicitud {$solCode} · Punto puntual" : 'Punto puntual';
                                    $sub = $sub !== '' ? "{$prefix} · {$sub}" : $prefix;
                                }
                                ?>
                                <option value="<?= (int)$row['ubicacion_id'] ?>" data-subtext="<?= e($sub) ?>"><?= e($row['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help">Incluye ubicaciones frecuentes y puntos marcados en mapa de solicitudes pendientes por asignar.</p>
                    </div>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="parada-orden">Orden de parada <span class="required">*</span></label>
                                <input class="input" id="parada-orden" name="orden" type="number" min="1" value="<?= count($paradas) + 1 ?>" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="parada-tipo">Tipo de parada <span class="required">*</span></label>
                                <div class="select is-fullwidth">
                                    <select id="parada-tipo" name="tipo_parada" required>
                                        <option value="RECOGIDA">Recogida</option>
                                        <option value="ENTREGA">Entrega</option>
                                        <option value="MIXTA">Mixta</option>
                                        <option value="INTERMEDIA">Intermedia</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($action === 'asignar'): ?>
                    <?php
                    $paradasCatalogo = array_map(
                        static fn(array $row): array => [
                            'id' => (int)$row['parada_id'],
                            'ubicacion_id' => (int)$row['ubicacion_id'],
                            'orden' => (int)$row['orden'],
                            'tipo_parada' => strtoupper(trim((string)$row['tipo_parada'])),
                            'ubicacion_nombre' => (string)$row['ubicacion_nombre'],
                            'direccion' => (string)($row['direccion'] ?? ''),
                            'label' => '#' . (int)$row['orden'] . ' · ' . (string)$row['ubicacion_nombre'] . ' (' . humanize_status($row['tipo_parada']) . ')',
                            'subtext' => trim((string)($row['direccion'] ?? '')),
                        ],
                        $paradas
                    );
                    ?>
                    <?php $canAutoStops = has_permission('VIAJES_PARADAS'); ?>
                    <?php if (is_array($capacidadViaje)): ?>
                        <?php
                        $capPesoMax = (float)($capacidadViaje['capacidad_carga_kg'] ?? 0);
                        $capPesoUsado = (float)($capacidadViaje['peso_usado_kg'] ?? 0);
                        $capPesoLibre = max(0.0, $capPesoMax - $capPesoUsado);
                        ?>
                        <div class="notification notification-info py-2 px-3 mb-3">
                            <strong>Capacidad disponible en la unidad:</strong>
                            <?= e(format_cr_measure($capPesoLibre)) ?> kg libres
                            (<?= e(format_cr_measure($capPesoUsado)) ?> / <?= e(format_cr_measure($capPesoMax)) ?> kg usados)
                        </div>
                    <?php endif; ?>
                    <div data-assign-stops-root data-can-auto-stops="<?= $canAutoStops ? '1' : '0' ?>" data-trip-stops="<?= e(json_encode($paradasCatalogo, JSON_UNESCAPED_UNICODE)) ?>">
                        <div class="field">
                            <label class="label" for="asignar-solicitud">Solicitud elegible <span class="required">*</span></label>
                            <select id="asignar-solicitud" name="solicitud_id" data-combobox data-allow-clear="true" data-placeholder="Buscar solicitud por código o cliente…" required>
                                <option value="">Selecciona solicitud…</option>
                                <?php foreach ($solicitudes as $row): ?>
                                    <?php
                                    $pInfo = $pesosSolicitudes[(int)$row['solicitud_id']] ?? null;
                                    $pesoTxt = ($pInfo && $pInfo['peso'] > 0) ? (' · ' . format_cr_measure($pInfo['peso']) . ' kg') : '';
                                    $fitRow = $row['_fit'] ?? null;
                                    $fitTag = is_array($fitRow) ? ('[' . $fitRow['etiqueta'] . '] ') : '';
                                    ?>
                                    <option value="<?= (int)$row['solicitud_id'] ?>" data-origen="<?= (int)($row['origen_id'] ?? 0) ?>" data-destino="<?= (int)($row['destino_id'] ?? 0) ?>" data-origen-nombre="<?= e($row['origen_nombre'] ?? '') ?>" data-destino-nombre="<?= e($row['destino_nombre'] ?? '') ?>" data-subtext="<?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '') . $pesoTxt . (is_array($fitRow) ? ' · ' . $fitRow['motivo'] : '')) ?>"><?= e($fitTag . $row['codigo_solicitud'] . ' · ' . ($row['cliente_nombre'] ?? humanize_status($row['estado'])) . $pesoTxt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($canAutoStops): ?>
                            <div class="field mb-3">
                                <label class="checkbox">
                                    <input type="checkbox" name="auto_crear_paradas" value="1" checked data-auto-stops-toggle>
                                    <span><strong>Crear automáticamente las paradas faltantes</strong> (reutiliza las existentes en el viaje y agrega solo las que falten)</span>
                                </label>
                            </div>
                        <?php endif; ?>
                        <div class="columns">
                            <div class="column">
                                <div class="field">
                                    <label class="label" for="parada-recogida">Parada de recogida<?= $canAutoStops ? '' : ' <span class="required">*</span>' ?></label>
                                    <select id="parada-recogida" name="parada_recogida_id" data-combobox data-allow-clear="true" data-placeholder="Selecciona primero una solicitud…" disabled <?= $canAutoStops ? '' : 'required' ?>>
                                        <option value="">Selecciona primero una solicitud…</option>
                                    </select>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label" for="parada-entrega">Parada de entrega<?= $canAutoStops ? '' : ' <span class="required">*</span>' ?></label>
                                    <select id="parada-entrega" name="parada_entrega_id" data-combobox data-allow-clear="true" data-placeholder="Selecciona primero una solicitud…" disabled <?= $canAutoStops ? '' : 'required' ?>>
                                        <option value="">Selecciona primero una solicitud…</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div id="asignar-validacion-aviso" class="notification notification-warning p-2 mb-2" role="status" aria-live="polite" hidden></div>
                        <p class="help" id="asignar-paradas-ayuda">Selecciona una solicitud: el sistema reutilizará paradas compatibles del viaje o creará automáticamente las que falten.</p>
                    </div>
                <?php elseif ($action === 'asignar_lote'): ?>
                    <?php
                    $capPesoMax = (float)($capacidadViaje['capacidad_carga_kg'] ?? 0);
                    $capPesoUsado = (float)($capacidadViaje['peso_usado_kg'] ?? 0);
                    $capPesoLibre = max(0.0, $capPesoMax - $capPesoUsado);
                    ?>
                    <div data-batch-assign-root data-free-kg="<?= e((string)$capPesoLibre) ?>" data-max-kg="<?= e((string)$capPesoMax) ?>">
                        <div class="notification notification-info py-2 px-3 mb-3 is-flex is-justify-content-space-between is-align-items-center" style="gap: 0.75rem; flex-wrap: wrap;">
                            <div>
                                <strong>Capacidad libre:</strong> <?= e(format_cr_measure($capPesoLibre)) ?> kg
                                · <span data-batch-summary>0 seleccionadas (0 kg)</span>
                            </div>
                            <?php if ($solicitudes): ?>
                                <button class="button is-small button-secondary" type="button" data-batch-select-compatible>
                                    <span>Seleccionar compatibles</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="notification notification-danger py-2 px-3 mb-3" data-batch-overweight hidden></div>

                        <?php if (!$solicitudes): ?>
                            <?= empty_state('Sin solicitudes disponibles', 'No hay solicitudes en estado Registrada o Pendiente de asignación.') ?>
                        <?php else: ?>
                            <p class="help mb-2">Marca las solicitudes que deseas subir a este viaje. El sistema reutilizará o creará automáticamente las paradas faltantes en una sola transacción.</p>
                            <div class="field mb-3">
                                <label class="checkbox">
                                    <input type="checkbox" name="optimizar_orden" value="1" checked>
                                    <span><strong>Aplicar Ruta inteligente automáticamente</strong> (ordena las paradas por cercanía Haversine respetando recogida antes que entrega)</span>
                                </label>
                            </div>
                            <div class="table-container" style="max-height: 320px; overflow-y: auto;">
                                <table class="table is-fullwidth is-hoverable is-narrow">
                                    <thead>
                                        <tr>
                                            <th style="width: 36px;"></th>
                                            <th>Compatibilidad</th>
                                            <th>Solicitud / Cliente</th>
                                            <th>Trayecto</th>
                                            <th class="has-text-right">Peso</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($solicitudes as $row): ?>
                                            <?php
                                            $sId = (int)$row['solicitud_id'];
                                            $pInfo = $pesosSolicitudes[$sId] ?? null;
                                            $pesoVal = (float)($pInfo['peso'] ?? 0);
                                            $fitRow = $row['_fit'] ?? null;
                                            $cabe = is_array($fitRow) ? !empty($fitRow['cabe']) : true;
                                            $nivel = is_array($fitRow) ? (string)$fitRow['nivel'] : 'compatible';
                                            $tagCls = is_array($fitRow) ? (string)$fitRow['tag_class'] : 'is-info';
                                            $etiqueta = is_array($fitRow) ? (string)$fitRow['etiqueta'] : 'Compatible';
                                            ?>
                                            <tr>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        name="solicitudes[]"
                                                        value="<?= $sId ?>"
                                                        data-batch-check
                                                        data-peso="<?= e((string)$pesoVal) ?>"
                                                        data-nivel="<?= e($nivel) ?>"
                                                        <?= $cabe ? '' : 'disabled title="Esta solicitud por sí sola excede la capacidad libre del camión."' ?>
                                                    >
                                                </td>
                                                <td><span class="tag <?= e($tagCls) ?>"><?= e($etiqueta) ?></span></td>
                                                <td>
                                                    <div class="cell-stack">
                                                        <strong><?= e($row['codigo_solicitud']) ?></strong>
                                                        <span class="is-size-7"><?= e($row['cliente_nombre'] ?? '') ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="is-size-7"><?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '')) ?></span>
                                                </td>
                                                <td class="has-text-right"><strong><?= e(format_cr_measure($pesoVal)) ?> kg</strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($action === 'cancelar'): ?>
                    <div class="notification notification-danger mb-4">
                        <p><strong>¿Confirmas la cancelación de este despacho?</strong></p>
                        <p class="mt-1">El viaje pasará a estado <code>CANCELADO</code>, las órdenes asociadas retornarán a <code>PENDIENTE_ASIGNACION</code> para ser reprogramadas, y el vehículo y conductor quedarán inmediatamente <code>DISPONIBLE</code>.</p>
                    </div>
                    <div class="field">
                        <label class="label" for="cancelar-motivo">Motivo o justificación</label>
                        <textarea class="textarea" id="cancelar-motivo" name="motivo" rows="2" maxlength="500" placeholder="Ej. Cambio de ruta, mantenimiento o solicitud de la gerencia…"></textarea>
                    </div>
                <?php else: ?>
                    <p><?= $action === 'iniciar' ? 'Se pondrá el viaje en curso siempre que cuente con solicitudes asignadas.' : 'Se marcará el viaje como finalizado una vez que todas las solicitudes asociadas tengan resultado de entrega.' ?></p>
                <?php endif; ?>
            </section>
            <?= modal_footer($submitLabels[$action], in_array($action, ['finalizar', 'cancelar'], true) ? 'is-danger' : 'is-primary') ?>
        </form>
    </div>
</div>
