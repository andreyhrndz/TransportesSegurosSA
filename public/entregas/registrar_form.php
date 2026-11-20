<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('ENTREGAS_REGISTRAR');

$assignment = filter_var($_GET['viaje_solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignment === false) {
    http_response_code(404);
    exit('Asignación no encontrada.');
}

$ctx = null;
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_contexto_entrega @viaje_solicitud_id=?;', [(int)$assignment]);
    $ctx = $sets[0][0] ?? null;
} catch (PDOException) {
    $ctx = null;
}

$prefillNombre = trim((string)($ctx['destinatario_nombre'] ?? ''));
$prefillId = trim((string)($ctx['destinatario_identificacion'] ?? ''));
$prefillTel = trim((string)($ctx['destinatario_telefono'] ?? ''));
$rawReturnTo = trim((string)($_GET['return_to'] ?? ''));
$returnTo = preg_match('#^(/viajes/detalle\.php\?id=[1-9]\d*|/entregas\.php\?seccion=(pendientes|entregas)|/solicitudes/detalle\.php\?id=[1-9]\d*)$#', $rawReturnTo) === 1
    ? $rawReturnTo
    : '';
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-entrega">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-entrega', 'Registrar entrega') ?>
        <form hx-post="/entregas/registrar.php" hx-encoding="multipart/form-data" enctype="multipart/form-data" hx-target="#mensaje-entrega" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="viaje_solicitud_id" value="<?= (int)$assignment ?>">
                <?php if ($returnTo !== ''): ?>
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                <?php endif; ?>
                <div id="mensaje-entrega" role="alert"></div>

                <?php if (is_array($ctx)): ?>
                    <div class="detail-grid mb-4">
                        <div>
                            <span>Solicitud</span>
                            <strong><?= e($ctx['codigo_solicitud'] ?? '') ?></strong>
                        </div>
                        <div>
                            <span>Viaje</span>
                            <strong><?= e($ctx['codigo_viaje'] ?? '') ?></strong>
                        </div>
                        <div>
                            <span>Destino</span>
                            <strong><?= e($ctx['destino_nombre'] ?? '') ?></strong>
                        </div>
                    </div>
                <?php endif; ?>

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
                        <input class="input" id="receptor-nombre" name="receptor_nombre" maxlength="150" value="<?= e($prefillNombre) ?>" placeholder="Nombre de quien recibe la carga" required>
                        <?php if ($prefillNombre !== ''): ?>
                            <p class="help">Prellenado con el destinatario autorizado de la solicitud (puedes editarlo si recibió otra persona).</p>
                        <?php endif; ?>
                    </div>
                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="receptor-id">Identificación</label>
                                <input class="input" id="receptor-id" name="receptor_identificacion" maxlength="30" value="<?= e($prefillId) ?>">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="receptor-telefono">Teléfono</label>
                                <input class="input" id="receptor-telefono" name="receptor_telefono" maxlength="30" value="<?= e($prefillTel) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="comprobante-archivo">Comprobante de entrega (foto o PDF — opcional)</label>
                        <input class="input" id="comprobante-archivo" name="comprobante_archivo" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        <p class="help">Formatos permitidos: JPG, PNG o PDF (máx. 5 MB). Si adjuntas un archivo, se guardará automáticamente también en <strong>Documentos</strong>.</p>
                    </div>
                    <details class="advanced-coords-panel mt-2">
                        <summary>Opciones avanzadas: vincular comprobante por URL externa (opcional)</summary>
                        <div class="advanced-coords-body">
                            <div class="field mb-0">
                                <label class="label" for="comprobante-url">URL del comprobante (opcional)</label>
                                <input class="input" id="comprobante-url" name="comprobante_url" type="url" maxlength="500" placeholder="https://…">
                                <p class="help">Úsalo solo si el respaldo ya está alojado en un sistema externo. Si adjuntas archivo arriba, tiene prioridad el archivo.</p>
                            </div>
                        </div>
                    </details>
                </div>

                <div class="field" data-entrega-fallo hidden>
                    <label class="label" for="motivo-fallo">Motivo del fallo <span class="required">*</span></label>
                    <textarea class="textarea" id="motivo-fallo" name="motivo_fallo" maxlength="500" placeholder="Detalla la causa por la que no pudo concretarse la entrega"></textarea>
                </div>
            </section>
            <?= modal_footer('Registrar entrega', 'is-primary') ?>
        </form>
    </div>
</div>
