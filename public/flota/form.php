<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/master_data.php';

header('Cache-Control: no-store');

try {
    $entity = strtolower(trim((string)($_GET['entidad'] ?? '')));
    $meta = master_entity_meta($entity);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    exit(ui_notice($exception->getMessage(), 'danger'));
}

require_permission($meta['write_permission']);

$idParam = $_GET['id'] ?? null;
$isEdit = $idParam !== null && $idParam !== '';
$record = null;

if ($isEdit) {
    require_permission($meta['read_permission']);
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(404);
        exit(ui_notice('Registro no encontrado.', 'danger'));
    }

    try {
        $record = master_find_record($entity, $id);
    } catch (PDOException) {
        read_error_response('No se pudo cargar el registro solicitado.');
    }

    if (!is_array($record)) {
        http_response_code(404);
        exit(ui_notice('Registro no encontrado.', 'danger'));
    }

    if ((int)$record['activo'] !== 1) {
        http_response_code(409);
        exit(ui_notice('No se puede editar un registro inactivo.', 'warning'));
    }

    if ($entity === 'vehiculo' && (string)$record['estado'] === 'ASIGNADO') {
        http_response_code(409);
        exit(ui_notice('No se puede editar un vehículo mientras está asignado a un viaje.', 'warning'));
    }

    if ($entity === 'conductor' && (string)$record['estado_operativo'] === 'ASIGNADO') {
        http_response_code(409);
        exit(ui_notice('No se puede editar un conductor mientras está asignado a un viaje.', 'warning'));
    }
}

$activeLocations = [];
if ($entity === 'ruta') {
    if (!has_permission('UBICACIONES_VER')) {
        http_response_code(403);
        exit(ui_notice('Se requiere permiso para consultar ubicaciones antes de configurar rutas.', 'danger'));
    }
    try {
        $activeLocations = read_procedure_list(
            'DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
            []
        )['rows'];
    } catch (PDOException) {
        read_error_response('No se pudieron cargar las ubicaciones activas.');
    }
}

$activeUsers = [];
$catalogLicencias = [];
$driverLicenses = [];
if ($entity === 'conductor') {
    $catalogLicencias = master_license_catalog();
    if ($isEdit && isset($id)) {
        $driverLicensesRaw = master_driver_licenses($id);
        foreach ($driverLicensesRaw as $dl) {
            $driverLicenses[$dl['codigo_licencia']] = $dl;
        }
    }
    if (has_permission('USUARIOS_VER')) {
        try {
            $activeUsers = read_procedure_list(
                'DECLARE @total_registros INT; EXEC dbo.sp_listar_usuarios @buscar=NULL,@activo=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
                []
            )['rows'];
        } catch (PDOException) {
            $activeUsers = [];
        }
    }
}

$titles = [
    'vehiculo' => $isEdit ? 'Editar vehículo' : 'Nuevo vehículo',
    'conductor' => $isEdit ? 'Editar conductor' : 'Nuevo conductor',
    'ruta' => $isEdit ? 'Editar ruta' : 'Nueva ruta',
    'ubicacion' => $isEdit ? 'Editar ubicación' : 'Nueva ubicación',
    'tipo_mercancia' => $isEdit ? 'Editar tipo de mercancía' : 'Nuevo tipo de mercancía',
    'tipo_incidencia' => $isEdit ? 'Editar tipo de incidencia' : 'Nuevo tipo de incidencia',
    'tipo_documento' => $isEdit ? 'Editar tipo de documento' : 'Nuevo tipo de documento',
];
$wideModal = in_array($entity, ['vehiculo', 'conductor', 'ruta', 'ubicacion'], true);
$maxYear = (int)gmdate('Y') + 1;
$todayUtc = gmdate('Y-m-d');
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-form-maestro">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card <?= $wideModal ? 'modal-card-wide' : '' ?>">
        <?= modal_header('titulo-form-maestro', $titles[$entity]) ?>
        <form hx-post="/flota/guardar.php" hx-target="#mensaje-form-maestro" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="entidad" value="<?= e($entity) ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= (int)$record[$meta['id_column']] ?>">
                <?php endif; ?>
                <div id="mensaje-form-maestro" role="alert"></div>

                <?php if ($entity === 'vehiculo'): ?>
                    <?php if ($isEdit): ?>
                        <div class="readonly-summary">
                            <div><span>Placa (inmutable)</span><strong><?= e($record['placa']) ?></strong></div>
                            <div><span>Odómetro registrado</span><strong><?= e($record['kilometraje_actual']) ?> km</strong></div>
                        </div>
                    <?php else: ?>
                        <div class="field">
                            <label class="label" for="veh-placa">Placa <span class="required">*</span></label>
                            <input class="input" id="veh-placa" name="placa" maxlength="20" value="" required autofocus>
                        </div>
                    <?php endif; ?>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-marca">Marca <span class="required">*</span></label>
                                <input class="input" id="veh-marca" name="marca" maxlength="80" value="<?= e($record['marca'] ?? '') ?>" required <?= $isEdit ? 'autofocus' : '' ?>>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-modelo">Modelo <span class="required">*</span></label>
                                <input class="input" id="veh-modelo" name="modelo" maxlength="80" value="<?= e($record['modelo'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="column is-3">
                            <div class="field">
                                <label class="label" for="veh-anio">Año <span class="required">*</span></label>
                                <input class="input" id="veh-anio" name="anio" type="number" min="1980" max="<?= $maxYear ?>" value="<?= e($record['anio'] ?? gmdate('Y')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="notification is-info is-light py-3 px-4 mb-4">
                        <p class="is-size-7"><strong>Distinción técnica legal (Ley 9078):</strong> La <em>capacidad de carga</em> corresponde al peso neto transportable de mercancía. El <strong>PMA (Peso Máximo Autorizado)</strong> es la masa total del vehículo cargado (Tara + Capacidad), la cual determina obligatoriamente la habilitación de licencias de conducir: B-1 (&le; 4.000 kg), B-2 (&le; 8.000 kg) o B-3 / B-4 (&gt; 8.000 kg).</p>
                    </div>

                    <div class="notification is-info is-light pma-help-callout mb-3" role="note" style="border-left: 4px solid var(--bulma-info, #2563eb); font-size: 0.875rem;">
                        <p><strong>Distinción legal Ley 9078:</strong> El <em>PMA (Peso Máximo Autorizado)</em> corresponde a la masa total reglamentaria en ficha técnica oficial. La <em>capacidad de carga</em> es el peso neto útil transportable.</p>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-carga">Capacidad carga útil (kg) <span class="required">*</span></label>
                                <input class="input" id="veh-carga" name="capacidad_carga_kg" inputmode="decimal" maxlength="15" value="<?= e($record['capacidad_carga_kg'] ?? '') ?>" required>
                                <p class="help">Peso neto máximo de mercancías admitido.</p>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-pma">PMA / Peso Bruto (kg) <span class="required">*</span></label>
                                <input class="input" id="veh-pma" name="pma_kg" inputmode="decimal" maxlength="15" value="<?= e($record['pma_kg'] ?? '') ?>" placeholder="Ej. 7500.00" required>
                                <p class="help">Masa máxima autorizada en ficha técnica MOPT.</p>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-volumen">Capacidad volumen (m³)</label>
                                <input class="input" id="veh-volumen" name="capacidad_volumen_m3" inputmode="decimal" maxlength="16" value="<?= e($record['capacidad_volumen_m3'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-clase">Clasificación legal <span class="required">*</span></label>
                                <div class="select is-fullwidth">
                                    <?php $vehClase = (string)($record['clase_legal'] ?? 'LIVIANO'); ?>
                                    <select id="veh-clase" name="clase_legal" required>
                                        <option value="LIVIANO" <?= $vehClase === 'LIVIANO' ? 'selected' : '' ?>>Liviano (&le; 4.000 kg PMA)</option>
                                        <option value="MEDIANO_RIGIDO" <?= $vehClase === 'MEDIANO_RIGIDO' ? 'selected' : '' ?>>Mediano rígido (&le; 8.000 kg PMA)</option>
                                        <option value="PESADO_RIGIDO" <?= $vehClase === 'PESADO_RIGIDO' ? 'selected' : '' ?>>Pesado rígido (&gt; 8.000 kg PMA)</option>
                                        <option value="PESADO_ARTICULADO" <?= $vehClase === 'PESADO_ARTICULADO' ? 'selected' : '' ?>>Pesado articulado (cabezal/tráiler)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-homologacion">Estado de homologación <span class="required">*</span></label>
                                <div class="select is-fullwidth">
                                    <?php $vehHomo = (string)($record['estado_homologacion'] ?? 'VERIFICADO'); ?>
                                    <select id="veh-homologacion" name="estado_homologacion" required>
                                        <option value="VERIFICADO" <?= $vehHomo === 'VERIFICADO' ? 'selected' : '' ?>>Verificado (habilitado para viajes)</option>
                                        <option value="PENDIENTE_VERIFICACION" <?= $vehHomo === 'PENDIENTE_VERIFICACION' ? 'selected' : '' ?>>Pendiente de verificación</option>
                                        <option value="NO_CONFORME" <?= $vehHomo === 'NO_CONFORME' ? 'selected' : '' ?>>No conforme</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="field mb-4">
                        <label class="checkbox">
                            <input type="checkbox" id="veh-articulado" name="es_articulado" value="1" <?= !empty($record['es_articulado']) ? 'checked' : '' ?>>
                            <strong>Vehículo articulado o combinación de cabezal con semirremolque</strong>
                        </label>
                        <p class="help">Los vehículos articulados exigen obligatoriamente licencia de conducir Clase B-4 o E-2 según el art. 86 de la Ley 9078.</p>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-km">Kilometraje actual <span class="required">*</span></label>
                                <input class="input" id="veh-km" name="kilometraje_actual" inputmode="decimal" maxlength="14" value="<?= e($record['kilometraje_actual'] ?? '0') ?>" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-estado">Estado operativo <span class="required">*</span></label>
                                <div class="select is-fullwidth">
                                    <?php $vehState = (string)($record['estado'] ?? 'DISPONIBLE'); ?>
                                    <select id="veh-estado" name="estado" required>
                                        <option value="DISPONIBLE" <?= $vehState === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
                                        <option value="MANTENIMIENTO" <?= $vehState === 'MANTENIMIENTO' ? 'selected' : '' ?>>En mantenimiento</option>
                                        <option value="FUERA_SERVICIO" <?= $vehState === 'FUERA_SERVICIO' ? 'selected' : '' ?>>Fuera de servicio</option>
                                    </select>
                                </div>
                                <p class="help">El estado Asignado se actualiza automáticamente desde la planificación de viajes.</p>
                            </div>
                        </div>
                    </div>

                <?php elseif ($entity === 'conductor'): ?>
                    <?php if ($isEdit): ?>
                        <div class="readonly-summary">
                            <div><span>Identificación (inmutable)</span><strong><?= e($record['identificacion']) ?></strong></div>
                            <div><span>Condición de registro</span><strong>Activo</strong></div>
                        </div>
                    <?php else: ?>
                        <div class="field">
                            <label class="label" for="cond-id">Identificación <span class="required">*</span></label>
                            <input class="input" id="cond-id" name="identificacion" maxlength="30" value="" required autofocus>
                        </div>
                    <?php endif; ?>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-nombre">Nombre(s) <span class="required">*</span></label>
                                <input class="input" id="cond-nombre" name="nombre" maxlength="100" value="<?= e($record['nombre'] ?? '') ?>" required <?= $isEdit ? 'autofocus' : '' ?>>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-ap1">Primer apellido <span class="required">*</span></label>
                                <input class="input" id="cond-ap1" name="primer_apellido" maxlength="100" value="<?= e($record['primer_apellido'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-ap2">Segundo apellido</label>
                                <input class="input" id="cond-ap2" name="segundo_apellido" maxlength="100" value="<?= e($record['segundo_apellido'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-licencia">Número de cédula / licencia física <span class="required">*</span></label>
                                <input class="input" id="cond-licencia" name="numero_licencia" maxlength="50" value="<?= e($record['numero_licencia'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-vencimiento">Vencimiento general de licencia <span class="required">*</span></label>
                                <input class="input" id="cond-vencimiento" name="fecha_vencimiento_licencia" type="text" data-datepicker="date" min="<?= e($todayUtc) ?>" value="<?= e($record['fecha_vencimiento_licencia'] ?? '') ?>" placeholder="dd/mm/aaaa" required>
                            </div>
                        </div>
                    </div>

                    <div class="field mb-4">
                        <label class="label">Categorías de licencia habilitadas (Ley de Tránsito 9078) <span class="required">*</span></label>
                        <p class="help mb-3">Selecciona una o más categorías oficiales. El sistema validará en cada viaje que el conductor cuente con categoría verificada y vigente según el PMA del vehículo asignado.</p>
                        <div class="table-container">
                            <table class="table is-fullwidth is-narrow is-bordered license-selector-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;" class="has-text-center">Activa</th>
                                        <th style="width: 220px;">Categoría</th>
                                        <th>PMA y vehículos autorizados</th>
                                        <th style="width: 150px;">Vencimiento</th>
                                        <th style="width: 140px;">Verificación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catalogLicencias as $catLic): ?>
                                        <?php
                                        $catCod = (string)$catLic['codigo'];
                                        $hasCat = isset($driverLicenses[$catCod]);
                                        $catData = $driverLicenses[$catCod] ?? null;
                                        $catVenc = (string)($catData['fecha_vencimiento'] ?? ($record['fecha_vencimiento_licencia'] ?? ''));
                                        $catEst = (string)($catData['estado_verificacion'] ?? 'VERIFICADA');
                                        ?>
                                        <tr>
                                            <td class="has-text-center">
                                                <input type="checkbox" name="licencias_seleccionadas[]" value="<?= e($catCod) ?>" <?= $hasCat ? 'checked' : '' ?> aria-label="Habilitar categoría <?= e($catCod) ?>">
                                            </td>
                                            <td>
                                                <strong class="tag is-dark is-rounded mr-1"><?= e($catCod) ?></strong>
                                                <span class="is-size-7"><?= e($catLic['nombre']) ?></span>
                                            </td>
                                            <td class="is-size-7">
                                                <?= e($catLic['descripcion']) ?>
                                            </td>
                                            <td>
                                                <input class="input is-small" name="licencia_vencimiento[<?= e($catCod) ?>]" type="text" data-datepicker="date" min="<?= e($todayUtc) ?>" value="<?= e($catVenc) ?>" placeholder="dd/mm/aaaa">
                                            </td>
                                            <td>
                                                <div class="select is-small is-fullwidth">
                                                    <select name="licencia_estado[<?= e($catCod) ?>]">
                                                        <option value="VERIFICADA" <?= $catEst === 'VERIFICADA' ? 'selected' : '' ?>>Verificada</option>
                                                        <option value="PENDIENTE" <?= $catEst === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                                                        <option value="RECHAZADA" <?= $catEst === 'RECHAZADA' ? 'selected' : '' ?>>Rechazada</option>
                                                    </select>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-telefono">Teléfono</label>
                                <input class="input" id="cond-telefono" name="telefono" maxlength="30" value="<?= e($record['telefono'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-correo">Correo electrónico</label>
                                <input class="input" id="cond-correo" name="correo" type="email" maxlength="150" value="<?= e($record['correo'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-estado">Estado operativo <span class="required">*</span></label>
                                <div class="select is-fullwidth">
                                    <?php $condState = (string)($record['estado_operativo'] ?? 'DISPONIBLE'); ?>
                                    <select id="cond-estado" name="estado_operativo" required>
                                        <option value="DISPONIBLE" <?= $condState === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
                                        <option value="INACTIVO" <?= $condState === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-usuario">Cuenta de usuario vinculada (opcional)</label>
                                <?php if ($activeUsers): ?>
                                    <?php $selectedUid = (int)($record['usuario_id'] ?? 0); ?>
                                    <select id="cond-usuario" name="usuario_id" data-combobox data-allow-clear="true" data-remote-url="/usuarios/lista.php" data-placeholder="Buscar cuenta de usuario…">
                                        <option value="">Sin cuenta vinculada</option>
                                        <?php foreach ($activeUsers as $u): ?>
                                            <option value="<?= (int)$u['usuario_id'] ?>" data-subtext="<?= e($u['correo_acceso']) ?>" <?= $selectedUid === (int)$u['usuario_id'] ? 'selected' : '' ?>>
                                                <?= e($u['nombre_usuario'] . ' · ' . $u['correo_acceso']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="input" id="cond-usuario" name="usuario_id" type="number" min="1" placeholder="ID opcional" value="<?= e($record['usuario_id'] ?? '') ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($entity === 'ruta'): ?>
                    <div class="field">
                        <label class="label" for="ruta-nombre">Nombre de la ruta <span class="required">*</span></label>
                        <input class="input" id="ruta-nombre" name="nombre" maxlength="150" value="<?= e($record['nombre'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ruta-origen">Ubicación de origen <span class="required">*</span></label>
                                <?php $origId = (int)($record['origen_id'] ?? 0); ?>
                                <select id="ruta-origen" name="origen_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones" data-placeholder="Buscar ubicación de origen…" required>
                                    <option value="">Selecciona ubicación…</option>
                                    <?php foreach ($activeLocations as $loc): ?>
                                        <option value="<?= (int)$loc['ubicacion_id'] ?>" data-subtext="<?= e($loc['direccion'] ?? '') ?>" <?= $origId === (int)$loc['ubicacion_id'] ? 'selected' : '' ?>><?= e($loc['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ruta-destino">Ubicación de destino <span class="required">*</span></label>
                                <?php $destId = (int)($record['destino_id'] ?? 0); ?>
                                <select id="ruta-destino" name="destino_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones" data-placeholder="Buscar ubicación de destino…" required>
                                    <option value="">Selecciona ubicación…</option>
                                    <?php foreach ($activeLocations as $loc): ?>
                                        <option value="<?= (int)$loc['ubicacion_id'] ?>" data-subtext="<?= e($loc['direccion'] ?? '') ?>" <?= $destId === (int)$loc['ubicacion_id'] ? 'selected' : '' ?>><?= e($loc['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ruta-distancia">Distancia (km) <span class="required">*</span></label>
                                <input class="input" id="ruta-distancia" name="distancia_km" inputmode="decimal" maxlength="12" value="<?= e($record['distancia_km'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ruta-duracion">Duración estimada (minutos) <span class="required">*</span></label>
                                <input class="input" id="ruta-duracion" name="duracion_estimada_min" type="number" min="1" max="10080" value="<?= e($record['duracion_estimada_min'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>
                    <?php if ($isEdit): ?>
                        <p class="help">Si la ruta ya cuenta con viajes registrados, se conservarán protegidos su origen y destino históricos.</p>
                    <?php endif; ?>

                <?php elseif ($entity === 'ubicacion'): ?>
                    <div class="field">
                        <label class="label" for="ubi-nombre">Nombre de la ubicación <span class="required">*</span></label>
                        <input class="input" id="ubi-nombre" name="nombre" maxlength="150" value="<?= e($record['nombre'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="field">
                        <label class="label" for="ubi-direccion">Dirección exacta <span class="required">*</span></label>
                        <textarea class="textarea" id="ubi-direccion" name="direccion" rows="2" maxlength="250" required><?= e($record['direccion'] ?? '') ?></textarea>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ubi-latitud">Latitud (-90 a 90)</label>
                                <input class="input" id="ubi-latitud" name="latitud" inputmode="decimal" maxlength="12" placeholder="Ej. 9.934739" value="<?= e($record['latitud'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="ubi-longitud">Longitud (-180 a 180)</label>
                                <input class="input" id="ubi-longitud" name="longitud" inputmode="decimal" maxlength="13" placeholder="Ej. -84.087502" value="<?= e($record['longitud'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <p class="help">Las coordenadas son opcionales, pero si se registran deben completarse ambas.</p>

                <?php else: ?>
                    <div class="field">
                        <label class="label" for="cat-nombre">Nombre <span class="required">*</span></label>
                        <input class="input" id="cat-nombre" name="nombre" maxlength="100" value="<?= e($record['nombre'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="field">
                        <label class="label" for="cat-descripcion">Descripción</label>
                        <textarea class="textarea" id="cat-descripcion" name="descripcion" rows="2" maxlength="250"><?= e($record['descripcion'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </section>
            <?= modal_footer('Guardar', 'is-primary') ?>
        </form>
    </div>
</div>
