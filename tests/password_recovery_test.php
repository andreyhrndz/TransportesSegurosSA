<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/user_security.php';
require_once __DIR__ . '/../app/mail/mail_service.php';

echo "=== INICIO DE PRUEBAS DE RECUPERACIÓN DE CONTRASEÑA ===\n";

function pwd_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO RECUPERACION TEST: $msg");
    }
}

$pdo = db();

// 1. Obtener un usuario de prueba existente
$stmt = $pdo->query('SELECT TOP 1 usuario_id, correo_acceso FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id ASC;');
$user = $stmt->fetch();
$stmt->closeCursor();

pwd_assert($user !== false, "Debe existir al menos un usuario activo en el sistema de pruebas");
$userId = (int)$user['usuario_id'];
$userEmail = (string)$user['correo_acceso'];

// 2. Solicitar recuperación para correo existente
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

$stmt = $pdo->prepare('DECLARE @uid INT, @nom NVARCHAR(80); EXEC dbo.sp_solicitar_recuperacion_password @correo=?, @token_hash=?, @minutos_vigencia=120, @usuario_id=@uid OUTPUT, @nombre_usuario=@nom OUTPUT; SELECT @uid AS usuario_id;');
$stmt->execute([$userEmail, $tokenHash]);
$outUid = (int)$stmt->fetchColumn();
$stmt->closeCursor();

pwd_assert($outUid === $userId, "El procedimiento debe retornar el usuario_id coincidente ($userId, obtenido: $outUid)");

// Enviar con FakeMailCaptureSender
$sender = new FakeMailCaptureSender();
$link = get_app_base_url() . '/auth/reset_password.php?token=' . urlencode($token);
$html = render_password_reset_email($link, $userEmail);
$sender->send($userEmail, 'Restablecer contraseña', $html);

pwd_assert($sender->count() === 1, "Debe haberse capturado el correo de recuperación");
$captured = $sender->getLastMessage();
pwd_assert($captured['to'] === $userEmail, "El destinatario debe ser el correo del usuario");
pwd_assert(str_contains($captured['htmlBody'], $token), "El correo debe incluir el token");

// 3. Solicitar recuperación para correo inexistente (no debe insertar token ni revelar existencia)
$fakeEmail = 'noexiste.' . time() . '@inexistente.cr';
$tokenFake = bin2hex(random_bytes(32));
$tokenFakeHash = hash('sha256', $tokenFake);

$stmt = $pdo->prepare('DECLARE @uid INT, @nom NVARCHAR(80); EXEC dbo.sp_solicitar_recuperacion_password @correo=?, @token_hash=?, @minutos_vigencia=120, @usuario_id=@uid OUTPUT, @nombre_usuario=@nom OUTPUT; SELECT @uid AS usuario_id;');
$stmt->execute([$fakeEmail, $tokenFakeHash]);
$outFakeUid = (int)$stmt->fetchColumn();
$stmt->closeCursor();

pwd_assert($outFakeUid === 0, "Para un correo inexistente debe retornar 0");

// 4. Validar token
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_recuperacion @token_hash=?;');
$stmt->execute([$tokenHash]);
$valRow = $stmt->fetch();
$stmt->closeCursor();

pwd_assert($valRow['resultado'] === 'valido', "El token de recuperación debe ser válido");
pwd_assert((int)$valRow['usuario_id'] === $userId, "El usuario_id validado debe ser $userId");

// 5. Completar recuperación (actualizar contraseña)
$nuevaPassword = 'NuevaClaveSegura2026!#';
$nuevoHash = validate_new_password_hash($nuevaPassword, $nuevaPassword, '', $userEmail);

$stmt = $pdo->prepare('EXEC dbo.sp_completar_recuperacion_password @token_hash=?, @password_hash=?;');
$stmt->execute([$tokenHash, $nuevoHash]);
$stmt->closeCursor();

// Verificar que la contraseña en dbo.usuarios se actualizó
$stmt = $pdo->prepare('SELECT password_hash FROM dbo.usuarios WHERE usuario_id = ?;');
$stmt->execute([$userId]);
$dbHash = (string)$stmt->fetchColumn();
$stmt->closeCursor();

pwd_assert(password_verify($nuevaPassword, $dbHash), "password_verify debe ser exitoso con la nueva contraseña");

// 6. Token ahora debe estar marcado como utilizado y retornar invalido al consultar
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_recuperacion @token_hash=?;');
$stmt->execute([$tokenHash]);
$usedRow = $stmt->fetch();
$stmt->closeCursor();

pwd_assert($usedRow['resultado'] === 'invalido', "El token usado ya no debe ser válido");

echo "=== TODAS LAS PRUEBAS DE RECUPERACIÓN DE CONTRASEÑA PASARON EXITOSAMENTE ===\n";
