<?php
declare(strict_types=1);
require __DIR__ . '/core.php';
require __DIR__ . '/view.php';
require __DIR__ . '/actions.php';
require __DIR__ . '/maintenance.php';
require __DIR__ . '/v32.php';
require __DIR__ . '/learning.php';
require __DIR__ . '/teaching.php';
require __DIR__ . '/teacher-ui-v32.php';
require __DIR__ . '/student-ui-v32.php';
require __DIR__ . '/v332.php';
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'",
);
header('Cache-Control: no-store');
header('X-SGR-Portal: ' . PORTAL);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$api = str_starts_with($path, '/api/');
$u = null;
function respondJson(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit();
}
function authorized(?array $u, string $role, bool $api): void
{
    if (!$u) {
        // Student and teacher sessions use different cookie names; a session in one portal must not block the other.
        if ($api) {
            fail(401);
        }
        if ($role !== PORTAL) {
            fail(403);
        }
        redirect('/login.php');
    }
    if ($u['role'] !== $role || PORTAL !== $role) {
        fail(403);
    }
}
function handleApi(string $path, ?array $u, string $method): never
{
    $role = str_starts_with($path, '/api/teacher/')
        ? 'teacher'
        : (str_starts_with($path, '/api/student/')
            ? 'student'
            : PORTAL);
    authorized($u, $role, true);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        csrf();
        fail(405);
    }
    switch ($path) {
        case '/api/me':
            respondJson([
                'id' => (int) $u['id'],
                'role' => $u['role'],
                'name' => fullname($u),
                'username' => $u['username'],
            ]);
        case '/api/student/scores':
            $term = selectedTerm();
            respondJson([
                'term' => $term,
                'points' => scoreTotal((int) $u['id'], (int) $term['id']),
                'maximum' => 200,
                'pass' => 100,
            ]);
        case '/api/teacher/summary':
            respondJson(teacherStats((int) selectedTerm()['id']));
        default:
            fail(404);
    }
}
try {
    startSession();
    $u = person();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($api) {
        handleApi($path, $u, $method);
    }
    $common = ['/', '/index.php', '/info.php', '/login.php', '/file.php'];
    $studentPublic = ['/register.php', '/forgot.php', '/reset.php'];
    $both = [
        '/dashboard.php',
        '/activities.php',
        '/assignments.php',
        '/item.php',
        '/attendance.php',
        '/scores.php',
        '/announcements.php',
        '/logout.php',
    ];
    $teachers = [
        '/students.php',
        '/student.php',
        '/requests.php',
        '/new-item.php',
        '/new-attendance.php',
        '/roll.php',
        '/terms.php',
        '/audit.php', '/more.php',
        '/system-reset.php',
        '/enrollments.php','/behavior.php','/reports.php','/feature-settings.php',
        '/account.php', '/gradebook.php', '/activity-log.php',
    ];
    $students = ['/notifications.php', '/profile.php', '/renew.php', '/history.php', '/behavior.php', '/more.php'];
    if ($path === '/report.php') {
        authorized($u, PORTAL, false);
        if ($method !== 'GET') fail(405);
        v32Report($u);
    }
    if ($path === '/info.php' && PORTAL === 'teacher') fail(404);
    $public =
        in_array($path, $common, true) || (PORTAL === 'student' && in_array($path, $studentPublic, true));
    if (!$public) {
        if (in_array($path,['/more.php','/behavior.php'],true)) {
            $role = PORTAL;
        } elseif (in_array($path, $teachers, true)) {
            $role = 'teacher';
        } elseif (in_array($path, $students, true)) {
            $role = 'student';
        } elseif (in_array($path, $both, true)) {
            $role = PORTAL;
        } else {
            fail(404);
        }
        authorized($u, $role, false);
    }
    if ($method === 'POST') {
        csrf();
        if ($path === '/login.php' || $path === '/logout.php' || in_array($path, $studentPublic, true)) {
            authAction($path);
        }
        if ($public) {
            fail(405);
        }
        if (PORTAL === 'teacher') {
            teacherAction($path, (int) $u['id']);
        } else {
            studentAction($path, (int) $u['id']);
        }
        fail(405);
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        fail(405);
    }
    if ($path === '/file.php') {
        sendFile($u);
    }
    // V3.3: a signed-in user opening the public home or auth pages goes straight to their dashboard.
    if ($u && in_array($path, ['/', '/index.php', '/login.php', '/register.php', '/forgot.php', '/reset.php'], true)) {
        redirect('/dashboard.php');
    }
    if (($path === '/' || $path === '/index.php') && PORTAL === 'teacher') {
        // V3.3.2: the teacher web app has no public landing page, only the login popup.
        [$title, $body] = authPage('/login.php');
    } elseif ($path === '/' || $path === '/index.php') {
        $title = 'ยินดีต้อนรับ';
        $body = studentWelcomeV32();
    } elseif ($path === '/info.php') {
        $title = 'เกี่ยวกับระบบ';
        $body = PORTAL === 'student' ? studentInfoV32() : infoPage();
    } elseif ($path === '/login.php' || in_array($path, $studentPublic, true)) {
        [$title, $body] = authPage($path);
    } elseif ($path === '/activities.php' || $path === '/assignments.php') {
        $kind = $path === '/activities.php' ? 'activity' : 'assignment';
        $title = $kind === 'activity' ? 'กิจกรรม' : 'งาน / ใบงาน';
        $body = workList($kind, $u, selectedTerm());
    } elseif ($path === '/item.php') {
        [$title, $body] = itemPage($u);
    } elseif ($path === '/logout.php') {
        fail(405);
    } else {
        [$title, $body] = PORTAL === 'teacher' ? teacherPage($path, $u) : studentPage($path, $u);
    }
    echo layout($title, $body, $u);
} catch (Throwable $e) {
    $duplicate = $e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062;
    $status = $e instanceof HttpError ? $e->getCode() : ($duplicate ? 409 : 500);
    $message =
        $e instanceof HttpError ? $e->getMessage() : ($duplicate ? 'มีชื่อผู้ใช้ รหัสนักศึกษา หรือรายการนี้อยู่แล้ว' : '');
    if ($status === 500) {
        error_log('SGR ' . PORTAL . ' ' . $path . ' ' . $e);
    }
    http_response_code($status);
    if ($api) {
        respondJson([
            'error' => $status,
            'message' =>
                $message ?:
                [401 => 'กรุณาเข้าสู่ระบบ', 403 => 'ไม่มีสิทธิ์เข้าถึง', 419 => 'แบบฟอร์มหมดอายุ', 404 => 'ไม่พบรายการ'][
                        $status
                    ] ?? 'ดำเนินการไม่สำเร็จ',
        ]);
    }
    echo errorPage($status, $message, $u);
}
