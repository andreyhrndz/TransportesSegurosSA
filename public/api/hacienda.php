<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/hacienda_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// 1. Verificar si hay sesión PHP activa
$hasActiveSession = !empty($_SESSION['usuario_id']) && session_is_current();
$authorized = false;

if ($hasActiveSession) {
    if (has_permission('CLIENTES_CREAR') || has_permission('SOLICITUDES_CREAR') || has_permission('CLIENTES_VER')) {
        $authorized = true;
    } else {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'No tiene permisos suficientes para consultar el servicio de Hacienda.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Si no hay sesión activa, verificar si se suministró un token de invitación válido (flujo Onboarding)
$invitationToken = trim((string)($_GET['token'] ?? ''));
if (!$authorized && $invitationToken !== '') {
    try {
        $pdo = db();
        $tokenHash = hash('sha256', $invitationToken);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.invitaciones_usuario WHERE token_hash = ? AND estado = \'PENDIENTE\' AND fecha_expiracion > SYSUTCDATETIME()');
        $stmt->execute([$tokenHash]);
        if ((int)$stmt->fetchColumn() > 0) {
            $authorized = true;
        }
    } catch (Throwable) {
        $authorized = false;
    }
}

// 3. Si no está autorizado por ninguna vía, rechazar con 401 Unauthorized
if (!$authorized) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'Acceso no autorizado para consultar Hacienda. Requiere sesión activa o invitación válida.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Validar parámetro de identificación
$identificacion = trim((string)($_GET['identificacion'] ?? ''));
if ($identificacion === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'invalido',
        'mensaje' => 'Debe suministrar el número de identificación a consultar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Ejecutar consulta mediante HaciendaService
$resultado = HaciendaService::consultar($identificacion);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
