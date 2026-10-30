<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('CLIENTES_VER');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) { http_response_code(404); exit('Cliente no encontrado.'); }
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_cliente @cliente_id=?;', [$id]);
    $customer = $sets[0][0] ?? null;
    if (!is_array($customer)) { http_response_code(404); exit('Cliente no encontrado.'); }
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) { http_response_code(404); exit('Cliente no encontrado.'); }
    read_error_response('No se pudo consultar el cliente.');
}
require_once __DIR__ . '/../../app/layout.php';
$isPhysical = $customer['tipo_cliente'] === 'FISICA';
layout_start('clientes', 'Detalle de cliente', 'Identificación y subtipo son informativos e inmutables hasta aprobar el contrato de ciclo de vida.');
?>
<section class="panel-white"><div class="panel-heading-custom"><div><h2 class="title is-5"><?= e($isPhysical ? trim($customer['nombre'] . ' ' . $customer['primer_apellido'] . ' ' . ($customer['segundo_apellido'] ?? '')) : ($customer['nombre_comercial'] ?: $customer['razon_social'])) ?></h2><p><?= e($isPhysical ? 'Cliente físico' : 'Cliente jurídico') ?></p></div><a class="button button-secondary" href="/clientes.php">Volver</a></div><div class="detail-grid"><div><span>Identificación</span><strong><?= e($customer['identificacion']) ?></strong></div><div><span>Estado</span><strong><?= status_tag((int)$customer['activo'] === 1 ? 'ACTIVO' : 'INACTIVO') ?></strong></div><div><span>Registro</span><strong><?= e(format_utc_datetime($customer['fecha_registro'])) ?></strong></div><div><span>Correo</span><strong><?= e($customer['correo']) ?></strong></div><div><span>Teléfono</span><strong><?= e($customer['telefono'] ?: 'No registrado') ?></strong></div><div><span>Dirección</span><strong><?= e($customer['direccion'] ?: 'No registrada') ?></strong></div><?php if ($isPhysical): ?><div><span>Nombre</span><strong><?= e($customer['nombre']) ?></strong></div><div><span>Primer apellido</span><strong><?= e($customer['primer_apellido']) ?></strong></div><div><span>Segundo apellido</span><strong><?= e($customer['segundo_apellido'] ?: 'No registrado') ?></strong></div><?php else: ?><div><span>Razón social</span><strong><?= e($customer['razon_social']) ?></strong></div><div><span>Nombre comercial</span><strong><?= e($customer['nombre_comercial'] ?: 'No registrado') ?></strong></div><div><span>Representante legal</span><strong><?= e($customer['representante_legal'] ?: 'No registrado') ?></strong></div><?php endif; ?></div><p class="help mt-5">Editar y desactivar permanecen indisponibles: requieren contratos SQL aprobados con `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR`.</p></section>
<?php layout_end(); ?>
