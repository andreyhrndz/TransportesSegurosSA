<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string)($_GET['accion'] ?? '');
if ($id === false || !in_array($action, ['prioridad', 'cancelar'], true)) {
    http_response_code(404);
    exit('Acción no disponible.');
}
require_permission($action === 'prioridad' ? 'SOLICITUDES_AJUSTAR_PRIORIDAD' : 'SOLICITUDES_CANCELAR');
try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_solicitud @solicitud_id=?;', [(int)$id]);
    $solicitud = $sets[0][0] ?? null;
} catch (PDOException) {
    read_error_response();
}
if (!is_array($solicitud) || !in_array($solicitud['estado'], ['REGISTRADA', 'PENDIENTE_ASIGNACION'], true)) {
    http_response_code(409);
    exit(ui_notice('La solicitud ya no admite esta acción.', 'warning'));
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-accion">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-accion', $action === 'prioridad' ? 'Ajustar prioridad' : 'Cancelar solicitud') ?>
        <form hx-post="/solicitudes/accion.php" hx-target="#mensaje-accion" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="solicitud_id" value="<?= (int)$id ?>">
                <input type="hidden" name="accion" value="<?= e($action) ?>">
                <div id="mensaje-accion" role="alert"></div>

                <div class="detail-grid mb-4">
                    <div>
                        <span>Solicitud</span>
                        <strong><?= e($solicitud['codigo_solicitud']) ?></strong>
                    </div>
                    <div>
                        <span>Estado actual</span>
                        <div><?= status_tag($solicitud['estado']) ?></div>
                    </div>
                </div>

                <?php if ($action === 'prioridad'): ?>
                    <div class="field">
                        <label class="label" for="prioridad">Nueva prioridad</label>
                        <div class="select is-fullwidth">
                            <select id="prioridad" name="prioridad" required>
                                <option value="NORMAL">Normal</option>
                                <option value="ALTA">Alta</option>
                                <option value="URGENTE">Urgente</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="justificacion">Justificación</label>
                        <textarea class="textarea" id="justificacion" name="justificacion" maxlength="500" placeholder="Describe el motivo operativo del ajuste" required></textarea>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label class="label" for="motivo">Motivo de cancelación</label>
                        <textarea class="textarea" id="motivo" name="motivo" maxlength="500" placeholder="Indica el motivo por el cual se cancela la orden" required></textarea>
                    </div>
                    <p class="help">Esta acción no se puede revertir desde la aplicación.</p>
                <?php endif; ?>
            </section>
            <?= modal_footer($action === 'cancelar' ? 'Confirmar cancelación' : 'Guardar', $action === 'cancelar' ? 'is-danger' : 'is-primary') ?>
        </form>
    </div>
</div>
