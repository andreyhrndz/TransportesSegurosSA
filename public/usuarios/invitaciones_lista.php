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
                <th>Entrega</th>
                <th>Permisos planificados</th>
                <th>Invitado por</th>
                <th>Fecha de emisión</th>
                <th>Vigencia</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invitaciones)): ?>
                <tr>
                    <td colspan="8"><?= empty_state('Sin invitaciones', 'No se han emitido invitaciones en el sistema.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($invitaciones as $inv): ?>
                <?php
                $invId = (int)$inv['invitacion_id'];
                $estado = (string)$inv['estado'];
                $estadoEnvio = (string)($inv['estado_envio'] ?? 'PENDIENTE');
                $isPendiente = ($estado === 'PENDIENTE');
                $isCompletada = ($estado === 'COMPLETADA');
                $enviosContador = (int)($inv['envios_contador'] ?? 1);
                $totalPerms = (int)($inv['total_permisos'] ?? 0);
                $permisosCodigos = (string)($inv['permisos_codigos'] ?? '');
                ?>
                <tr>
                    <td><strong><?= e($inv['correo_destino']) ?></strong></td>
                    <td><?= status_tag($estado) ?></td>
                    <td>
                        <?= status_tag($estadoEnvio) ?>
                        <?php if ($enviosContador > 1): ?>
                            <span class="tag is-dark is-small" title="Número total de envíos emitidos"><?= $enviosContador ?> envíos</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($totalPerms > 0): ?>
                            <span class="tag is-info is-light" title="<?= e($permisosCodigos) ?>">
                                <?= $totalPerms ?> <?= $totalPerms === 1 ? 'permiso' : 'permisos' ?>
                            </span>
                        <?php else: ?>
                            <span class="has-text-grey is-size-7">0 permisos</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($inv['creado_por_nombre'] ?? 'Sistema') ?></td>
                    <td><?= e(format_utc_datetime($inv['fecha_creacion'])) ?></td>
                    <td>
                        <?php if ($isPendiente): ?>
                            <span class="has-text-grey-light" title="Vence a las 24 horas de su emisión"><?= e(format_utc_datetime($inv['fecha_expiracion'])) ?></span>
                        <?php elseif ($isCompletada): ?>
                            <span class="has-text-success">Completada<?= !empty($inv['completado_por_nombre']) ? ' (' . e($inv['completado_por_nombre']) . ')' : '' ?></span>
                        <?php else: ?>
                            <span class="has-text-grey"><?= e(humanize_status($estado)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="has-text-right">
                        <?php if ($canManageAccounts): ?>
                            <div class="row-actions is-justify-content-flex-end">
                                <?php if ($isPendiente): ?>
                                    <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-confirm="¿Reenviar invitación a <?= e($inv['correo_destino']) ?>? Esto invalidará el enlace anterior y generará uno nuevo con 24 horas de vigencia." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas">
                                            <?= svg_icon('refresh') ?>
                                            <span>Reenviar</span>
                                        </button>
                                    </form>
                                    <form hx-post="/usuarios/revocar_invitacion.php" hx-target="#invitaciones-resultado" hx-confirm="¿Revocar la invitación a <?= e($inv['correo_destino']) ?>? El enlace dejará de ser válido inmediatamente." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary is-danger" type="submit" title="Invalida el enlace de activación inmediatamente">
                                            <?= svg_icon('close') ?>
                                            <span>Revocar</span>
                                        </button>
                                    </form>
                                <?php elseif (!$isCompletada): ?>
                                    <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-confirm="¿Emitir una nueva invitación para <?= e($inv['correo_destino']) ?> con nuevo token y 24 horas de vigencia?" class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas">
                                            <?= svg_icon('refresh') ?>
                                            <span>Reenviar</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="has-text-grey is-size-7">—</span>
                                <?php endif; ?>
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
