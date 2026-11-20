<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_permission('VIAJES_PARADAS');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Viaje no encontrado.', 'danger'));
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [(int)$id]);
    $viaje = $sets[0][0] ?? null;
    $paradas = $sets[1] ?? [];
    $asignaciones = $sets[2] ?? [];
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit(ui_notice('Viaje no encontrado.', 'danger'));
    }
    read_error_response();
}

if (!is_array($viaje)) {
    http_response_code(404);
    exit(ui_notice('Viaje no encontrado.', 'danger'));
}

if (($viaje['estado'] ?? '') !== 'PLANIFICADO') {
    http_response_code(409);
    exit(ui_notice('Solo se pueden reordenar paradas de viajes en estado Planificado.', 'warning'));
}

if (count($paradas) < 2) {
    http_response_code(422);
    exit(ui_notice('El viaje requiere al menos dos paradas para reordenar el itinerario.', 'warning'));
}

$total = count($paradas);
$autoSugerir = ((string)($_GET['auto_sugerir'] ?? '') === '1');
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-reordenar-paradas">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-reordenar-paradas', $autoSugerir ? 'Ruta inteligente — Orden sugerido' : 'Reordenar paradas del itinerario') ?>
        <form hx-post="/viajes/paradas_orden_guardar.php" hx-target="#mensaje-reordenar-paradas" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_id" value="<?= (int)$id ?>">
                <div id="mensaje-reordenar-paradas" role="alert"></div>

                <div class="detail-grid mb-4">
                    <div>
                        <span>Viaje</span>
                        <strong><?= e($viaje['codigo_viaje']) ?></strong>
                    </div>
                    <div>
                        <span>Paradas actuales</span>
                        <strong><?= $total ?> paradas</strong>
                    </div>
                </div>

                <?php if ($asignaciones): ?>
                    <div class="notification notification-info mb-4">
                        <?= svg_icon('shield') ?>
                        <span>Este viaje tiene <?= count($asignaciones) ?> solicitud(es) asignada(s). Puedes ajustar la secuencia siempre que cada recogida permanezca antes de su entrega.</span>
                    </div>
                <?php else: ?>
                    <p class="help mb-3">Usa los botones Subir y Bajar para definir el orden secuencial del itinerario y guarda los cambios.</p>
                <?php endif; ?>

                <?php
                $precedencePairs = [];
                foreach ($asignaciones as $asig) {
                    $pRec = (int)($asig['parada_recogida_id'] ?? 0);
                    $pEnt = (int)($asig['parada_entrega_id'] ?? 0);
                    if ($pRec > 0 && $pEnt > 0 && $pRec !== $pEnt) {
                        $precedencePairs[] = [$pRec, $pEnt];
                    }
                }
                ?>
                <div class="is-flex is-justify-content-space-between is-align-items-center mb-3" style="gap: 0.75rem; flex-wrap: wrap;">
                    <span class="help mb-0">Puedes ajustar manualmente o pedir una sugerencia por cercanía geográfica.</span>
                    <button class="button is-small button-secondary" type="button" data-stop-suggest-order>
                        <?= svg_icon('map-pin') ?>
                        <span>Sugerir orden óptimo</span>
                    </button>
                </div>
                <div id="orden-optimo-aviso" class="notification notification-info p-2 mb-3" role="status" aria-live="polite" hidden></div>

                <ol class="stop-reorder-list" data-stop-reorder-list data-auto-suggest="<?= $autoSugerir ? '1' : '0' ?>" data-precedence-pairs="<?= e(json_encode($precedencePairs)) ?>">
                    <?php foreach ($paradas as $index => $row): ?>
                        <?php $vinculada = !empty($row['tiene_solicitudes']); ?>
                        <li class="stop-reorder-item" data-stop-item data-parada-id="<?= (int)$row['parada_id'] ?>" data-lat="<?= e($row['latitud'] ?? '') ?>" data-lon="<?= e($row['longitud'] ?? '') ?>" data-tipo="<?= e($row['tipo_parada'] ?? '') ?>">
                            <input type="hidden" name="orden_paradas[]" value="<?= (int)$row['parada_id'] ?>">
                            <span class="stop-reorder-badge" data-stop-order-badge>#<?= $index + 1 ?></span>
                            <div class="stop-reorder-info">
                                <strong><?= e($row['ubicacion_nombre']) ?></strong>
                                <span class="stop-reorder-meta">
                                    <?= e(humanize_status($row['tipo_parada'])) ?>
                                    <?php if (!empty($row['direccion'])): ?>
                                        · <?= e($row['direccion']) ?>
                                    <?php endif; ?>
                                    <?php if ($vinculada): ?>
                                        · <em>Con solicitud</em>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="stop-reorder-controls">
                                <button
                                    class="button is-small button-secondary icon-button"
                                    type="button"
                                    data-stop-move="up"
                                    aria-label="Subir parada <?= e($row['ubicacion_nombre']) ?>"
                                    title="Subir una posición"
                                    <?= $index === 0 ? 'disabled' : '' ?>
                                >
                                    <?= svg_icon('arrow-up') ?>
                                </button>
                                <button
                                    class="button is-small button-secondary icon-button"
                                    type="button"
                                    data-stop-move="down"
                                    aria-label="Bajar parada <?= e($row['ubicacion_nombre']) ?>"
                                    title="Bajar una posición"
                                    <?= $index === ($total - 1) ? 'disabled' : '' ?>
                                >
                                    <?= svg_icon('arrow-down') ?>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?= modal_footer('Guardar orden', 'is-primary') ?>
        </form>
    </div>
</div>
