<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_permission('REPORTES_VER');
require_once __DIR__ . '/../app/layout.php';

// Solo lecturas. El panel se alimenta de las tablas reales del proyecto.
$stats = db()->query("SELECT
 (SELECT COUNT(*) FROM dbo.clientes WHERE activo=1) AS clientes,
 (SELECT COUNT(*) FROM dbo.solicitudes) AS solicitudes,
 (SELECT COUNT(*) FROM dbo.viajes WHERE estado IN ('PLANIFICADO','EN_CURSO')) AS viajes,
 (SELECT COUNT(*) FROM dbo.entregas WHERE estado='ENTREGADA') AS entregas")->fetch();
$recientes = db()->query("SELECT TOP (5) codigo_solicitud, estado, prioridad_actual, fecha_solicitud FROM dbo.solicitudes ORDER BY fecha_solicitud DESC, solicitud_id DESC")->fetchAll();

layout_start('dashboard', 'Panel general', 'Resumen operativo del sistema de transporte.');
?>
<div class="dashboard-hero"><div><span class="hero-kicker">CENTRO DE OPERACIONES</span><h2>Visión general de tu logística</h2><p>Indicadores calculados directamente desde TransportesSegurosSA.</p></div><span class="hero-icon" aria-hidden="true">▣</span></div>
<div class="stat-grid">
    <div class="stat-card"><span>Clientes activos</span><strong><?= (int)$stats['clientes'] ?></strong><small>Clientes físicos y jurídicos</small></div>
    <div class="stat-card"><span>Solicitudes registradas</span><strong><?= (int)$stats['solicitudes'] ?></strong><small>Histórico de solicitudes</small></div>
    <div class="stat-card"><span>Viajes activos / planificados</span><strong><?= (int)$stats['viajes'] ?></strong><small>Operación de transporte</small></div>
    <div class="stat-card"><span>Entregas exitosas</span><strong><?= (int)$stats['entregas'] ?></strong><small>Resultados confirmados</small></div>
</div>
<div class="panel-white"><div class="panel-heading-custom"><h2 class="title is-5">Solicitudes recientes</h2><p>Los últimos movimientos registrados en el sistema.</p></div><div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr><th>Código</th><th>Estado</th><th>Prioridad</th><th>Fecha (UTC)</th></tr></thead><tbody>
<?php if (!$recientes): ?><tr><td colspan="4" class="has-text-centered">No hay solicitudes registradas.</td></tr><?php endif; ?>
<?php foreach ($recientes as $s): ?><tr><td><strong><?= e($s['codigo_solicitud']) ?></strong></td><td><span class="tag is-light"><?= e($s['estado']) ?></span></td><td><?= e($s['prioridad_actual']) ?></td><td><?= e($s['fecha_solicitud']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php layout_end(); ?>
