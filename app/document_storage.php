<?php
declare(strict_types=1);

/**
 * Servicio de almacenamiento privado y validación multicapa de adjuntos documentales
 * (PDF, JPG/JPEG y PNG) fuera del document root public/.
 *
 * Controles OWASP ASVS:
 * - Aislamiento estricto fuera de public/ (sin acceso directo HTTP a blobs).
 * - Claves aleatorias opacas (nunca usar el nombre original del cliente como ruta).
 * - Validación real por finfo_file + firma estructural (%PDF- / getimagesize) + extensión.
 * - Protección contra path traversal, symlinks/reparse points e inyección CRLF en cabeceras.
 * - Nunca expone rutas absolutas del servidor ni datos privados en mensajes o logs.
 */

const DOCUMENT_MAX_BYTES = 10485760; // 10 MiB exactos (10 * 1024 * 1024)

/**
 * Excepción controlada con código HTTP asociado (413, 422, 500) y mensaje seguro para el usuario.
 */
final class DocumentUploadException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

function document_parse_ini_bytes(string|false $value): int
{
    if ($value === false) {
        return 0;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '0' || $trimmed === '-1') {
        return 0;
    }
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?$/i', $trimmed, $m)) {
        return 0;
    }
    $num = (float)$m[1];
    $unit = strtoupper($m[2] ?? '');
    $mult = match ($unit) {
        'G' => 1073741824,
        'M' => 1048576,
        'K' => 1024,
        default => 1,
    };
    return (int)floor($num * $mult);
}

/**
 * Límite máximo real aceptado por esta instancia PHP, acotado al tope de negocio de 10 MiB.
 * Refleja con honestidad upload_max_filesize y post_max_size del runtime activo.
 */
function document_effective_max_bytes(): int
{
    $limit = DOCUMENT_MAX_BYTES;
    $uploadMax = document_parse_ini_bytes(ini_get('upload_max_filesize'));
    if ($uploadMax > 0) {
        $limit = min($limit, $uploadMax);
    }
    $postMax = document_parse_ini_bytes(ini_get('post_max_size'));
    if ($postMax > 0) {
        // Reservar ~256 KiB para el encabezado multipart y campos del formulario.
        $postAllowance = max(1, $postMax - 262144);
        $limit = min($limit, $postAllowance);
    }
    return max(1, $limit);
}

function document_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return format_cr_measure($bytes / 1024, 1) . ' KB';
    }
    return format_cr_measure($bytes / 1048576, 2) . ' MB';
}

/**
 * Detecta cuando PHP descartó silenciosamente $_POST y $_FILES por exceder post_max_size.
 */
function document_request_exceeded_post_max_size(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $postMax = document_parse_ini_bytes(ini_get('post_max_size'));
    if ($postMax <= 0) {
        return false;
    }
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    return $contentLength > $postMax && empty($_POST) && empty($_FILES);
}

function document_is_absolute_local_path(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
        return false;
    }
    // Bloquear rutas UNC (\\servidor\recurso) para evitar accesos SMB/SSRF en Windows.
    if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
    return str_starts_with($path, '/');
}

function document_path_starts_with(string $candidate, string $prefix): bool
{
    $normCand = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
    $normPref = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $prefix), DIRECTORY_SEPARATOR);
    if (PHP_OS_FAMILY === 'Windows') {
        $normCand = strtolower($normCand);
        $normPref = strtolower($normPref);
    }
    return $normCand === $normPref || str_starts_with($normCand, $normPref . DIRECTORY_SEPARATOR);
}

/**
 * Resuelve y verifica el directorio privado de almacenamiento fuera de public/.
 * Crea el directorio si no existe y rechaza symlinks o rutas dentro de public/.
 */
function document_storage_root(): string
{
    $repoRoot = realpath(dirname(__DIR__));
    $publicRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($repoRoot === false || $publicRoot === false) {
        error_log('TSSA documents: unable to resolve repository/public roots');
        throw new DocumentUploadException('El repositorio privado de documentos no está disponible.', 500);
    }

    $configured = trim((string)(getenv('TSSA_DOCUMENT_STORAGE_DIR') ?: ''));
    if ($configured !== '') {
        if (!document_is_absolute_local_path($configured)) {
            error_log('TSSA documents: invalid TSSA_DOCUMENT_STORAGE_DIR configuration');
            throw new DocumentUploadException('El repositorio privado de documentos no está configurado correctamente.', 500);
        }
        $target = rtrim($configured, "/\\");
    } else {
        $target = $repoRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'documents';
    }

    if (is_link($target)) {
        error_log('TSSA documents: storage root cannot be a symlink');
        throw new DocumentUploadException('El repositorio privado de documentos no cumple la política de aislamiento.', 500);
    }

    if (!is_dir($target)) {
        if (!@mkdir($target, 0750, true) && !is_dir($target)) {
            error_log('TSSA documents: unable to create private storage directory');
            throw new DocumentUploadException('No se pudo preparar el directorio privado de documentos. Contacta al administrador.', 500);
        }
    }

    $realTarget = realpath($target);
    if ($realTarget === false || !is_dir($realTarget) || is_link($realTarget)) {
        error_log('TSSA documents: resolved storage directory invalid or symlink');
        throw new DocumentUploadException('El repositorio privado de documentos no está disponible.', 500);
    }

    if (document_path_starts_with($realTarget, $publicRoot)) {
        error_log('TSSA documents: storage directory inside public/ rejected');
        throw new DocumentUploadException('El almacenamiento de documentos debe residir fuera del directorio público.', 500);
    }

    if (!is_writable($realTarget)) {
        error_log('TSSA documents: storage directory is not writable');
        throw new DocumentUploadException('El repositorio privado de documentos no tiene permisos de escritura.', 500);
    }

    return $realTarget;
}

/**
 * Sanea el nombre original únicamente para presentación y cabecera de descarga;
 * jamás se emplea para construir rutas en disco.
 */
function document_sanitize_display_name(string $rawName, string $canonicalExt): string
{
    $normalized = str_replace('\\', '/', $rawName);
    $base = basename($normalized);
    $base = preg_replace('/[\x00-\x1F\x7F"<>|:*?\/\\\\;]+/u', ' ', $base) ?? '';
    $base = str_replace('..', '.', $base);
    $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '', " .\t\n\r");

    $extSuffix = '.' . $canonicalExt;
    $stem = preg_replace('/\.[^.]+$/u', '', $base) ?? $base;
    $stem = trim($stem, " ._-");
    if ($stem === '') {
        $stem = 'documento';
    }
    if (mb_strlen($stem) > 160) {
        $stem = rtrim(mb_substr($stem, 0, 160), " ._-");
    }
    return $stem . $extSuffix;
}

/**
 * Genera un nombre ASCII estricto para el atributo filename="..." de Content-Disposition.
 */
function document_header_filename_ascii(string $safeName): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeName) ?? 'documento';
    $ascii = trim($ascii, '._-');
    if ($ascii === '' || !str_contains($ascii, '.')) {
        return 'documento.bin';
    }
    return substr($ascii, 0, 160);
}

/**
 * Valida la sintaxis canónica de una clave opaca persistida en SQL.
 */
function document_valid_storage_key(string $storageKey): bool
{
    return preg_match('/^[0-9]{4}\/[0-9]{2}\/[0-9a-f]{64}\.(pdf|jpg|png)$/', $storageKey) === 1;
}

/**
 * Valida en múltiples capas un archivo recibido en $_FILES y lo almacena bajo clave opaca.
 *
 * @param array<string, mixed> $file Entrada de $_FILES['archivo']
 * @param callable(string):bool|null $isUploadedFn Solo para inyección en pruebas unitarias CLI
 * @return array{
 *   storage_key: string,
 *   nombre_original: string,
 *   mime_type: string,
 *   tamano_bytes: int,
 *   sha256_hex: string
 * }
 */
function document_validate_and_store_upload(array $file, ?callable $isUploadedFn = null): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new DocumentUploadException('Envía un único archivo válido por documento.', 422);
    }

    $errCode = (int)$file['error'];
    $effectiveMax = document_effective_max_bytes();
    $maxLabel = document_format_bytes($effectiveMax);

    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        throw new DocumentUploadException("El archivo supera el tamaño máximo permitido ({$maxLabel}).", 413);
    }
    if ($errCode === UPLOAD_ERR_NO_FILE) {
        throw new DocumentUploadException('Selecciona un archivo PDF, JPG o PNG para subir.', 422);
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        throw new DocumentUploadException('La carga del archivo no pudo completarse. Inténtalo nuevamente.', 422);
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $checkUploaded = $isUploadedFn ?? static fn(string $p): bool => is_uploaded_file($p);
    if ($tmpPath === '' || !is_file($tmpPath) || !$checkUploaded($tmpPath)) {
        throw new DocumentUploadException('El archivo recibido no supera la verificación de carga segura.', 422);
    }

    $rawName = (string)($file['name'] ?? '');
    $rawFullPath = (string)($file['full_path'] ?? '');
    if ($rawName === ''
        || str_contains($rawName, "\0")
        || str_contains($rawName, '..')
        || str_contains($rawName, '/')
        || str_contains($rawName, '\\')
        || ($rawFullPath !== '' && (str_contains($rawFullPath, "\0") || str_contains($rawFullPath, '..') || str_contains($rawFullPath, '/') || str_contains($rawFullPath, '\\')))) {
        throw new DocumentUploadException('El nombre del archivo contiene caracteres o rutas no permitidas.', 422);
    }

    // Bloquear extensiones dobles ejecutables/peligrosas (ej. shell.php.pdf o xss.svg.png)
    if (preg_match('/\.(php\d*|phtml|phar|exe|bat|cmd|com|sh|ps1|js|mjs|html?|xhtml|svg|xml|asp|aspx|jsp)\./i', $rawName) === 1) {
        throw new DocumentUploadException('El nombre del archivo contiene una extensión intermedia no permitida.', 422);
    }

    $ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        throw new DocumentUploadException('Formato no permitido. Solo se aceptan archivos PDF, JPG/JPEG y PNG.', 422);
    }

    $size = @filesize($tmpPath);
    if ($size === false || $size < 1) {
        throw new DocumentUploadException('El archivo seleccionado está vacío o no se pudo leer.', 422);
    }
    if ($size > $effectiveMax) {
        throw new DocumentUploadException("El archivo supera el tamaño máximo permitido ({$maxLabel}).", 413);
    }

    if (!class_exists('finfo')) {
        error_log('TSSA documents: fileinfo extension missing');
        throw new DocumentUploadException('El servidor no tiene habilitada la verificación segura de tipos de archivo.', 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = strtolower(trim((string)($finfo->file($tmpPath) ?: '')));
    $allowedMap = [
        'application/pdf' => ['exts' => ['pdf'], 'canonical' => 'pdf'],
        'image/jpeg'      => ['exts' => ['jpg', 'jpeg'], 'canonical' => 'jpg'],
        'image/png'       => ['exts' => ['png'], 'canonical' => 'png'],
    ];

    if (!isset($allowedMap[$detectedMime])) {
        throw new DocumentUploadException('El contenido del archivo no corresponde a un PDF, JPG o PNG válido.', 422);
    }

    if (!in_array($ext, $allowedMap[$detectedMime]['exts'], true)) {
        throw new DocumentUploadException('La extensión del archivo no coincide con su contenido real.', 422);
    }

    // Validación estructural del formato real
    if ($detectedMime === 'application/pdf') {
        $fh = @fopen($tmpPath, 'rb');
        $head = $fh ? (string)fread($fh, 1024) : '';
        if ($fh) {
            fclose($fh);
        }
        if (!str_starts_with($head, '%PDF-') || preg_match('/<(!doctype|html|svg|script|\?php)/i', $head) === 1) {
            throw new DocumentUploadException('El archivo PDF está dañado o no presenta una cabecera PDF válida.', 422);
        }
    } else {
        $imgInfo = @getimagesize($tmpPath);
        $expectedType = $detectedMime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if (!is_array($imgInfo) || (int)$imgInfo[0] < 1 || (int)$imgInfo[1] < 1 || (int)$imgInfo[2] !== $expectedType) {
            throw new DocumentUploadException('La imagen seleccionada está dañada o no coincide con su formato.', 422);
        }
    }

    $canonicalExt = $allowedMap[$detectedMime]['canonical'];
    $safeDisplayName = document_sanitize_display_name($rawName, $canonicalExt);
    $sha256 = hash_file('sha256', $tmpPath);
    if (!is_string($sha256) || strlen($sha256) !== 64) {
        throw new DocumentUploadException('No se pudo calcular la huella de integridad del archivo.', 500);
    }

    // Almacenar bajo clave opaca aleatoria dentro del directorio privado
    $root = document_storage_root();
    $year = gmdate('Y');
    $month = gmdate('m');
    $yearDir = $root . DIRECTORY_SEPARATOR . $year;
    $monthDir = $yearDir . DIRECTORY_SEPARATOR . $month;

    foreach ([$yearDir, $monthDir] as $dir) {
        if (is_link($dir)) {
            error_log('TSSA documents: symlink detected in storage hierarchy');
            throw new DocumentUploadException('El repositorio privado de documentos no cumple la política de seguridad.', 500);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('TSSA documents: unable to create dated storage subdirectory');
            throw new DocumentUploadException('No se pudo almacenar el archivo en el repositorio privado.', 500);
        }
    }

    $realMonthDir = realpath($monthDir);
    if ($realMonthDir === false || !document_path_starts_with($realMonthDir, $root)) {
        error_log('TSSA documents: month directory escaped storage root');
        throw new DocumentUploadException('No se pudo validar la ruta interna de almacenamiento.', 500);
    }

    $randomToken = bin2hex(random_bytes(32));
    $storageKey = "{$year}/{$month}/{$randomToken}.{$canonicalExt}";
    $destPath = $realMonthDir . DIRECTORY_SEPARATOR . "{$randomToken}.{$canonicalExt}";

    $moved = ($isUploadedFn === null)
        ? @move_uploaded_file($tmpPath, $destPath)
        : @copy($tmpPath, $destPath);

    if (!$moved || !is_file($destPath) || is_link($destPath)) {
        @unlink($destPath);
        error_log('TSSA documents: failed to move uploaded file into private storage');
        throw new DocumentUploadException('No se pudo guardar el archivo en el repositorio privado.', 500);
    }

    @chmod($destPath, 0640);
    $storedHash = hash_file('sha256', $destPath);
    $storedSize = @filesize($destPath);
    if ($storedHash !== $sha256 || $storedSize !== $size) {
        @unlink($destPath);
        error_log('TSSA documents: post-write integrity verification failed');
        throw new DocumentUploadException('No se pudo verificar la integridad del archivo almacenado.', 500);
    }

    return [
        'storage_key' => $storageKey,
        'nombre_original' => $safeDisplayName,
        'mime_type' => $detectedMime,
        'tamano_bytes' => (int)$size,
        'sha256_hex' => strtolower($sha256),
    ];
}

/**
 * Resuelve de forma segura la ruta física de una clave opaca ya registrada en SQL.
 */
function document_resolve_existing_path(string $storageKey): ?string
{
    if (!document_valid_storage_key($storageKey)) {
        return null;
    }
    try {
        $root = document_storage_root();
    } catch (DocumentUploadException) {
        return null;
    }

    $parts = explode('/', $storageKey);
    if (count($parts) !== 3) {
        return null;
    }
    $yearDir = $root . DIRECTORY_SEPARATOR . $parts[0];
    $monthDir = $yearDir . DIRECTORY_SEPARATOR . $parts[1];
    $fullPath = $monthDir . DIRECTORY_SEPARATOR . $parts[2];

    if (is_link($yearDir) || is_link($monthDir) || is_link($fullPath)) {
        error_log('TSSA documents: symlink blocked on download');
        return null;
    }

    $realPath = realpath($fullPath);
    if ($realPath === false || !document_path_starts_with($realPath, $root)) {
        return null;
    }
    if (!is_file($realPath) || !is_readable($realPath)) {
        return null;
    }
    return $realPath;
}

/**
 * Elimina un archivo recién almacenado si la transacción SQL falla (evita huérfanos).
 */
function document_delete_stored_file(string $storageKey): void
{
    $path = document_resolve_existing_path($storageKey);
    if ($path !== null) {
        @unlink($path);
    }
}
