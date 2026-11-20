<?php
declare(strict_types=1);

const APP_VERSION = '3.3.3';
final class HttpError extends RuntimeException {}
function fail(int $status, string $message = ''): never
{
    throw new HttpError($message, $status);
}
function esc(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cfg(): array
{
    static $config;
    if ($config === null) {
        $config = parse_ini_file(
            getenv('SGR_CONFIG') ?: '/etc/sgr-library-v3/settings.ini',
            true,
            INI_SCANNER_RAW,
        );
        if (!$config || empty($config['database']['name']) || strlen($config['app']['key'] ?? '') < 32) {
            throw new RuntimeException('Missing SGR configuration');
        }
    }
    return $config;
}
function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = cfg()['database'];
        $transport = !empty($c['socket'])
            ? 'unix_socket=' . $c['socket']
            : 'host=127.0.0.1;port=' . (int) ($c['port'] ?? 3306);
        $pdo = new PDO("mysql:{$transport};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone='+07:00'");
    }
    return $pdo;
}
function run(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function rows(string $sql, array $args = []): array
{
    return run($sql, $args)->fetchAll();
}
function row(string $sql, array $args = []): ?array
{
    return run($sql, $args)->fetch() ?: null;
}
function val(string $sql, array $args = []): mixed
{
    return run($sql, $args)->fetchColumn();
}
function inserted(): int
{
    return (int) db()->lastInsertId();
}
function tx(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    $GLOBALS['new_uploads'] = [];
    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($GLOBALS['new_uploads'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $e;
    } finally {
        $GLOBALS['new_uploads'] = [];
    }
}
function audit(?int $actor, string $event, ?int $student, ?int $entity, array $details = []): void
{
    run('INSERT INTO audit_log(actor_id,event_name,student_id,entity_id,details) VALUES(?,?,?,?,?)', [
        $actor,
        $event,
        $student,
        $entity,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}
function notifyStudent(int $student, string $body, string $target, ?int $termId = null): void
{
    $termId ??= (int) currentTerm()['id'];
    run('INSERT INTO notices(student_id,body,target,term_id) VALUES(?,?,?,?)', [
        $student,
        mb_substr($body, 0, 500),
        $target,
        $termId,
    ]);
}
function arg(array $input, string $name, int $limit = 255, bool $required = true): string
{
    $raw = $input[$name] ?? '';
    if (!is_string($raw)) {
        fail(422, 'รูปแบบข้อมูลไม่ถูกต้อง');
    }
    $s = trim($raw);
    if (($required && $s === '') || !mb_check_encoding($s, 'UTF-8') || mb_strlen($s) > $limit) {
        fail(422, 'กรุณาตรวจช่องที่จำเป็นและความยาวของข้อความ');
    }
    return $s;
}
function post(string $key, int $max = 255, bool $need = true): string
{
    return arg($_POST, $key, $max, $need);
}
function choice(string $key, array $values): string
{
    $s = post($key, 100);
    if (!in_array($s, $values, true)) {
        fail(422, 'กรุณาเลือกค่าจากรายการ');
    }
    return $s;
}
function number(mixed $v, int $min = 1, int $max = 2147483647): int
{
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false || $n < $min || $n > $max) {
        fail(422, 'ตัวเลขอยู่นอกช่วงที่กำหนด');
    }
    return $n;
}
function dateInput(string $key, bool $dayOnly = false): string
{
    $s = post($key, 25);
    $format = $dayOnly ? 'Y-m-d' : 'Y-m-d\TH:i';
    $dt = DateTimeImmutable::createFromFormat('!' . $format, $s);
    if (!$dt || $dt->format($format) !== $s) {
        fail(422, 'วันหรือเวลาไม่ถูกต้อง');
    }
    return $dt->format($dayOnly ? 'Y-m-d' : 'Y-m-d H:i:s');
}
function username(): string
{
    $name = post('username', 60);
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/D', $name)) {
        fail(422, 'ชื่อผู้ใช้ต้องเป็นอักษรอังกฤษ ตัวเลข หรือ . _ - จำนวน 3–60 ตัว');
    }
    return $name;
}
function verifyStoredPassword(string $plain, string $stored): bool
{
    // Be tolerant of hashes imported from older tools: surrounding whitespace and bcrypt 2a/2b prefixes.
    $hash = trim($stored);
    if ($hash === '') {
        return false;
    }
    if (password_verify($plain, $hash)) {
        return true;
    }
    if (preg_match('/^\\$2[ab]\\$/', $hash)) {
        return password_verify($plain, '$2y$' . substr($hash, 4));
    }
    return false;
}
function normalizedPasswordHash(string $stored): string
{
    $hash = trim($stored);
    return preg_match('/^\\$2[ab]\\$/', $hash) ? '$2y$' . substr($hash, 4) : $hash;
}
function newPassword(): string
{
    $p = $_POST['password'] ?? null;
    if (
        !is_string($p) ||
        str_contains($p, "\0") ||
        !mb_check_encoding($p, 'UTF-8') ||
        mb_strlen($p) < 8 ||
        strlen($p) > 72 ||
        $p !== ($_POST['confirm'] ?? null)
    ) {
        fail(422, 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว ไม่เกิน 72 ไบต์ และทั้งสองช่องต้องตรงกัน');
    }
    return password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
}
const LEVELS = ['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'];
const GENDERS = ['ไม่ระบุ', 'หญิง', 'ชาย', 'อื่น ๆ'];
function identityFields(): array
{
    $code = post('student_code', 40);
    if (!preg_match('/^[\p{L}\p{N}_-]+$/uD', $code)) {
        fail(422, 'กรุณาตรวจรหัสนักศึกษา');
    }
    $gender = post('gender', 40, false) ?: 'ไม่ระบุ';
    if (!in_array($gender, GENDERS, true)) {
        fail(422, 'กรุณาตรวจข้อมูลเพศ');
    }
    return [post('first_name', 100), post('last_name', 100), $code, choice('level_name', LEVELS), $gender];
}
function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit();
}
function flash(string $s): void
{
    $_SESSION['flash'] = $s;
}
function currentTerm(): array
{
    return row('SELECT t.* FROM site_state st JOIN terms t ON t.id=st.current_term_id WHERE st.id=1') ??
        throw new RuntimeException('Missing current term');
}
function termName(array $t): string
{
    return 'ภาคเรียนที่ ' . $t['semester'] . ' / ' . $t['school_year'];
}
function listTerms(): array
{
    return rows('SELECT * FROM terms ORDER BY school_year DESC,semester DESC');
}
function selectedTerm(): array
{
    if (empty($_GET['term'])) {
        return currentTerm();
    }
    return row('SELECT * FROM terms WHERE id=?', [number($_GET['term'])]) ?? fail(404);
}
function student(int $id, bool $lock = false): array
{
    return row(
        "SELECT id,username,first_name,last_name,student_code,level_name,gender,phone,line_contact,avatar_id,last_login,last_seen FROM users WHERE role='student' AND id=?" .
            ($lock ? ' FOR UPDATE' : ''),
        [$id],
    ) ?? fail(404);
}
function fullname(array $u): string
{
    return trim($u['first_name'] . ' ' . $u['last_name']);
}
function thDate(?string $s, bool $time = true): string
{
    if (!$s) {
        return '—';
    }
    $d = new DateTimeImmutable($s);
    $months = [
        '',
        'ม.ค.',
        'ก.พ.',
        'มี.ค.',
        'เม.ย.',
        'พ.ค.',
        'มิ.ย.',
        'ก.ค.',
        'ส.ค.',
        'ก.ย.',
        'ต.ค.',
        'พ.ย.',
        'ธ.ค.',
    ];
    return $d->format('j') .
        ' ' .
        $months[(int) $d->format('n')] .
        ' ' .
        ((int) $d->format('Y') + 543) .
        ($time ? ' · ' . $d->format('H:i') : '');
}
function requireTerm(int $id): void
{
    if (!val('SELECT id FROM terms WHERE id=?', [$id])) {
        fail(422, 'ไม่พบภาคเรียน');
    }
}
function isSecure(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SGR_TRUSTED_HTTPS'] ?? '') === '1';
}
function sessionLabel(string $role): string
{
    return $role === 'student' ? 'SGRSTUDENT' : 'SGRTEACHER';
}
function startSession(): void
{
    $secure = isSecure();
    $dir = cfg()['app']['storage'] . '/sessions';
    session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '7200');
    session_name(sessionLabel(PORTAL));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (
        !empty($_SESSION['uid']) &&
        (time() - ($_SESSION['active'] ?? 0) > 7200 || time() - ($_SESSION['started'] ?? 0) > 43200)
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['uid'])) {
        $_SESSION['active'] = time();
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function person(): ?array
{
    if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== PORTAL) {
        return null;
    }
    $u = row(
        'SELECT id,role,username,first_name,last_name,student_code,level_name,gender,phone,line_contact,avatar_id,wallpaper,wallpaper_id,last_login,last_seen,auth_version FROM users WHERE id=? AND role=?',
        [$_SESSION['uid'], PORTAL],
    );
    if (!$u || (int) $u['auth_version'] !== (int) $_SESSION['version']) {
        unset($_SESSION['uid'], $_SESSION['role'], $_SESSION['version']);
        return null;
    }
    if (time() - ($_SESSION['touched'] ?? 0) >= 60) {
        run('UPDATE users SET last_seen=NOW() WHERE id=?', [$u['id']]);
        $_SESSION['touched'] = time();
    }
    return $u;
}
// A second, valid student cookie on the LAN can explain a teacher-portal denial.
// It is read only to return 403, never to authorize access to the teacher portal.
function otherStudentSignedIn(): bool
{
    $name = sessionLabel('student');
    if (
        PORTAL !== 'teacher' ||
        !isset($_COOKIE[$name]) ||
        !is_string($_COOKIE[$name]) ||
        !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $_COOKIE[$name])
    ) {
        return false;
    }
    session_write_close();
    $oldName = session_name($name);
    $oldId = session_id($_COOKIE[$name]);
    session_start(['use_cookies' => 0, 'read_and_close' => true, 'cache_limiter' => '']);
    $s = $_SESSION;
    $ok =
        ($s['role'] ?? '') === 'student' &&
        !empty($s['uid']) &&
        time() - ($s['active'] ?? 0) <= 7200 &&
        time() - ($s['started'] ?? 0) <= 43200 &&
        (int) val("SELECT auth_version FROM users WHERE id=? AND role='student'", [$s['uid']]) ===
            (int) ($s['version'] ?? -1);
    session_name($oldName);
    session_id($oldId);
    session_start();
    return $ok;
}
function csrf(): void
{
    $s = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($s) || !hash_equals($_SESSION['csrf'], $s)) {
        fail(419);
    }
}
function limitAttempts(string $scope, string $subject, int $max, int $seconds): void
{
    $key = hash_hmac('sha256', $scope . "\n" . $subject, cfg()['app']['key']);
    run(
        'INSERT INTO rate_limits(rate_key,hits,expires_at) VALUES(?,1,TIMESTAMPADD(SECOND,?,NOW()))
        ON DUPLICATE KEY UPDATE hits=IF(expires_at<=NOW(),1,hits+1),expires_at=IF(expires_at<=NOW(),VALUES(expires_at),expires_at)',
        [$key, $seconds],
    );
    if ((int) val('SELECT hits FROM rate_limits WHERE rate_key=?', [$key]) > $max) {
        header('Retry-After: ' . $seconds);
        fail(429, 'ลองใหม่หลังจากช่วงเวลาที่กำหนด');
    }
    if (random_int(1, 128) === 1) {
        run('DELETE FROM rate_limits WHERE expires_at<NOW() LIMIT 500');
    }
}
function clientIp(): string
{
    $ip =
        isSecure() && ($_SERVER['SGR_TRUSTED_HTTPS'] ?? '') === '1'
            ? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''
            : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $_SERVER['REMOTE_ADDR'] ?? 'local';
}
function saveUpload(string $field, int $owner, bool $imageOnly = false, bool $required = false): ?int
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            fail(422, 'กรุณาแนบไฟล์');
        }
        return null;
    }
    if (
        $f['error'] !== UPLOAD_ERR_OK ||
        $f['size'] < 1 ||
        $f['size'] > 8 * 1024 * 1024 ||
        !is_uploaded_file($f['tmp_name'])
    ) {
        fail(422, 'อัปโหลดไม่สำเร็จ ขนาดสูงสุด 8 MB');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true) && !(!$imageOnly && $mime === 'application/pdf')) {
        fail(422, 'รองรับ JPG, PNG, WEBP' . ($imageOnly ? '' : ' และ PDF'));
    }
    $extensions = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];
    $suffix = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($suffix, $extensions[$mime], true)) {
        fail(422, 'ชนิดของไฟล์ไม่ตรงกับนามสกุล');
    }
    if ($mime !== 'application/pdf') {
        $image = @getimagesize($f['tmp_name']);
        if (!$image || ($image['mime'] ?? '') !== $mime || $image[0] * $image[1] > 40000000) {
            fail(422, 'อ่านข้อมูลรูปภาพไม่ได้หรือรูปมีความละเอียดสูงเกินไป');
        }
    }
    $disk = bin2hex(random_bytes(32));
    $path = cfg()['app']['storage'] . '/uploads/' . $disk;
    if (!db()->inTransaction() || !move_uploaded_file($f['tmp_name'], $path)) {
        throw new RuntimeException('Upload storage unavailable');
    }
    $GLOBALS['new_uploads'][] = $path;
    chmod($path, 0640);
    $name = mb_substr(
        preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $f['name']))) ?: 'document',
        0,
        180,
    );
    run('INSERT INTO files(disk_name,original_name,mime,byte_size,owner_id) VALUES(?,?,?,?,?)', [
        $disk,
        $name,
        $mime,
        $f['size'],
        $owner,
    ]);
    return inserted();
}
function sendFile(?array $u): never
{
    $id = number($_GET['id'] ?? 0);
    $f = row('SELECT * FROM files WHERE id=?', [$id]) ?? fail(404);
    $public = val('SELECT id FROM announcements WHERE image_id=? AND publish_on<=CURDATE() LIMIT 1', [$id])
        // V3.3.2: the image a teacher chose as the teacher-login background is shown before sign-in.
        || (PORTAL === 'teacher' && $id === loginWallpaperFileId());
    if (
        !$public &&
        !(
            $u &&
            ($u['role'] === 'teacher' ||
                (int) $f['owner_id'] === (int) $u['id'] ||
                val('SELECT id FROM work_items WHERE resource_id=? LIMIT 1', [$id]))
        )
    ) {
        fail($u ? 403 : 401);
    }
    if (!preg_match('/^[0-9a-f]{64}$/D', $f['disk_name'])) {
        fail(404);
    }
    $path = cfg()['app']['storage'] . '/uploads/' . $f['disk_name'];
    if (!is_file($path)) {
        fail(404);
    }
    session_write_close();
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    $mode = $f['mime'] === 'application/pdf' ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $mode . "; filename*=UTF-8''" . rawurlencode($f['original_name']));
    readfile($path);
    exit();
}
