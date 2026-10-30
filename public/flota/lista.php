<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
header('Cache-Control: no-store');

$section = (string)($_GET['seccion'] ?? '');
$activeFilter = filter_var($_GET['activo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
$soloActivos = $activeFilter === false ? null : $activeFilter;

if ($section === 'catalogos') {
    require_permission('CATALOGOS_VER');
    $canManageCatalogs = has_permission('CATALOGOS_GESTIONAR');
    $search = (string)(read_optional_text($_GET['buscar'] ?? null, 100) ?? '');

    try {
        $mercancias = read_procedure_sets('EXEC dbo.sp_listar_tipos_mercancia @solo_activos=?;', [$soloActivos])[0] ?? [];
        $incidencias = read_procedure_sets('EXEC dbo.sp_listar_tipos_incidencia @solo_activos=?;', [$soloActivos])[0] ?? [];
        $documentos = read_procedure_sets('EXEC dbo.sp_listar_tipos_documento @solo_activos=?;', [$soloActivos])[0] ?? [];
    } catch (PDOException) {
        read_error_response('No se pudieron consultar los catálogos operativos.');
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
    <div class="detail-grid">
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
            <section class="box">
                <div class="catalog-card-head">
                    <h3 class="title is-6"><?= e($panel['title']) ?></h3>
                    <?php if ($canManageCatalogs): ?>
                        <button class="button is-small is-primary" type="button" hx-get="/flota/form.php?entidad=<?= e($panel['entity']) ?>" hx-target="#modal-contenido">
                            <?= svg_icon('plus') ?><span>Nuevo</span>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!$filteredRows): ?>
                    <p class="has-text-grey">Sin registros para los filtros seleccionados.</p>
                <?php else: ?>
                    <ul class="catalog-list">
                        <?php foreach ($filteredRows as $row): ?>
                            <?php $itemId = (int)$row[$panel['id_key']]; ?>
                            <?php $isActive = (int)$row['activo'] === 1; ?>
                            <li class="catalog-item">
                                <div class="catalog-item-meta">
                                    <div>
                                        <strong><?= e($row['nombre']) ?></strong>
                                        <span class="ml-1"><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></span>
                                    </div>
                                    <small><?= e($row['descripcion'] ?: 'Sin descripción') ?></small>
                                </div>
                                <?php if ($canManageCatalogs && $isActive): ?>
                                    <div class="table-actions">
                                        <button class="button is-small button-secondary" type="button" hx-get="/flota/form.php?entidad=<?= e($panel['entity']) ?>&id=<?= $itemId ?>" hx-target="#modal-contenido" aria-label="Editar <?= e($row['nombre']) ?>"><?= svg_icon('edit') ?><span>Editar</span></button>
                                        <button class="button is-small button-secondary" type="button" hx-get="/flota/desactivar_form.php?entidad=<?= e($panel['entity']) ?>&id=<?= $itemId ?>" hx-target="#modal-contenido" aria-label="Desactivar <?= e($row['nombre']) ?>"><?= svg_icon('deactivate') ?><span>Desactivar</span></button>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
$perPage = 10;

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
    read_error_response('No se pudo consultar la sección solicitada.');
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
                        <td><strong><?= e($row['placa']) ?></strong></td>
                        <td><?= e($row['marca'] . ' ' . $row['modelo']) ?><br><small class="has-text-grey">Modelo <?= (int)$row['anio'] ?></small></td>
                        <td>
                            <?= e($row['capacidad_carga_kg']) ?> kg · <?= e($row['capacidad_volumen_m3'] !== null ? $row['capacidad_volumen_m3'] . ' m³' : 'Vol. N/D') ?><br>
                            <small class="has-text-grey"><?= e($row['kilometraje_actual']) ?> km</small>
                        </td>
                        <td><?= status_tag($row['estado']) ?></td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php elseif ($section === 'conductores'): ?>
                        <?php $rowId = (int)$row['conductor_id']; ?>
                        <?php $isAssigned = (string)$row['estado_operativo'] === 'ASIGNADO'; ?>
                        <td><strong><?= e(trim($row['nombre'] . ' ' . $row['primer_apellido'] . ' ' . ($row['segundo_apellido'] ?? ''))) ?></strong></td>
                        <td>
                            <?= e($row['identificacion']) ?><br>
                            <small class="has-text-grey"><?= e($row['telefono'] ?: ($row['correo'] ?: 'Sin contacto')) ?></small>
                        </td>
                        <td>
                            <strong><?= e($row['numero_licencia']) ?></strong><br>
                            <small class="has-text-grey">Vence: <?= e($row['fecha_vencimiento_licencia']) ?></small>
                        </td>
                        <td><?= status_tag($row['estado_operativo']) ?></td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php elseif ($section === 'rutas'): ?>
                        <?php $rowId = (int)$row['ruta_id']; ?>
                        <?php $isAssigned = false; ?>
                        <td><strong><?= e($row['nombre']) ?></strong></td>
                        <td><?= e($row['origen_nombre']) ?></td>
                        <td><?= e($row['destino_nombre']) ?></td>
                        <td><?= e($row['distancia_km']) ?> km · <?= (int)$row['duracion_estimada_min'] ?> min</td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php else: ?>
                        <?php $rowId = (int)$row['ubicacion_id']; ?>
                        <?php $isAssigned = false; ?>
                        <td><strong><?= e($row['nombre']) ?></strong></td>
                        <td><?= e($row['direccion']) ?></td>
                        <td>
                            <?php if ($row['latitud'] !== null && $row['longitud'] !== null): ?>
                                <?= e($row['latitud'] . ', ' . $row['longitud']) ?>
                            <?php else: ?>
                                <span class="has-text-grey">Sin coordenadas</span>
                            <?php endif; ?>
                        </td>
                        <td><?= status_tag($isActive ? 'ACTIVO' : 'INACTIVO') ?></td>
                    <?php endif; ?>

                    <?php if ($canWrite): ?>
                        <td>
                            <div class="table-actions">
                                <?php if (!$isActive): ?>
                                    <span class="has-text-grey is-size-7">Histórico</span>
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
<div class="pagination-wrap">
    <span><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
    <div class="buttons" hx-target="#flota-resultado" hx-include="#flota-filtros">
        <button class="button is-small" type="button" <?= $page <= 1 ? 'disabled' : '' ?> hx-get="/flota/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page - 1 ?>">Anterior</button>
        <span class="pagination-info">Página <?= $page ?> de <?= $pages ?></span>
        <button class="button is-small" type="button" <?= $page >= $pages ? 'disabled' : '' ?> hx-get="/flota/lista.php?seccion=<?= e($section) ?>&pagina=<?= $page + 1 ?>">Siguiente</button>
    </div>
</div>
