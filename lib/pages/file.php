<?php
// Sert un justificatif aux utilisateurs connectés uniquement.
$e = load_entry((int)($_GET['id'] ?? 0));
$path = $e && $e['receipt'] ? UPLOAD_DIR . '/' . basename($e['receipt']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Justificatif introuvable.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="justificatif-' . (int)$e['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=3600');
header_remove('Content-Security-Policy');
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
}
readfile($path);
exit;
