<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('ENTREGAS_REGISTRAR');

$assignment = filter_var($_GET['viaje_solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignment === false) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-entrega">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-entrega', 'Registrar entrega') ?>
        <form hx-post="/entregas/registrar.php" hx-target="#mensaje-entrega" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_solicitud_id" value="<?= (int)$assignment ?>">
                <div id="mensaje-entrega" role="alert"></div>

                <div class="field">
                    <label class="label" for="entrega-estado">Resultado de la operación <span class="required">*</span></label>
                    <div class="select is-fullwidth">
                        <select id="entrega-estado" name="estado" required data-entrega-estado>
                            <option value="ENTREGADA">Entregada</option>
                            <option value="FALLIDA">Fallida</option>
                        </select>
                    </div>
                </div>

                <div data-entrega-exito>
                    <div class="field">
                        <label class="label" for="receptor-nombre">Receptor efectivo <span class="required">*</span></label>
                        <input class="input" id="receptor-nombre" name="receptor_nombre" maxlength="150" placeholder="Nombre de quien recibe la carga" required>
                    </div>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="receptor-id">Identificación</label>
                                <input class="input" id="receptor-id" name="receptor_identificacion" maxlength="30">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="receptor-telefono">Teléfono</label>
                                <input class="input" id="receptor-telefono" name="receptor_telefono" maxlength="30">
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="comprobante-url">URL del comprobante <span class="required">*</span></label>
                        <input class="input" id="comprobante-url" name="comprobante_url" type="url" maxlength="500" placeholder="https://…" required>
                    </div>
                </div>

                <div class="field" data-entrega-fallo hidden>
                    <label class="label" for="motivo-fallo">Motivo del fallo <span class="required">*</span></label>
                    <textarea class="textarea" id="motivo-fallo" name="motivo_fallo" maxlength="500" placeholder="Detalla la causa por la que no pudo concretarse la entrega"></textarea>
                </div>
            </section>
            <?= modal_footer('Registrar', 'is-primary') ?>
        </form>
    </div>
</div>
