<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/hacienda_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Control de acceso: requiere sesión activa o token válido de invitación
$authenticated = is_authenticated();
$invitationToken = trim((string)($_GET['token'] ?? ''));

if (!$authenticated && $invitationToken !== '') {
    try {
        $pdo = db();
        $tokenHash = hash('sha256', $invitationToken);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.invitaciones_usuario WHERE token_hash = ? AND estado = \'PENDIENTE\' AND fecha_expiracion > SYSUTCDATETIME()');
        $stmt->execute([$tokenHash]);
        if ((int)$stmt->fetchColumn() > 0) {
            $authenticated = true;
        }
    } catch (Throwable) {
        $authenticated = false;
    }
}

if (!$authenticated) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'Acceso no autorizado para consultar Hacienda.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$identificacion = trim((string)($_GET['identificacion'] ?? ''));
if ($identificacion === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'invalido',
        'mensaje' => 'Debe suministrar el número de identificación a consultar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$resultado = HaciendaService::consultar($identificacion);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
