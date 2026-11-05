<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/transport_request_tvp.php';
require_operation_post('SOLICITUDES_CREAR');

try {
    // 1. Validaciones estructurales y de integridad
    $clienteId = operation_id($_POST['cliente_id'] ?? null);
    $origenId = operation_id($_POST['origen_id'] ?? null);
    $destinoId = operation_id($_POST['destino_id'] ?? null);
    if ($origenId === $destinoId) {
        throw new InvalidArgumentException('Origen y destino deben ser distintos.');
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
    // 5. Invocación de procedimiento almacenado con código autogenerado por SQL Server
    $values = implode(',', array_fill(0, count($tvp['tipo_mercancias']), '(?,?,?,?)'));
    $sql = 'DECLARE @solicitud_id INT; DECLARE @mercancias dbo.tipo_mercancias; INSERT INTO @mercancias (tipo_mercancia_id,descripcion,peso_kg,volumen_m3) VALUES ' . $values . '; EXEC dbo.sp_crear_solicitud @codigo=NULL,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=?,@destinatario_apellido1=?,@destinatario_apellido2=?,@destinatario_identificacion=?,@destinatario_telefono=?,@fecha_entrega_requerida=?,@mercancias=@mercancias,@solicitud_id=@solicitud_id OUTPUT; SELECT @solicitud_id AS solicitud_id;';
    
    $params = [];
    foreach ($tvp['tipo_mercancias'] as $row) {
        array_push($params, ...$row);
    }
    $params = array_merge($params, [$clienteId, $origenId, $destinoId, ...$destinatario, $fecha]);
    
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
        50204 => 'Origen y destino deben ser distintos.',
        50205 => 'La fecha de entrega debe programarse a futuro.',
        50206 => 'Incluye al menos una mercancía.',
        50207 => 'Revisa el peso y volumen de las mercancías.',
        50208 => 'El cliente no está activo.',
    ]);
} catch (RuntimeException) {
    operation_error('No se pudo registrar la solicitud. Inténtalo nuevamente.', 500);
}

operation_success('Solicitud registrada correctamente.', 'solicitudCreada', '/solicitudes/detalle.php?id=' . $newId);
