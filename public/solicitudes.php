<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('SOLICITUDES_VER');
require_once __DIR__ . '/../app/layout.php';

$rawAlerta = strtolower(trim((string)($_GET['alerta'] ?? '')));
$alerta = in_array($rawAlerta, ['atrasadas', 'sin_asignar'], true) ? $rawAlerta : '';
$alertaLabels = [
    'atrasadas' => 'Mostrando únicamente solicitudes abiertas con fecha de entrega vencida (atrasadas).',
    'sin_asignar' => 'Mostrando únicamente solicitudes pendientes de asignar a un viaje (Registrada / Pendiente de asignación).',
];

layout_start('solicitudes', 'Solicitudes', 'Consulta órdenes de transporte, destinatarios previstos y mercancías registradas.');
?>
<?php if ($alerta !== ''): ?>
    <div class="notification notification-warning is-flex is-justify-content-space-between is-align-items-center py-2 px-3 mb-3" style="gap: 0.75rem; flex-wrap: wrap;">
        <span><?= e($alertaLabels[$alerta]) ?></span>
        <a class="button is-small button-secondary" href="/solicitudes.php">Ver todas las solicitudes</a>
    </div>
<?php endif; ?>

<section class="toolbar-panel" aria-label="Filtros y creación de solicitudes">
    <form class="filter-form" id="solicitudes-filtros" hx-get="/solicitudes/lista.php" hx-target="#solicitudes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <?php if ($alerta !== ''): ?>
            <input type="hidden" name="alerta" value="<?= e($alerta) ?>">
        <?php endif; ?>
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
    <div id="solicitudes-resultado" hx-get="/solicitudes/lista.php" hx-include="#solicitudes-filtros" hx-trigger="load" hx-swap="innerHTML"></div>
</section>
<?php layout_end(); ?>
