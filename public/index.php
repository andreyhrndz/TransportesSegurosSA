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
require_once __DIR__ . '/../app/read_queries.php';
require_permission('REPORTES_VER');
require_once __DIR__ . '/../app/layout.php';

// Sólo contratos de lectura confirmados; el panel no modifica la base operativa.
$stats = [];
$recientes = [];
try {
    if (has_permission('CLIENTES_VER')) {
        $stats['clientes'] = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_clientes @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', [])['total'];
    }
    if (has_permission('SOLICITUDES_VER')) {
        $solicitudes = read_procedure_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=5,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;', []);
        $stats['solicitudes'] = $solicitudes['total'];
        $recientes = $solicitudes['rows'];
    }
    if (has_permission('VIAJES_VER')) {
        $planificados = read_procedure_list("DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=N'PLANIFICADO',@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;", [])['total'];
        $enCurso = read_procedure_list("DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @estado=N'EN_CURSO',@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;", [])['total'];
        $stats['viajes'] = $planificados + $enCurso;
    }
    if (has_permission('ENTREGAS_VER')) {
        $stats['entregas'] = read_procedure_list("DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas @estado=N'ENTREGADA',@viaje_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=1,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;", [])['total'];
    }
} catch (PDOException) {
    read_error_response('No se pudo actualizar el resumen operativo.');
}

layout_start('dashboard', 'Panel general', 'Resumen operativo desde contratos de lectura autorizados.');
?>
<section class="dashboard-summary" aria-labelledby="resumen-operativo">
    <h2 id="resumen-operativo" class="is-sr-only">Indicadores operativos</h2>
    <div class="stat-grid">
        <article class="stat-card"><span>Clientes activos</span><strong><?= array_key_exists('clientes', $stats) ? (int)$stats['clientes'] : '—' ?></strong><small>Personas y empresas</small></article>
        <article class="stat-card"><span>Solicitudes registradas</span><strong><?= array_key_exists('solicitudes', $stats) ? (int)$stats['solicitudes'] : '—' ?></strong><small>Histórico operativo</small></article>
        <article class="stat-card"><span>Viajes activos</span><strong><?= array_key_exists('viajes', $stats) ? (int)$stats['viajes'] : '—' ?></strong><small>Planificados o en curso</small></article>
        <article class="stat-card"><span>Entregas exitosas</span><strong><?= array_key_exists('entregas', $stats) ? (int)$stats['entregas'] : '—' ?></strong><small>Resultados confirmados</small></article>
    </div>
</section>
<section class="panel-white" aria-labelledby="solicitudes-recientes">
    <div class="panel-heading-custom"><div><h2 id="solicitudes-recientes" class="title is-5">Solicitudes recientes</h2><p>Los últimos movimientos registrados.</p></div></div>
    <?php if (!$recientes): ?>
        <?= empty_state('Aún no hay solicitudes', 'Las solicitudes recientes aparecerán aquí cuando existan registros.') ?>
    <?php else: ?>
        <div class="table-container"><table class="table is-fullwidth is-hoverable"><thead><tr><th>Código</th><th>Estado</th><th>Prioridad</th><th>Fecha (UTC)</th></tr></thead><tbody>
        <?php foreach ($recientes as $s): ?><tr><td><strong><?= e($s['codigo_solicitud']) ?></strong></td><td><span class="tag is-light"><?= e($s['estado']) ?></span></td><td><?= e($s['prioridad_actual']) ?></td><td><?= e(format_utc_datetime($s['fecha_solicitud'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
