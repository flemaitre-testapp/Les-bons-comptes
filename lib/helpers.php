<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function url(string $page, array $params = []): string
{
    $params = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function money(int $cents, bool $sign = false): string
{
    $s = number_format(abs($cents) / 100, 2, ',', "\u{202F}") . "\u{00A0}€";
    if ($cents < 0) {
        return '-' . $s;
    }
    return ($sign && $cents > 0 ? '+' : '') . $s;
}

/** "12,50" / "12.5" / "1 234,56 €" -> 1250 ; null si invalide */
function parse_money(string $raw): ?int
{
    $s = str_replace(["\u{202F}", "\u{00A0}", ' ', '€'], '', trim($raw));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int)round(((float)$s) * 100);
}

/** "40" / "33,33" -> basis points (4000 / 3333) */
function parse_pct(string $raw): ?int
{
    $s = str_replace([',', '%', ' '], ['.', '', ''], trim($raw));
    if (!is_numeric($s)) {
        return null;
    }
    $bp = (int)round(((float)$s) * 100);
    return ($bp < 0 || $bp > 10000) ? null : $bp;
}

function pct(int $bp): string
{
    $v = $bp / 100;
    return (floor($v) == $v ? (string)(int)$v : number_format($v, 2, ',', '')) . "\u{00A0}%";
}

function fdate(?string $iso, bool $time = false): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $time ? date('d/m/Y à H:i', $t) : date('d/m/Y', $t);
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

const MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function month_label(string $ym): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    return MOIS[$m] . ' ' . $y;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée, recharge la page.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function categories(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM categories' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, name';
    return q($sql)->fetchAll();
}

function category_name(?int $id): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = array_column(categories(false), 'name', 'id');
    }
    return $id ? ($cache[$id] ?? '') : '';
}

/**
 * Enregistre un justificatif uploadé. Retourne [nom_stocké, nom_original, sha256] ou null.
 * Lève une RuntimeException si le fichier est refusé.
 */
function store_receipt(string $field): ?array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Échec de l\'envoi du justificatif (fichier trop lourd ?).');
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Justificatif trop lourd (10 Mo maximum).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/heif' => 'heic', 'application/pdf' => 'pdf',
    ][$mime] ?? null;
    if (!$ext) {
        throw new RuntimeException('Format refusé : photo (JPG, PNG, WEBP, HEIC) ou PDF uniquement.');
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Impossible d\'enregistrer le justificatif.');
    }
    $orig = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '_', (string)$f['name']), 0, 120);
    return [$name, $orig, hash_file('sha256', UPLOAD_DIR . '/' . $name)];
}
