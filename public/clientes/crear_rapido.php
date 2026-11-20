<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!has_permission('CLIENTES_CREAR')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tienes permiso para registrar clientes.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'El formulario caducó. Recarga la página e inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tipo = strtolower(trim((string)($_POST['tipo'] ?? '')));
if (!in_array($tipo, ['fisico', 'juridico'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selecciona si el cliente es físico o jurídico.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $identificacion = (string)operation_text($_POST['identificacion'] ?? null, 30);
    $correo = (string)operation_email($_POST['correo'] ?? null, 150);
    $telefono = operation_text($_POST['telefono'] ?? null, 30, false);

    if ($tipo === 'fisico') {
        $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
        $apellido1 = (string)operation_text($_POST['primer_apellido'] ?? null, 100);
        $apellido2 = operation_text($_POST['segundo_apellido'] ?? null, 100, false);
        $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_cliente_fisico @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @correo=?, @telefono=?, @cliente_id=@nuevo_id OUTPUT; SELECT @nuevo_id AS cliente_id;';
        $params = [$identificacion, $nombre, $apellido1, $apellido2, $correo, $telefono];
        $displayName = trim($nombre . ' ' . $apellido1 . ($apellido2 ? ' ' . $apellido2 : ''));
        $tipoDb = 'FISICA';
    } else {
        $razon = (string)operation_text($_POST['razon_social'] ?? null, 150);
        $comercial = operation_text($_POST['nombre_comercial'] ?? null, 150, false);
        $representante = operation_text($_POST['representante_legal'] ?? null, 150, false);
        $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=?, @razon_social=?, @nombre_comercial=?, @representante_legal=?, @correo=?, @cliente_id=@nuevo_id OUTPUT; SELECT @nuevo_id AS cliente_id;';
        $params = [$identificacion, $razon, $comercial, $representante, $correo];
        $displayName = $comercial ?: $razon;
        $tipoDb = 'JURIDICA';
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $nuevoId = 0;
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['cliente_id'])) {
                $nuevoId = (int)$row['cliente_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    if ($nuevoId < 1) {
        throw new RuntimeException('No se obtuvo el identificador del cliente creado.');
    }
} catch (PDOException $exception) {
    $code = (int)($exception->errorInfo[1] ?? 0);
    if ($code === 2601 || $code === 2627) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe un cliente registrado con esa identificación.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el cliente. Revisa los datos e inténtalo nuevamente.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (RuntimeException) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno al crear el cliente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'cliente' => [
        'cliente_id' => $nuevoId,
        'tipo_cliente' => $tipoDb,
        'nombre' => $displayName,
        'identificacion' => $identificacion,
        'correo' => $correo,
        'telefono' => $telefono ?? '',
    ],
], JSON_UNESCAPED_UNICODE);
exit;
