<?php
/*
 * Safe file uploads. Files are checked by their real content type, renamed to a random
 * name, and stored in the private storage folder (never directly reachable from the web).
 * They are served only through access-checked download scripts.
 */
require_once __DIR__ . '/config.php';

const CR_UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

function cr_allowed_upload_types(): array {
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

/**
 * Stores an uploaded file from $_FILES. Returns ['path','original_name','mime'] on success
 * or ['error' => message] on failure. 'path' is relative to the storage folder.
 */
function cr_store_upload(array $file, string $folder): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['error' => 'The file is too large (maximum 10 MB).'];
    if ($err !== UPLOAD_ERR_OK) return ['error' => 'File upload failed. Please try again.'];
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return ['error' => 'File upload failed. Please try again.'];
    if ((int)$file['size'] <= 0 || (int)$file['size'] > CR_UPLOAD_MAX_BYTES) return ['error' => 'The file is too large (maximum 10 MB).'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $allowed = cr_allowed_upload_types();
    if (!isset($allowed[$mime])) return ['error' => 'Only PDF, JPG, PNG or WEBP files are allowed.'];

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder);
    $relative = $folder . '/' . date('Y/m');
    $dir = cr_storage_path($relative);
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        error_log('Upload move failed to ' . $dir);
        return ['error' => 'File could not be saved. Please check folder permissions.'];
    }

    $original = preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename((string)($file['name'] ?? 'document'))) ?: 'document';
    return ['path' => $relative . '/' . $name, 'original_name' => mb_substr($original, 0, 200), 'mime' => $mime];
}

/** Sends a stored file to the browser. $relativePath comes from the database, never from the request. */
function cr_send_stored_file(string $relativePath, string $downloadName, string $mime): void {
    $base = realpath(cr_storage_path());
    $full = realpath(cr_storage_path() . '/' . ltrim($relativePath, '/'));
    if (!$base || !$full || strpos($full, $base) !== 0 || !is_file($full)) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = array_key_exists($mime, cr_allowed_upload_types()) ? $mime : 'application/octet-stream';
    $inline = $mime !== 'application/octet-stream';
    $safeName = str_replace(['"', "\r", "\n"], '', $downloadName);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
}
