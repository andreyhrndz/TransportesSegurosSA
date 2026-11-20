<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/master_data.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/transport_request_tvp.php';
require_operation_post('SOLICITUDES_CREAR');

/**
 * Distancia aproximada en metros entre dos pares de coordenadas (fórmula de Haversine).
 */
function solicitud_haversine_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2.0) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2.0) ** 2);
    $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    return 6371000.0 * $c;
}

/**
 * Valida y normaliza un extremo (origen o destino) en modo 'guardada' o 'mapa'.
 * @return array{
 *   modo: string,
 *   id: ?int,
 *   nombre: ?string,
 *   direccion: ?string,
 *   latitud: ?string,
 *   longitud: ?string,
 *   guardar_frecuente: int
 * }
 */
function solicitud_read_location_slot(string $slot, string $label): array
{
    $rawMode = strtolower(trim((string)($_POST[$slot . '_modo'] ?? 'guardada')));
    if (!in_array($rawMode, ['guardada', 'mapa'], true)) {
        throw new InvalidArgumentException("Modo de ubicación inválido para el {$label}.");
    }

    if ($rawMode === 'guardada') {
        $id = operation_id($_POST[$slot . '_id'] ?? null);
        return [
            'modo' => 'GUARDADA',
            'id' => $id,
            'nombre' => null,
            'direccion' => null,
            'latitud' => null,
            'longitud' => null,
            'guardar_frecuente' => 0,
        ];
    }

    $direccionRaw = trim((string)($_POST[$slot . '_direccion'] ?? ''));
    if ($direccionRaw === '') {
        throw new InvalidArgumentException("Indica una referencia descriptiva del sitio para el {$label}.");
    }
    $direccion = operation_text($direccionRaw, 250, true);

    [$lat, $lon] = master_coordinates($_POST[$slot . '_latitud'] ?? null, $_POST[$slot . '_longitud'] ?? null);
    if ($lat === null || $lon === null) {
        throw new InvalidArgumentException("Selecciona un punto válido en el mapa (latitud y longitud) para el {$label}.");
    }

    $rawSave = strtolower(trim((string)($_POST[$slot . '_guardar_frecuente'] ?? '0')));
    $wantsFrequent = in_array($rawSave, ['1', 'true', 'on', 'si'], true);
    if ($wantsFrequent && !has_permission('UBICACIONES_GESTIONAR')) {
        operation_error('No tienes permiso para guardar ubicaciones en el catálogo frecuente.', 403);
    }

    $nombre = operation_text($_POST[$slot . '_nombre'] ?? null, 150, false);
    if ($wantsFrequent) {
        if ($nombre === null || mb_strlen($nombre) < 3) {
            throw new InvalidArgumentException("Indica un nombre de al menos 3 caracteres para guardar el {$label} como ubicación frecuente.");
        }
    }

    return [
        'modo' => 'MAPA',
        'id' => null,
        'nombre' => $nombre,
        'direccion' => $direccion,
        'latitud' => $lat,
        'longitud' => $lon,
        'guardar_frecuente' => $wantsFrequent ? 1 : 0,
    ];
}

try {
    // 1. Validaciones estructurales y de integridad de ubicaciones híbridas
    $clienteId = operation_id($_POST['cliente_id'] ?? null);
    $origen = solicitud_read_location_slot('origen', 'origen');
    $destino = solicitud_read_location_slot('destino', 'destino');

    if ($origen['modo'] === 'GUARDADA' && $destino['modo'] === 'GUARDADA' && $origen['id'] === $destino['id']) {
        throw new InvalidArgumentException('Origen y destino deben ser distintos.');
    }

    if ($origen['modo'] === 'MAPA' && $destino['modo'] === 'MAPA') {
        $dist = solicitud_haversine_meters(
            (float)$origen['latitud'],
            (float)$origen['longitud'],
            (float)$destino['latitud'],
            (float)$destino['longitud']
        );
        if ($dist <= 25.0) {
            throw new InvalidArgumentException('El origen y el destino corresponden al mismo punto geográfico (tolerancia máxima: 25 metros).');
        }
    }

    $fecha = operation_future_datetime($_POST['fecha_entrega'] ?? null);

    // 2. Consulta autoritativa del cliente desde base de datos
    $rowsCli = read_procedure_sets('EXEC dbo.sp_obtener_cliente @cliente_id = ?;', [$clienteId])[0] ?? [];
    $cliente = $rowsCli[0] ?? null;
    if (!$cliente || empty($cliente['activo'])) {
        throw new InvalidArgumentException('El cliente seleccionado no existe o no está activo.');
    }

    // 3. Resolución autoritativa del destinatario
    $modo = trim((string)($_POST['modo_destinatario'] ?? 'mismo_cliente'));
    if (!in_array($modo, ['mismo_cliente', 'tercero'], true)) {
        throw new InvalidArgumentException('Modo de destinatario inválido.');
    }

    if ($modo === 'mismo_cliente') {
        if (($cliente['tipo_cliente'] ?? '') !== 'FISICA') {
            throw new InvalidArgumentException('Para clientes de tipo jurídico (empresas) es obligatorio indicar una persona física autorizada para recibir la entrega.');
        }

        $destNombre = trim((string)($cliente['nombre'] ?? ''));
        $destAp1 = trim((string)($cliente['primer_apellido'] ?? ''));
        $destAp2 = trim((string)($cliente['segundo_apellido'] ?? ''));
        $destId = trim((string)($cliente['identificacion'] ?? ''));
        $destTel = trim((string)($cliente['telefono'] ?? ''));

        if ($destNombre === '' || $destAp1 === '') {
            throw new InvalidArgumentException('El cliente no cuenta con nombre y primer apellido válidos en el registro del sistema.');
        }
    } else {
        // Modo tercero: validar estrictamente los datos recibidos del formulario
        $destNombre = operation_text($_POST['destinatario_nombre'] ?? null, 150);
        $destAp1 = operation_text($_POST['destinatario_apellido1'] ?? null, 100);
        $destAp2Raw = trim((string)($_POST['destinatario_apellido2'] ?? ''));
        $destAp2 = ($destAp2Raw !== '') ? operation_text($destAp2Raw, 100, false) : '';
        $destId = operation_text($_POST['destinatario_identificacion'] ?? null, 30, false);
        $destTel = operation_text($_POST['destinatario_telefono'] ?? null, 30, false);
    }

    $destinatario = [$destNombre, $destAp1, $destAp2, $destId, $destTel];

    // 4. Validación de mercancías
    $mercancias = $_POST['mercancias'] ?? null;
    if (!is_array($mercancias) || count($mercancias) > 20) {
        throw new InvalidArgumentException('Incluye entre una y veinte mercancías válidas.');
    }
    $tvp = build_tipo_mercancias_tvp(array_values($mercancias));

} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    // 5. Invocación atómica del procedimiento almacenado híbrido con TVP de mercancías
    $values = implode(',', array_fill(0, count($tvp['tipo_mercancias']), '(?,?,?,?)'));
    $sql = 'DECLARE @solicitud_id INT, @origen_id_resuelto INT, @destino_id_resuelto INT; '
        . 'DECLARE @mercancias dbo.tipo_mercancias; '
        . 'INSERT INTO @mercancias (tipo_mercancia_id,descripcion,peso_kg,volumen_m3) VALUES ' . $values . '; '
        . 'EXEC dbo.sp_crear_solicitud_con_ubicaciones '
        . '@codigo=NULL,@cliente_id=?,'
        . '@origen_modo=?,@origen_id=?,@origen_nombre=?,@origen_direccion=?,@origen_latitud=?,@origen_longitud=?,@origen_guardar_frecuente=?,'
        . '@destino_modo=?,@destino_id=?,@destino_nombre=?,@destino_direccion=?,@destino_latitud=?,@destino_longitud=?,@destino_guardar_frecuente=?,'
        . '@destinatario_nombre=?,@destinatario_apellido1=?,@destinatario_apellido2=?,@destinatario_identificacion=?,@destinatario_telefono=?,'
        . '@fecha_entrega_requerida=?,@mercancias=@mercancias,'
        . '@solicitud_id=@solicitud_id OUTPUT,@origen_id_resuelto=@origen_id_resuelto OUTPUT,@destino_id_resuelto=@destino_id_resuelto OUTPUT; '
        . 'SELECT @solicitud_id AS solicitud_id;';

    $params = [];
    foreach ($tvp['tipo_mercancias'] as $row) {
        array_push($params, ...$row);
    }
    $params = array_merge($params, [
        $clienteId,
        $origen['modo'],
        $origen['id'],
        $origen['nombre'],
        $origen['direccion'],
        $origen['latitud'],
        $origen['longitud'],
        $origen['guardar_frecuente'],
        $destino['modo'],
        $destino['id'],
        $destino['nombre'],
        $destino['direccion'],
        $destino['latitud'],
        $destino['longitud'],
        $destino['guardar_frecuente'],
        ...$destinatario,
        $fecha,
    ]);

    $statement = db()->prepare($sql);
    $statement->execute($params);
    $newId = null;
    do {
        if ($statement->columnCount() > 0) {
            $row = $statement->fetch();
            if (is_array($row) && isset($row['solicitud_id'])) {
                $newId = (int)$row['solicitud_id'];
            }
        }
    } while ($statement->nextRowset());
    $statement->closeCursor();

    if ($newId === null || $newId < 1) {
        throw new RuntimeException('No se recibió el identificador de la solicitud generada.');
    }
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50203 => 'No tienes permiso para crear solicitudes.',
        50204 => 'Origen y destino deben ser distintos.',
        50205 => 'La fecha de entrega debe programarse a futuro.',
        50206 => 'Incluye al menos una mercancía.',
        50207 => 'Revisa el peso y volumen de las mercancías.',
        50208 => 'El cliente no está activo.',
        50230 => 'Modo de ubicación inválido.',
        50231 => 'La ubicación seleccionada no existe o no está activa.',
        50232 => 'Revisa la referencia y las coordenadas del punto marcado en el mapa.',
        50233 => 'No tienes permiso para guardar ubicaciones en el catálogo frecuente.',
        50234 => 'El origen y el destino corresponden al mismo punto geográfico (tolerancia máxima: 25 metros).',
        50235 => 'Algún tipo de mercancía indicado no existe o no está activo.',
        50504 => 'Ya existe una ubicación frecuente registrada con ese nombre. Elige otro alias o selecciona la ubicación guardada.',
    ]);
} catch (RuntimeException) {
    operation_error('No se pudo registrar la solicitud. Inténtalo nuevamente.', 500);
}

operation_success('Solicitud registrada correctamente.', 'solicitudCreada', '/solicitudes/detalle.php?id=' . $newId);
