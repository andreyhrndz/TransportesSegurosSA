<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';
require_permission('AUDITORIA_VER');
require_permission('USUARIOS_GESTIONAR_CUENTAS');

$estadoDb = [];
$historial = [];
$jobs = [];
$errorCarga = null;

try {
    $sets = read_procedure_sets('EXEC dbo.sp_listar_respaldos_y_jobs;', []);
    $estadoDb = $sets[0][0] ?? [];
    $historial = $sets[1] ?? [];
    $jobs = $sets[2] ?? [];
} catch (PDOException) {
    $errorCarga = 'No se pudo consultar el historial de respaldos en msdb. Verifica que el motor SQL Server esté disponible y la Migración 14 aplicada.';
}

$baseActiva = (string)($estadoDb['base_datos'] ?? configured_database_name());
$modeloRec = (string)($estadoDb['recovery_model_desc'] ?? 'DESCONOCIDO');
$rutaDefault = (string)($estadoDb['ruta_predeterminada_backups'] ?? 'No disponible');
$tieneFull = !empty($estadoDb['tiene_full_base']);
$agentActivo = !empty($estadoDb['agent_activo']);
$puedeDiff = $tieneFull;
$puedeLog = $tieneFull && strtoupper($modeloRec) === 'FULL';

function format_job_schedule(array $job): string
{
    $ft = (int)($job['freq_type'] ?? 0);
    $st = (int)($job['freq_subday_type'] ?? 0);
    $si = (int)($job['freq_subday_interval'] ?? 0);
    if ($ft === 4 && $st === 4 && $si > 0) {
        return 'Cada ' . $si . ' minutos (RPO ≤ ' . $si . ' min)';
    }
    if ($ft === 8) {
        return (string)($job['description'] ?: 'Semanal programado');
    }
    if ($ft === 4) {
        return (string)($job['description'] ?: 'Diario programado');
    }
    return (string)($job['schedule_name'] ?: ($job['description'] ?: 'Manual / sin horario'));
}

function format_sql_local_dt(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Nunca ejecutado';
    }
    $str = trim((string)$value);
    $ts = strtotime($str);
    return $ts !== false ? date('d/m/Y, h:i:s a', $ts) : $str;
}

require_once __DIR__ . '/../app/layout.php';
layout_start('respaldos', 'Respaldos y recuperación', 'Generación verificada de copias FULL, Diferencial y Transaction Log (RPO ≤ 15 min), rutas físicas y tareas de SQL Server Agent.');
?>

<?php if ($errorCarga !== null): ?>
    <div class="notification notification-danger mb-4"><?= e($errorCarga) ?></div>
<?php endif; ?>

<section class="panel-box">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title">Estado de protección y política RPO / RTO</h2>
            <p class="panel-box-subtitle">Todos los respaldos se crean con <code>COMPRESSION</code>, <code>CHECKSUM</code> y validación automática <code>RESTORE VERIFYONLY</code>.</p>
        </div>
        <span class="tag <?= strtoupper($modeloRec) === 'FULL' ? 'is-success' : 'is-warning' ?> is-medium">
            Modelo: <?= e($modeloRec) ?>
        </span>
    </div>

    <div class="detail-grid mb-4">
        <div class="detail-field">
            <span>Base de datos activa</span>
            <strong><code><?= e($baseActiva) ?></code></strong>
        </div>
        <div class="detail-field">
            <span>Objetivo RPO (Log)</span>
            <strong>≤ 15 minutos (<code>.trn</code>)</strong>
        </div>
        <div class="detail-field">
            <span>SQL Server Agent</span>
            <strong>
                <?php if ($agentActivo): ?>
                    <span class="status-tag status-tag-success">En ejecución</span>
                <?php else: ?>
                    <span class="status-tag status-tag-warning">Detenido (entorno local)</span>
                <?php endif; ?>
            </strong>
        </div>
        <div class="detail-field">
            <span>Último FULL (<code>.bak</code>)</span>
            <strong><?= e(format_sql_local_dt($estadoDb['ultimo_full'] ?? null)) ?></strong>
        </div>
        <div class="detail-field">
            <span>Último Diferencial (<code>.bak</code>)</span>
            <strong><?= e(format_sql_local_dt($estadoDb['ultimo_diff'] ?? null)) ?></strong>
        </div>
        <div class="detail-field">
            <span>Último Transaction Log (<code>.trn</code>)</span>
            <strong><?= e(format_sql_local_dt($estadoDb['ultimo_log'] ?? null)) ?></strong>
        </div>
    </div>

    <div class="buttons" style="flex-wrap: wrap;">
        <form method="post" action="/respaldos/ejecutar.php" hx-post="/respaldos/ejecutar.php" hx-target="#mensaje-respaldo" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="FULL">
            <button class="button is-primary" type="submit">
                <?= svg_icon('download') ?>
                <span>Generar Backup FULL (.bak)</span>
            </button>
        </form>

        <form method="post" action="/respaldos/ejecutar.php" hx-post="/respaldos/ejecutar.php" hx-target="#mensaje-respaldo" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="DIFF">
            <button class="button button-secondary" type="submit" <?= $puedeDiff ? '' : 'disabled title="Genera primero un Backup FULL base."' ?>>
                <?= svg_icon('download') ?>
                <span>Generar Backup Diferencial (.bak)</span>
            </button>
        </form>

        <form method="post" action="/respaldos/ejecutar.php" hx-post="/respaldos/ejecutar.php" hx-target="#mensaje-respaldo" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="LOG">
            <button class="button button-secondary" type="submit" <?= $puedeLog ? '' : 'disabled title="Requiere modelo de recuperación FULL y al menos un Backup FULL base."' ?>>
                <?= svg_icon('download') ?>
                <span>Generar Backup Log 15m (.trn)</span>
            </button>
        </form>

        <form method="post" action="/respaldos/ejecutar.php" hx-post="/respaldos/ejecutar.php" hx-target="#mensaje-respaldo" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="ETL">
            <button class="button button-secondary" type="submit">
                <?= svg_icon('reports') ?>
                <span>Sincronizar ETL Data Warehouse</span>
            </button>
        </form>
    </div>
    <div id="mensaje-respaldo" class="mt-3" role="alert"></div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title">Historial real de respaldos (<code>msdb.dbo.backupset</code>)</h2>
            <p class="panel-box-subtitle">Últimos 25 archivos generados para <code><?= e($baseActiva) ?></code> con su ruta física exacta en disco.</p>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Fecha y hora</th>
                    <th>Tamaño / Comprimido</th>
                    <th>Integridad</th>
                    <th>Ruta física del archivo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$historial): ?>
                    <tr><td colspan="5"><?= empty_state('Sin respaldos registrados', 'Pulsa «Generar Backup FULL (.bak)» arriba para crear el primer respaldo base verificado.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($historial as $row): ?>
                    <?php
                    $tipoCod = (string)($row['tipo_codigo'] ?? '');
                    $tagCls = $tipoCod === 'D' ? 'is-primary' : ($tipoCod === 'I' ? 'is-info' : 'is-success');
                    ?>
                    <tr>
                        <td><span class="tag <?= $tagCls ?>"><?= e($row['tipo_etiqueta']) ?></span></td>
                        <td><?= e(format_sql_local_dt($row['fecha_fin'] ?? $row['fecha_inicio'] ?? null)) ?></td>
                        <td><?= e((string)$row['tamano_mb']) ?> MB → <strong><?= e((string)$row['comprimido_mb']) ?> MB</strong></td>
                        <td>
                            <?php if (!empty($row['tiene_checksum'])): ?>
                                <span class="status-tag status-tag-success">CHECKSUM OK</span>
                            <?php else: ?>
                                <span class="status-tag status-tag-warning">Sin checksum</span>
                            <?php endif; ?>
                        </td>
                        <td><code style="word-break: break-all; font-size: 0.78rem;"><?= e($row['ruta_fisica']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel-box table-panel">
    <div class="panel-box-header">
        <div>
            <h2 class="panel-box-title">Tareas programadas de SQL Server Agent</h2>
            <p class="panel-box-subtitle">Jobs configurados en <code>msdb.dbo.sysjobs</code> para automatizar respaldos (FULL, DIFF, LOG cada 15 min) y ETL del Data Warehouse.</p>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Estado</th>
                    <th>Frecuencia / Política</th>
                    <th>Descripción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$jobs): ?>
                    <tr><td colspan="4"><?= empty_state('Sin jobs registrados', 'Ejecuta los scripts en database/baseline/06_maintenance_and_jobs/ para registrar los 4 jobs en msdb.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><code><?= e($job['job_name']) ?></code></td>
                        <td><?= status_tag(!empty($job['enabled']) ? 'ACTIVO' : 'INACTIVO') ?></td>
                        <td><strong><?= e(format_job_schedule($job)) ?></strong></td>
                        <td><?= e($job['description'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="notification notification-info py-2 px-3 mb-4">
    <strong>Ruta de almacenamiento en disco (SQL Server):</strong><br>
    <code><?= e($rutaDefault) ?></code>
    <p class="help mt-1 mb-0">
        Política programada: <strong>FULL</strong> domingos 01:00 a. m. · <strong>Diferencial</strong> lunes a sábado 01:00 a. m. · <strong>Transaction Log</strong> cada 15 minutos (<code>.trn</code>) · <strong>ETL Data Warehouse</strong> diario 02:00 a. m.
    </p>
</div>
<?php layout_end(); ?>
