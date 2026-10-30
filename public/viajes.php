<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_permission('VIAJES_VER');
require_once __DIR__ . '/../app/layout.php';
layout_start('viajes', 'Viajes', 'Consulta recursos asignados, rutas, paradas y solicitudes por viaje.');
?>
<section class="panel-white"><div class="panel-heading-custom"><div><h2 class="title is-5">Planificación de viajes</h2><p>Información operativa disponible según el contrato de lectura.</p></div><?php if(has_permission('VIAJES_CREAR')):?><button class="button is-primary" hx-get="/viajes/form.php" hx-target="#modal-contenido"><?= svg_icon('plus') ?><span>Nuevo viaje</span></button><?php endif;?></div>
<form class="filter-grid" id="viajes-filtros" hx-get="/viajes/lista.php" hx-target="#viajes-resultado" hx-trigger="change" hx-include="this"><div class="field mb-0"><label class="label" for="viaje-estado">Estado</label><div class="control select is-fullwidth"><select id="viaje-estado" name="estado"><option value="">Todos</option><option>PLANIFICADO</option><option>EN_CURSO</option><option>FINALIZADO</option><option>CANCELADO</option></select></div></div><div class="field mb-0"><label class="label" for="viaje-desde">Desde</label><div class="control"><input class="input" id="viaje-desde" type="date" name="desde"></div></div><div class="field mb-0"><label class="label" for="viaje-hasta">Hasta</label><div class="control"><input class="input" id="viaje-hasta" type="date" name="hasta"></div></div></form>
<div id="viajes-resultado" hx-get="/viajes/lista.php" hx-trigger="load"><div class="is-skeleton" aria-label="Cargando viajes"></div></div></section>
<?php layout_end(); ?>
