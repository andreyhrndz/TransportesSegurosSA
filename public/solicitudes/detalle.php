<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('SOLICITUDES_VER');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) { http_response_code(404); exit('Solicitud no encontrada.'); }
try { $sets = read_procedure_sets('EXEC dbo.sp_obtener_solicitud @solicitud_id=?;', [$id]); } catch (PDOException $exception) { if (read_is_missing_record($exception)) { http_response_code(404); exit('Solicitud no encontrada.'); } read_error_response(); }
$solicitud = $sets[0][0] ?? null;
if (!is_array($solicitud)) { http_response_code(404); exit('Solicitud no encontrada.'); }
$mercancias = $sets[1] ?? []; $historial = $sets[2] ?? [];
require_once __DIR__ . '/../../app/layout.php';
layout_start('solicitudes', 'Solicitud ' . (string)$solicitud['codigo_solicitud'], 'Detalle de la solicitud y su destinatario previsto.');
?>
<p><a class="table-link" href="/solicitudes.php">← Volver a solicitudes</a></p>
<?php if(in_array($solicitud['estado'], ['REGISTRADA','PENDIENTE_ASIGNACION'], true) && (has_permission('SOLICITUDES_AJUSTAR_PRIORIDAD') || has_permission('SOLICITUDES_CANCELAR'))):?><div class="buttons mb-4"><?php if(has_permission('SOLICITUDES_AJUSTAR_PRIORIDAD')):?><button class="button button-secondary" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=prioridad" hx-target="#modal-contenido">Ajustar prioridad</button><?php endif;?><?php if(has_permission('SOLICITUDES_CANCELAR')):?><button class="button is-danger is-light" hx-get="/solicitudes/accion_form.php?id=<?= (int)$id ?>&accion=cancelar" hx-target="#modal-contenido">Cancelar</button><?php endif;?></div><?php endif;?>
<section class="panel-white"><div class="detail-grid">
<dl class="detail-field"><dt>Estado</dt><dd><?= status_tag($solicitud['estado']) ?></dd></dl><dl class="detail-field"><dt>Prioridad</dt><dd><?= status_tag($solicitud['prioridad_actual']) ?></dd></dl><dl class="detail-field"><dt>Entrega requerida</dt><dd><?= format_utc_datetime($solicitud['fecha_entrega_requerida']) ?></dd></dl>
<dl class="detail-field"><dt>Origen</dt><dd><?= e($solicitud['origen_nombre']) ?></dd></dl><dl class="detail-field"><dt>Destino</dt><dd><?= e($solicitud['destino_nombre']) ?></dd></dl><dl class="detail-field"><dt>Viaje asignado</dt><dd><?= $solicitud['codigo_viaje'] ? e($solicitud['codigo_viaje']) : 'Sin asignar' ?></dd></dl>
</div>
<div class="detail-section"><h2 class="title is-5">Destinatario esperado</h2><div class="detail-grid"><dl class="detail-field"><dt>Nombre</dt><dd><?= e(trim($solicitud['destinatario_nombre'].' '.$solicitud['destinatario_apellido1'].' '.$solicitud['destinatario_apellido2'])) ?></dd></dl><dl class="detail-field"><dt>Identificación</dt><dd><?= e($solicitud['destinatario_identificacion'] ?: 'No indicada') ?></dd></dl><dl class="detail-field"><dt>Teléfono</dt><dd><?= e($solicitud['destinatario_telefono'] ?: 'No indicado') ?></dd></dl></div></div>
<div class="detail-section"><h2 class="title is-5">Mercancías</h2><div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Tipo</th><th>Descripción</th><th>Peso</th><th>Volumen</th></tr></thead><tbody><?php foreach ($mercancias as $item): ?><tr><td><?= e($item['tipo_mercancia']) ?></td><td><?= e($item['descripcion']) ?></td><td><?= e($item['peso_kg']) ?> kg</td><td><?= e($item['volumen_m3'] ?? '—') ?> m³</td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($historial): ?><div class="detail-section"><h2 class="title is-5">Historial de estado</h2><div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Fecha</th><th>Transición</th><th>Observación</th></tr></thead><tbody><?php foreach ($historial as $item): ?><tr><td><?= format_utc_datetime($item['fecha_cambio']) ?></td><td><?= e(($item['estado_anterior'] ?: 'Inicio').' → '.$item['estado_nuevo']) ?></td><td><?= e($item['observacion'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</section>
<?php layout_end(); ?>
