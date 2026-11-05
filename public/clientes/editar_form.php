<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

require_permission('CLIENTES_VER');
require_permission('CLIENTES_EDITAR');
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
    read_error_response('No se pudo cargar el formulario de edición.');
}

$isPhysical = $customer['tipo_cliente'] === 'FISICA';
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-cliente">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card modal-card-wide">
        <?= modal_header('titulo-editar-cliente', $isPhysical ? 'Editar cliente físico' : 'Editar cliente jurídico') ?>
        <form hx-post="/clientes/actualizar.php" hx-target="#mensaje-editar-cliente" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="cliente_id" value="<?= (int)$customer['cliente_id'] ?>">
                <input type="hidden" name="subtipo" value="<?= e($customer['tipo_cliente']) ?>">
                <div id="mensaje-editar-cliente" role="alert"></div>

                <div class="readonly-summary" aria-label="Campos inmutables del cliente">
                    <div>
                        <span>Identificación (inmutable)</span>
                        <strong id="edit-identificacion-val"><?= e($customer['identificacion']) ?></strong>
                    </div>
                    <div>
                        <span>Subtipo (inmutable)</span>
                        <strong><?= e($isPhysical ? 'Persona física' : 'Persona jurídica') ?></strong>
                    </div>
                    <div style="display: flex; align-items: flex-end;">
                        <button class="button button-secondary is-small" type="button" id="btn-consultar-hacienda-edit">
                            <?= svg_icon('search') ?>
                            <span>Consultar Hacienda</span>
                        </button>
                    </div>
                </div>
                <div id="hacienda-edit-aviso" class="mb-3" hidden></div>

                <?php if ($isPhysical): ?>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="edit-nombre">Nombre <span class="required">*</span></label>
                                <input class="input" id="edit-nombre" name="nombre" maxlength="100" value="<?= e($customer['nombre']) ?>" required autofocus>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="edit-primer-apellido">Primer apellido <span class="required">*</span></label>
                                <input class="input" id="edit-primer-apellido" name="primer_apellido" maxlength="100" value="<?= e($customer['primer_apellido']) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="edit-segundo-apellido">Segundo apellido</label>
                        <input class="input" id="edit-segundo-apellido" name="segundo_apellido" maxlength="100" value="<?= e($customer['segundo_apellido'] ?? '') ?>">
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label class="label" for="edit-razon-social">Razón social <span class="required">*</span></label>
                        <input class="input" id="edit-razon-social" name="razon_social" maxlength="150" value="<?= e($customer['razon_social']) ?>" required autofocus>
                    </div>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="edit-nombre-comercial">Nombre comercial</label>
                                <input class="input" id="edit-nombre-comercial" name="nombre_comercial" maxlength="150" value="<?= e($customer['nombre_comercial'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="edit-representante">Representante legal</label>
                                <input class="input" id="edit-representante" name="representante_legal" maxlength="150" value="<?= e($customer['representante_legal'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="edit-correo">Correo electrónico <span class="required">*</span></label>
                            <input class="input" id="edit-correo" name="correo" type="email" maxlength="150" value="<?= e($customer['correo']) ?>" required>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="edit-telefono">Teléfono</label>
                            <input class="input" id="edit-telefono" name="telefono" type="tel" maxlength="30" value="<?= e($customer['telefono'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="edit-direccion">Dirección</label>
                    <input class="input" id="edit-direccion" name="direccion" maxlength="250" value="<?= e($customer['direccion'] ?? '') ?>">
                </div>

                <p class="help">Los campos marcados con * son obligatorios.</p>
            </section>
            <?= modal_footer('Guardar', 'is-primary') ?>
        </form>
    </div>
</div>
