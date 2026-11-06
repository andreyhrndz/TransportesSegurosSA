<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
header('Cache-Control: no-store');

$isJson = ($_GET['formato'] ?? '') === 'json';
$section = (string)($_GET['seccion'] ?? '');
$activeFilter = filter_var($_GET['activo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
$soloActivos = $activeFilter === false ? ($isJson ? 1 : null) : $activeFilter;

if ($section === 'catalogos') {
    require_permission('CATALOGOS_VER');
    $canManageCatalogs = has_permission('CATALOGOS_GESTIONAR');
    $search = (string)(read_optional_text($_GET['buscar'] ?? null, 100) ?? '');

    try {
        $mercancias = read_procedure_sets('EXEC dbo.sp_listar_tipos_mercancia @solo_activos=?;', [$soloActivos])[0] ?? [];
        $incidencias = read_procedure_sets('EXEC dbo.sp_listar_tipos_incidencia @solo_activos=?;', [$soloActivos])[0] ?? [];
        $documentos = read_procedure_sets('EXEC dbo.sp_listar_tipos_documento @solo_activos=?;', [$soloActivos])[0] ?? [];
    } catch (PDOException) {
        if ($isJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo '[]';
            exit;
        }
        read_error_response('No se pudieron consultar los catálogos operativos.');
    }

    if ($isJson) {
        header('Content-Type: application/json; charset=UTF-8');
        $tipo = (string)($_GET['tipo'] ?? 'mercancia');
        $source = match ($tipo) {
            'incidencia' => ['rows' => $incidencias, 'key' => 'tipo_incidencia_id'],
            'documento' => ['rows' => $documentos, 'key' => 'tipo_documento_id'],
            default => ['rows' => $mercancias, 'key' => 'tipo_mercancia_id'],
        };
        $options = [];
        foreach ($source['rows'] as $row) {
            if ($search !== '' && stripos((string)$row['nombre'] . ' ' . (string)($row['descripcion'] ?? ''), $search) === false) {
                continue;
            }
            $options[] = [
                'value' => (string)(int)$row[$source['key']],
                'text' => (string)$row['nombre'],
                'subtext' => (string)($row['descripcion'] ?? ''),
            ];
        }
        echo json_encode($options, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $catalogPanels = [
        [
            'title' => 'Tipos de mercancía',
            'entity' => 'tipo_mercancia',
            'id_key' => 'tipo_mercancia_id',
            'rows' => $mercancias,
        ],
        [
            'title' => 'Tipos de incidencia',
            'entity' => 'tipo_incidencia',
            'id_key' => 'tipo_incidencia_id',
            'rows' => $incidencias,
        ],
        [
            'title' => 'Tipos de documento',
            'entity' => 'tipo_documento',
            'id_key' => 'tipo_documento_id',
            'rows' => $documentos,
        ],
    ];
    ?>
    <div class="catalog-grid detail-grid">
        <?php foreach ($catalogPanels as $panel): ?>
            <?php
            $filteredRows = $panel['rows'];
            if ($search !== '') {
                $pattern = '/' . preg_quote($search, '/') . '/iu';
                $filteredRows = array_values(array_filter(
                    $filteredRows,
                    static fn (array $r): bool => preg_match($pattern, (string)$r['nombre'] . ' ' . (string)($r['descripcion'] ?? '')) === 1
                ));
            }
            ?>
            <section class="panel-box catalog-card">
                <div class="panel-box-header catalog-card-header">
                    <h3 class="panel-box-title catalog-card-title"><?= e($panel['title']) ?></h3>
                    <?php if ($canManageCatalogs): ?>
                        <button class="button is-small is-primary" type="button" hx-get="/flota/form.php?entidad=<?= e($panel['entity']) ?>" hx-target="#modal-contenido">
                            <?= svg_icon('plus') ?><span>Nuevo</span>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!$filteredRows): ?>
                    <p class="has-text-grey p-4">Sin registros para los filtros seleccionados.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table is-fullwidth is-hoverable catalog-table">
                            <tbody>
                                <?php foreach ($filteredRows as $row): ?>
                                    <?php $itemId = (int)$row[$panel['id_key']]; ?>
                                    <?php $isActive = (int)$row['activo'] === 1; ?>
                                    <tr>
                                        <td class="catalog-info-cell">
                                            <div class="cell-stack">
                                                <div class="catalog-item-title-row">
                                                    <strong class="catalog-item-name"><?= e($row['nombre']) ?></strong>
                                                    <span class="ml-2"><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></span>
                                                </div>
                                                <span class="catalog-item-desc"><?= e($row['descripcion'] ?: 'Sin descripción') ?></span>
                                            </div>
                                        </td>
                                        <?php if ($canManageCatalogs && $isActive): ?>
                                            <td class="catalog-actions-cell">
                                                <div class="row-actions">
                                                    <button class="button is-small button-secondary" type="button" hx-get="/flota/form.php?entidad=<?= e($panel['entity']) ?>&id=<?= $itemId ?>" hx-target="#modal-contenido" aria-label="Editar <?= e($row['nombre']) ?>"><?= svg_icon('edit') ?><span>Editar</span></button>
                                                    <button class="button is-small button-secondary" type="button" hx-get="/flota/desactivar_form.php?entidad=<?= e($panel['entity']) ?>&id=<?= $itemId ?>" hx-target="#modal-contenido" aria-label="Desactivar <?= e($row['nombre']) ?>"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
    exit;
}

$configs = [
    'vehiculos' => [
        'read' => 'FLOTAS_VER',
        'write' => 'FLOTAS_GESTIONAR',
        'entity' => 'vehiculo',
        'sql' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=?,@solo_activos=?,@buscar=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'headers' => ['Placa', 'Vehículo', 'Capacidad y odómetro', 'Estado operativo', 'Registro'],
    ],
    'conductores' => [
        'read' => 'FLOTAS_VER',
        'write' => 'FLOTAS_GESTIONAR',
        'entity' => 'conductor',
        'sql' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_conductores @estado=?,@solo_activos=?,@buscar=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'headers' => ['Conductor', 'Identificación y contacto', 'Licencia y vigencia', 'Estado operativo', 'Registro'],
    ],
    'rutas' => [
        'read' => 'RUTAS_VER',
        'write' => 'RUTAS_GESTIONAR',
        'entity' => 'ruta',
        'sql' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_rutas @origen_id=NULL,@destino_id=NULL,@solo_activas=?,@buscar=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'headers' => ['Ruta', 'Origen', 'Destino', 'Distancia y duración', 'Registro'],
    ],
    'ubicaciones' => [
        'read' => 'UBICACIONES_VER',
        'write' => 'UBICACIONES_GESTIONAR',
        'entity' => 'ubicacion',
        'sql' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=?,@solo_activos=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'headers' => ['Ubicación', 'Dirección', 'Coordenadas', 'Registro'],
    ],
];

if (!isset($configs[$section])) {
    http_response_code(404);
    exit('Sección no encontrada.');
}

$cfg = $configs[$section];
require_permission($cfg['read']);
$canWrite = has_permission($cfg['write']);

$search = read_optional_text($_GET['buscar'] ?? null, $section === 'vehiculos' ? 80 : 120);
$page = read_page_number($_GET['pagina'] ?? null);
$perPage = $isJson ? 25 : 10;

$allowedStates = $section === 'vehiculos'
    ? ['DISPONIBLE', 'ASIGNADO', 'MANTENIMIENTO', 'FUERA_SERVICIO']
    : ['DISPONIBLE', 'ASIGNADO', 'INACTIVO'];
$rawState = strtoupper(trim((string)($_GET['estado'] ?? '')));
$state = in_array($rawState, $allowedStates, true) ? $rawState : null;

try {
    $params = match ($section) {
        'vehiculos', 'conductores' => [$state, $soloActivos, $search, $page, $perPage],
        'rutas' => [$soloActivos, $search, $page, $perPage],
        'ubicaciones' => [$search, $soloActivos, $page, $perPage],
    };
    $result = read_procedure_list($cfg['sql'], $params);
} catch (PDOException) {
    if ($isJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo '[]';
        exit;
    }
    read_error_response('No se pudo consultar la sección solicitada.');
}

if ($isJson) {
    header('Content-Type: application/json; charset=UTF-8');
    $options = [];
    foreach ($result['rows'] as $row) {
        if ($section === 'vehiculos') {
            $pmaVal = $row['pma_kg'] !== null ? format_number_str($row['pma_kg']) : format_number_str($row['capacidad_carga_kg']);
            $options[] = [
                'value' => (string)(int)$row['vehiculo_id'],
                'text' => (string)$row['placa'] . ' · ' . (string)$row['marca'] . ' ' . (string)$row['modelo'],
                'subtext' => 'PMA ' . $pmaVal . ' kg · ' . humanize_status($row['estado']) . (!empty($row['es_articulado']) ? ' (Articulado)' : ''),
                'pma' => (string)($row['pma_kg'] ?? ''),
                'articulado' => (string)(int)($row['es_articulado'] ?? 0),
                'clase' => (string)($row['clase_legal'] ?? ''),
            ];
        } elseif ($section === 'conductores') {
            $fullName = trim((string)$row['nombre'] . ' ' . (string)$row['primer_apellido'] . ' ' . (string)($row['segundo_apellido'] ?? ''));
            $lics = !empty($row['licencias_verificadas']) ? (string)$row['licencias_verificadas'] : ('Lic. ' . (string)$row['numero_licencia']);
            $options[] = [
                'value' => (string)(int)$row['conductor_id'],
                'text' => $fullName,
                'subtext' => $lics . ' · Vence ' . format_cr_date($row['fecha_vencimiento_licencia']),
                'licencias' => (string)($row['licencias_verificadas'] ?? ''),
                'vencimiento' => (string)($row['fecha_vencimiento_licencia'] ?? ''),
            ];
        } elseif ($section === 'rutas') {
            $options[] = [
                'value' => (string)(int)$row['ruta_id'],
                'text' => (string)$row['nombre'],
                'subtext' => (string)$row['origen_nombre'] . ' → ' . (string)$row['destino_nombre'],
            ];
        } else {
            $options[] = [
                'value' => (string)(int)$row['ubicacion_id'],
                'text' => (string)$row['nombre'],
                'subtext' => (string)($row['direccion'] ?? ''),
            ];
        }
    }
    echo json_encode($options, JSON_UNESCAPED_UNICODE);
    exit;
}

$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$colCount = count($cfg['headers']) + ($canWrite ? 1 : 0);
?>
<div class="table-container">
    <table class="table is-fullwidth is-hoverable">
        <thead>
            <tr>
                <?php foreach ($cfg['headers'] as $header): ?>
                    <th><?= e($header) ?></th>
                <?php endforeach; ?>
                <?php if ($canWrite): ?><th class="has-text-right">Acciones</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$result['rows']): ?>
                <tr>
                    <td colspan="<?= $colCount ?>"><?= empty_state('Sin registros', 'No hay resultados para los filtros seleccionados.') ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row): ?>
                <?php $isActive = (int)$row['activo'] === 1; ?>
                <tr>
                    <?php if ($section === 'vehiculos'): ?>
                        <?php $rowId = (int)$row['vehiculo_id']; ?>
                        <?php $isAssigned = (string)$row['estado'] === 'ASIGNADO'; ?>
                        <td><code><?= e($row['placa']) ?></code></td>
                        <td>
                            <div class="cell-stack">
                                <strong><?= e($row['marca'] . ' ' . $row['modelo']) ?></strong>
                                <span class="is-size-7">Modelo <?= (int)$row['anio'] ?> · <?= e($row['clase_legal'] ?? 'LIVIANO') ?><?= !empty($row['es_articulado']) ? ' · Articulado' : '' ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="cell-stack">
                                <strong>Útil: <?= e(format_number_str($row['capacidad_carga_kg'])) ?> kg · PMA: <?= e(format_number_str($row['pma_kg'] ?? $row['capacidad_carga_kg'])) ?> kg</strong>
                                <span class="is-size-7"><?= e(format_number_str($row['kilometraje_actual'])) ?> km · Homol: <?= status_tag($row['estado_homologacion'] ?? 'VERIFICADO') ?></span>
                            </div>
                        </td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php elseif ($section === 'conductores'): ?>
                        <?php $rowId = (int)$row['conductor_id']; ?>
                        <?php $isAssigned = (string)$row['estado_operativo'] === 'ASIGNADO'; ?>
                        <td><strong><?= e(trim($row['nombre'] . ' ' . $row['primer_apellido'] . ' ' . ($row['segundo_apellido'] ?? ''))) ?></strong></td>
                        <td>
                            <div class="cell-stack">
                                <strong><?= e($row['identificacion']) ?></strong>
                                <span><?= e($row['telefono'] ?: ($row['correo'] ?: 'Sin contacto')) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="cell-stack">
                                <div class="is-flex is-align-items-center is-flex-wrap-wrap mb-1">
                                    <span class="has-text-weight-semibold">N° <?= e($row['numero_licencia']) ?></span>
                                    <?php
                                    $licBadges = array_filter(array_map('trim', explode(',', (string)($row['licencias_verificadas'] ?? ''))));
                                    foreach ($licBadges as $lb):
                                    ?>
                                        <span class="tag is-primary is-light is-rounded ml-1"><?= e($lb) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <span class="is-size-7">Vence: <?= e(format_cr_date($row['fecha_vencimiento_licencia'])) ?></span>
                            </div>
                        </td>
                        <td><?= status_tag($row['estado_operativo']) ?></td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php elseif ($section === 'rutas'): ?>
                        <?php $rowId = (int)$row['ruta_id']; ?>
                        <?php $isAssigned = false; ?>
                        <td><strong><?= e($row['nombre']) ?></strong></td>
                        <td><?= e($row['origen_nombre']) ?></td>
                        <td><?= e($row['destino_nombre']) ?></td>
                        <td><?= e(format_number_str($row['distancia_km'])) ?> km · <?= (int)$row['duracion_estimada_min'] ?> min</td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php else: ?>
                        <?php $rowId = (int)$row['ubicacion_id']; ?>
                        <?php $isAssigned = false; ?>
                        <td><strong><?= e($row['nombre']) ?></strong></td>
                        <td><?= e($row['direccion']) ?></td>
                        <td>
                            <?php if ($row['latitud'] !== null && $row['longitud'] !== null): ?>
                                <code><?= e($row['latitud'] . ', ' . $row['longitud']) ?></code>
                            <?php else: ?>
                                <span class="has-text-grey">Sin coordenadas</span>
                            <?php endif; ?>
                        </td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php endif; ?>

                    <?php if ($canWrite): ?>
                        <td>
                            <div class="row-actions">
                                <?php if (!$isActive): ?>
                                    <span class="has-text-grey">Histórico</span>
                                <?php elseif ($isAssigned): ?>
                                    <span class="status-tag status-tag-info">En viaje</span>
                                <?php else: ?>
                                    <button class="button is-small button-secondary" type="button" hx-get="/flota/form.php?entidad=<?= e($cfg['entity']) ?>&id=<?= $rowId ?>" hx-target="#modal-contenido"><?= svg_icon('edit') ?><span>Editar</span></button>
                                    <button class="button is-small button-secondary" type="button" hx-get="/flota/desactivar_form.php?entidad=<?= e($cfg['entity']) ?>&id=<?= $rowId ?>" hx-target="#modal-contenido"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="pagination-bar">
    <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#flota-resultado" hx-include="#flota-filtros">
        <button class="button is-small button-secondary" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/flota/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small button-secondary" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/flota/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
