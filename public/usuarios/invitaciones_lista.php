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

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable" aria-label="Invitaciones de usuario">
        <thead>
            <tr>
                <th>Correo electrónico</th>
                <th>Estado y entrega</th>
                <th>Permisos planificados</th>
                <th>Vigencia</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invitaciones)): ?>
                <tr>
                    <td colspan="5"><?= empty_state('Sin invitaciones', 'No se han emitido invitaciones en el sistema.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($invitaciones as $inv): ?>
                <?php
                $invId = (int)$inv['invitacion_id'];
                $estadoRaw = strtoupper(trim((string)$inv['estado']));
                $expRaw = (string)($inv['fecha_expiracion'] ?? '');
                $isExpiredByTime = false;
                if ($expRaw !== '') {
                    try {
                        $expDt = new DateTimeImmutable($expRaw, new DateTimeZone('UTC'));
                        $isExpiredByTime = ($expDt <= $nowUtc);
                    } catch (Exception) {
                        $isExpiredByTime = false;
                    }
                }
                // Estado efectivo: una invitación vencida nunca se muestra como PENDIENTE utilizable.
                $estado = ($estadoRaw === 'PENDIENTE' && $isExpiredByTime) ? 'EXPIRADA' : $estadoRaw;
                $estadoEnvio = (string)($inv['estado_envio'] ?? 'PENDIENTE');
                $isPendiente = ($estado === 'PENDIENTE');
                $isExpirada = ($estado === 'EXPIRADA');
                $isCompletada = ($estado === 'COMPLETADA');
                $enviosContador = (int)($inv['envios_contador'] ?? 1);
                $totalPerms = (int)($inv['total_permisos'] ?? 0);
                $permisosCodigos = (string)($inv['permisos_codigos'] ?? '');
                ?>
                <tr data-invitacion-id="<?= $invId ?>" data-invitacion-estado="<?= e($estado) ?>">
                    <td>
                        <div class="cell-stack">
                            <strong><?= e($inv['correo_destino']) ?></strong>
                            <span>Por <?= e($inv['creado_por_nombre'] ?? 'Sistema') ?> · <?= e(format_utc_datetime($inv['fecha_creacion'])) ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="cell-stack">
                            <div><?= status_tag($estado) ?></div>
                            <div><?= status_tag($estadoEnvio) ?></div>
                            <?php if ($enviosContador > 1): ?>
                                <span title="Número total de envíos emitidos"><?= $enviosContador ?> envíos</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($totalPerms > 0): ?>
                            <span class="status-tag status-tag-info" title="<?= e($permisosCodigos) ?>">
                                <?= $totalPerms ?> <?= $totalPerms === 1 ? 'permiso' : 'permisos' ?>
                            </span>
                        <?php else: ?>
                            <span class="has-text-grey is-size-7">0 permisos</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isPendiente): ?>
                            <span class="has-text-grey-light" title="Vence a las 24 horas de su emisión">Vence: <?= e(format_utc_datetime($inv['fecha_expiracion'])) ?></span>
                        <?php elseif ($isExpirada): ?>
                            <span class="has-text-grey" title="El enlace ya venció y no permite activar cuenta">Venció: <?= e(format_utc_datetime($inv['fecha_expiracion'])) ?></span>
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
                                    <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Reenviar invitación a <?= e($inv['correo_destino']) ?>? Esto invalidará el enlace anterior y generará uno nuevo con 24 horas de vigencia." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas">
                                            <?= svg_icon('refresh') ?>
                                            <span>Reenviar</span>
                                        </button>
                                    </form>
                                    <form hx-post="/usuarios/revocar_invitacion.php" hx-target="#invitaciones-resultado" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Revocar la invitación vigente a <?= e($inv['correo_destino']) ?>? El enlace dejará de ser válido inmediatamente." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary is-danger" type="submit" title="Invalida el enlace de activación inmediatamente">
                                            <?= svg_icon('close') ?>
                                            <span>Revocar</span>
                                        </button>
                                    </form>
                                <?php elseif ($isExpirada): ?>
                                    <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Emitir una nueva invitación para <?= e($inv['correo_destino']) ?> con nuevo enlace y 24 horas de vigencia?" class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas conservando permisos planificados">
                                            <?= svg_icon('refresh') ?>
                                            <span>Reenviar</span>
                                        </button>
                                    </form>
                                    <form hx-post="/usuarios/revocar_invitacion.php" hx-target="#invitaciones-resultado" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Descartar y cancelar la invitación expirada para <?= e($inv['correo_destino']) ?>? El registro permanecerá en el historial como cancelado." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary is-danger" type="submit" title="Descarta la invitación expirada conservando su historial como cancelada">
                                            <?= svg_icon('close') ?>
                                            <span>Cancelar</span>
                                        </button>
                                    </form>
                                <?php elseif (!$isCompletada): ?>
                                    <form hx-post="/usuarios/reenviar_invitacion.php" hx-target="#invitaciones-resultado" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Emitir una nueva invitación para <?= e($inv['correo_destino']) ?> con nuevo enlace y 24 horas de vigencia?" class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary" type="submit" title="Genera un nuevo enlace válido por 24 horas">
                                            <?= svg_icon('refresh') ?>
                                            <span>Reenviar</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="has-text-grey is-size-7" title="La cuenta ya fue activada; administra su estado desde la pestaña Cuentas de usuario">—</span>
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
