<?php
// El servidor integrado puede derivar recursos inexistentes a index.php.
// Estos nunca deben iniciar sesión ni redirigir a la pantalla de acceso.
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestedPath) && preg_match('/\.(?:avif|bmp|css|gif|ico|jpe?g|js|map|png|svg|webmanifest|webp|woff2?)$/i', $requestedPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_permission('REPORTES_VER');
require_once __DIR__ . '/../app/layout.php';

// Solo lecturas; el panel no modifica la base operativa.
$stats = db()->query("SELECT
 (SELECT COUNT(*) FROM dbo.clientes WHERE activo=1) AS clientes,
 (SELECT COUNT(*) FROM dbo.solicitudes) AS solicitudes,
 (SELECT COUNT(*) FROM dbo.viajes WHERE estado IN ('PLANIFICADO','EN_CURSO')) AS viajes,
 (SELECT COUNT(*) FROM dbo.entregas WHERE estado='ENTREGADA') AS entregas")->fetch();
$recientes = db()->query("SELECT TOP (5) codigo_solicitud, estado, prioridad_actual, fecha_solicitud FROM dbo.solicitudes ORDER BY fecha_solicitud DESC, solicitud_id DESC")->fetchAll();

layout_start('dashboard', 'Panel general', 'Resumen operativo actualizado desde la base de datos.');
?>
<section class="dashboard-summary" aria-labelledby="resumen-operativo">
    <h2 id="resumen-operativo" class="is-sr-only">Indicadores operativos</h2>
    <div class="stat-grid">
        <article class="stat-card"><span>Clientes activos</span><strong><?= (int)$stats['clientes'] ?></strong><small>Personas y empresas</small></article>
        <article class="stat-card"><span>Solicitudes registradas</span><strong><?= (int)$stats['solicitudes'] ?></strong><small>Histórico operativo</small></article>
        <article class="stat-card"><span>Viajes activos</span><strong><?= (int)$stats['viajes'] ?></strong><small>Planificados o en curso</small></article>
        <article class="stat-card"><span>Entregas exitosas</span><strong><?= (int)$stats['entregas'] ?></strong><small>Resultados confirmados</small></article>
    </div>
</section>
<section class="panel-white" aria-labelledby="solicitudes-recientes">
    <div class="panel-heading-custom"><div><h2 id="solicitudes-recientes" class="title is-5">Solicitudes recientes</h2><p>Los últimos movimientos registrados.</p></div></div>
    <?php if (!$recientes): ?>
        <?= empty_state('Aún no hay solicitudes', 'Las solicitudes recientes aparecerán aquí cuando existan registros.') ?>
    <?php else: ?>
        <div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr><th>Código</th><th>Estado</th><th>Prioridad</th><th>Fecha (UTC)</th></tr></thead><tbody>
        <?php foreach ($recientes as $s): ?><tr><td><strong><?= e($s['codigo_solicitud']) ?></strong></td><td><span class="tag is-light"><?= e($s['estado']) ?></span></td><td><?= e($s['prioridad_actual']) ?></td><td><?= e($s['fecha_solicitud']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
