<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('USUARIOS_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('usuarios', 'Usuarios y permisos', 'Administra cuentas de acceso y asignaciones de permisos individuales del personal.');
?>
<section class="toolbar-panel" aria-label="Filtros y acciones de usuarios">
    <form class="filter-form" id="usuarios-filtros" hx-get="/usuarios/lista.php" hx-target="#usuarios-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="input-with-icon">
            <?= svg_icon('search', '', 'input-icon') ?>
            <input class="input" id="usuarios-buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar por nombre de usuario o correo" autocomplete="off" aria-label="Buscar usuarios" aria-controls="usuarios-resultado">
        </div>
        <div class="select">
            <select id="usuarios-activo" name="activo" aria-label="Estado del usuario">
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
                <span>Nuevo usuario</span>
            </button>
        </div>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div id="usuarios-resultado" hx-get="/usuarios/lista.php" hx-trigger="load, usuarioActualizado from:body" hx-include="#usuarios-filtros" hx-swap="innerHTML"></div>
</section>
<?php layout_end(); ?>
