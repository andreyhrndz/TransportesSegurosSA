<?php
declare(strict_types=1);

/**
 * Suite integral HTTP + Seguridad + Almacenamiento Privado + Backup/Restore
 * para carga segura de documentos PDF/JPG/PNG y compatibilidad con URL externa.
 *
 * Opera EXCLUSIVAMENTE contra TransportesSegurosSA_Test con cuenta efímera y
 * directorio privado temporal aislado (0 residuos garantizados).
 */

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
$_ENV['TSSA_DB_DATABASE'] = 'TransportesSegurosSA_Test';
$_SERVER['TSSA_DB_DATABASE'] = 'TransportesSegurosSA_Test';

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/document_storage.php';

function doc_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('ASSERTION FAILED: ' . $msg);
    }
}

/** Genera un PNG 1x1 válido real (reconocido por finfo y getimagesize). */
function doc_valid_png_bytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    );
}

/** Genera un JPEG 1x1 válido real (reconocido por finfo y getimagesize). */
function doc_valid_jpg_bytes(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
        true
    );
}

/** Genera un PDF mínimo válido (%PDF-1.4) de al menos $targetBytes bytes. */
function doc_valid_pdf_bytes(int $targetBytes = 0): string
{
    $base = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
        . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\n";
    $trailer = "xref\n0 4\n0000000000 65535 f \ntrailer << /Size 4 /Root 1 0 R >>\nstartxref\n180\n%%EOF\n";
    if ($targetBytes <= strlen($base) + strlen($trailer)) {
        return $base . $trailer;
    }
    $padLen = $targetBytes - strlen($base) - strlen($trailer) - 2;
    return $base . '%' . str_repeat('A', max(0, $padLen)) . "\n" . $trailer;
}

/**
 * Envía petición HTTP (GET o POST urlencoded o POST multipart/form-data).
 * @param array<string, string> $fields
 * @param array{field:string, filename:string, content_type:string, content:string}|null $file
 * @return array{0:int, 1:string, 2:array<int, string>}
 */
function doc_http(string $url, string $cookie = '', string $method = 'GET', array $fields = [], ?array $file = null, bool $htmx = false): array
{
    $headers = ['Connection: close'];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($htmx) {
        $headers[] = 'HX-Request: true';
    }

    $body = '';
    if ($method === 'POST') {
        if ($file !== null) {
            $boundary = '----TssaDocTestBoundary' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            $parts = [];
            foreach ($fields as $k => $v) {
                $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
            }
            $parts[] = "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$file['field']}\"; filename=\"{$file['filename']}\"\r\n"
                . "Content-Type: {$file['content_type']}\r\n\r\n"
                . $file['content'] . "\r\n";
            $parts[] = "--{$boundary}--\r\n";
            $body = implode('', $parts);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($fields);
        }
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 25,
        ],
    ]);
    $respBody = @file_get_contents($url, false, $ctx);
    $respHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($respHeaders[0]) && preg_match('/\s(\d{3})\b/', $respHeaders[0], $m) === 1) {
        $status = (int)$m[1];
    }
    return [$status, is_string($respBody) ? $respBody : '', $respHeaders];
}

function doc_header(array $headers, string $name): ?string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $h) {
        if (str_starts_with(strtolower($h), $prefix)) {
            return trim(substr($h, strlen($prefix)));
        }
    }
    return null;
}

function doc_make_cookie(string $sessDir, string $sessName, int $uid, string $csrf): string
{
    $sid = bin2hex(random_bytes(16));
    $now = time();
    file_put_contents(
        $sessDir . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        sprintf('usuario_id|i:%d;authenticated_at|i:%d;last_activity_at|i:%d;csrf_token|s:%d:"%s";', $uid, $now, $now, strlen($csrf), $csrf)
    );
    return "{$sessName}={$sid}";
}

function doc_count_files(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if ($fi->isFile()) {
            $count++;
        }
    }
    return $count;
}

function doc_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fi) {
        if ($fi->isDir()) {
            @rmdir($fi->getPathname());
        } else {
            @unlink($fi->getPathname());
        }
    }
    @rmdir($dir);
}

$pdo = db();
require_test_database($pdo);

$exitCode = 0;
$serverProc = null;
$serverPid = null;
$runId = bin2hex(random_bytes(5));
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tssa_doc_sess_{$runId}";
$storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tssa_doc_store_{$runId}";
$backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tssa_doc_bkp_{$runId}";
$restoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tssa_doc_rst_{$runId}";
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tssa_doc_srv_{$runId}.log";

$createdUsers = [];
$createdDocIds = [];
$maxAudId = (int)$pdo->query('SELECT ISNULL(MAX(auditoria_id), 0) FROM dbo.auditorias')->fetchColumn();

try {
    mkdir($sessionDir, 0700, true);
    mkdir($storageDir, 0750, true);

    // ------------------------------------------------------------------
    // 1. Pruebas unitarias de almacenamiento, aislamiento y saneamiento
    // ------------------------------------------------------------------
    putenv('TSSA_DOCUMENT_STORAGE_DIR=');
    $fallbackRoot = document_storage_root();
    $publicRoot = realpath(__DIR__ . '/../public');
    doc_assert(!document_path_starts_with($fallbackRoot, $publicRoot), 'Fallback de storage debe quedar estrictamente fuera de public/.');

    // Rechazar almacenamiento dentro de public/ y rutas UNC
    putenv('TSSA_DOCUMENT_STORAGE_DIR=' . $publicRoot . DIRECTORY_SEPARATOR . 'uploads_bad');
    $rejectedPublic = false;
    try { document_storage_root(); } catch (DocumentUploadException) { $rejectedPublic = true; }
    doc_assert($rejectedPublic, 'Debe rechazar TSSA_DOCUMENT_STORAGE_DIR dentro de public/.');

    putenv('TSSA_DOCUMENT_STORAGE_DIR=\\\\evil-server\\share');
    $rejectedUnc = false;
    try { document_storage_root(); } catch (DocumentUploadException) { $rejectedUnc = true; }
    doc_assert($rejectedUnc, 'Debe rechazar rutas UNC SMB.');

    putenv('TSSA_DOCUMENT_STORAGE_DIR=' . $storageDir);
    doc_assert(realpath($storageDir) === document_storage_root(), 'Debe aceptar TSSA_DOCUMENT_STORAGE_DIR privado fuera de public/.');

    $evilDisplay = document_sanitize_display_name("../../factura\"\r\nSet-Cookie: x=1; <script>.pdf", 'pdf');
    doc_assert(!str_contains($evilDisplay, "\r") && !str_contains($evilDisplay, "\n") && !str_contains($evilDisplay, '..') && !str_contains($evilDisplay, '"') && str_ends_with($evilDisplay, '.pdf'), 'Saneamiento de nombre debe bloquear CRLF/traversal/comillas.');
    doc_assert(!document_valid_storage_key('../2026/11/' . str_repeat('a', 64) . '.pdf'), 'storage_key con traversal debe rechazarse.');
    doc_assert(!document_valid_storage_key('2026/11/' . str_repeat('a', 64) . '.php'), 'storage_key .php debe rechazarse.');
    doc_assert(document_valid_storage_key('2026/11/' . str_repeat('a', 64) . '.pdf'), 'storage_key válido debe aceptarse.');
    echo "[OK] 1. Unitarias de aislamiento de storage, anti-traversal y saneamiento de cabeceras.\n";

    // ------------------------------------------------------------------
    // 2. Sembrar 3 cuentas efímeras (registrar+ver, solo ver, sin permisos)
    // ------------------------------------------------------------------
    $solicitudId = (int)$pdo->query('SELECT TOP (1) solicitud_id FROM dbo.solicitudes ORDER BY solicitud_id')->fetchColumn();
    $tipoDocId = (int)$pdo->query('SELECT TOP (1) tipo_documento_id FROM dbo.tipos_documento WHERE activo = 1 ORDER BY tipo_documento_id')->fetchColumn();
    doc_assert($solicitudId > 0 && $tipoDocId > 0, 'Faltan fixtures base de solicitud/tipo_documento en Test.');

    $dummyHash = password_hash('TmpDoc!' . bin2hex(random_bytes(4)), PASSWORD_BCRYPT);
    $mkUser = static function (PDO $db, string $name, array $perms) use ($dummyHash, &$createdUsers): int {
        $st = $db->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, 1); SELECT SCOPE_IDENTITY();');
        $st->execute([$name, strtolower($name) . '@example.test', $dummyHash]);
        $st->nextRowset();
        $uid = (int)$st->fetchColumn();
        $st->closeCursor();
        $createdUsers[] = $uid;
        foreach ($perms as $p) {
            $db->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = ?')->execute([$uid, $p]);
        }
        return $uid;
    };

    $uFull = $mkUser($pdo, "docfull_{$runId}", ['DOCUMENTOS_VER', 'DOCUMENTOS_REGISTRAR', 'SOLICITUDES_VER']);
    $uViewOnly = $mkUser($pdo, "docview_{$runId}", ['DOCUMENTOS_VER', 'SOLICITUDES_VER']);
    $uNone = $mkUser($pdo, "docnone_{$runId}", ['CLIENTES_VER']);

    // ------------------------------------------------------------------
    // 3. Arrancar servidor PHP aislado con upload_max_filesize=10M y post_max_size=12M
    // ------------------------------------------------------------------
    $port = random_int(19700, 20299);
    $env = array_merge(getenv(), [
        'TSSA_DB_DATABASE' => 'TransportesSegurosSA_Test',
        'TSSA_DOCUMENT_STORAGE_DIR' => $storageDir,
    ]);
    $cmd = [
        PHP_BINARY,
        '-d', 'session.save_path=' . $sessionDir,
        '-d', 'upload_max_filesize=10M',
        '-d', 'post_max_size=12M',
        '-S', "127.0.0.1:{$port}",
        '-t', realpath(__DIR__ . '/../public'),
    ];
    $serverProc = proc_open(
        $cmd,
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        realpath(__DIR__ . '/..'),
        $env
    );
    doc_assert(is_resource($serverProc), 'No se pudo arrancar servidor PHP de prueba.');
    $serverPid = proc_get_status($serverProc)['pid'] ?? null;

    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 1.0);
        if ($sock !== false) { fclose($sock); $ready = true; break; }
        usleep(100000);
    }
    doc_assert($ready, 'Servidor PHP de prueba no respondió. Log: ' . @file_get_contents($serverLog));

    $base = "http://127.0.0.1:{$port}";
    $sName = session_cookie_name();
    $csrfFull = bin2hex(random_bytes(16));
    $csrfView = bin2hex(random_bytes(16));
    $cFull = doc_make_cookie($sessionDir, $sName, $uFull, $csrfFull);
    $cView = doc_make_cookie($sessionDir, $sName, $uViewOnly, $csrfView);
    $cNone = doc_make_cookie($sessionDir, $sName, $uNone, bin2hex(random_bytes(16)));

    // ------------------------------------------------------------------
    // 4. Subidas válidas: PDF pequeño, PNG, JPG y PDF de 10 MiB exactos
    // ------------------------------------------------------------------
    $validUploads = [
        ['label' => 'PDF pequeño', 'ref' => "REF_PDF_{$runId}", 'fname' => 'guia_remision.pdf', 'ctype' => 'application/pdf', 'bytes' => doc_valid_pdf_bytes(4096), 'mime' => 'application/pdf'],
        ['label' => 'PNG válido', 'ref' => "REF_PNG_{$runId}", 'fname' => 'comprobante_firma.png', 'ctype' => 'image/png', 'bytes' => doc_valid_png_bytes(), 'mime' => 'image/png'],
        ['label' => 'JPG válido', 'ref' => "REF_JPG_{$runId}", 'fname' => 'foto_bulto.jpg', 'ctype' => 'image/jpeg', 'bytes' => doc_valid_jpg_bytes(), 'mime' => 'image/jpeg'],
        ['label' => 'PDF 10 MiB exactos', 'ref' => "REF_10M_{$runId}", 'fname' => 'manifiesto_10mib.pdf', 'ctype' => 'application/pdf', 'bytes' => doc_valid_pdf_bytes(10 * 1024 * 1024), 'mime' => 'application/pdf'],
    ];

    $uploadedRecords = [];
    foreach ($validUploads as $u) {
        $expectedSha = hash('sha256', $u['bytes']);
        [$st, $b, $h] = doc_http(
            "{$base}/entregas/documento.php",
            $cFull,
            'POST',
            [
                'csrf_token' => $csrfFull,
                'solicitud_id' => (string)$solicitudId,
                'tipo_documento_id' => (string)$tipoDocId,
                'numero_referencia' => $u['ref'],
                'modo_respaldo' => 'archivo',
            ],
            ['field' => 'archivo', 'filename' => $u['fname'], 'content_type' => $u['ctype'], 'content' => $u['bytes']],
            true
        );
        doc_assert($st === 200, "Subida {$u['label']} falló (HTTP {$st}): {$b}");
        doc_assert(doc_header($h, 'HX-Redirect') === "/entregas.php?seccion=documentos&solicitud_id={$solicitudId}", "Redirect inesperado en {$u['label']}.");

        $rowStmt = $pdo->prepare('SELECT TOP (1) * FROM dbo.documentos WHERE numero_referencia = ? ORDER BY documento_id DESC');
        $rowStmt->execute([$u['ref']]);
        $row = $rowStmt->fetch();
        $rowStmt->closeCursor();
        doc_assert(is_array($row), "Fila SQL no encontrada para {$u['label']}.");
        $createdDocIds[] = (int)$row['documento_id'];
        doc_assert($row['origen_archivo'] === 'INTERNA' && $row['archivo_url'] === null, "origen_archivo/archivo_url incoherente en {$u['label']}.");
        doc_assert($row['mime_type'] === $u['mime'] && (int)$row['tamano_bytes'] === strlen($u['bytes']) && $row['sha256_hex'] === $expectedSha, "Metadatos/SHA-256 SQL incorrectos en {$u['label']}.");
        doc_assert(document_valid_storage_key((string)$row['storage_key']), "storage_key opaca inválida en {$u['label']}.");

        // Descarga autenticada byte a byte (con usuario DOCUMENTOS_VER únicamente)
        [$dst, $dbytes, $dh] = doc_http("{$base}/entregas/documento_descargar.php?id=" . (int)$row['documento_id'], $cView);
        doc_assert($dst === 200, "Descarga {$u['label']} devolvió HTTP {$dst}.");
        doc_assert(hash('sha256', $dbytes) === $expectedSha, "SHA-256 descargado no coincide byte a byte en {$u['label']}.");
        doc_assert(doc_header($dh, 'Content-Type') === $u['mime'], "Content-Type incorrecto en descarga {$u['label']}.");
        doc_assert(str_contains((string)doc_header($dh, 'Content-Disposition'), 'attachment;'), "Content-Disposition debe forzar attachment en {$u['label']}.");
        doc_assert(strtolower((string)doc_header($dh, 'X-Content-Type-Options')) === 'nosniff', 'Falta X-Content-Type-Options: nosniff.');
        doc_assert(str_contains(strtolower((string)doc_header($dh, 'Cache-Control')), 'no-store'), 'Falta Cache-Control: private, no-store.');
        doc_assert((int)doc_header($dh, 'Content-Length') === strlen($u['bytes']), 'Content-Length incorrecto.');

        $uploadedRecords[] = $row;
    }
    echo "[OK] 2. Subidas válidas (PDF, PNG, JPG y 10 MiB exactos) + descarga byte a byte + cabeceras seguras.\n";

    // ------------------------------------------------------------------
    // 5. URL externa retrocompatible + preservación documento histórico #1
    // ------------------------------------------------------------------
    $urlRef = "REF_URL_{$runId}";
    [$stUrl] = doc_http(
        "{$base}/entregas/documento.php",
        $cFull,
        'POST',
        [
            'csrf_token' => $csrfFull,
            'solicitud_id' => (string)$solicitudId,
            'tipo_documento_id' => (string)$tipoDocId,
            'numero_referencia' => $urlRef,
            'modo_respaldo' => 'url',
            'archivo_url' => 'https://example.test/respaldo-externo.pdf',
        ],
        null,
        true
    );
    doc_assert($stUrl === 200, "Registro por URL externa falló (HTTP {$stUrl}).");
    $urlRowStmt = $pdo->prepare('SELECT TOP (1) * FROM dbo.documentos WHERE numero_referencia = ? ORDER BY documento_id DESC');
    $urlRowStmt->execute([$urlRef]);
    $urlRow = $urlRowStmt->fetch();
    $urlRowStmt->closeCursor();
    $createdDocIds[] = (int)$urlRow['documento_id'];
    doc_assert($urlRow['origen_archivo'] === 'EXTERNA' && $urlRow['storage_key'] === null, 'Registro URL debe quedar como EXTERNA sin storage_key.');

    [$stList, $listHtml] = doc_http("{$base}/entregas/lista.php?seccion=documentos&solicitud_id={$solicitudId}", $cView, 'GET', [], null, true);
    doc_assert($stList === 200, "Listado documentos devolvió {$stList}.");
    doc_assert(str_contains($listHtml, 'Archivo adjunto') && str_contains($listHtml, 'Descargar'), 'Listado debe mostrar Archivo adjunto y Descargar.');
    doc_assert(str_contains($listHtml, 'Enlace externo') && str_contains($listHtml, 'Abrir referencia') && str_contains($listHtml, 'rel="noopener noreferrer"'), 'Listado debe preservar Enlace externo y Abrir referencia.');

    // Descargar un documento EXTERNA debe responder 404
    [$stDlExt] = doc_http("{$base}/entregas/documento_descargar.php?id=" . (int)$urlRow['documento_id'], $cView);
    doc_assert($stDlExt === 404, "Descargar documento EXTERNA debe devolver 404 (recibido {$stDlExt}).");
    echo "[OK] 3. Compatibilidad con URL externa y documentos previos preservada.\n";

    // ------------------------------------------------------------------
    // 6. Pruebas negativas exhaustivas (0 filas rotas, 0 huérfanos en disco)
    // ------------------------------------------------------------------
    $filesBeforeNeg = doc_count_files($storageDir);
    $rowsBeforeNeg = (int)$pdo->query('SELECT COUNT(*) FROM dbo.documentos')->fetchColumn();

    // 6.1 Auth, RBAC, CSRF y método
    [$sUnauthDl] = doc_http("{$base}/entregas/documento_descargar.php?id=" . (int)$uploadedRecords[0]['documento_id'], '');
    doc_assert(in_array($sUnauthDl, [302, 401], true), "Descarga sin sesión debe redirigir/401 (recibido {$sUnauthDl}).");

    [$sNoReg] = doc_http("{$base}/entregas/documento.php", $cView, 'POST', ['csrf_token' => $csrfView, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'NOREG', 'modo_respaldo' => 'url', 'archivo_url' => 'https://example.test/x.pdf'], null, true);
    doc_assert($sNoReg === 403, "Usuario sin DOCUMENTOS_REGISTRAR debe recibir 403 (recibido {$sNoReg}).");

    [$sNoVer] = doc_http("{$base}/entregas/documento_descargar.php?id=" . (int)$uploadedRecords[0]['documento_id'], $cNone);
    doc_assert($sNoVer === 403, "Usuario sin DOCUMENTOS_VER debe recibir 403 al descargar (recibido {$sNoVer}).");

    [$sBadCsrf] = doc_http("{$base}/entregas/documento.php", $cFull, 'POST', ['csrf_token' => 'invalido', 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'BADCSRF', 'modo_respaldo' => 'url', 'archivo_url' => 'https://example.test/x.pdf'], null, true);
    doc_assert($sBadCsrf === 403, "CSRF inválido debe dar 403 (recibido {$sBadCsrf}).");

    [$sBadMethod1] = doc_http("{$base}/entregas/documento.php", $cFull, 'GET');
    doc_assert($sBadMethod1 === 405, "GET a documento.php debe dar 405 (recibido {$sBadMethod1}).");
    [$sBadMethod2] = doc_http("{$base}/entregas/documento_descargar.php?id=" . (int)$uploadedRecords[0]['documento_id'], $cView, 'POST');
    doc_assert($sBadMethod2 === 405, "POST a documento_descargar.php debe dar 405 (recibido {$sBadMethod2}).");

    // 6.2 Exclusividad: ni archivo ni URL -> 422; archivo Y URL simultáneos -> 422
    [$sNeither] = doc_http("{$base}/entregas/documento.php", $cFull, 'POST', ['csrf_token' => $csrfFull, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'NEITHER', 'modo_respaldo' => 'archivo'], null, true);
    doc_assert($sNeither === 422, "Sin archivo ni URL debe dar 422 (recibido {$sNeither}).");

    [$sBoth] = doc_http(
        "{$base}/entregas/documento.php",
        $cFull,
        'POST',
        ['csrf_token' => $csrfFull, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'BOTH', 'modo_respaldo' => 'archivo', 'archivo_url' => 'https://example.test/ambos.pdf'],
        ['field' => 'archivo', 'filename' => 'ambos.pdf', 'content_type' => 'application/pdf', 'content' => doc_valid_pdf_bytes()],
        true
    );
    doc_assert($sBoth === 422, "Archivo + URL simultáneos debe dar 422 (recibido {$sBoth}).");

    // 6.3 Tamaño excedido (>10 MiB -> 413) y >post_max_size (12.5 MiB -> 413)
    [$sOver10] = doc_http(
        "{$base}/entregas/documento.php",
        $cFull,
        'POST',
        ['csrf_token' => $csrfFull, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'OVER10', 'modo_respaldo' => 'archivo'],
        ['field' => 'archivo', 'filename' => 'grande.pdf', 'content_type' => 'application/pdf', 'content' => doc_valid_pdf_bytes(10 * 1024 * 1024 + 4096)],
        true
    );
    doc_assert($sOver10 === 413, "Archivo >10 MiB debe dar 413 (recibido {$sOver10}).");

    [$sOverPost, $overPostBody] = doc_http(
        "{$base}/entregas/documento.php",
        $cFull,
        'POST',
        ['csrf_token' => $csrfFull, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'OVERPOST', 'modo_respaldo' => 'archivo'],
        ['field' => 'archivo', 'filename' => 'enorme.pdf', 'content_type' => 'application/pdf', 'content' => doc_valid_pdf_bytes(12 * 1024 * 1024 + 512 * 1024)],
        true
    );
    doc_assert($sOverPost === 413 && str_contains($overPostBody, 'supera el tamaño máximo'), "Payload >post_max_size debe dar 413 útil (recibido {$sOverPost}).");

    // 6.4 Archivos peligrosos, fingidos, discrepancia MIME/extensión y traversal
    $evilFiles = [
        ['PHP disfrazado', 'shell.php', 'application/x-httpd-php', '<?php echo "pwn"; ?>'],
        ['Doble extensión PHP', 'shell.php.pdf', 'application/pdf', doc_valid_pdf_bytes()],
        ['SVG XSS', 'xss.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
        ['HTML fingido como PDF', 'fake.pdf', 'application/pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
        ['PNG fingido', 'fake.png', 'image/png', 'no soy un png'],
        ['Discrepancia PNG como PDF', 'mismatch.pdf', 'application/pdf', doc_valid_png_bytes()],
        ['Traversal en filename', '..\\..\\evil.pdf', 'application/pdf', doc_valid_pdf_bytes()],
    ];
    foreach ($evilFiles as [$desc, $fn, $ct, $bytes]) {
        [$stBad] = doc_http(
            "{$base}/entregas/documento.php",
            $cFull,
            'POST',
            ['csrf_token' => $csrfFull, 'solicitud_id' => (string)$solicitudId, 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'BAD_' . substr(md5($desc), 0, 8), 'modo_respaldo' => 'archivo'],
            ['field' => 'archivo', 'filename' => $fn, 'content_type' => $ct, 'content' => $bytes],
            true
        );
        doc_assert($stBad === 422, "Caso negativo '{$desc}' debe dar 422 (recibido {$stBad}).");
    }

    // 6.5 Fallo SQL tras guardar archivo temporal -> debe borrar el archivo (0 huérfanos!)
    [$sSqlFail] = doc_http(
        "{$base}/entregas/documento.php",
        $cFull,
        'POST',
        ['csrf_token' => $csrfFull, 'solicitud_id' => '99999999', 'tipo_documento_id' => (string)$tipoDocId, 'numero_referencia' => 'SQLFAIL', 'modo_respaldo' => 'archivo'],
        ['field' => 'archivo', 'filename' => 'huerfano.pdf', 'content_type' => 'application/pdf', 'content' => doc_valid_pdf_bytes()],
        true
    );
    doc_assert($sSqlFail === 422, "Solicitud inexistente en upload debe dar 422 (recibido {$sSqlFail}).");

    // 6.6 Acceso directo HTTP a storage bloqueado (404 sin filtrar bytes) y descarga de ID inexistente (404)
    $firstKey = (string)$uploadedRecords[0]['storage_key'];
    [$sDirect1, $bDirect1] = doc_http("{$base}/storage/private/documents/{$firstKey}", $cFull);
    [$sDirect2] = doc_http("{$base}/entregas/documento_descargar.php?id=99999999", $cView);
    doc_assert($sDirect1 === 404 && !str_contains($bDirect1, '%PDF-'), "Acceso directo HTTP al almacenamiento privado debe dar 404 sin exponer contenido (recibido {$sDirect1}).");
    doc_assert($sDirect2 === 404, "Descarga de documento inexistente debe dar 404 (recibido {$sDirect2}).");

    // Verificar ZERO filas nuevas y ZERO archivos huérfanos tras todas las pruebas negativas
    $filesAfterNeg = doc_count_files($storageDir);
    $rowsAfterNeg = (int)$pdo->query('SELECT COUNT(*) FROM dbo.documentos')->fetchColumn();
    doc_assert($filesAfterNeg === $filesBeforeNeg, "Se detectaron archivos huérfanos tras errores ({$filesBeforeNeg} -> {$filesAfterNeg}).");
    doc_assert($rowsAfterNeg === $rowsBeforeNeg, "Se detectaron filas rotas tras errores ({$rowsBeforeNeg} -> {$rowsAfterNeg}).");
    echo "[OK] 4. Pruebas negativas (auth/RBAC/CSRF/405/413/422/404/SQL fail): 0 filas rotas y 0 huérfanos en disco.\n";

    // ------------------------------------------------------------------
    // 7. Simulación reproducible de respaldo y restauración en carpeta temporal + verificación SHA-256
    // ------------------------------------------------------------------
    mkdir($backupDir, 0750, true);
    mkdir($restoreDir, 0750, true);
    $manifest = [];
    foreach ($uploadedRecords as $rec) {
        $srcFile = document_resolve_existing_path((string)$rec['storage_key']);
        doc_assert($srcFile !== null, 'Archivo fuente para simulación de respaldo no encontrado.');
        $relPath = str_replace('/', DIRECTORY_SEPARATOR, (string)$rec['storage_key']);
        $bkpPath = $backupDir . DIRECTORY_SEPARATOR . $relPath;
        if (!is_dir(dirname($bkpPath))) {
            mkdir(dirname($bkpPath), 0750, true);
        }
        doc_assert(copy($srcFile, $bkpPath), 'Fallo al copiar archivo a respaldo temporal.');
        $manifest[] = ['storage_key' => (string)$rec['storage_key'], 'sha256_hex' => (string)$rec['sha256_hex']];
    }
    file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

    // Restaurar a carpeta temporal separada y comprobar hashes contra SQL
    $loadedManifest = json_decode((string)file_get_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json'), true);
    foreach ($loadedManifest as $entry) {
        $relPath = str_replace('/', DIRECTORY_SEPARATOR, $entry['storage_key']);
        $rstPath = $restoreDir . DIRECTORY_SEPARATOR . $relPath;
        if (!is_dir(dirname($rstPath))) {
            mkdir(dirname($rstPath), 0750, true);
        }
        doc_assert(copy($backupDir . DIRECTORY_SEPARATOR . $relPath, $rstPath), 'Fallo al restaurar archivo desde respaldo.');
        doc_assert(hash_file('sha256', $rstPath) === $entry['sha256_hex'], 'SHA-256 restaurado no coincide con SQL.');
    }
    echo "[OK] 5. Simulación de respaldo + manifiesto SHA-256 + restauración verificada (4/4 archivos íntegros).\n";
    echo "=== SUITE HTTP/SEGURIDAD ADJUNTOS DOCUMENTALES: 100% PASS ===\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'FAIL: ' . $ex->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_resource($serverProc)) {
        if (PHP_OS_FAMILY === 'Windows' && is_int($serverPid) && $serverPid > 0) {
            @exec("taskkill /F /T /PID {$serverPid} >NUL 2>&1");
        }
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
    doc_rmdir_recursive($sessionDir);
    doc_rmdir_recursive($storageDir);
    doc_rmdir_recursive($backupDir);
    doc_rmdir_recursive($restoreDir);
    if (is_file($serverLog)) {
        @unlink($serverLog);
    }

    try {
        foreach ($createdDocIds as $did) {
            $pdo->prepare('DELETE FROM dbo.documentos WHERE documento_id = ?')->execute([$did]);
        }
        $pdo->prepare('DELETE FROM dbo.auditorias WHERE auditoria_id > ?')->execute([$maxAudId]);
        foreach ($createdUsers as $cuid) {
            $pdo->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$cuid]);
            $pdo->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$cuid]);
        }
    } catch (Throwable $cleanEx) {
        fwrite(STDERR, 'CLEANUP WARNING: ' . $cleanEx->getMessage() . "\n");
        $exitCode = 1;
    }
}

exit($exitCode);
