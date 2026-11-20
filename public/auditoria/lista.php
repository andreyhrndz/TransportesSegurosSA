<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/audit_presenter.php';
require_permission('AUDITORIA_VER');
header('Cache-Control: no-store');

$pagina = read_page_number($_GET['pagina'] ?? null);
$porPagina = 20;
$usuario = read_optional_text($_GET['usuario'] ?? '', 100);
$entidad = read_optional_text($_GET['entidad'] ?? '', 100);
$accionRaw = read_optional_text($_GET['accion'] ?? '', 20);
$accion = null;
if ($accionRaw !== null) {
    $accionUpper = strtoupper($accionRaw);
    if (preg_match('/^[A-Z0-9_]{1,20}$/', $accionUpper) === 1) {
        $accion = $accionUpper;
    }
}

$desde = read_optional_date($_GET['desde'] ?? null);
$hasta = read_optional_date($_GET['hasta'] ?? null);
if ($desde !== null && $hasta !== null && $desde > $hasta) {
    http_response_code(422);
    exit('<div class="p-4">' . ui_notice('La fecha «Desde» no puede ser posterior a la fecha «Hasta».', 'warning') . '</div>');
}

try {
    $result = read_procedure_list(
        'DECLARE @total_registros INT; '
        . 'EXEC dbo.sp_listar_auditorias '
        . '@entidad=?,@registro_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT,'
        . '@usuario_buscar=?,@accion=?; '
        . 'SELECT @total_registros AS total_registros;',
        [$entidad, $desde, $hasta, $pagina, $porPagina, $usuario, $accion]
    );
} catch (PDOException) {
    read_error_response('No se pudo consultar el historial de actividad.');
}

$rows = $result['rows'];
$total = $result['total'];
$pages = max(1, (int)ceil($total / $porPagina));
$pagina = min($pagina, $pages);
?>
<div class="panel-box-header">
    <div>
        <h2 class="panel-box-title">Eventos registrados</h2>
        <p class="panel-box-subtitle">Incluye acciones auditadas explícitamente por el sistema y triggers operativos. No registra consultas de solo lectura.</p>
    </div>
    <span class="status-tag status-tag-info"><?= $total ?> evento<?= $total === 1 ? '' : 's' ?></span>
</div>

<?php if (!$rows): ?>
    <div class="p-4">
        <?= empty_state('Sin eventos que coincidan', 'Ajusta el usuario, módulo, tipo de acción o rango de fechas para explorar otros registros.') ?>
    </div>
<?php else: ?>
    <div class="table-container">
        <table class="table is-fullwidth is-hoverable">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Usuario</th>
                    <th>Módulo</th>
                    <th>Evento</th>
                    <th>Detalle técnico</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $item = audit_present_row($row); ?>
                    <tr>
                        <td class="is-nowrap"><?= e(format_utc_datetime($row['fecha_hora'])) ?></td>
                        <td>
                            <div class="cell-stack">
                                <strong><?= e($item['usuario_nombre']) ?></strong>
                                <?php if ($item['usuario_nota'] !== ''): ?>
                                    <span><?= e($item['usuario_nota']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e($item['modulo']) ?></td>
                        <td>
                            <div class="cell-stack">
                                <div>
                                    <span class="status-tag status-tag-<?= e($item['accion_tono']) ?>"><?= e($item['accion_etiqueta']) ?></span>
                                </div>
                                <span><?= e($item['descripcion']) ?></span>
                            </div>
                        </td>
                        <td>
                            <details class="audit-tech-details">
                                <summary>Ver soporte (<?= e($item['referencia']) ?>)</summary>
                                <div class="audit-tech-body">
                                    <div><strong>Entidad:</strong> <code><?= e($row['entidad'] ?? '') ?></code></div>
                                    <div><strong>Acción:</strong> <code><?= e($row['accion'] ?? '') ?></code></div>
                                    <div><strong>Registro:</strong> <code><?= e($row['registro_id'] ?? '') ?></code></div>
                                    <?php if ($item['anterior'] !== null): ?>
                                        <div><strong>Anterior:</strong> <span><?= e($item['anterior']) ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($item['nuevo'] !== null): ?>
                                        <div><strong>Nuevo:</strong> <span><?= e($item['nuevo']) ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="pagination-bar">
        <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
        <div class="buttons" hx-target="#auditoria-lista" hx-include="#auditoria-filtros">
            <button class="button is-small button-secondary" type="button" <?= $pagina <= 1 ? 'disabled' : '' ?> hx-get="/auditoria/lista.php?pagina=<?= $pagina - 1 ?>">Anterior</button>
            <span class="pagination-info">Página <?= $pagina ?> de <?= $pages ?></span>
            <button class="button is-small button-secondary" type="button" <?= $pagina >= $pages ? 'disabled' : '' ?> hx-get="/auditoria/lista.php?pagina=<?= $pagina + 1 ?>">Siguiente</button>
        </div>
    </div>
<?php endif; ?>
