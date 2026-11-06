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

try {
    $recordId = $isEdit ? operation_id($idParam) : 0;

    switch ($entity) {
        case 'vehiculo':
            $marca = (string)operation_text($_POST['marca'] ?? null, 80);
            $modelo = (string)operation_text($_POST['modelo'] ?? null, 80);
            $anio = (int)master_int($_POST['anio'] ?? null, 1980, $maxYear, true, 'año');
            $carga = (string)master_decimal($_POST['capacidad_carga_kg'] ?? null, 0.0, 9999999999.99, true, 'capacidad de carga útil', true);
            $pmaRaw = trim((string)($_POST['pma_kg'] ?? ''));
            $pma = (string)master_decimal($pmaRaw !== '' ? $pmaRaw : $carga, 0.0, 9999999999.99, true, 'PMA / peso bruto vehicular', true);
            $volumen = master_decimal($_POST['capacidad_volumen_m3'] ?? null, 0.0, 999999999.999, false, 'capacidad de volumen', true);
            $km = (string)master_decimal($_POST['kilometraje_actual'] ?? '0', 0.0, 99999999999.9, true, 'kilometraje actual');
            $claseLegal = strtoupper(trim((string)($_POST['clase_legal'] ?? '')));
            if ($claseLegal === '') {
                $claseLegal = ((float)$pma > 8000 ? 'PESADO_RIGIDO' : ((float)$pma > 4000 ? 'MEDIANO_RIGIDO' : 'LIVIANO'));
            }
            if (!in_array($claseLegal, ['LIVIANO', 'MEDIANO_RIGIDO', 'PESADO_RIGIDO', 'PESADO_ARTICULADO'], true)) {
                throw new InvalidArgumentException('Selecciona una clasificación legal válida.');
            }
            $esArticulado = !empty($_POST['es_articulado']) ? 1 : 0;
            $estadoHomologacion = strtoupper(trim((string)($_POST['estado_homologacion'] ?? 'VERIFICADO')));
            if (!in_array($estadoHomologacion, ['VERIFICADO', 'PENDIENTE_VERIFICACION', 'NO_CONFORME'], true)) {
                throw new InvalidArgumentException('Selecciona un estado de homologación válido.');
            }
            $estado = strtoupper(trim((string)($_POST['estado'] ?? 'DISPONIBLE')));
            if (!in_array($estado, ['DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO'], true)) {
                throw new InvalidArgumentException('Selecciona un estado operativo permitido para el vehículo.');
            }
            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_vehiculo @vehiculo_id=?, @marca=?, @modelo=?, @anio=?, @capacidad_carga_kg=?, @capacidad_volumen_m3=?, @kilometraje_actual=?, @estado=?, @pma_kg=?, @clase_legal=?, @es_articulado=?, @estado_homologacion=?;';
                $params = [$recordId, $marca, $modelo, $anio, $carga, $volumen, $km, $estado, $pma, $claseLegal, $esArticulado, $estadoHomologacion];
            } else {
                $placa = strtoupper((string)operation_text($_POST['placa'] ?? null, 20));
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
            $vencimiento = master_license_date($_POST['fecha_vencimiento_licencia'] ?? null);
            $estadoOp = strtoupper(trim((string)($_POST['estado_operativo'] ?? 'DISPONIBLE')));
            if (!in_array($estadoOp, ['DISPONIBLE', 'INACTIVO'], true)) {
                throw new InvalidArgumentException('Selecciona un estado operativo permitido para el conductor.');
            }
            $usuarioId = master_int($_POST['usuario_id'] ?? null, 1, 2147483647, false, 'cuenta de usuario');

            $selectedLicencias = (array)($_POST['licencias_seleccionadas'] ?? []);
            if (empty($selectedLicencias)) {
                $selectedLicencias = ['B4'];
            }

            $licenciasPayload = [];
            foreach ($selectedLicencias as $codeRaw) {
                $code = strtoupper(trim((string)$codeRaw));
                if ($code === '') continue;
                $vencLic = master_license_date($_POST['licencia_vencimiento'][$code] ?? $vencimiento);
                $estLic = strtoupper(trim((string)($_POST['licencia_estado'][$code] ?? 'VERIFICADA')));
                if (!in_array($estLic, ['VERIFICADA', 'PENDIENTE', 'RECHAZADA'], true)) {
                    $estLic = 'VERIFICADA';
                }
                $licenciasPayload[$code] = [
                    'vencimiento' => $vencLic,
                    'estado' => $estLic,
                ];
            }

            if ($isEdit) {
                $sql = 'EXEC dbo.sp_actualizar_conductor @conductor_id=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @telefono=?, @correo=?, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=?, @usuario_id=?;';
                $params = [$recordId, $nombre, $ap1, $ap2, $telefono, $correo, $licencia, $vencimiento, $estadoOp, $usuarioId];
            } else {
                $identificacion = (string)operation_text($_POST['identificacion'] ?? null, 30);
                $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @telefono=?, @correo=?, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=?, @usuario_id=?, @conductor_id=@nuevo_id OUTPUT; SELECT @nuevo_id AS nuevo_id;';
                $params = [$identificacion, $nombre, $ap1, $ap2, $telefono, $correo, $licencia, $vencimiento, $estadoOp, $usuarioId];
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

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $savedConductorId = $recordId;
    if ($entity === 'conductor' && !$isEdit) {
        do {
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['nuevo_id'])) {
                    $savedConductorId = (int)$row['nuevo_id'];
                }
            }
        } while ($stmt->nextRowset());
    }
    $stmt->closeCursor();

    if ($entity === 'conductor' && $savedConductorId > 0 && !empty($licenciasPayload)) {
        if ($isEdit) {
            $stmtCur = db()->prepare('EXEC dbo.sp_listar_licencias_conductor @conductor_id=?;');
            $stmtCur->execute([$savedConductorId]);
            $currentLicencias = $stmtCur->fetchAll(PDO::FETCH_ASSOC);
            $stmtCur->closeCursor();

            foreach ($currentLicencias as $curLic) {
                $curCod = (string)$curLic['codigo_licencia'];
                if (!isset($licenciasPayload[$curCod])) {
                    $stmtRem = db()->prepare('EXEC dbo.sp_remover_licencia_conductor @conductor_id=?, @codigo_licencia=?;');
                    $stmtRem->execute([$savedConductorId, $curCod]);
                    $stmtRem->closeCursor();
                }
            }
        }

        $stmtLic = db()->prepare('EXEC dbo.sp_asignar_licencia_conductor @conductor_id=?, @codigo_licencia=?, @numero_licencia=?, @fecha_vencimiento=?, @estado_verificacion=?, @observaciones=NULL;');
        foreach ($licenciasPayload as $catCod => $catInfo) {
            $stmtLic->execute([
                $savedConductorId,
                $catCod,
                $licencia,
                $catInfo['vencimiento'],
                $catInfo['estado'],
            ]);
            $stmtLic->closeCursor();
        }
    }
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50501 => 'No tienes permiso para gestionar este registro.',
        50502 => 'Revisa los valores ingresados (capacidad, kilometraje no regresivo, vigencia o coordenadas).',
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
