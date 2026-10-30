<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
$sections = [
    'vehiculos' => ['Vehículos', 'FLOTAS_VER'], 'conductores' => ['Conductores', 'FLOTAS_VER'],
    'rutas' => ['Rutas', 'RUTAS_VER'], 'ubicaciones' => ['Ubicaciones', 'UBICACIONES_VER'], 'catalogos' => ['Catálogos', 'CATALOGOS_VER'],
];
$section = (string)($_GET['seccion'] ?? 'vehiculos'); if (!isset($sections[$section])) { $section = 'vehiculos'; }
require_permission($sections[$section][1]); require_once __DIR__ . '/../app/layout.php'; layout_start('flota', 'Flota y maestros', 'Consulta recursos, rutas, ubicaciones y catálogos activos.');
?>
<section class="panel-white"><nav class="module-tabs" aria-label="Secciones de flota y maestros"><?php foreach($sections as $key=>[$label,$permission]): if(has_permission($permission)): ?><a class="<?= $section===$key?'active':'' ?>" href="/flota.php?seccion=<?= e($key) ?>"><?= e($label) ?></a><?php endif; endforeach; ?></nav>
<div class="panel-heading-custom"><div><h2 class="title is-5"><?= e($sections[$section][0]) ?></h2><p>Consulta de sólo lectura según el contrato SQL disponible.</p></div></div>
<form class="filter-grid" id="flota-filtros" hx-get="/flota/lista.php" hx-target="#flota-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this"><input type="hidden" name="seccion" value="<?= e($section) ?>"><div class="field mb-0"><label class="label" for="flota-buscar">Buscar</label><div class="control"><input class="input" id="flota-buscar" type="search" name="buscar" maxlength="120" placeholder="Buscar <?= strtolower(e($sections[$section][0])) ?>"></div></div><?php if(in_array($section,['vehiculos','conductores'],true)): ?><div class="field mb-0"><label class="label" for="flota-estado">Estado</label><div class="control select is-fullwidth"><select id="flota-estado" name="estado"><option value="">Todos</option><option>DISPONIBLE</option><option>ASIGNADO</option><?php if($section==='vehiculos'): ?><option>MANTENIMIENTO</option><option>FUERA_SERVICIO</option><?php else: ?><option>INACTIVO</option><?php endif;?></select></div></div><?php endif;?></form>
<div id="flota-resultado" hx-get="/flota/lista.php?seccion=<?= e($section) ?>" hx-trigger="load"><div class="is-skeleton" aria-label="Cargando <?= strtolower(e($sections[$section][0])) ?>"></div></div></section><?php layout_end(); ?>
