<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('CLIENTES_VER');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Cliente no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_cliente @cliente_id=?;', [$id]);
    $customer = $sets[0][0] ?? null;
    if (!is_array($customer)) {
        http_response_code(404);
        exit('Cliente no encontrado.');
    }
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Cliente no encontrado.');
    }
    read_error_response('No se pudo consultar el cliente.');
}

$isPhysical = $customer['tipo_cliente'] === 'FISICA';
$isActive = (int)$customer['activo'] === 1;
$canEdit = has_permission('CLIENTES_EDITAR');
$canDeactivate = has_permission('CLIENTES_DESACTIVAR');
$displayName = $isPhysical
    ? trim((string)$customer['nombre'] . ' ' . (string)$customer['primer_apellido'] . ' ' . (string)($customer['segundo_apellido'] ?? ''))
    : (string)($customer['nombre_comercial'] ?: $customer['razon_social']);

function render_customer_detail_card(array $customer, int $id, bool $isPhysical, bool $isActive, bool $canEdit, bool $canDeactivate, string $displayName): void
{
?>
<div class="panel-box-header">
    <div>
        <h2 class="title is-5 mb-1"><?= e($displayName) ?></h2>
        <p class="panel-box-subtitle"><?= e($isPhysical ? 'Persona física' : 'Persona jurídica') ?></p>
    </div>
    <div class="buttons">
        <?php if ($canEdit): ?>
            <button class="button is-primary" type="button" hx-get="/clientes/editar_form.php?id=<?= $id ?>" hx-target="#modal-contenido"><?= svg_icon('edit') ?><span>Editar</span></button>
        <?php endif; ?>
        <?php if ($canDeactivate && $isActive): ?>
            <button class="button is-danger" type="button" hx-get="/clientes/desactivar_form.php?id=<?= $id ?>" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
        <?php endif; ?>
        <a class="button button-secondary" href="/clientes.php"><?= svg_icon('arrow-left') ?><span>Volver</span></a>
    </div>
</div>
<dl class="detail-grid">
    <div class="detail-field"><dt>Identificación (inmutable)</dt><dd><strong><?= e($customer['identificacion']) ?></strong></dd></div>
    <div class="detail-field"><dt>Estado</dt><dd><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></dd></div>
    <div class="detail-field"><dt>Fecha de registro</dt><dd><strong><?= e(format_utc_datetime($customer['fecha_registro'])) ?></strong></dd></div>
    <div class="detail-field"><dt>Correo electrónico</dt><dd><strong><?= e($customer['correo']) ?></strong></dd></div>
    <div class="detail-field"><dt>Teléfono</dt><dd><strong><?= e($customer['telefono'] ?: 'No registrado') ?></strong></dd></div>
    <div class="detail-field"><dt>Dirección</dt><dd><strong><?= e($customer['direccion'] ?: 'No registrada') ?></strong></dd></div>
    <?php if ($isPhysical): ?>
        <div class="detail-field"><dt>Nombre</dt><dd><strong><?= e($customer['nombre']) ?></strong></dd></div>
        <div class="detail-field"><dt>Primer apellido</dt><dd><strong><?= e($customer['primer_apellido']) ?></strong></dd></div>
        <div class="detail-field"><dt>Segundo apellido</dt><dd><strong><?= e($customer['segundo_apellido'] ?: 'No registrado') ?></strong></dd></div>
    <?php else: ?>
        <div class="detail-field"><dt>Razón social</dt><dd><strong><?= e($customer['razon_social']) ?></strong></dd></div>
        <div class="detail-field"><dt>Nombre comercial</dt><dd><strong><?= e($customer['nombre_comercial'] ?: 'No registrado') ?></strong></dd></div>
        <div class="detail-field"><dt>Representante legal</dt><dd><strong><?= e($customer['representante_legal'] ?: 'No registrado') ?></strong></dd></div>
    <?php endif; ?>
</dl>
<p class="help mt-5">La identificación y el subtipo (físico o jurídico) son inmutables. Los clientes no se eliminan físicamente para conservar la integridad de sus solicitudes e historial.</p>
<?php
}

if (($_GET['parcial'] ?? '') === '1' && is_htmx_request()) {
    header('Cache-Control: no-store');
    render_customer_detail_card($customer, (int)$id, $isPhysical, $isActive, $canEdit, $canDeactivate, $displayName);
    exit;
}

require_once __DIR__ . '/../../app/layout.php';
layout_start('clientes', 'Detalle de cliente', 'Ficha administrativa con edición autorizada y desactivación preservando el historial.');
?>
<section class="panel-box" id="cliente-detalle-panel" hx-get="/clientes/detalle.php?id=<?= (int)$id ?>&parcial=1" hx-trigger="clienteActualizado from:body" hx-swap="innerHTML">
    <?php render_customer_detail_card($customer, (int)$id, $isPhysical, $isActive, $canEdit, $canDeactivate, $displayName); ?>
</section>
<?php layout_end(); ?>
