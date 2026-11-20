<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/dispatch_advisor.php';
require_permission('VIAJES_CREAR');

$canAutoAssign = has_permission('VIAJES_ASIGNAR') && has_permission('VIAJES_PARADAS') && has_permission('SOLICITUDES_VER');
$canAutoRoute = $canAutoAssign && has_permission('RUTAS_GESTIONAR');
$preselectedSolicitud = filter_var($_GET['solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$solicitudesPendientes = [];

try {
    $vehiculos = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=?,@solo_activos=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        ['DISPONIBLE']
    );
    $conductores = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_conductores @estado=?,@solo_activos=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        ['DISPONIBLE']
    );
    $rutas = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_rutas @origen_id=NULL,@destino_id=NULL,@solo_activas=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'
    );
    if ($canAutoAssign) {
        $reg = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['REGISTRADA']);
        $pend = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=?,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', ['PENDIENTE_ASIGNACION']);
        $solicitudesPendientes = array_merge($reg, $pend);
    }
} catch (PDOException) {
    read_error_response('No se pudieron cargar los recursos disponibles.');
}

$defaultRutaId = count($rutas) === 1 ? (int)$rutas[0]['ruta_id'] : 0;
$autoRouteChecked = false;
$preselectedSolRow = null;

if ($preselectedSolicitud > 0 && $solicitudesPendientes) {
    foreach ($solicitudesPendientes as $sp) {
        if ((int)$sp['solicitud_id'] === $preselectedSolicitud) {
            $preselectedSolRow = $sp;
            $oriId = (int)($sp['origen_id'] ?? 0);
            $desId = (int)($sp['destino_id'] ?? 0);
            // Buscar coincidencia exacta de origen y destino primero
            $exactRouteId = 0;
            foreach ($rutas as $r) {
                if ((int)($r['origen_id'] ?? 0) === $oriId && (int)($r['destino_id'] ?? 0) === $desId) {
                    $exactRouteId = (int)$r['ruta_id'];
                    break;
                }
            }
            if ($exactRouteId > 0) {
                $defaultRutaId = $exactRouteId;
                $autoRouteChecked = false;
            } elseif ($canAutoRoute) {
                // No existe ruta exacta (ej. Puntarenas -> Santa Cruz): activar generación automática
                $defaultRutaId = 0;
                $autoRouteChecked = true;
            } else {
                $suggestedRoute = dispatch_best_route_id_for_request($rutas, $oriId, $desId);
                if ($suggestedRoute > 0) {
                    $defaultRutaId = $suggestedRoute;
                }
            }
            break;
        }
    }
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-viaje">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-viaje', 'Nuevo viaje') ?>
        <form hx-post="/viajes/guardar.php" hx-target="#mensaje-viaje" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]" data-trip-create-form>
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-viaje" role="alert"></div>
                <div id="aviso-diagnostico-habilitacion" class="notification py-2 px-3 mb-3" style="display: none;" role="status" aria-live="polite"></div>

                <?php if ($canAutoAssign && $solicitudesPendientes): ?>
                    <div class="field">
                        <label class="label" for="viaje-solicitud-inicial">Solicitud inicial a despachar (opcional)</label>
                        <select id="viaje-solicitud-inicial" name="solicitud_inicial_id" data-combobox data-allow-clear="true" data-placeholder="Opcional: elige una solicitud para armar ruta y paradas automáticamente…">
                            <option value="">Ninguna por ahora (asignar después)</option>
                            <?php foreach ($solicitudesPendientes as $sol): ?>
                                <option value="<?= (int)$sol['solicitud_id'] ?>" <?= (int)$sol['solicitud_id'] === $preselectedSolicitud ? 'selected' : '' ?> data-origen="<?= (int)($sol['origen_id'] ?? 0) ?>" data-destino="<?= (int)($sol['destino_id'] ?? 0) ?>" data-trayecto="<?= e(($sol['origen_nombre'] ?? '') . ' → ' . ($sol['destino_nombre'] ?? '')) ?>" data-subtext="<?= e(($sol['origen_nombre'] ?? '') . ' → ' . ($sol['destino_nombre'] ?? '') . ' · ' . humanize_status($sol['prioridad_actual'] ?? 'NORMAL')) ?>">
                                    <?= e($sol['codigo_solicitud'] . ' · ' . ($sol['cliente_nombre'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help">Al seleccionar una solicitud, el sistema puede generar automáticamente su ruta exacta y sus paradas en un solo paso.</p>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="label" for="viaje-vehiculo">Vehículo disponible <span class="required">*</span></label>
                    <select id="viaje-vehiculo" name="vehiculo_id" data-combobox data-remote-url="/flota/lista.php?seccion=vehiculos&estado=DISPONIBLE" data-placeholder="Buscar unidad por placa o modelo…" required>
                        <option value="">Selecciona vehículo…</option>
                        <?php foreach ($vehiculos as $row): ?>
                            <?php
                            $pmaDisplay = format_cr_measure($row['pma_kg'] ?? $row['capacidad_carga_kg']);
                            $subtextVeh = 'PMA: ' . $pmaDisplay . ' kg · ' . humanize_status($row['clase_legal'] ?? 'LIVIANO') . (!empty($row['es_articulado']) ? ' (Articulado)' : '');
                            ?>
                            <option value="<?= (int)$row['vehiculo_id'] ?>" data-pma="<?= e($row['pma_kg'] ?? $row['capacidad_carga_kg']) ?>" data-articulado="<?= (int)($row['es_articulado'] ?? 0) ?>" data-clase="<?= e($row['clase_legal'] ?? 'LIVIANO') ?>" data-subtext="<?= e($subtextVeh) ?>">
                                <?= e($row['placa'] . ' · ' . $row['marca'] . ' ' . $row['modelo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="viaje-conductor">Conductor disponible <span class="required">*</span></label>
                    <select id="viaje-conductor" name="conductor_id" data-combobox data-remote-url="/flota/lista.php?seccion=conductores&estado=DISPONIBLE" data-placeholder="Buscar conductor disponible…" required>
                        <option value="">Selecciona conductor…</option>
                        <?php foreach ($conductores as $row): ?>
                            <?php
                            $licRaw = trim((string)($row['licencias_verificadas'] ?? ''));
                            $licSub = ($licRaw !== '' && strtoupper($licRaw) !== 'PENDIENTE') ? ('Lic: ' . $licRaw) : 'Sin categoría registrada';
                            ?>
                            <option value="<?= (int)$row['conductor_id'] ?>" data-licencias="<?= e($licRaw) ?>" data-vencimiento="<?= e(substr((string)($row['fecha_vencimiento_licencia'] ?? ''), 0, 10)) ?>" data-subtext="<?= e($licSub) ?>">
                                <?= e(trim($row['nombre'] . ' ' . $row['primer_apellido'] . ' ' . ($row['segundo_apellido'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($canAutoRoute && $solicitudesPendientes): ?>
                    <div class="field mb-2" data-auto-route-wrap <?= $preselectedSolicitud > 0 ? '' : 'hidden' ?>>
                        <label class="checkbox">
                            <input type="checkbox" name="auto_crear_ruta" value="1" data-auto-route-toggle <?= $autoRouteChecked ? 'checked' : '' ?>>
                            <span><strong>Generar automáticamente la ruta exacta desde esta solicitud</strong></span>
                        </label>
                        <p class="help" data-auto-route-help>
                            <?php if ($autoRouteChecked && is_array($preselectedSolRow)): ?>
                                No existe una ruta exacta registrada para <strong><?= e(($preselectedSolRow['origen_nombre'] ?? '') . ' → ' . ($preselectedSolRow['destino_nombre'] ?? '')) ?></strong>; se creará automáticamente con distancia estimada (Haversine).
                            <?php else: ?>
                                Si no existe una ruta exacta en el catálogo para el origen y destino de la solicitud, SQL Server la crea automáticamente.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="field" data-manual-route-box <?= $autoRouteChecked ? 'hidden' : '' ?>>
                    <label class="label" for="viaje-ruta">Corredor / Ruta de referencia <span class="required">*</span></label>
                    <select id="viaje-ruta" name="ruta_id" data-combobox data-remote-url="/flota/lista.php?seccion=rutas" data-placeholder="Buscar ruta por nombre, origen o destino…" <?= $autoRouteChecked ? 'disabled' : 'required' ?>>
                        <option value="">Selecciona corredor o ruta…</option>
                        <?php foreach ($rutas as $row): ?>
                            <option value="<?= (int)$row['ruta_id'] ?>" <?= (int)$row['ruta_id'] === $defaultRutaId ? 'selected' : '' ?> data-origen="<?= (int)($row['origen_id'] ?? 0) ?>" data-destino="<?= (int)($row['destino_id'] ?? 0) ?>" data-subtext="<?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '')) ?>"><?= e($row['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help">Define el corredor general del despacho; las paradas exactas se crean automáticamente al asignar solicitudes.</p>
                </div>

                <div class="field">
                    <label class="label" for="viaje-fecha">Fecha y hora de salida programada <span class="required">*</span></label>
                    <?php $minLocalCr = (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->modify('+1 minute')->format('Y-m-d\\TH:i'); ?>
                    <input class="input" id="viaje-fecha" name="fecha_programada" type="text" data-datepicker="datetime" min="<?= e($minLocalCr) ?>" placeholder="dd/mm/aaaa, hh:mm" required>
                </div>
            </section>
            <?= modal_footer('Crear viaje', 'is-primary') ?>
        </form>
    </div>
</div>
