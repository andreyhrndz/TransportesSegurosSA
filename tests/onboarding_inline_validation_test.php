<?php
declare(strict_types=1);

/**
 * Suite de Pruebas Automatizadas: Corrección de Onboarding, Validaciones Inline y Negociación JSON
 * Transportes Seguros S.A.
 * Ejecuta exclusivamente sobre TransportesSegurosSA_Test con cuentas efímeras.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/user_security.php';
require_once __DIR__ . '/../app/mail/mail_service.php';

echo "=== INICIO DE PRUEBAS DE ONBOARDING INLINE Y NEGOCIACIÓN JSON ===\n";

function onb_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO ONBOARDING TEST: $msg");
    }
}

function test_extract_cookie(array $headers): string {
    $cookie = '';
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
            $cookie = $m[1];
        }
    }
    return $cookie;
}

$pdo = db();
require_test_database($pdo);

$adminId = 1;
$ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?, @read_only=0;");
$ctx->execute([$adminId]);
$ctx->closeCursor();

$baseUrl = get_app_base_url();
$suffix = time() . '_' . random_int(1000, 9999);
$testEmail1 = "test.onb.valid.{$suffix}@example.test";
$testEmailDupUser = "test.onb.dupuser.{$suffix}@example.test";
$testEmailDupCed = "test.onb.dupced.{$suffix}@example.test";

$createdUserIds = [];
$createdInvIds = [];

function http_req_onb(string $url, string $cookie = '', bool $sendJsonHeader = true, ?array $postData = null): array {
    $headers = [];
    if ($cookie !== '') {
        $headers[] = "Cookie: {$cookie}";
    }
    if ($sendJsonHeader) {
        $headers[] = "Accept: application/json";
        $headers[] = "X-Requested-With: XMLHttpRequest";
    }

    $content = null;
    $method = 'GET';
    if ($postData !== null) {
        $method = 'POST';
        $content = http_build_query($postData);
        $headers[] = "Content-Type: application/x-www-form-urlencoded";
        $headers[] = "Content-Length: " . strlen($content);
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
    $status = isset($match[1]) ? (int)$match[1] : 0;

    return [$status, $response, $http_response_header ?? []];
}

try {
    // 1. Crear invitación efímera 1
    $token1 = bin2hex(random_bytes(32));
    $tokenHash1 = hash('sha256', $token1);

    $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
    $stmt->execute([$testEmail1, $tokenHash1]);
    $invId1 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $invId1;
    onb_assert($invId1 > 0, "Invitación 1 creada exitosamente");

    // Abrir formulario onboarding vía GET para obtener sesión y CSRF
    $onbFormUrl = "{$baseUrl}/auth/onboarding.php?token={$token1}";
    [$formStatus, $formHtml, $formHeaders] = http_req_onb($onbFormUrl, '', false);
    onb_assert($formStatus === 200, "Formulario debe responder 200 (recibido {$formStatus})");
    $cookie = test_extract_cookie($formHeaders);
    onb_assert($cookie !== '', "Debe recibirse cookie de sesión del servidor");

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formHtml, $mCsrf);
    $validCsrf = $mCsrf[1] ?? '';
    onb_assert($validCsrf !== '', "Debe extraerse token CSRF válido");

    $guardarUrl = "{$baseUrl}/auth/onboarding_guardar.php";

    // 2. Probar error CSRF con JSON (debe responder 403)
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => 'token_invalido_123',
        'token' => $token1,
    ]);
    onb_assert($status === 403, "CSRF inválido debe responder HTTP 403 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert(isset($data['ok']) && $data['ok'] === false, "Respuesta JSON debe contener ok: false");
    echo "  [PASS] Error CSRF devuelve HTTP 403 estructurado\n";

    // 3. Probar token inválido con JSON (debe responder 422)
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => 'token_corto_malformado',
    ]);
    onb_assert($status === 422, "Token malformado debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['ok'] === false, "ok debe ser false en token malformado");
    echo "  [PASS] Token malformado devuelve HTTP 422 estructurado\n";

    // 4. Probar token inexistente con JSON (debe responder 409)
    $fakeToken = bin2hex(random_bytes(32));
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $fakeToken,
    ]);
    onb_assert($status === 409, "Token inexistente debe responder HTTP 409 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['ok'] === false, "ok debe ser false en token inexistente");
    echo "  [PASS] Token inexistente devuelve HTTP 409 estructurado\n";

    // 5. Probar error de paso 1: cédula vacía (debe responder 422, field: identificacion, paso: 1)
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'carlos.mora',
        'password' => 'ClaveSegura2026!#',
        'password_confirmacion' => 'ClaveSegura2026!#',
    ]);
    onb_assert($status === 422, "Cédula vacía debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'identificacion', "Campo con error debe ser 'identificacion'");
    onb_assert($data['paso'] === 1, "Paso asociado debe ser 1");
    echo "  [PASS] Cédula vacía reporta field: identificacion y paso: 1\n";

    // 6. Probar error de paso 1: nombre vacío (debe responder 422, field: nombre, paso: 1)
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => '',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'carlos.mora',
        'password' => 'ClaveSegura2026!#',
        'password_confirmacion' => 'ClaveSegura2026!#',
    ]);
    onb_assert($status === 422, "Nombre vacío debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'nombre', "Campo con error debe ser 'nombre'");
    onb_assert($data['paso'] === 1, "Paso asociado debe ser 1");
    echo "  [PASS] Nombre vacío reporta field: nombre y paso: 1\n";

    // 7. Probar error de paso 2: nombre de usuario inválido con espacios o caracteres ilegales
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'usr con espacios',
        'password' => 'ClaveSegura2026!#',
        'password_confirmacion' => 'ClaveSegura2026!#',
    ]);
    onb_assert($status === 422, "Usuario con caracteres inválidos debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'nombre_usuario', "Campo con error debe ser 'nombre_usuario'");
    onb_assert($data['paso'] === 2, "Paso asociado debe ser 2");
    echo "  [PASS] Usuario con caracteres inválidos reporta field: nombre_usuario y paso: 2\n";

    // 8. Probar error de paso 2: contraseña menor a 12 caracteres
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'carlos.mora',
        'password' => 'Corta123!',
        'password_confirmacion' => 'Corta123!',
    ]);
    onb_assert($status === 422, "Contraseña menor a 12 chars debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'password', "Campo con error debe ser 'password'");
    onb_assert($data['paso'] === 2, "Paso asociado debe ser 2");
    echo "  [PASS] Contraseña menor a 12 chars reporta field: password y paso: 2\n";

    // 9. Probar error de paso 2: confirmación no coincidente
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'carlos.mora',
        'password' => 'ClaveSegura2026!#1',
        'password_confirmacion' => 'ClaveSegura2026!#2_distinta',
    ]);
    onb_assert($status === 422, "Confirmación no coincidente debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'password_confirmacion', "Campo con error debe ser 'password_confirmacion'");
    onb_assert($data['paso'] === 2, "Paso asociado debe ser 2");
    echo "  [PASS] Confirmación no coincidente reporta field: password_confirmacion y paso: 2\n";

    // 10. Probar error de paso 2: contraseña débil o con nombre de la empresa
    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'carlos.mora',
        'password' => 'transportes2026',
        'password_confirmacion' => 'transportes2026',
    ]);
    onb_assert($status === 422, "Contraseña con nombre de empresa debe responder HTTP 422 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert($data['field'] === 'password', "Campo con error debe ser 'password'");
    echo "  [PASS] Contraseña débil/predecible reporta field: password\n";

    // 11. Probar éxito con JSON
    $validCedula1 = '1' . random_int(10000000, 99999999);
    $validUser1 = 'user.onb.' . substr(md5($testEmail1), 0, 8);
    $validPass1 = 'ClaveSeguraOnboarding2026!#';

    [$status, $body] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => $validCedula1,
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'segundo_apellido' => 'Alvarado',
        'nombre_usuario' => $validUser1,
        'password' => $validPass1,
        'password_confirmacion' => $validPass1,
    ]);

    onb_assert($status === 200, "Activación exitosa con JSON debe responder HTTP 200 (recibido {$status})");
    $data = json_decode($body, true);
    onb_assert(isset($data['ok']) && $data['ok'] === true, "Respuesta debe tener ok: true");
    onb_assert(str_contains($data['redirect'] ?? '', 'exito=1'), "Redirección debe apuntar a exito=1");
    echo "  [PASS] Activación válida con JSON devuelve HTTP 200 y redirect: exito=1\n";

    // Verificar que el usuario fue creado en la base de pruebas
    $chkUser = $pdo->prepare('SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = ?;');
    $chkUser->execute([$validUser1]);
    $newUid = (int)$chkUser->fetchColumn();
    $chkUser->closeCursor();
    onb_assert($newUid > 0, "El usuario debe existir en dbo.usuarios");
    $createdUserIds[] = $newUid;

    // 12. Probar pantalla de éxito vía GET
    [$statusExito, $bodyExito] = http_req_onb("{$baseUrl}/auth/onboarding_guardar.php?exito=1", '', false);
    onb_assert($statusExito === 200, "Pantalla de éxito vía GET debe responder HTTP 200 (recibido {$statusExito})");
    onb_assert(str_contains($bodyExito, '¡Cuenta activada!'), "Debe incluir encabezado '¡Cuenta activada!'");
    onb_assert(str_contains($bodyExito, 'Los permisos asignados a tu cuenta por el administrador'), "Debe contener redacción neutral de permisos");
    echo "  [PASS] Pantalla de éxito vía GET responde HTTP 200 con redacción neutral\n";

    // 13. Probar reuso de token ya completado (debe ser rechazado con 409)
    [$statusReuso, $bodyReuso] = http_req_onb($guardarUrl, $cookie, true, [
        'csrf_token' => $validCsrf,
        'token' => $token1,
        'identificacion' => '109990888',
        'nombre' => 'Carlos',
        'primer_apellido' => 'Mora',
        'nombre_usuario' => 'otro.usuario',
        'password' => $validPass1,
        'password_confirmacion' => $validPass1,
    ]);
    onb_assert($statusReuso === 409, "Reutilizar token completado debe responder HTTP 409 (recibido {$statusReuso})");
    $dataReuso = json_decode($bodyReuso, true);
    onb_assert($dataReuso['ok'] === false, "ok debe ser false en token reutilizado");
    echo "  [PASS] Intento de reusar token completado es bloqueado con HTTP 409\n";

    // 14. Probar colisión de usuario existente con invitación nueva (error 50434 -> 409, field: nombre_usuario, paso: 2)
    $token2 = bin2hex(random_bytes(32));
    $tokenHash2 = hash('sha256', $token2);
    $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
    $stmt->execute([$testEmailDupUser, $tokenHash2]);
    $invId2 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $invId2;

    // Obtener sesión y CSRF para token 2
    [$s2, $h2, $hdr2] = http_req_onb("{$baseUrl}/auth/onboarding.php?token={$token2}", '', false);
    $c2 = test_extract_cookie($hdr2);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $h2, $m2);
    $csrf2 = $m2[1] ?? '';

    [$statusDupUsr, $bodyDupUsr] = http_req_onb($guardarUrl, $c2, true, [
        'csrf_token' => $csrf2,
        'token' => $token2,
        'identificacion' => '1' . random_int(10000000, 99999999),
        'nombre' => 'Ana',
        'primer_apellido' => 'Soto',
        'nombre_usuario' => $validUser1, // Usuario ya existente
        'password' => $validPass1,
        'password_confirmacion' => $validPass1,
    ]);
    onb_assert($statusDupUsr === 409, "Usuario duplicado debe responder HTTP 409 (recibido {$statusDupUsr})");
    $dataDupUsr = json_decode($bodyDupUsr, true);
    onb_assert($dataDupUsr['field'] === 'nombre_usuario', "Error debe apuntar al field nombre_usuario");
    onb_assert($dataDupUsr['paso'] === 2, "Error de usuario duplicado debe apuntar al paso 2");
    echo "  [PASS] Usuario duplicado en servidor devuelve HTTP 409, field: nombre_usuario y paso: 2\n";

    // 15. Probar colisión de cédula existente con invitación nueva (error 50433 -> 409, field: identificacion, paso: 1)
    $token3 = bin2hex(random_bytes(32));
    $tokenHash3 = hash('sha256', $token3);
    $stmt = $pdo->prepare('DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;');
    $stmt->execute([$testEmailDupCed, $tokenHash3]);
    $invId3 = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $createdInvIds[] = $invId3;

    // Obtener sesión y CSRF para token 3
    [$s3, $h3, $hdr3] = http_req_onb("{$baseUrl}/auth/onboarding.php?token={$token3}", '', false);
    $c3 = test_extract_cookie($hdr3);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $h3, $m3);
    $csrf3 = $m3[1] ?? '';

    [$statusDupCed, $bodyDupCed] = http_req_onb($guardarUrl, $c3, true, [
        'csrf_token' => $csrf3,
        'token' => $token3,
        'identificacion' => $validCedula1, // Cédula ya existente
        'nombre' => 'Ana',
        'primer_apellido' => 'Soto',
        'nombre_usuario' => 'ana.soto.' . substr(md5($token3), 0, 6),
        'password' => $validPass1,
        'password_confirmacion' => $validPass1,
    ]);
    onb_assert($statusDupCed === 409, "Cédula duplicada debe responder HTTP 409 (recibido {$statusDupCed})");
    $dataDupCed = json_decode($bodyDupCed, true);
    onb_assert($dataDupCed['field'] === 'identificacion', "Error debe apuntar al field identificacion");
    onb_assert($dataDupCed['paso'] === 1, "Error de cédula duplicada debe indicar paso: 1 para re-navegación");
    echo "  [PASS] Cédula duplicada en servidor devuelve HTTP 409, field: identificacion y paso: 1\n";

    echo "\nRESULTADO: TODAS LAS PRUEBAS DE ONBOARDING INLINE Y JSON APROBADAS EXITOSAMENTE.\n";

} finally {
    // Limpieza segura en TransportesSegurosSA_Test
    foreach ($createdUserIds as $uid) {
        try {
            $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?;')->execute([$uid]);
            $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?;')->execute([$uid]);
        } catch (Throwable) {}
    }
    foreach ($createdInvIds as $iid) {
        try {
            $pdo->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id = ?;')->execute([$iid]);
            $pdo->prepare('DELETE FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;')->execute([$iid]);
        } catch (Throwable) {}
    }
}
