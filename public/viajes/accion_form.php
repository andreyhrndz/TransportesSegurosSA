<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string)($_GET['accion'] ?? '');
$permissions = [
    'parada' => 'VIAJES_PARADAS',
    'asignar' => 'VIAJES_ASIGNAR',
    'iniciar' => 'VIAJES_INICIAR',
    'finalizar' => 'VIAJES_FINALIZAR',
    'cancelar' => 'VIAJES_CANCELAR',
];
if ($id === false || !isset($permissions[$action])) {
    http_response_code(404);
    exit('Acción no disponible.');
}
require_permission($permissions[$action]);
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
        $ubicaciones = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    } catch (PDOException) {
        read_error_response();
    }
}
if ($action === 'asignar') {
    try {
        $registradas = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['REGISTRADA']);
        $pendientes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['PENDIENTE_ASIGNACION']);
        $solicitudes = array_merge($registradas, $pendientes);
    } catch (PDOException) {
        read_error_response();
    }
}

$titles = [
    'parada' => 'Agregar parada',
    'asignar' => 'Asignar solicitud',
    'iniciar' => 'Iniciar viaje',
    'finalizar' => 'Finalizar viaje',
    'cancelar' => 'Cancelar viaje',
];
$submitLabels = [
    'parada' => 'Agregar',
    'asignar' => 'Asignar',
    'iniciar' => 'Iniciar',
    'finalizar' => 'Finalizar',
    'cancelar' => 'Confirmar cancelación',
];
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-viaje-accion">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
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
                        <select id="parada-ubicacion" name="ubicacion_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones" data-placeholder="Buscar ubicación…" required>
                            <option value="">Selecciona ubicación…</option>
                            <?php foreach ($ubicaciones as $row): ?>
                                <option value="<?= (int)$row['ubicacion_id'] ?>" data-subtext="<?= e($row['direccion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                    <div class="field">
                        <label class="label" for="asignar-solicitud">Solicitud elegible <span class="required">*</span></label>
                        <select id="asignar-solicitud" name="solicitud_id" data-combobox data-placeholder="Buscar solicitud por código o cliente…" required>
                            <option value="">Selecciona solicitud…</option>
                            <?php foreach ($solicitudes as $row): ?>
                                <option value="<?= (int)$row['solicitud_id'] ?>" data-origen="<?= (int)($row['origen_id'] ?? 0) ?>" data-destino="<?= (int)($row['destino_id'] ?? 0) ?>" data-subtext="<?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '') . ' · ' . humanize_status($row['estado'])) ?>"><?= e($row['codigo_solicitud'] . ' · ' . ($row['cliente_nombre'] ?? humanize_status($row['estado']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="parada-recogida">Parada de recogida <span class="required">*</span></label>
                                <select id="parada-recogida" name="parada_recogida_id" data-combobox data-placeholder="Selecciona parada…" required>
                                    <option value="">Selecciona</option>
                                    <?php foreach ($paradas as $row): ?>
                                        <option value="<?= (int)$row['parada_id'] ?>" data-ubicacion="<?= (int)$row['ubicacion_id'] ?>" data-orden="<?= (int)$row['orden'] ?>" data-tipo="<?= e($row['tipo_parada']) ?>"><?= (int)$row['orden'] ?> · <?= e($row['ubicacion_nombre'] . ' (' . humanize_status($row['tipo_parada']) . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="parada-entrega">Parada de entrega <span class="required">*</span></label>
                                <select id="parada-entrega" name="parada_entrega_id" data-combobox data-placeholder="Selecciona parada…" required>
                                    <option value="">Selecciona</option>
                                    <?php foreach ($paradas as $row): ?>
                                        <option value="<?= (int)$row['parada_id'] ?>" data-ubicacion="<?= (int)$row['ubicacion_id'] ?>" data-orden="<?= (int)$row['orden'] ?>" data-tipo="<?= e($row['tipo_parada']) ?>"><?= (int)$row['orden'] ?> · <?= e($row['ubicacion_nombre'] . ' (' . humanize_status($row['tipo_parada']) . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="asignar-validacion-aviso" class="notification notification-warning p-2 mb-2" hidden></div>
                    <p class="help">Las paradas deben corresponder al origen y destino de la orden y respetar el orden secuencial del itinerario.</p>
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
