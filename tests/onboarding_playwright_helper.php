<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();
require_test_database($pdo);

$action = $argv[1] ?? '';

if ($action === 'crear') {
    $ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1, @read_only=0;");
    $ctx->execute();
    $ctx->closeCursor();

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $email = 'playwright.onb.' . time() . '.' . random_int(100, 999) . '@example.test';

    $stmt = $pdo->prepare("DECLARE @id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@id OUTPUT; SELECT @id AS id;");
    $stmt->execute([$email, $tokenHash]);
    $invId = (int)$stmt->fetchColumn();
    $stmt->closeCursor();

    echo json_encode(['token' => $token, 'email' => $email, 'invId' => $invId]);
    exit(0);
}

if ($action === 'limpiar_invitacion') {
    $invId = (int)($argv[2] ?? 0);
    if ($invId > 0) {
        $pdo->prepare("DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id = ?;")->execute([$invId]);
        $pdo->prepare("DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;")->execute([$invId]);
    }
    echo "OK";
    exit(0);
}

if ($action === 'limpiar_usuario') {
    $username = trim($argv[2] ?? '');
    if ($username !== '') {
        $stmt = $pdo->prepare("SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ?;");
        $stmt->execute([$username]);
        $uid = (int)$stmt->fetchColumn();
        $stmt->closeCursor();

        if ($uid > 0) {
            $pdo->prepare("DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?;")->execute([$uid]);
            $pdo->prepare("DELETE FROM dbo.usuarios_perfil WHERE usuario_id = ?;")->execute([$uid]);
            $pdo->prepare("DELETE FROM dbo.auditorias WHERE usuario_id = ?;")->execute([$uid]);
            $pdo->prepare("DELETE FROM dbo.usuarios WHERE usuario_id = ?;")->execute([$uid]);
        }
    }
    echo "OK";
    exit(0);
}
