<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('USUARIOS_VER');
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
    <section class="toolbar-panel" aria-label="Acciones de invitaciones">
        <p class="is-size-6 has-text-grey-light">Invitaciones enviadas por correo electrónico para autoservicio de onboarding y activación de cuentas.</p>
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
        <div id="invitaciones-resultado" hx-get="/usuarios/invitaciones_lista.php" hx-trigger="load, invitacionActualizada from:body, usuarioActualizado from:body" hx-swap="innerHTML"></div>
    </section>
</div>
<?php layout_end(); ?>
