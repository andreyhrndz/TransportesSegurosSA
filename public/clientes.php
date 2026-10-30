<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('CLIENTES_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('clientes', 'Clientes', 'Administra el directorio de personas y empresas con trazabilidad de solicitudes e historial.');
?>
<section class="toolbar-panel" aria-label="Filtros y acciones de clientes">
    <form class="filter-form" id="clientes-filtros" hx-get="/clientes/lista.php" hx-target="#clientes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="input-with-icon">
            <?= svg_icon('search', '', 'input-icon') ?>
            <input class="input" id="buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar clientes por nombre, identificación o correo" autocomplete="off" aria-label="Buscar clientes" aria-controls="clientes-resultado">
        </div>
        <div class="select">
            <select id="clientes-activo" name="activo" aria-label="Estado del cliente">
                <option value="">Todos los estados</option>
                <option value="1">Activos</option>
                <option value="0">Inactivos</option>
            </select>
        </div>
    </form>
    <?php if (has_permission('CLIENTES_CREAR')): ?>
        <div class="buttons">
            <button class="button is-primary" type="button" hx-get="/clientes/form.php?tipo=fisico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nuevo cliente</span></button>
            <button class="button button-secondary" type="button" hx-get="/clientes/form.php?tipo=juridico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nueva empresa</span></button>
        </div>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div id="clientes-resultado" hx-get="/clientes/lista.php" hx-trigger="load, clienteCreado from:body, clienteActualizado from:body" hx-include="#clientes-filtros" hx-swap="innerHTML"></div>
</section>
<?php layout_end(); ?>
