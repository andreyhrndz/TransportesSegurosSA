<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/master_data.php';

try {
    $entity = strtolower(trim((string)($_POST['entidad'] ?? '')));
    $meta = master_entity_meta($entity);
} catch (InvalidArgumentException $exception) {
    require_login();
    operation_error($exception->getMessage(), 422);
}

// ASVS 3.5.1, 8.2.1: exige permiso individual específico de la entidad, método POST y CSRF válido.
require_operation_post($meta['write_permission']);

$idParam = $_POST['id'] ?? null;
$isEdit = $idParam !== null && $idParam !== '';
$maxYear = (int)gmdate('Y') + 1;

$codigoLicencia = '';
$licencia = '';
$vencimiento = '';

try {
    $recordId = $isEdit ? operation_id($idParam) : 0;

    switch ($entity) {
        case 'vehiculo':
            $marca = (string)operation_text($_POST['marca'] ?? null, 80);
            $modelo = (string)operation_text($_POST['modelo'] ?? null, 80);
            $anio = (int)master_int($_POST['anio'] ?? null, 1980, $maxYear, true, 'año');
            $carga = (string)master_decimal($_POST['capacidad_carga_kg'] ?? null, 0.0, 9999999999.99, true, 'capacidad de carga útil', true);

            $pmaRaw = trim((string)($_POST['pma_kg'] ?? ''));
            if ($pmaRaw === '') {
                throw new InvalidArgumentException('Indica el PMA / peso bruto autorizado del vehículo.');
            }
            $pma = (string)master_decimal($pmaRaw, 0.0, 9999999999.99, true, 'PMA / peso bruto autorizado', true);

            $volumen = master_decimal($_POST['capacidad_volumen_m3'] ?? null, 0.0, 999999999.999, false, 'capacidad de volumen', true);

            // Odómetro retirado de la UI: en alta inicia en 0; en edición preserva el valor almacenado en BD si se omite.
            $kmProvided = isset($_POST['kilometraje_actual']) && trim((string)$_POST['kilometraje_actual']) !== '';
            $km = $kmProvided
                ? (string)master_decimal($_POST['kilometraje_actual'], 0.0, 99999999999.9, true, 'kilometraje actual')
                : ($isEdit ? null : '0');

            $claseLegal = strtoupper(trim((string)($_POST['clase_legal'] ?? '')));
            if (!in_array($claseLegal, ['LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'], true)) {
                throw new InvalidArgumentException('Selecciona una clasificación legal válida.');
            }
            $esArticulado = !empty($_POST['es_articulado']) ? 1 : 0;
            master_validate_vehicle_coherence((float)$carga, (float)$pma, $claseLegal, $esArticulado === 1);

            $estado = strtoupper(trim((string)($_POST['estado'] ?? 'DISPONIBLE')));
            if (!in_array($estado, ['DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO'], true)) {
                throw new InvalidArgumentException('Selecciona un estado operativo permitido para el vehículo.');
            }

            // ASVS 2.3.1, 8.2.1: nunca aceptar promoción arbitraria de estado_homologacion desde POST ordinario.
            // En alta se registra PENDIENTE_VERIFICACION (no simula homologación oficial MOPT);
            // en edición se envía NULL para preservar el valor existente (incluido NO_CONFORME),
            // salvo levantamiento administrativo explícito sobre una unidad bloqueada.
            if ($isEdit) {
                $estadoHomologacion = null;
                if (!empty($_POST['levantar_bloqueo_no_conforme'])) {
                    $existingVehicle = master_find_record('vehiculo', $recordId);
                    if (is_array($existingVehicle) && (string)($existingVehicle['estado_homologacion'] ?? '') === 'NO_CONFORME') {
                        $estadoHomologacion = 'PENDIENTE_VERIFICACION';
                    }
                }
                $sql = 'EXEC dbo.sp_actualizar_vehiculo @vehiculo_id=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=?, @kilometraje_actual=?, @estado=?, @pma_kg=?, @clase_legal=?, @es_articulado=?, @estado_homologacion=?;';
                $params = [$recordId, $marca, $modelo, $anio, $carga, $volumen, $km, $estado, $pma, $claseLegal, $esArticulado, $estadoHomologacion];
            } else {
                $placa = strtoupper((string)operation_text($_POST['placa'] ?? null, 20));
                $estadoHomologacion = 'PENDIENTE_VERIFICACION';
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_vehiculo @placa=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=?, @kilometraje_actual=?, @estado=?, @pma_kg=?, @clase_legal=?, @es_articulado=?, @estado_homologacion=?, @vehiculo_id=@nuevo_id OUTPUT;';
                $params = [$placa, $marca, $modelo, $anio, $carga, $volumen, $km, $estado, $pma, $claseLegal, $esArticulado, $estadoHomologacion];
            }
            break;

        case 'conductor':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
            $ap1 = (string)operation_text($_POST['primer_apellido'] ?? null, 100);
            $ap2 = operation_text($_POST['segundo_apellido'] ?? null, 100, false);
            $telefono = operation_text($_POST['telefono'] ?? null, 30, false);
            $correo = operation_email($_POST['correo'] ?? null, 150, false);
            $licencia = strtoupper((string)operation_text($_POST['numero_licencia'] ?? null, 50));

            // ASVS 2.2.1, 2.3.1: categoría única explícita validada contra catálogo activo; sin B4 por defecto ni estado libre desde POST.
            $codigoLicencia = strtoupper(trim((string)($_POST['codigo_licencia'] ?? '')));
            if ($codigoLicencia === '') {
                throw new InvalidArgumentException('Selecciona el tipo de licencia del conductor.');
            }
            $activeCatalogCodes = array_map(
                static fn(array $row): string => strtoupper(trim((string)($row['codigo'] ?? ''))),
                master_license_catalog()
            );
            if (!in_array($codigoLicencia, $activeCatalogCodes, true)) {
                throw new InvalidArgumentException('La categoría de licencia seleccionada no es válida o no está activa.');
            }

            $vencimiento = master_license_date($_POST['fecha_vencimiento_licencia'] ?? null);

            $estadoOp = strtoupper(trim((string)($_POST['estado_operativo'] ?? 'DISPONIBLE')));
            if (!in_array($estadoOp, ['DISPONIBLE', 'INACTIVO'], true)) {
                throw new InvalidArgumentException('Selecciona un estado operativo permitido para el conductor.');
            }
            $usuarioId = master_int($_POST['usuario_id'] ?? null, 1, 2147483647, false, 'cuenta de usuario');

            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_conductor @conductor_id=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @telefono=?, @correo=?, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=?, @usuario_id=?;';
                $params = [$recordId, $nombre, $ap1, $ap2, $telefono, $correo, $licencia, $vencimiento, $estadoOp, $usuarioId];
            } else {
                $identificacion = (string)operation_text($_POST['identificacion'] ?? null, 30);
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @telefono=?, @correo=?, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=?, @usuario_id=?, @codigo_licencia_inicial=?, @conductor_id=@nuevo_id OUTPUT; SELECT @nuevo_id AS nuevo_id;';
                $params = [$identificacion, $nombre, $ap1, $ap2, $telefono, $correo, $licencia, $vencimiento, $estadoOp, $usuarioId, $codigoLicencia];
            }
            break;

        case 'ruta':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 150);
            $origenId = operation_id($_POST['origen_id'] ?? null);
            $destinoId = operation_id($_POST['destino_id'] ?? null);
            if ($origenId === $destinoId) {
                throw new InvalidArgumentException('La ubicación de origen y la de destino deben ser distintas.');
            }
            $distancia = (string)master_decimal($_POST['distancia_km'] ?? null, 0.0, 99999999.99, true, 'distancia en km', true);
            $duracion = (int)master_int($_POST['duracion_estimada_min'] ?? null, 1, 10080, true, 'duración estimada');

            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_ruta @ruta_id=?, @nombre=?, @origen_id=?, @destino_id=?, @distancia_km=?, @duracion_estimada_min=?;';
                $params = [$recordId, $nombre, $origenId, $destinoId, $distancia, $duracion];
            } else {
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_ruta @nombre=?, @origen_id=?, @destino_id=?, @distancia_km=?, @duracion_estimada_min=?, @ruta_id=@nuevo_id OUTPUT;';
                $params = [$nombre, $origenId, $destinoId, $distancia, $duracion];
            }
            break;

        case 'ubicacion':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 150);
            $direccion = (string)operation_text($_POST['direccion'] ?? null, 250);
            [$lat, $lon] = master_coordinates($_POST['latitud'] ?? null, $_POST['longitud'] ?? null);

            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_ubicacion @ubicacion_id=?, @nombre=?, @direccion=?, @latitud=?, @longitud=?;';
                $params = [$recordId, $nombre, $direccion, $lat, $lon];
            } else {
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_ubicacion @nombre=?, @direccion=?, @latitud=?, @longitud=?, @ubicacion_id=@nuevo_id OUTPUT;';
                $params = [$nombre, $direccion, $lat, $lon];
            }
            break;

        case 'tipo_mercancia':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
            $descripcion = operation_text($_POST['descripcion'] ?? null, 250, false);
            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_tipo_mercancia @tipo_mercancia_id=?, @nombre=?, @descripcion=?;';
                $params = [$recordId, $nombre, $descripcion];
            } else {
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_tipo_mercancia @nombre=?, @descripcion=?, @tipo_mercancia_id=@nuevo_id OUTPUT;';
                $params = [$nombre, $descripcion];
            }
            break;

        case 'tipo_incidencia':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
            $descripcion = operation_text($_POST['descripcion'] ?? null, 250, false);
            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_tipo_incidencia @tipo_incidencia_id=?, @nombre=?, @descripcion=?;';
                $params = [$recordId, $nombre, $descripcion];
            } else {
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_tipo_incidencia @nombre=?, @descripcion=?, @tipo_incidencia_id=@nuevo_id OUTPUT;';
                $params = [$nombre, $descripcion];
            }
            break;

        case 'tipo_documento':
            $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
            $descripcion = operation_text($_POST['descripcion'] ?? null, 250, false);
            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_tipo_documento @tipo_documento_id=?, @nombre=?, @descripcion=?;';
                $params = [$recordId, $nombre, $descripcion];
            } else {
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_tipo_documento @nombre=?, @descripcion=?, @tipo_documento_id=@nuevo_id OUTPUT;';
                $params = [$nombre, $descripcion];
            }
            break;

        default:
            throw new InvalidArgumentException('Tipo de registro no reconocido.');
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

$pdo = db();
$useAtomicDriverTx = ($entity === 'conductor' && $isEdit);

try {
    if ($useAtomicDriverTx) {
        $pdo->beginTransaction();
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();

    if ($useAtomicDriverTx) {
        // Contrato atómico: sustituye categorías previas para evitar habilitaciones ocultas y sincroniza dbo.conductores.
        $stmtLic = $pdo->prepare(
            'EXEC dbo.sp_actualizar_licencia_principal_conductor @conductor_id=?, @codigo_licencia=?, @numero_licencia=?, @fecha_vencimiento=?, @observaciones=NULL;'
        );
        $stmtLic->execute([$recordId, $codigoLicencia, $licencia, $vencimiento]);
        $stmtLic->closeCursor();
        $pdo->commit();
    }
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    operation_sql_error($exception, [
        50501 => 'No tienes permiso para gestionar este registro.',
        50502 => 'Revisa los valores ingresados (carga útil y PMA coherentes, categoría y vigencia de licencia o coordenadas).',
        50503 => 'El registro solicitado no existe.',
        50504 => 'Ya existe un registro con esos datos únicos (placa, identificación, licencia, usuario o nombre).',
        50505 => 'No se puede modificar el registro porque participa en viajes activos o tiene referencias históricas protegidas.',
        50506 => 'No se puede editar un registro inactivo.',
        50507 => 'Las referencias seleccionadas (origen, destino o cuenta de usuario) no existen o están inactivas.',
    ]);
}

$label = utf8_ucfirst($meta['label']);
$message = $isEdit ? "{$label} actualizado correctamente." : "{$label} registrado correctamente.";
operation_modal_success($message, 'maestroActualizado', '/flota.php?seccion=' . $meta['section']);
