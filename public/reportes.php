<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';

$sections = [];
if (has_permission('REPORTES_VER') && has_permission('SOLICITUDES_VER')) { $sections['solicitudes'] = ['Solicitudes', 'SOLICITUDES_VER']; }
if (has_permission('REPORTES_VER') && has_permission('FLOTAS_VER')) { $sections['flota'] = ['Flota', 'FLOTAS_VER']; }
if (has_permission('REPORTES_VER') && has_permission('VIAJES_VER')) { $sections['viajes'] = ['Viajes', 'VIAJES_VER']; }
if (has_permission('REPORTES_VER') && has_permission('ENTREGAS_VER')) { $sections['entregas'] = ['Entregas', 'ENTREGAS_VER']; }
if (has_permission('AUDITORIA_VER')) { $sections['auditoria'] = ['Auditoría', 'AUDITORIA_VER']; }
require_any_permission(array_column($sections, 1));

$section = (string)($_GET['seccion'] ?? array_key_first($sections));
if (!isset($sections[$section])) { $section = (string)array_key_first($sections); }
require_permission($sections[$section][1]);
if ($section !== 'auditoria') { require_permission('REPORTES_VER'); }
require_once __DIR__ . '/../app/layout.php';
layout_start('reportes', 'Reportes', 'Consulta operativa de solo lectura con filtros y paginación autorizados.');
?>
<section class="panel-white">
    <nav class="module-tabs" aria-label="Tipos de reporte">
        <?php foreach ($sections as $key => [$label]): ?>
            <a class="<?= $section === $key ? 'active' : '' ?>" href="/reportes.php?seccion=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="panel-heading-custom"><div><h2 class="title is-5"><?= e($sections[$section][0]) ?></h2><p><?= $section === 'auditoria' ? 'Metadatos de eventos registrados. No se muestran valores anteriores ni posteriores.' : 'Fuente: contratos operativos de lectura en la base vigente.' ?></p></div></div>
    <form class="filter-grid" id="reportes-filtros" hx-get="/reportes/lista.php" hx-target="#reportes-resultado" hx-trigger="keyup changed delay:350ms from:input, change" hx-include="this">
        <input type="hidden" name="seccion" value="<?= e($section) ?>">
        <?php if ($section === 'solicitudes'): ?>
            <div class="field mb-0"><label class="label" for="reporte-buscar">Buscar</label><div class="control"><input class="input" id="reporte-buscar" type="search" name="buscar" maxlength="120" placeholder="Código, cliente o destinatario"></div></div>
            <div class="field mb-0"><label class="label" for="reporte-estado">Estado</label><div class="control select is-fullwidth"><select id="reporte-estado" name="estado"><option value="">Todos</option><option>REGISTRADA</option><option>PENDIENTE_ASIGNACION</option><option>ASIGNADA</option><option>EN_TRANSITO</option><option>ENTREGADA</option><option>ENTREGA_FALLIDA</option><option>CANCELADA</option></select></div></div>
        <?php elseif ($section === 'flota'): ?>
            <div class="field mb-0"><label class="label" for="reporte-buscar">Buscar</label><div class="control"><input class="input" id="reporte-buscar" type="search" name="buscar" maxlength="80" placeholder="Placa, marca o modelo"></div></div>
            <div class="field mb-0"><label class="label" for="reporte-estado">Estado</label><div class="control select is-fullwidth"><select id="reporte-estado" name="estado"><option value="">Todos</option><option>DISPONIBLE</option><option>ASIGNADO</option><option>MANTENIMIENTO</option><option>FUERA_SERVICIO</option></select></div></div>
        <?php elseif ($section === 'auditoria'): ?>
            <div class="field mb-0"><label class="label" for="reporte-entidad">Entidad</label><div class="control"><input class="input" id="reporte-entidad" type="search" name="entidad" maxlength="100" placeholder="Ej. solicitudes"></div></div>
            <div class="field mb-0"><label class="label" for="reporte-registro">Registro</label><div class="control"><input class="input" id="reporte-registro" type="search" name="registro_id" maxlength="100" placeholder="Identificador auditado"></div></div>
        <?php else: ?>
            <div class="field mb-0"><label class="label" for="reporte-estado">Estado</label><div class="control select is-fullwidth"><select id="reporte-estado" name="estado"><option value="">Todos</option><?php if ($section === 'viajes'): ?><option>PLANIFICADO</option><option>EN_CURSO</option><option>FINALIZADO</option><option>CANCELADO</option><?php else: ?><option>ENTREGADA</option><option>FALLIDA</option><?php endif; ?></select></div></div>
        <?php endif; ?>
        <?php if ($section !== 'flota'): ?>
            <div class="field mb-0"><label class="label" for="reporte-desde">Desde</label><div class="control"><input class="input" id="reporte-desde" type="date" name="desde"></div></div>
            <div class="field mb-0"><label class="label" for="reporte-hasta">Hasta</label><div class="control"><input class="input" id="reporte-hasta" type="date" name="hasta"></div></div>
        <?php endif; ?>
    </form>
    <div id="reportes-resultado" hx-get="/reportes/lista.php?seccion=<?= e($section) ?>" hx-trigger="load"><div class="is-skeleton" aria-label="Cargando reporte"></div></div>
</section>
<?php layout_end(); ?>
