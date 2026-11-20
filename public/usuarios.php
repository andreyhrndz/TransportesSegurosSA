<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('USUARIOS_VER');

$hasLegacyCanceled = false;
try {
    $chkCanc = db()->query("SELECT TOP 1 1 FROM dbo.invitaciones_usuario WHERE estado = 'CANCELADA';");
    $hasLegacyCanceled = ($chkCanc && $chkCanc->fetchColumn() !== false);
} catch (PDOException) {
    $hasLegacyCanceled = false;
}

require_once __DIR__ . '/../app/layout.php';
layout_start('usuarios', 'Usuarios y permisos', 'Administra cuentas de acceso y asignaciones de permisos individuales del personal.');
?>
<div class="tabs is-boxed mb-4" id="usuarios-tabs" role="tablist">
    <ul>
        <li class="is-active" id="tab-btn-cuentas">
            <a role="tab" id="tab-cuentas" aria-selected="true" aria-controls="panel-cuentas" data-user-tab="cuentas">
                <?= svg_icon('users') ?>
                <span>Cuentas de usuario</span>
            </a>
        </li>
        <li id="tab-btn-invitaciones">
            <a role="tab" id="tab-invitaciones" aria-selected="false" aria-controls="panel-invitaciones" data-user-tab="invitaciones">
                <?= svg_icon('envelope') ?>
                <span>Invitaciones</span>
            </a>
        </li>
    </ul>
</div>

<div id="panel-cuentas" role="tabpanel" aria-labelledby="tab-cuentas">
    <section class="toolbar-panel" aria-label="Filtros y acciones de usuarios">
        <form class="filter-form" id="usuarios-filtros" hx-get="/usuarios/lista.php" hx-target="#usuarios-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="usuarios-buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar por nombre de usuario o correo" autocomplete="off" aria-label="Buscar usuarios" aria-controls="usuarios-resultado">
            </div>
            <div class="select">
                <select id="usuarios-activo" name="activo" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado del usuario">
                    <option value="">Todos los estados</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
            </div>
        </form>
        <?php if (has_permission('USUARIOS_GESTIONAR_CUENTAS')): ?>
            <div class="buttons">
                <button class="button is-primary" type="button" hx-get="/usuarios/form.php" hx-target="#modal-contenido">
                    <?= svg_icon('plus') ?>
                    <span>Invitar usuario</span>
                </button>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel-box table-panel">
        <div id="usuarios-resultado" hx-get="/usuarios/lista.php" hx-trigger="load, usuarioActualizado from:body" hx-include="#usuarios-filtros" hx-swap="innerHTML"></div>
    </section>
</div>

<div id="panel-invitaciones" role="tabpanel" aria-labelledby="tab-invitaciones" class="is-hidden">
    <section class="toolbar-panel" aria-label="Filtros y acciones de invitaciones">
        <form class="filter-form" id="invitaciones-filtros" hx-get="/usuarios/invitaciones_lista.php" hx-target="#invitaciones-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
            <div class="input-with-icon">
                <?= svg_icon('search', '', 'input-icon') ?>
                <input class="input" id="invitaciones-buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar por correo electrónico" autocomplete="off" aria-label="Buscar invitaciones por correo" aria-controls="invitaciones-resultado">
            </div>
            <div class="select">
                <select id="invitaciones-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado de la invitación">
                    <option value="">Todos los estados</option>
                    <option value="PENDIENTE">Pendientes</option>
                    <option value="EXPIRADA">Expiradas</option>
                    <option value="REVOCADA">Revocadas</option>
                    <option value="COMPLETADA">Completadas</option>
                    <?php if ($hasLegacyCanceled): ?>
                        <option value="CANCELADA">Canceladas (historial)</option>
                    <?php endif; ?>
                </select>
            </div>
        </form>
        <?php if (has_permission('USUARIOS_GESTIONAR_CUENTAS')): ?>
            <div class="buttons">
                <button class="button is-primary" type="button" hx-get="/usuarios/form.php" hx-target="#modal-contenido">
                    <?= svg_icon('plus') ?>
                    <span>Invitar usuario</span>
                </button>
            </div>
        <?php endif; ?>
    </section>
    <div id="invitaciones-feedback" role="alert" aria-live="assertive"></div>
    <section class="panel-box table-panel">
        <div id="invitaciones-resultado" hx-get="/usuarios/invitaciones_lista.php" hx-trigger="load, invitacionActualizada from:body, usuarioActualizado from:body" hx-include="#invitaciones-filtros, #invitaciones-pagina-actual" hx-swap="innerHTML"></div>
    </section>
</div>
<?php layout_end(); ?>
