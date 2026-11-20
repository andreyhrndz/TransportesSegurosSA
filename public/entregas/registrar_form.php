<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/document_storage.php';
require_permission('ENTREGAS_REGISTRAR');

$assignment = filter_var($_GET['viaje_solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignment === false) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

$maxBytes = document_effective_max_bytes();
$maxLabel = document_format_bytes($maxBytes);
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-entrega">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-entrega', 'Registrar entrega') ?>
        <form
            hx-post="/entregas/registrar.php"
            hx-encoding="multipart/form-data"
            enctype="multipart/form-data"
            hx-target="#mensaje-entrega"
            hx-swap="innerHTML"
            hx-disabled-elt="find button[type=submit]"
            data-doc-upload-form
            data-max-bytes="<?= $maxBytes ?>"
            data-max-label="<?= e($maxLabel) ?>"
        >
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_solicitud_id" value="<?= (int)$assignment ?>">
                <div id="mensaje-entrega" role="alert" data-doc-feedback></div>

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

                    <fieldset class="field doc-mode-fieldset">
                        <legend class="label">Comprobante de entrega <span class="required">*</span></legend>
                        <div class="doc-mode-switch" role="radiogroup" aria-label="Tipo de comprobante de entrega">
                            <label class="doc-mode-option is-active" data-doc-mode-label="archivo">
                                <input type="radio" name="modo_comprobante" value="archivo" data-doc-mode-radio checked>
                                <?= svg_icon('upload') ?>
                                <span>Subir archivo local</span>
                            </label>
                            <label class="doc-mode-option" data-doc-mode-label="url">
                                <input type="radio" name="modo_comprobante" value="url" data-doc-mode-radio>
                                <?= svg_icon('link') ?>
                                <span>Vincular URL externa</span>
                            </label>
                        </div>
                    </fieldset>

                    <div id="entrega-panel-archivo" class="field" data-doc-panel="archivo">
                        <label class="label" for="comprobante-archivo">Archivo del comprobante <span class="required">*</span></label>
                        <div class="doc-dropzone" data-doc-dropzone tabindex="0" role="group" aria-describedby="comprobante-archivo-ayuda comprobante-archivo-estado">
                            <input
                                class="doc-file-input"
                                id="comprobante-archivo"
                                name="comprobante_archivo"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                data-doc-file-input
                                required
                            >
                            <div class="doc-dropzone-icon" aria-hidden="true"><?= svg_icon('upload') ?></div>
                            <div class="doc-dropzone-copy">
                                <strong>Arrastra un PDF, JPG o PNG aquí</strong>
                                <span id="comprobante-archivo-ayuda">o usa el botón para elegir desde tu equipo · Máximo <?= e($maxLabel) ?> · Un archivo por entrega.</span>
                            </div>
                            <button class="button is-small button-secondary" type="button" data-doc-browse>
                                <?= svg_icon('paperclip') ?>
                                <span>Seleccionar archivo</span>
                            </button>
                        </div>

                        <div class="doc-upload-status" id="comprobante-archivo-estado" role="status" aria-live="polite" data-doc-status>
                            Sin archivo seleccionado
                        </div>

                        <div class="doc-file-card" data-doc-preview hidden>
                            <div class="doc-file-card-info">
                                <?= svg_icon('file-text') ?>
                                <div class="cell-stack">
                                    <strong data-doc-preview-name></strong>
                                    <span data-doc-preview-meta></span>
                                </div>
                            </div>
                            <button class="button is-small button-secondary" type="button" data-doc-clear>
                                <?= svg_icon('close') ?>
                                <span>Quitar</span>
                            </button>
                        </div>
                    </div>

                    <div id="entrega-panel-url" class="field" data-doc-panel="url" hidden>
                        <label class="label" for="comprobante-url">URL del comprobante <span class="required">*</span></label>
                        <input class="input" id="comprobante-url" name="comprobante_url" type="url" maxlength="500" placeholder="https://…" data-doc-url-input disabled>
                        <p class="help">Solo enlaces HTTP o HTTPS sin credenciales. El servidor guarda el enlace sin descargar contenido remoto.</p>
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
