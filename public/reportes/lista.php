<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
header('Cache-Control: no-store');

$section = (string)($_GET['seccion'] ?? '');
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;
$state = strtoupper((string)($_GET['estado'] ?? ''));
$from = read_optional_date($_GET['desde'] ?? null);
$to = read_optional_date($_GET['hasta'] ?? null);
if ($from !== null && $to !== null && $from > $to) { $from = $to = null; }

try {
    if ($section === 'solicitudes') {
        require_permission('REPORTES_VER'); require_permission('SOLICITUDES_VER');
        $state = in_array($state, ['REGISTRADA','PENDIENTE_ASIGNACION','ASIGNADA','EN_TRANSITO','ENTREGADA','ENTREGA_FALLIDA','CANCELADA'], true) ? $state : null;
        $rows = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=?,@estado=?,@cliente_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [read_optional_text($_GET['buscar'] ?? '', 120), $state, $from, $to, $page, $perPage]);
    } elseif ($section === 'flota') {
        require_permission('REPORTES_VER'); require_permission('FLOTAS_VER');
        $state = in_array($state, ['DISPONIBLE','ASIGNADO','MANTENIMIENTO','FUERA_SERVICIO'], true) ? $state : null;
        $rows = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=?,@solo_activos=NULL,@buscar=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [$state, read_optional_text($_GET['buscar'] ?? '', 80), $page, $perPage]);
    } elseif ($section === 'viajes') {
        require_permission('REPORTES_VER'); require_permission('VIAJES_VER');
        $state = in_array($state, ['PLANIFICADO','EN_CURSO','FINALIZADO','CANCELADO'], true) ? $state : null;
        $rows = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=?,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [$state, $from, $to, $page, $perPage]);
    } elseif ($section === 'entregas') {
        require_permission('REPORTES_VER'); require_permission('ENTREGAS_VER');
        $state = in_array($state, ['ENTREGADA','FALLIDA'], true) ? $state : null;
        $rows = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas @estado=?,@viaje_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [$state, $from, $to, $page, $perPage]);
    } elseif ($section === 'auditoria') {
        require_permission('AUDITORIA_VER');
        $rows = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_auditorias @entidad=?,@registro_id=?,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [read_optional_text($_GET['entidad'] ?? '', 100), read_optional_text($_GET['registro_id'] ?? '', 100), $from, $to, $page, $perPage]);
    } else { http_response_code(404); exit('Reporte no encontrado.'); }
} catch (PDOException) { read_error_response('No se pudo consultar el reporte.'); }

$total = $rows['total']; $pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
?>
<div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr>
<?php if ($section === 'solicitudes'): ?><th>Código</th><th>Cliente</th><th>Estado</th><th>Prioridad</th><th>Fecha</th>
<?php elseif ($section === 'flota'): ?><th>Placa</th><th>Vehículo</th><th>Capacidad</th><th>Estado</th>
<?php elseif ($section === 'viajes'): ?><th>Viaje</th><th>Ruta</th><th>Vehículo</th><th>Conductor</th><th>Estado</th><th>Salida</th>
<?php elseif ($section === 'entregas'): ?><th>Solicitud</th><th>Viaje</th><th>Resultado</th><th>Receptor efectivo</th><th>Fecha</th>
<?php else: ?><th>Entidad</th><th>Registro</th><th>Acción</th><th>Usuario</th><th>Fecha</th><?php endif; ?>
</tr></thead><tbody><?php if (!$rows['rows']): ?><tr><td colspan="6"><?= empty_state('Sin resultados', 'No hay registros disponibles para los filtros seleccionados.') ?></td></tr><?php endif; ?>
<?php foreach ($rows['rows'] as $row): ?><tr>
<?php if ($section === 'solicitudes'): ?><td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><?= e($row['codigo_solicitud']) ?></a></td><td><?= e($row['cliente_nombre']) ?></td><td><?= status_tag($row['estado']) ?></td><td><?= e($row['prioridad_actual']) ?></td><td><?= e(format_utc_datetime($row['fecha_solicitud'])) ?></td>
<?php elseif ($section === 'flota'): ?><td><?= e($row['placa']) ?></td><td><?= e(trim($row['marca'] . ' ' . $row['modelo'])) ?></td><td><?= e($row['capacidad_carga_kg']) ?> kg</td><td><?= status_tag($row['estado']) ?></td>
<?php elseif ($section === 'viajes'): ?><td><a class="table-link" href="/viajes/detalle.php?id=<?= (int)$row['viaje_id'] ?>"><?= e($row['codigo_viaje']) ?></a></td><td><?= e($row['ruta_nombre']) ?></td><td><?= e($row['placa']) ?></td><td><?= e($row['conductor_nombre']) ?></td><td><?= status_tag($row['estado']) ?></td><td><?= e(format_utc_datetime($row['fecha_programada_salida'])) ?></td>
<?php elseif ($section === 'entregas'): ?><td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><?= e($row['codigo_solicitud']) ?></a></td><td><a class="table-link" href="/viajes/detalle.php?id=<?= (int)$row['viaje_id'] ?>">#<?= (int)$row['viaje_id'] ?></a></td><td><?= status_tag($row['estado']) ?></td><td><?= e($row['receptor_nombre'] ?: 'No aplica') ?></td><td><?= e(format_utc_datetime($row['fecha_resultado'])) ?></td>
<?php else: ?><td><?= e($row['entidad']) ?></td><td><?= e($row['registro_id']) ?></td><td><?= e($row['accion']) ?></td><td><?= e($row['usuario'] ?: 'Sistema') ?></td><td><?= e(format_utc_datetime($row['fecha_hora'])) ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody></table></div>
<div class="pagination-wrap"><span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span><div class="buttons" hx-target="#reportes-resultado" hx-include="#reportes-filtros"><button class="button is-small" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page - 1 ?>">Anterior</button><span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span><button class="button is-small" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/reportes/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page + 1 ?>">Siguiente</button></div></div>
