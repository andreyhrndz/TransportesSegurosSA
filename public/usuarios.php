<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('USUARIOS_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('usuarios', 'Usuarios y permisos', 'Administra cuentas de acceso y asignaciones de permisos individuales sin roles ni exposición de credenciales.');
?>
<section class="panel-white clients-toolbar">
    <div>
        <h2 class="title is-5 mb-1">Directorio de usuarios</h2>
        <p class="has-text-grey">Gestiona cuentas activas, estado operativo y permisos individuales por código.</p>
    </div>
    <?php if (has_permission('USUARIOS_GESTIONAR_CUENTAS')): ?>
        <div class="buttons">
            <button class="button is-primary" type="button" hx-get="/usuarios/form.php" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nuevo usuario</span></button>
        </div>
    <?php endif; ?>
</section>
<section class="panel-white">
    <form class="filter-grid" id="usuarios-filtros" hx-get="/usuarios/lista.php" hx-target="#usuarios-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="field mb-0">
            <label class="label" for="usuarios-buscar">Buscar</label>
            <div class="control has-icons-left">
                <input class="input" id="usuarios-buscar" type="search" name="buscar" maxlength="120" placeholder="Usuario o correo" autocomplete="off" aria-controls="usuarios-resultado">
                <span class="icon is-small is-left"><?= svg_icon('search') ?></span>
            </div>
        </div>
        <div class="field mb-0">
            <label class="label" for="usuarios-activo">Estado</label>
            <div class="control select is-fullwidth">
                <select id="usuarios-activo" name="activo">
                    <option value="">Todos</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
            </div>
        </div>
    </form>
    <div id="usuarios-resultado" hx-get="/usuarios/lista.php" hx-trigger="load, usuarioActualizado from:body" hx-include="#usuarios-filtros" hx-swap="innerHTML">
        <div class="is-skeleton" aria-label="Cargando usuarios"></div>
    </div>
</section>
<?php layout_end(); ?>
