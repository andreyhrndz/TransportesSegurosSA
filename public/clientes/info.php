<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('CLIENTES_VER');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$clienteId = filter_var($_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT);
if (!$clienteId || $clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Identificador de cliente inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = read_procedure_sets('EXEC dbo.sp_obtener_cliente @cliente_id = ?;', [$clienteId])[0] ?? [];
    $cliente = $rows[0] ?? null;
    if (!$cliente) {
        http_response_code(404);
        echo json_encode(['error' => 'Cliente no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'cliente_id' => (int)$cliente['cliente_id'],
        'tipo_cliente' => (string)$cliente['tipo_cliente'],
        'identificacion' => (string)$cliente['identificacion'],
        'nombre' => (string)($cliente['nombre'] ?? ''),
        'primer_apellido' => (string)($cliente['primer_apellido'] ?? ''),
        'segundo_apellido' => (string)($cliente['segundo_apellido'] ?? ''),
        'nombre_completo' => trim((string)$cliente['nombre'] . ' ' . (string)($cliente['primer_apellido'] ?? '') . ' ' . (string)($cliente['segundo_apellido'] ?? '')),
        'razon_social' => (string)($cliente['razon_social'] ?? ''),
        'nombre_comercial' => (string)($cliente['nombre_comercial'] ?? ''),
        'representante_legal' => (string)($cliente['representante_legal'] ?? ''),
        'telefono' => (string)($cliente['telefono'] ?? ''),
        'correo' => (string)($cliente['correo'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener cliente'], JSON_UNESCAPED_UNICODE);
}
