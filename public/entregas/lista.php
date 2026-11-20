<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/document_storage.php';
header('Cache-Control: no-store');

$section = (string)($_GET['seccion'] ?? '');
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = 10;
$state = strtoupper((string)($_GET['estado'] ?? ''));

try {
    if ($section === 'entregas') {
        require_permission('ENTREGAS_VER');
        $state = in_array($state, ['ENTREGADA', 'FALLIDA'], true) ? $state : null;
        $result = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas @estado=?,@viaje_id=NULL,@desde=NULL,@hasta=NULL,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$state, $page, $perPage]
        );
    } elseif ($section === 'incidencias') {
        require_permission('INCIDENCIAS_VER');
        $state = in_array($state, ['ABIERTA', 'EN_ATENCION', 'RESUELTA'], true) ? $state : null;
        $result = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_incidencias @solicitud_id=NULL,@estado=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$state, $page, $perPage]
        );
    } elseif ($section === 'notificaciones') {
        require_permission('NOTIFICACIONES_VER');
        $state = in_array($state, ['PENDIENTE', 'ENVIADA', 'ERROR'], true) ? $state : null;
        $result = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_notificaciones @solicitud_id=NULL,@estado_envio=?,@desde=NULL,@hasta=NULL,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$state, $page, $perPage]
        );
    } elseif ($section === 'documentos') {
        require_permission('DOCUMENTOS_VER');
        $id = filter_var($_GET['solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            echo empty_state('Selecciona una solicitud', 'Elige una solicitud en el buscador superior para consultar sus documentos asociados.');
            exit;
        }
        $result = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_documentos @solicitud_id=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            [$id, $page, $perPage]
        );
    } else {
        http_response_code(404);
        exit('Sección no encontrada.');
    }
} catch (PDOException) {
    read_error_response();
}

$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable">
        <thead>
            <tr>
                <?php if ($section === 'entregas'): ?>
                    <th>Solicitud</th>
                    <th>Viaje</th>
                    <th>Estado</th>
                    <th>Receptor efectivo</th>
                    <th>Evidencia / Observación</th>
                <?php elseif ($section === 'incidencias'): ?>
                    <th>Solicitud</th>
                    <th>Tipo de incidencia</th>
                    <th>Estado</th>
                    <th>Responsable</th>
                    <th>Fecha de reporte</th>
                    <th class="has-text-right">Acciones</th>
                <?php elseif ($section === 'notificaciones'): ?>
                    <th>Solicitud</th>
                    <th>Evento</th>
                    <th>Estado</th>
                    <th>Asunto</th>
                    <th>Fecha de registro</th>
                <?php else: ?>
                    <th>Tipo de documento</th>
                    <th>Número de referencia</th>
                    <th>Fecha</th>
                    <th>Soporte</th>
                    <th class="has-text-right">Archivo / Enlace</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="6"><?= empty_state('Sin registros', 'No hay información disponible para los filtros seleccionados.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <?php if ($section === 'entregas'): ?>
                        <?php $url = read_safe_reference_url($row['comprobante_url']); ?>
                        <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong><?= e($row['codigo_solicitud']) ?></strong></a></td>
                        <td><a class="table-link" href="/viajes/detalle.php?id=<?= (int)$row['viaje_id'] ?>">#<?= (int)$row['viaje_id'] ?></a></td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= e($row['receptor_nombre'] ?: 'No aplica') ?></td>
                        <td>
                            <?php if ($url): ?>
                                <a class="table-link" target="_blank" rel="noopener noreferrer" href="<?= e($url) ?>">Ver comprobante <?= svg_icon('arrow-up-right') ?></a>
                            <?php else: ?>
                                <?= e($row['motivo_fallo'] ?: 'Sin evidencia') ?>
                            <?php endif; ?>
                        </td>
                    <?php elseif ($section === 'incidencias'): ?>
                        <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong>#<?= (int)$row['solicitud_id'] ?></strong></a></td>
                        <td><?= e($row['tipo_incidencia']) ?></td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= e($row['responsable']) ?></td>
                        <td><?= format_utc_datetime($row['fecha_reporte']) ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($row['estado'] !== 'RESUELTA' && has_permission('INCIDENCIAS_RESOLVER')): ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/entregas/incidencia_form.php?accion=resolver&incidencia_id=<?= (int)$row['incidencia_id'] ?>" hx-target="#modal-contenido">
                                        <?= svg_icon('activate') ?>
                                        <span>Resolver</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php elseif ($section === 'notificaciones'): ?>
                        <td><a class="table-link" href="/solicitudes/detalle.php?id=<?= (int)$row['solicitud_id'] ?>"><strong>#<?= (int)$row['solicitud_id'] ?></strong></a></td>
                        <td><?= e(humanize_status($row['tipo_evento'])) ?></td>
                        <td><?= status_tag($row['estado_envio']) ?></td>
                        <td><?= e($row['asunto']) ?></td>
                        <td><?= format_utc_datetime($row['fecha_intento']) ?></td>
                    <?php else: ?>
                        <?php
                        $origen = strtoupper((string)($row['origen_archivo'] ?? 'EXTERNA'));
                        $esInterno = $origen === 'INTERNA';
                        $url = !$esInterno ? read_safe_reference_url($row['archivo_url'] ?? null) : null;
                        $mimeShort = match (strtolower((string)($row['mime_type'] ?? ''))) {
                            'application/pdf' => 'PDF',
                            'image/jpeg' => 'JPG',
                            'image/png' => 'PNG',
                            default => 'Archivo',
                        };
                        $sizeTxt = isset($row['tamano_bytes']) && (int)$row['tamano_bytes'] > 0
                            ? document_format_bytes((int)$row['tamano_bytes'])
                            : '';
                        ?>
                        <td><strong><?= e($row['tipo_documento']) ?></strong></td>
                        <td><code><?= e($row['numero_referencia'] ?: '—') ?></code></td>
                        <td class="is-nowrap"><?= e(format_cr_date($row['fecha_documento'])) ?></td>
                        <td>
                            <?php if ($esInterno): ?>
                                <?php $nombreVisible = (string)($row['nombre_original'] ?? 'Documento'); ?>
                                <div class="cell-stack">
                                    <div><span class="status-tag status-tag-success">Archivo adjunto</span></div>
                                    <span class="doc-support-meta">
                                        <span class="doc-support-filename" title="<?= e($nombreVisible) ?>"><?= e($nombreVisible) ?></span>
                                        <span class="is-nowrap">· <?= e($mimeShort) ?><?= $sizeTxt !== '' ? ' · ' . e($sizeTxt) : '' ?></span>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span class="status-tag status-tag-info">Enlace externo</span>
                            <?php endif; ?>
                        </td>
                        <td class="has-text-right is-nowrap">
                            <?php if ($esInterno): ?>
                                <a class="button is-small button-secondary" href="/entregas/documento_descargar.php?id=<?= (int)$row['documento_id'] ?>">
                                    <?= svg_icon('download') ?>
                                    <span>Descargar</span>
                                </a>
                            <?php elseif ($url): ?>
                                <a class="table-link is-nowrap" target="_blank" rel="noopener noreferrer" href="<?= e($url) ?>">Abrir referencia <?= svg_icon('arrow-up-right') ?></a>
                            <?php else: ?>
                                <span class="has-text-grey">Sin archivo</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-bar">
    <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#entregas-resultado" hx-include="#entregas-filtros">
        <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/entregas/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/entregas/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
