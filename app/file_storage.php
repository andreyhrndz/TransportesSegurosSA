<?php
declare(strict_types=1);

/**
 * Almacenamiento seguro de comprobantes y documentos fuera de public/ (OWASP ASVS Nivel 2).
 */

const TSSA_MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

/** @return array<string, string> */
function storage_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];
}

function storage_proofs_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'comprobantes';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo preparar el directorio de almacenamiento.');
    }
    return $dir;
}

/**
 * Valida y guarda un archivo subido (JPG/PNG/PDF, <= 5 MB).
 * Retorna null si no se adjuntó ningún archivo.
 *
 * @return array{nombre_original: string, mime_type: string, tamano_bytes: int, ruta_interna: string}|null
 */
function storage_save_uploaded_proof(string $fieldName): ?array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('El archivo excede el tamaño máximo permitido de 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No se pudo recibir el archivo adjunto. Inténtalo de nuevo.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('Archivo adjunto inválido.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > TSSA_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('El archivo debe pesar entre 1 byte y 5 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($tmpPath) ?: '');
    $allowed = storage_allowed_mimes();
    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException('Formato no permitido. Solo se aceptan imágenes JPG, PNG o documentos PDF.');
    }

    $ext = $allowed[$mime];
    $safeRandomName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetDir = storage_proofs_dir();
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeRandomName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('No se pudo almacenar el archivo en el servidor.');
    }

    $rawName = basename((string)($file['name'] ?? ('comprobante.' . $ext)));
    $cleanOriginal = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $rawName) ?? ('comprobante.' . $ext);
    $cleanOriginal = mb_substr(trim($cleanOriginal) ?: ('comprobante.' . $ext), 0, 255, 'UTF-8');

    return [
        'nombre_original' => $cleanOriginal,
        'mime_type' => $mime,
        'tamano_bytes' => $size,
        'ruta_interna' => $safeRandomName,
    ];
}

function storage_resolve_proof_path(string $relativeName): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $relativeName)) {
        return null;
    }
    $baseDir = realpath(storage_proofs_dir());
    if ($baseDir === false) {
        return null;
    }
    $fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . $relativeName);
    if ($fullPath === false || !str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function storage_delete_proof_file(?string $relativeName): void
{
    if ($relativeName === null || $relativeName === '') {
        return;
    }
    $fullPath = storage_resolve_proof_path($relativeName);
    if ($fullPath !== null) {
        @unlink($fullPath);
    }
}
