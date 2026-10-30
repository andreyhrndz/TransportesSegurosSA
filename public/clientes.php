<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('CLIENTES_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('clientes', 'Clientes', 'Administra el directorio de clientes físicos y jurídicos con trazabilidad y preservación del historial.');
?>
<section class="panel-white clients-toolbar">
    <div>
        <h2 class="title is-5 mb-1">Directorio de clientes</h2>
        <p class="has-text-grey">Consulta, registra, actualiza y desactiva clientes según tus permisos individuales.</p>
    </div>
    <?php if (has_permission('CLIENTES_CREAR')): ?>
        <div class="buttons">
            <button class="button is-primary" type="button" hx-get="/clientes/form.php?tipo=fisico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nuevo cliente</span></button>
            <button class="button button-secondary" type="button" hx-get="/clientes/form.php?tipo=juridico" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nueva empresa</span></button>
        </div>
    <?php endif; ?>
</section>
<section class="panel-white">
    <form class="filter-grid" id="clientes-filtros" hx-get="/clientes/lista.php" hx-target="#clientes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="field mb-0">
            <label class="label" for="buscar">Buscar clientes</label>
            <div class="control has-icons-left">
                <input class="input" id="buscar" type="search" name="buscar" maxlength="120" placeholder="Nombre, identificación, correo o empresa" autocomplete="off" aria-controls="clientes-resultado">
                <span class="icon is-small is-left"><?= svg_icon('search') ?></span>
            </div>
        </div>
        <div class="field mb-0">
            <label class="label" for="clientes-activo">Estado</label>
            <div class="control select is-fullwidth">
                <select id="clientes-activo" name="activo">
                    <option value="">Todos</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
            </div>
        </div>
    </form>
    <div id="clientes-resultado" hx-get="/clientes/lista.php" hx-trigger="load, clienteCreado from:body, clienteActualizado from:body" hx-include="#clientes-filtros" hx-swap="innerHTML">
        <div class="is-skeleton" aria-label="Cargando clientes"></div>
    </div>
</section>
<?php layout_end(); ?>
