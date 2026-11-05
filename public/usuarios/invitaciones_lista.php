<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

$currentUser = require_login();
require_permission('USUARIOS_VER');
header('Cache-Control: no-store');

$canManageAccounts = has_permission('USUARIOS_GESTIONAR_CUENTAS');

try {
    $stmt = db()->prepare('EXEC dbo.sp_listar_invitaciones @estado=NULL;');
    $stmt->execute();
    $invitaciones = $stmt->fetchAll();
    $stmt->closeCursor();
} catch (PDOException) {
    http_response_code(500);
    echo ui_notice('No se pudo cargar la lista de invitaciones.', 'danger');
    exit;
}
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable" aria-label="Invitaciones de usuario">
        <thead>
            <tr>
                <th>Correo electrónico</th>
                <th>Estado</th>
                <th>Invitado por</th>
                <th>Fecha de envío</th>
                <th>Vigencia</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invitaciones)): ?>
                <tr>
                    <td colspan="6"><?= empty_state('Sin invitaciones', 'No se han emitido invitaciones en el sistema.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($invitaciones as $inv): ?>
                <?php
                $invId = (int)$inv['invitacion_id'];
                $estado = (string)$inv['estado'];
                $isPendiente = ($estado === 'PENDIENTE');
                ?>
                <tr>
                    <td><strong><?= e($inv['correo_destino']) ?></strong></td>
                    <td><?= status_tag($estado) ?></td>
                    <td><?= e($inv['creado_por_nombre'] ?? 'Sistema') ?></td>
                    <td><?= e(format_utc_datetime($inv['fecha_creacion'])) ?></td>
                    <td>
                        <?php if ($isPendiente): ?>
                            <span class="has-text-grey-light"><?= e(format_utc_datetime($inv['fecha_expiracion'])) ?></span>
                        <?php elseif ($estado === 'COMPLETADA'): ?>
                            <span class="has-text-success">Completado por <?= e($inv['completado_por_nombre'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="has-text-grey"><?= e(status_tag_label($estado)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="has-text-right">
                        <?php if ($canManageAccounts && $isPendiente): ?>
                            <div class="row-actions is-justify-content-flex-end">
                                <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-confirm="¿Reenviar invitación a <?= e($inv['correo_destino']) ?>?" class="is-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                    <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas">
                                        <?= svg_icon('refresh') ?>
                                        <span>Reenviar</span>
                                    </button>
                                </form>
                                <form hx-post="/usuarios/cancelar_invitacion.php" hx-target="#invitaciones-resultado" hx-confirm="¿Cancelar la invitación a <?= e($inv['correo_destino']) ?>?" class="is-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                    <button class="button is-small button-secondary is-danger" type="submit" title="Invalida el enlace de activación">
                                        <?= svg_icon('close') ?>
                                        <span>Cancelar</span>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="has-text-grey is-size-7">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
