<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('SOLICITUDES_VER');
require_once __DIR__ . '/../app/layout.php';

layout_start('solicitudes', 'Solicitudes', 'Consulta órdenes de transporte, destinatarios previstos y mercancías registradas.');
?>
<section class="toolbar-panel" aria-label="Filtros y creación de solicitudes">
    <form class="filter-form" id="solicitudes-filtros" hx-get="/solicitudes/lista.php" hx-target="#solicitudes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="input-with-icon">
            <?= svg_icon('search', '', 'input-icon') ?>
            <input class="input" id="solicitud-buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar por código, cliente o destinatario" aria-label="Buscar solicitudes">
        </div>
        <div class="select">
            <select id="solicitud-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Todos los estados" aria-label="Estado de solicitud">
                <option value="">Todos los estados</option>
                <option value="REGISTRADA">Registrada</option>
                <option value="PENDIENTE_ASIGNACION">Pendiente de asignación</option>
                <option value="ASIGNADA">Asignada</option>
                <option value="EN_TRANSITO">En tránsito</option>
                <option value="ENTREGADA">Entregada</option>
                <option value="ENTREGA_FALLIDA">Entrega fallida</option>
                <option value="CANCELADA">Cancelada</option>
            </select>
        </div>
        <input class="input" id="solicitud-desde" type="text" name="desde" data-datepicker="date" placeholder="Desde (dd/mm/aaaa)" aria-label="Fecha desde">
        <input class="input" id="solicitud-hasta" type="text" name="hasta" data-datepicker="date" placeholder="Hasta (dd/mm/aaaa)" aria-label="Fecha hasta">
    </form>
    <?php if (has_permission('SOLICITUDES_CREAR')): ?>
        <button class="button is-primary" type="button" hx-get="/solicitudes/form.php" hx-target="#modal-contenido">
            <?= svg_icon('plus') ?>
            <span>Nueva solicitud</span>
        </button>
    <?php endif; ?>
</section>

<section class="panel-box table-panel">
    <div id="solicitudes-resultado" hx-get="/solicitudes/lista.php" hx-trigger="load" hx-swap="innerHTML"></div>
</section>
<?php layout_end(); ?>
