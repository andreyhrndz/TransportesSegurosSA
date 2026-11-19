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
$licenseState = [
    'has_multiple_legacy' => false,
    'has_unverified_only' => false,
    'legacy_codes' => [],
    'selected_code' => '',
    'selected_expiry' => '',
];
if ($entity === 'conductor') {
    $catalogLicencias = master_license_catalog();
    if ($isEdit && isset($id)) {
        $driverLicensesRaw = master_driver_licenses($id);
        $licenseState = master_driver_license_state($driverLicensesRaw);
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
$vehIsNoConforme = ($entity === 'vehiculo' && $isEdit && (string)($record['estado_homologacion'] ?? '') === 'NO_CONFORME');
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-form-maestro">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card <?= $wideModal ? 'modal-card-wide' : '' ?>">
        <?= modal_header('titulo-form-maestro', $titles[$entity]) ?>
        <form hx-post="/flota/guardar.php" hx-target="#mensaje-form-maestro" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]"<?= $entity === 'conductor' ? ' data-conductor-form="1" novalidate' : '' ?><?= $entity === 'vehiculo' ? ' data-vehiculo-form="1"' : '' ?>>
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
                            <div><span>Condición de registro</span><strong>Activo</strong></div>
                        </div>
                        <?php if ($vehIsNoConforme): ?>
                            <div class="notification notification-warning mb-4" role="status">
                                <?= svg_icon('alert-triangle') ?>
                                <div>
                                    <strong>Unidad bloqueada para viajes (No conforme).</strong>
                                    <p class="is-size-7 mt-1">Editar los datos generales no levanta este bloqueo operativo. Si ya se resolvió la incidencia técnica, confirma el levantamiento administrativo a continuación:</p>
                                    <label class="checkbox is-flex is-align-items-center mt-2">
                                        <input type="checkbox" id="veh-levantar-bloqueo" name="levantar_bloqueo_no_conforme" value="1" class="mr-2">
                                        <span class="is-size-7"><strong>Confirmar levantamiento administrativo del bloqueo</strong> (habilitar unidad para operación)</span>
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
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

                    <div class="pma-help-callout mb-4" role="note">
                        <?= svg_icon('shield') ?>
                        <span>Carga útil = mercancía transportable. <strong>PMA</strong> = peso total máximo autorizado del vehículo; se usa para validar la licencia del conductor.</span>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-carga">Capacidad de carga útil (kg) <span class="required">*</span></label>
                                <input class="input" id="veh-carga" name="capacidad_carga_kg" inputmode="decimal" maxlength="15" value="<?= e(isset($record['capacidad_carga_kg']) ? format_number_str($record['capacidad_carga_kg']) : '') ?>" placeholder="Ej. 3500" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-pma">PMA / Peso bruto autorizado (kg) <span class="required">*</span></label>
                                <input class="input" id="veh-pma" name="pma_kg" inputmode="decimal" maxlength="15" value="<?= e(isset($record['pma_kg']) && $record['pma_kg'] !== null ? format_number_str($record['pma_kg']) : '') ?>" placeholder="Ej. 7500" required>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-volumen">Capacidad de volumen (m³)</label>
                                <input class="input" id="veh-volumen" name="capacidad_volumen_m3" inputmode="decimal" maxlength="16" value="<?= e(isset($record['capacidad_volumen_m3']) && $record['capacidad_volumen_m3'] !== null ? format_number_str($record['capacidad_volumen_m3']) : '') ?>" placeholder="Opcional">
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-clase">Clasificación legal <span class="required">*</span></label>
                                <?php $vehClase = (string)($record['clase_legal'] ?? 'LIVIANO'); ?>
                                <select id="veh-clase" name="clase_legal" data-combobox data-allow-clear="false" data-placeholder="Selecciona clasificación…" required>
                                    <option value="LIVIANO" <?= $vehClase === 'LIVIANO' ? 'selected' : '' ?>>Liviano (hasta 4.000 kg)</option>
                                    <option value="MEDIANO_RIGIDO" <?= $vehClase === 'MEDIANO_RIGIDO' ? 'selected' : '' ?>>Mediano rígido (hasta 8.000 kg)</option>
                                    <option value="PESADO_RIGIDO" <?= $vehClase === 'PESADO_RIGIDO' ? 'selected' : '' ?>>Pesado rígido (más de 8.000 kg)</option>
                                    <option value="PESADO_ARTICULADO" <?= $vehClase === 'PESADO_ARTICULADO' ? 'selected' : '' ?>>Pesado articulado (cabezal/tráiler)</option>
                                </select>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="veh-estado">Estado operativo <span class="required">*</span></label>
                                <?php $vehState = (string)($record['estado'] ?? 'DISPONIBLE'); ?>
                                <select id="veh-estado" name="estado" data-combobox data-allow-clear="false" data-placeholder="Selecciona estado operativo…" required>
                                    <option value="DISPONIBLE" <?= $vehState === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
                                    <option value="MANTENIMIENTO" <?= $vehState === 'MANTENIMIENTO' ? 'selected' : '' ?>>En mantenimiento</option>
                                    <option value="FUERA_SERVICIO" <?= $vehState === 'FUERA_SERVICIO' ? 'selected' : '' ?>>Fuera de servicio</option>
                                </select>
                                <p class="help">El estado Asignado se gestiona automáticamente al planificar viajes.</p>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <label class="checkbox is-flex is-align-items-center">
                            <input type="checkbox" id="veh-articulado" name="es_articulado" value="1" class="mr-2" <?= !empty($record['es_articulado']) ? 'checked' : '' ?>>
                            <span><strong>Vehículo articulado</strong> (cabezal con semirremolque)</span>
                        </label>
                        <p class="help">Las unidades articuladas requieren licencia B4 o E2.</p>
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

                    <?php if ($licenseState['has_multiple_legacy']): ?>
                        <div class="notification notification-warning mb-3" role="status" id="aviso-licencias-heredadas">
                            <?= svg_icon('alert-triangle') ?>
                            <span>Este conductor posee varias categorías heredadas (<strong><?= e(implode(', ', $licenseState['legacy_codes'])) ?></strong>). Selecciona la que quedará como principal e ingresa su fecha de vencimiento.</span>
                        </div>
                    <?php elseif ($licenseState['has_unverified_only']): ?>
                        <div class="notification notification-warning mb-3" role="status" id="aviso-licencias-pendientes">
                            <?= svg_icon('alert-triangle') ?>
                            <span>Este conductor no tiene una categoría habilitada vigente. Selecciona la categoría revisada en el documento físico y su fecha de vencimiento.</span>
                        </div>
                    <?php endif; ?>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-licencia">Número de licencia <span class="required">*</span></label>
                                <input class="input" id="cond-licencia" name="numero_licencia" maxlength="50" value="<?= e($record['numero_licencia'] ?? '') ?>" aria-describedby="cond-licencia-help" required>
                                <p class="help" id="cond-licencia-help">Número impreso en el documento físico.</p>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-tipo-licencia">Tipo de licencia <span class="required">*</span></label>
                                <select id="cond-tipo-licencia" name="codigo_licencia" data-combobox data-allow-clear="false" data-placeholder="Selecciona categoría…" data-license-select="1" data-prev-license-code="<?= e($licenseState['selected_code']) ?>" aria-required="true" aria-describedby="cond-tipo-licencia-help cond-tipo-licencia-error">
                                    <option value="">Selecciona categoría…</option>
                                    <?php foreach ($catalogLicencias as $catLic): ?>
                                        <?php $catCod = strtoupper((string)$catLic['codigo']); ?>
                                        <option value="<?= e($catCod) ?>" <?= $licenseState['selected_code'] === $catCod ? 'selected' : '' ?>>
                                            <?= e(master_license_short_label($catCod, (string)$catLic['nombre'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="help" id="cond-tipo-licencia-help">Categoría confirmada al revisar el documento.</p>
                                <p class="help is-danger" id="cond-tipo-licencia-error" role="alert" hidden></p>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="cond-vencimiento">Fecha de vencimiento <span class="required">*</span></label>
                                <input class="input" id="cond-vencimiento" name="fecha_vencimiento_licencia" type="text" data-datepicker="date" min="<?= e($todayUtc) ?>" value="<?= e($isEdit ? $licenseState['selected_expiry'] : '') ?>" placeholder="dd/mm/aaaa" aria-required="true" aria-describedby="cond-vencimiento-help cond-vencimiento-error">
                                <p class="help" id="cond-vencimiento-help">Al cambiar la categoría se limpia para ingresar la nueva vigencia.</p>
                                <p class="help is-danger" id="cond-vencimiento-error" role="alert" hidden></p>
                            </div>
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
                                <?php $condState = (string)($record['estado_operativo'] ?? 'DISPONIBLE'); ?>
                                <select id="cond-estado" name="estado_operativo" data-combobox data-allow-clear="false" data-placeholder="Selecciona estado operativo…" required>
                                    <option value="DISPONIBLE" <?= $condState === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
                                    <option value="INACTIVO" <?= $condState === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                </select>
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
                                <input class="input" id="ruta-distancia" name="distancia_km" inputmode="decimal" maxlength="12" value="<?= e(isset($record['distancia_km']) ? format_number_str($record['distancia_km']) : '') ?>" required>
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
