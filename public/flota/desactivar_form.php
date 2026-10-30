<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/master_data.php';

header('Cache-Control: no-store');

try {
    $entity = strtolower(trim((string)($_GET['entidad'] ?? '')));
    $meta = master_entity_meta($entity);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    exit(ui_notice($exception->getMessage(), 'danger'));
}

require_permission($meta['read_permission']);
require_permission($meta['write_permission']);

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Registro maestro no encontrado.', 'danger'));
}

try {
    $record = master_find_record($entity, $id);
} catch (PDOException) {
    read_error_response('No se pudo cargar la confirmación.');
}

if (!is_array($record)) {
    http_response_code(404);
    exit(ui_notice('Registro maestro no encontrado.', 'danger'));
}

if ((int)$record['activo'] !== 1) {
    http_response_code(409);
    exit(ui_notice('El registro maestro ya se encuentra inactivo.', 'warning'));
}

if (($entity === 'vehiculo' && (string)$record['estado'] === 'ASIGNADO')
    || ($entity === 'conductor' && (string)$record['estado_operativo'] === 'ASIGNADO')
) {
    http_response_code(409);
    exit(ui_notice('No se puede desactivar un recurso de flota mientras está asignado a un viaje.', 'warning'));
}

$summaryLeftLabel = 'Registro';
$summaryLeftVal = (string)($record['nombre'] ?? '');
$summaryRightLabel = 'Detalle';
$summaryRightVal = 'Activo';

if ($entity === 'vehiculo') {
    $summaryLeftLabel = 'Placa';
    $summaryLeftVal = (string)$record['placa'];
    $summaryRightLabel = 'Unidad';
    $summaryRightVal = trim((string)$record['marca'] . ' ' . (string)$record['modelo']);
} elseif ($entity === 'conductor') {
    $summaryLeftLabel = 'Conductor';
    $summaryLeftVal = trim((string)$record['nombre'] . ' ' . (string)$record['primer_apellido']);
    $summaryRightLabel = 'Licencia';
    $summaryRightVal = (string)$record['numero_licencia'];
} elseif ($entity === 'ruta') {
    $summaryLeftLabel = 'Ruta';
    $summaryLeftVal = (string)$record['nombre'];
    $summaryRightLabel = 'Tramo';
    $summaryRightVal = (string)$record['origen_nombre'] . ' → ' . (string)$record['destino_nombre'];
} elseif ($entity === 'ubicacion') {
    $summaryLeftLabel = 'Ubicación';
    $summaryLeftVal = (string)$record['nombre'];
    $summaryRightLabel = 'Dirección';
    $summaryRightVal = (string)$record['direccion'];
} else {
    $summaryLeftLabel = ucfirst($meta['label']);
    $summaryLeftVal = (string)$record['nombre'];
    $summaryRightLabel = 'Descripción';
    $summaryRightVal = (string)($record['descripcion'] ?: 'Sin descripción');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-desactivar-maestro">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-desactivar-maestro', 'Desactivar ' . $meta['label']) ?>
        <form hx-post="/flota/desactivar.php" hx-target="#mensaje-desactivar-maestro" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="entidad" value="<?= e($entity) ?>">
                <input type="hidden" name="id" value="<?= (int)$record[$meta['id_column']] ?>">
                <div id="mensaje-desactivar-maestro" role="alert"></div>

                <div class="readonly-summary">
                    <div>
                        <span><?= e($summaryLeftLabel) ?></span>
                        <strong><?= e($summaryLeftVal) ?></strong>
                    </div>
                    <div>
                        <span><?= e($summaryRightLabel) ?></span>
                        <strong><?= e($summaryRightVal) ?></strong>
                    </div>
                </div>

                <p>¿Confirmas la desactivación de este registro?</p>
                <p class="help mt-2">El historial operativo se conservará íntegro y el registro dejará de aparecer en selectores nuevos. Si participa en operaciones activas, SQL Server bloqueará la desactivación.</p>
            </section>
            <?= modal_footer('Desactivar', 'is-danger') ?>
        </form>
    </div>
</div>
