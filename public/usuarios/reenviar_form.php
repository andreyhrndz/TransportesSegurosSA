<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

require_permission('USUARIOS_GESTIONAR_CUENTAS');
header('Cache-Control: no-store');

$invitacionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invitacionId === false) {
    http_response_code(404);
    exit(ui_notice('La invitación solicitada no existe.', 'danger'));
}

try {
    $stmt = db()->prepare(
        'SELECT i.invitacion_id, i.correo_destino, i.estado, i.fecha_expiracion,
                (SELECT COUNT(*) FROM dbo.invitaciones_permisos ip JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id WHERE ip.invitacion_id = i.invitacion_id AND p.activo = 1) AS total_permisos
         FROM dbo.invitaciones_usuario AS i
         WHERE i.invitacion_id = ?;'
    );
    $stmt->execute([$invitacionId]);
    $inv = $stmt->fetch();
    $stmt->closeCursor();
} catch (PDOException) {
    http_response_code(500);
    exit(ui_notice('No se pudo cargar la invitación solicitada.', 'danger'));
}

if (!is_array($inv)) {
    http_response_code(404);
    exit(ui_notice('La invitación solicitada no existe.', 'danger'));
}

$estadoRaw = strtoupper(trim((string)$inv['estado']));
if ($estadoRaw === 'COMPLETADA') {
    http_response_code(409);
    exit(ui_notice('Una invitación completada no puede reenviarse. Administra la cuenta en la pestaña Cuentas de usuario.', 'warning'));
}

$correoActual = (string)$inv['correo_destino'];
$totalPerms = (int)($inv['total_permisos'] ?? 0);
$canManagePerms = has_permission('USUARIOS_GESTIONAR_PERMISOS');
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-reenviar-invitacion">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-reenviar-invitacion', 'Reenviar invitación') ?>
        <form id="form-reenviar-invitacion" hx-post="/usuarios/reenviar_invitacion.php" hx-target="#mensaje-reenviar-invitacion" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="invitacion_id" value="<?= (int)$inv['invitacion_id'] ?>">
                <input type="hidden" name="origen" value="modal">
                <div id="mensaje-reenviar-invitacion" role="alert" aria-live="assertive"></div>

                <div class="pma-help-callout mb-4" role="note">
                    <?= svg_icon('shield') ?>
                    <span>Puedes confirmar el mismo correo o corregirlo si tuvo un error. El enlace anterior quedará invalidado de inmediato y se emitirá un nuevo enlace válido por 24 horas.</span>
                </div>

                <div class="field">
                    <label class="label" for="reenviar-correo">Correo electrónico del destinatario <span class="required">*</span></label>
                    <input class="input" id="reenviar-correo" name="nuevo_correo" type="email" maxlength="150" value="<?= e($correoActual) ?>" autocomplete="email" aria-describedby="reenviar-correo-help" required autofocus>
                    <p class="help" id="reenviar-correo-help">
                        <?php if ($totalPerms > 0 && $canManagePerms): ?>
                            Se conservarán hasta <?= $totalPerms ?> <?= $totalPerms === 1 ? 'permiso planificado autorizado' : 'permisos planificados autorizados' ?> que tu cuenta pueda conceder.
                        <?php elseif ($totalPerms > 0): ?>
                            El enlace se emitirá sin permisos adicionales porque tu cuenta no administra asignación de permisos.
                        <?php else: ?>
                            El registro histórico original se conservará intacto en auditoría.
                        <?php endif; ?>
                    </p>
                </div>
            </section>
            <?= modal_footer('Enviar nuevo enlace', 'is-primary') ?>
        </form>
    </div>
</div>
