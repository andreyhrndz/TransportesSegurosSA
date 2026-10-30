<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('SOLICITUDES_VER');
require_once __DIR__ . '/../app/layout.php';

layout_start('solicitudes', 'Solicitudes', 'Consulta solicitudes, destinatarios previstos y mercancías registradas.');
?>
<section class="panel-white">
    <div class="panel-heading-custom"><div><h2 class="title is-5">Solicitudes de transporte</h2><p>Filtra por código, cliente, estado o período.</p></div></div>
    <form class="filter-grid" id="solicitudes-filtros" hx-get="/solicitudes/lista.php" hx-target="#solicitudes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <div class="field mb-0"><label class="label" for="solicitud-buscar">Buscar</label><div class="control"><input class="input" id="solicitud-buscar" type="search" name="buscar" maxlength="120" placeholder="Código, cliente o destinatario"></div></div>
        <div class="field mb-0"><label class="label" for="solicitud-estado">Estado</label><div class="control select is-fullwidth"><select id="solicitud-estado" name="estado"><option value="">Todos</option><option>REGISTRADA</option><option>PENDIENTE_ASIGNACION</option><option>ASIGNADA</option><option>EN_TRANSITO</option><option>ENTREGADA</option><option>ENTREGA_FALLIDA</option><option>CANCELADA</option></select></div></div>
        <div class="field mb-0"><label class="label" for="solicitud-desde">Desde</label><div class="control"><input class="input" id="solicitud-desde" type="date" name="desde"></div></div>
        <div class="field mb-0"><label class="label" for="solicitud-hasta">Hasta</label><div class="control"><input class="input" id="solicitud-hasta" type="date" name="hasta"></div></div>
    </form>
    <div id="solicitudes-resultado" hx-get="/solicitudes/lista.php" hx-trigger="load" hx-swap="innerHTML"><div class="is-skeleton" aria-label="Cargando solicitudes"></div></div>
</section>
<?php layout_end(); ?>
