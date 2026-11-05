<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/user_security.php';
require_once __DIR__ . '/../app/mail/mail_service.php';

echo "=== INICIO DE PRUEBAS DE INVITACIONES Y ONBOARDING ===\n";

function inv_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO INVITACIONES TEST: $msg");
    }
}

$pdo = db();
$adminId = 1;

// Configurar contexto de sesión como admin
$ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?, @read_only=0;");
$ctx->execute([$adminId]);
$ctx->closeCursor();

// 1. Limpieza de pruebas previas con correos de test
$testEmail1 = 'test.invitado.' . time() . '@transportesseguros.cr';
$testEmail2 = 'test.reenvio.' . time() . '@transportesseguros.cr';
$testCedula1 = '1' . str_pad((string)random_int(10000000, 99999999), 8, '0');

// 2. Crear invitación inicial
$token1 = bin2hex(random_bytes(32));
$tokenHash1 = hash('sha256', $token1);

$stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
$stmt->execute([$testEmail1, $tokenHash1]);
$invId1 = (int)$stmt->fetchColumn();
$stmt->closeCursor();

inv_assert($invId1 > 0, "La invitación inicial debe retornar un invitacion_id > 0 (obtenido: $invId1)");

// Enviar correo con FakeMailCaptureSender
$sender = new FakeMailCaptureSender();
$inviteLink1 = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($token1);
$html1 = render_invitation_email($inviteLink1, $testEmail1);
$sent = $sender->send($testEmail1, 'Invitación a Transportes Seguros S.A.', $html1);

inv_assert($sent, "El envío mediante FakeMailCaptureSender debe retornar true");
inv_assert($sender->count() === 1, "FakeMailCaptureSender debe registrar exactamente 1 correo");
$captured = $sender->getLastMessage();
inv_assert($captured !== null, "Debe existir un mensaje capturado");
inv_assert($captured['to'] === $testEmail1, "El destinatario debe coincidir con $testEmail1");
inv_assert(str_contains($captured['htmlBody'], $token1), "El cuerpo del correo debe incluir el token de activación");

// 3. Validar token
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$tokenHash1]);
$valRow = $stmt->fetch();
$stmt->closeCursor();

inv_assert($valRow['resultado'] === 'valida', "El token recién creado debe ser válido");
inv_assert($valRow['correo_destino'] === $testEmail1, "El correo validado debe coincidir");

// Validar token inventado o modificado
$badTokenHash = hash('sha256', 'token_falso_invalido_12345678901234567890');
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$badTokenHash]);
$badRow = $stmt->fetch();
$stmt->closeCursor();

inv_assert($badRow['resultado'] === 'invalido', "Un token inexistente debe retornar resultado = invalido");

// 4. Completar invitación (Onboarding)
$nuevoUsuario = 'usr.test.' . substr(md5($testEmail1), 0, 8);
$pwdHash = password_hash('ClaveSegura2026!#', PASSWORD_BCRYPT);

$stmt = $pdo->prepare('DECLARE @uid INT; EXEC dbo.sp_completar_invitacion_usuario @token_hash=?, @identificacion=?, @tipo_identificacion=\'01\', @nombre=?, @primer_apellido=?, @segundo_apellido=?, @nombre_usuario=?, @password_hash=?, @telefono=NULL, @nuevo_usuario_id=@uid OUTPUT; SELECT @uid AS usuario_id;');
$stmt->execute([
    $tokenHash1,
    $testCedula1,
    'Carlos',
    'Mora',
    'Alvarado',
    $nuevoUsuario,
    $pwdHash
]);
$nuevoUid = (int)$stmt->fetchColumn();
$stmt->closeCursor();

inv_assert($nuevoUid > 0, "El usuario debe crearse y retornar usuario_id > 0 (obtenido: $nuevoUid)");

// 5. Verificar que el usuario tiene CERO permisos por defecto
$stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.usuarios_permisos WHERE usuario_id = ?;');
$stmt->execute([$nuevoUid]);
$permisosCount = (int)$stmt->fetchColumn();
$stmt->closeCursor();

inv_assert($permisosCount === 0, "La cuenta creada por invitación debe tener exactamente 0 permisos por defecto");

// 6. Verificar que la invitación pasó a COMPLETADA
$stmt = $pdo->prepare('SELECT estado, fecha_completada, completado_usuario_id FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
$stmt->execute([$invId1]);
$invFinal = $stmt->fetch();
$stmt->closeCursor();

inv_assert($invFinal['estado'] === 'COMPLETADA', "El estado de la invitación debe ser COMPLETADA");
inv_assert($invFinal['fecha_completada'] !== null, "fecha_completada no debe ser nulo");
inv_assert((int)$invFinal['completado_usuario_id'] === $nuevoUid, "completado_usuario_id debe coincidir con $nuevoUid");

// 7. Revalidar el token usado: debe retornar completada
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$tokenHash1]);
$revalRow = $stmt->fetch();
$stmt->closeCursor();

inv_assert($revalRow['resultado'] === 'completada', "El token ya utilizado debe retornar completada");

// 8. Reenvío y cancelación
$token2 = bin2hex(random_bytes(32));
$tokenHash2 = hash('sha256', $token2);

$stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
$stmt->execute([$testEmail2, $tokenHash2]);
$invId2 = (int)$stmt->fetchColumn();
$stmt->closeCursor();

inv_assert($invId2 > 0, "Segunda invitación creada");

// Reenviar
$token2Reenvio = bin2hex(random_bytes(32));
$tokenHash2Reenvio = hash('sha256', $token2Reenvio);
$stmt = $pdo->prepare('DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT; SELECT @nueva_id AS nueva_invitacion_id;');
$stmt->execute([$invId2, $tokenHash2Reenvio]);
$nuevaInvId2 = (int)$stmt->fetchColumn();
$stmt->closeCursor();

inv_assert($nuevaInvId2 > 0, "Reenvío debe crear nueva invitación con nueva ID");

// Token antiguo no debe ser válido (fue cancelado)
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$tokenHash2]);
$rowOld = $stmt->fetch();
$stmt->closeCursor();
inv_assert($rowOld['resultado'] === 'invalido', "El token anterior tras reenvío debe ser inválido");

// Nuevo token debe ser válido
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$tokenHash2Reenvio]);
$rowNew = $stmt->fetch();
$stmt->closeCursor();
inv_assert($rowNew['resultado'] === 'valida', "El nuevo token de reenvío debe ser válido");

// Cancelar
$stmt = $pdo->prepare('EXEC dbo.sp_cancelar_invitacion_usuario @invitacion_id=?;');
$stmt->execute([$nuevaInvId2]);
$stmt->closeCursor();

// Tras cancelar, token nuevo no es válido
$stmt = $pdo->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
$stmt->execute([$tokenHash2Reenvio]);
$rowCancelled = $stmt->fetch();
$stmt->closeCursor();
inv_assert($rowCancelled['resultado'] === 'invalido', "El token cancelado debe ser inválido");

echo "=== TODAS LAS PRUEBAS DE INVITACIONES Y ONBOARDING PASARON EXITOSAMENTE ===\n";
