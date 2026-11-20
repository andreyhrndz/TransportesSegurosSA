<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_permission('VIAJES_PARADAS');

$viajeId = filter_var($_GET['viaje_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$paradaId = filter_var($_GET['parada_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($viajeId === false || $paradaId === false) {
    http_response_code(404);
    exit(ui_notice('Parada no encontrada.', 'danger'));
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [(int)$viajeId]);
    $viaje = $sets[0][0] ?? null;
    $paradas = $sets[1] ?? [];
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
    exit(ui_notice('Solo se pueden editar paradas de viajes en estado Planificado.', 'warning'));
}

$parada = null;
foreach ($paradas as $row) {
    if ((int)$row['parada_id'] === (int)$paradaId) {
        $parada = $row;
        break;
    }
}
if (!is_array($parada)) {
    http_response_code(404);
    exit(ui_notice('La parada indicada no pertenece a este viaje.', 'danger'));
}
if (!empty($parada['tiene_solicitudes'])) {
    http_response_code(409);
    exit(ui_notice('No se puede editar la ubicación ni el tipo de una parada vinculada a una solicitud asignada.', 'warning'));
}

try {
    $ubicaciones = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones_parada @buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
} catch (PDOException) {
    read_error_response();
}

$actualUbiId = (int)$parada['ubicacion_id'];
$actualPresente = false;
foreach ($ubicaciones as $u) {
    if ((int)$u['ubicacion_id'] === $actualUbiId) {
        $actualPresente = true;
        break;
    }
}
if (!$actualPresente) {
    array_unshift($ubicaciones, [
        'ubicacion_id' => $actualUbiId,
        'nombre' => (string)$parada['ubicacion_nombre'],
        'direccion' => (string)($parada['direccion'] ?? ''),
        'es_reutilizable' => (int)($parada['es_reutilizable'] ?? 1),
    ]);
}

$tipos = [
    'RECOGIDA' => 'Recogida',
    'ENTREGA' => 'Entrega',
    'MIXTA' => 'Mixta',
    'INTERMEDIA' => 'Intermedia',
];
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-parada">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-editar-parada', 'Editar parada #' . (int)$parada['orden']) ?>
        <form hx-post="/viajes/parada_editar_guardar.php" hx-target="#mensaje-editar-parada" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_id" value="<?= (int)$viajeId ?>">
                <input type="hidden" name="parada_id" value="<?= (int)$paradaId ?>">
                <div id="mensaje-editar-parada" role="alert"></div>

                <div class="detail-grid mb-4">
                    <div>
                        <span>Viaje</span>
                        <strong><?= e($viaje['codigo_viaje']) ?></strong>
                    </div>
                    <div>
                        <span>Posición actual</span>
                        <strong>Parada #<?= (int)$parada['orden'] ?></strong>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="editar-parada-ubicacion">Ubicación <span class="required">*</span></label>
                    <select id="editar-parada-ubicacion" name="ubicacion_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones&contexto=parada" data-placeholder="Buscar ubicación frecuente o de solicitud abierta…" required>
                        <option value="">Selecciona ubicación…</option>
                        <?php foreach ($ubicaciones as $row): ?>
                            <?php
                            $uid = (int)$row['ubicacion_id'];
                            $sub = (string)($row['direccion'] ?? '');
                            if (isset($row['es_reutilizable']) && (int)$row['es_reutilizable'] === 0) {
                                $solCode = trim((string)($row['solicitud_codigo'] ?? ''));
                                $prefix = $solCode !== '' ? "Solicitud {$solCode} · Punto puntual" : 'Punto puntual';
                                $sub = $sub !== '' ? "{$prefix} · {$sub}" : $prefix;
                            }
                            ?>
                            <option value="<?= $uid ?>" data-subtext="<?= e($sub) ?>" <?= $uid === $actualUbiId ? 'selected' : '' ?>><?= e($row['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="editar-parada-tipo">Tipo de parada <span class="required">*</span></label>
                    <div class="select is-fullwidth">
                        <select id="editar-parada-tipo" name="tipo_parada" required>
                            <?php foreach ($tipos as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= strtoupper((string)$parada['tipo_parada']) === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="help">Para cambiar la posición en la ruta utiliza la opción «Reordenar paradas».</p>
            </section>
            <?= modal_footer('Guardar cambios', 'is-primary') ?>
        </form>
    </div>
</div>
