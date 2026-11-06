<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

require_permission('CLIENTES_VER');
require_permission('CLIENTES_DESACTIVAR');
header('Cache-Control: no-store');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit(ui_notice('Cliente no encontrado.', 'danger'));
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_cliente @cliente_id=?;', [$id]);
    $customer = $sets[0][0] ?? null;
    if (!is_array($customer)) {
        http_response_code(404);
        exit(ui_notice('Cliente no encontrado.', 'danger'));
    }
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit(ui_notice('Cliente no encontrado.', 'danger'));
    }
    read_error_response('No se pudo cargar la confirmación.');
}

if ((int)$customer['activo'] !== 1) {
    http_response_code(409);
    exit(ui_notice('El cliente ya se encuentra inactivo.', 'warning'));
}

$isPhysical = $customer['tipo_cliente'] === 'FISICA';
$displayName = $isPhysical
    ? trim((string)$customer['nombre'] . ' ' . (string)$customer['primer_apellido'] . ' ' . (string)($customer['segundo_apellido'] ?? ''))
    : (string)($customer['nombre_comercial'] ?: $customer['razon_social']);
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-desactivar-cliente">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-desactivar-cliente', 'Desactivar cliente') ?>
        <form hx-post="/clientes/desactivar.php" hx-target="#mensaje-desactivar-cliente" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="cliente_id" value="<?= (int)$customer['cliente_id'] ?>">
                <div id="mensaje-desactivar-cliente" role="alert"></div>

                <div class="readonly-summary">
                    <div>
                        <span>Cliente</span>
                        <strong><?= e($displayName) ?></strong>
                    </div>
                    <div>
                        <span>Identificación</span>
                        <strong><?= e($customer['identificacion']) ?></strong>
                    </div>
                </div>

                <p>¿Confirmas la desactivación de este cliente?</p>
                <p class="help mt-2">No se eliminará ningún registro histórico. Si el cliente tiene solicitudes operativas no terminales, la operación será rechazada automáticamente.</p>
            </section>
            <?= modal_footer('Desactivar', 'is-danger') ?>
        </form>
    </div>
</div>
