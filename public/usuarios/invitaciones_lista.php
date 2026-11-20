<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

$currentUser = require_login();
require_permission('USUARIOS_VER');
header('Cache-Control: no-store');

$canManageAccounts = has_permission('USUARIOS_GESTIONAR_CUENTAS');

$buscar = read_optional_text($_GET['buscar'] ?? $_POST['buscar'] ?? '', 120);
$estadoParam = strtoupper(trim((string)($_GET['estado'] ?? $_POST['estado'] ?? '')));
$estadoFiltro = in_array($estadoParam, ['PENDIENTE', 'EXPIRADA', 'REVOCADA', 'COMPLETADA', 'CANCELADA'], true)
    ? $estadoParam
    : null;
$page = read_page_number($_GET['pagina'] ?? $_POST['pagina'] ?? null);
$perPage = 20;

$sqlList = 'DECLARE @total_registros INT; EXEC dbo.sp_listar_invitaciones_paginadas @buscar=?,@estado=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;';

try {
    $result = read_procedure_list($sqlList, [$buscar, $estadoFiltro, $page, $perPage]);
    $total = $result['total'];
    $pages = max(1, (int)ceil($total / $perPage));
    if ($total > 0 && $page > $pages) {
        $page = $pages;
        $result = read_procedure_list($sqlList, [$buscar, $estadoFiltro, $page, $perPage]);
        $total = $result['total'];
        $pages = max(1, (int)ceil($total / $perPage));
    }
    $invitaciones = $result['rows'];
} catch (PDOException) {
    if (is_htmx_request()) {
        header('HX-Retarget: #invitaciones-feedback');
        header('HX-Reswap: innerHTML');
    }
    http_response_code(500);
    echo ui_notice('No se pudo cargar la lista de invitaciones. Inténtalo nuevamente.', 'danger');
    exit;
}

$hasActiveFilters = ($buscar !== null || $estadoFiltro !== null);
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
?>
<input type="hidden" id="invitaciones-pagina-actual" name="pagina" value="<?= $page ?>">
<div class="table-container">
    <table class="table is-fullwidth is-hoverable" aria-label="Invitaciones de usuario">
        <thead>
            <tr>
                <th>Correo electrónico</th>
                <th>Estado</th>
                <th>Permisos planificados</th>
                <th>Vigencia</th>
                <th class="has-text-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invitaciones)): ?>
                <tr>
                    <td colspan="5">
                        <?php if ($hasActiveFilters): ?>
                            <?= empty_state('Sin resultados', 'No se encontraron invitaciones que coincidan con la búsqueda o el estado seleccionado.') ?>
                        <?php else: ?>
                            <?= empty_state('Sin invitaciones', 'No se han emitido invitaciones en el sistema.') ?>
                        <?php endif; ?>
                    </td>
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
                // Estado efectivo UTC: nunca mostrar una invitación vencida como pendiente utilizable.
                $estado = ($estadoRaw === 'PENDIENTE' && $isExpiredByTime) ? 'EXPIRADA' : $estadoRaw;
                $estadoEnvio = strtoupper(trim((string)($inv['estado_envio'] ?? 'PENDIENTE')));
                $isPendiente = ($estado === 'PENDIENTE');
                $isExpirada = ($estado === 'EXPIRADA');
                $isCompletada = ($estado === 'COMPLETADA');
                $enviosContador = (int)($inv['envios_contador'] ?? 1);
                $totalPerms = (int)($inv['total_permisos'] ?? 0);
                $permisosCodigos = (string)($inv['permisos_codigos'] ?? '');
                $deliverySummary = invitation_delivery_text($estadoEnvio);
                if ($enviosContador > 1) {
                    $deliverySummary .= ' · ' . $enviosContador . ' envíos';
                }
                $deliveryClass = $estadoEnvio === 'FALLIDO' ? 'has-text-danger' : '';
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
                            <span class="invitation-delivery-meta <?= $deliveryClass ?>"><?= e($deliverySummary) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($totalPerms > 0): ?>
                            <span class="perm-count-meta" title="<?= e($permisosCodigos) ?>">
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
                                    <button class="button is-small button-secondary" type="button" hx-get="/usuarios/reenviar_form.php?id=<?= $invId ?>" hx-target="#modal-contenido" title="Confirmar o corregir correo y emitir un nuevo enlace válido por 24 horas">
                                        <?= svg_icon('refresh') ?>
                                        <span>Reenviar</span>
                                    </button>
                                    <form hx-post="/usuarios/revocar_invitacion.php" hx-target="#invitaciones-resultado" hx-include="#invitaciones-filtros, #invitaciones-pagina-actual" hx-disabled-elt="find button[type=submit]" hx-confirm="¿Revocar la invitación vigente a <?= e($inv['correo_destino']) ?>? El enlace dejará de ser válido inmediatamente." class="is-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="invitacion_id" value="<?= $invId ?>">
                                        <button class="button is-small button-secondary is-danger" type="submit" title="Invalida el enlace de activación inmediatamente">
                                            <?= svg_icon('close') ?>
                                            <span>Revocar</span>
                                        </button>
                                    </form>
                                <?php elseif (!$isCompletada): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/usuarios/reenviar_form.php?id=<?= $invId ?>" hx-target="#modal-contenido" title="Confirmar o corregir correo y emitir un nuevo enlace válido por 24 horas">
                                        <?= svg_icon('refresh') ?>
                                        <span>Reenviar</span>
                                    </button>
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
<div class="pagination-bar">
    <span><?= $total ?> <?= $total === 1 ? 'invitación' : 'invitaciones' ?></span>
    <div class="buttons" hx-target="#invitaciones-resultado" hx-include="#invitaciones-filtros">
        <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/usuarios/invitaciones_lista.php?pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/usuarios/invitaciones_lista.php?pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
