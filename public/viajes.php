<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_permission('VIAJES_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('viajes', 'Viajes', 'Planificación y seguimiento de despachos, unidades, rutas, paradas y órdenes asociadas.');
?>
<section class="toolbar-panel" aria-label="Filtros y creación de viajes">
    <form class="filter-form" id="viajes-filtros" hx-get="/viajes/lista.php" hx-target="#viajes-resultado" hx-trigger="change" hx-include="this">
        <div class="select">
            <select id="viaje-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado del viaje">
                <option value="">Todos los estados</option>
                <option value="PLANIFICADO">Planificado</option>
                <option value="EN_CURSO">En curso</option>
                <option value="FINALIZADO">Finalizado</option>
                <option value="CANCELADO">Cancelado</option>
            </select>
        </div>
        <input class="input" id="viaje-desde" type="text" name="desde" data-datepicker="date" placeholder="Desde (dd/mm/aaaa)" aria-label="Fecha desde">
        <input class="input" id="viaje-hasta" type="text" name="hasta" data-datepicker="date" placeholder="Hasta (dd/mm/aaaa)" aria-label="Fecha hasta">
    </form>
    <?php if (has_permission('VIAJES_CREAR')): ?>
        <button class="button is-primary" type="button" hx-get="/viajes/form.php" hx-target="#modal-contenido">
            <?= svg_icon('plus') ?>
            <span>Nuevo viaje</span>
        </button>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div id="viajes-resultado" hx-get="/viajes/lista.php" hx-trigger="load"></div>
</section>
<?php layout_end(); ?>
