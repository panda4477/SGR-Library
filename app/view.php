<?php
declare(strict_types=1);
// V3.3 UI kit: one design system for both portals (desktop sidebar + mobile app bar/bottom tabs).
// CSP is strict (no inline style/script), so every visual state is a class or a native element.

function statusName(?string $s): string
{
    return [
        'submitted' => 'ส่งแล้ว · รอตรวจ',
        'acknowledged' => 'รับทราบแล้ว',
        'passed' => 'ผ่าน',
        'revise' => 'ต้องแก้ไข',
        'pending' => 'รอครูยืนยัน',
        'present' => 'มา',
        'absent' => 'ขาด',
        'leave' => 'ลา',
    ][$s] ?? 'ยังไม่ส่ง';
}
function badge(string $text, string $tone = ''): string
{
    return '<span class="badge ' . esc($tone) . '">' . esc($text) . '</span>';
}
function statusBadge(?string $s): string
{
    return badge(
        statusName($s),
        match ($s) {
            'passed', 'present' => 'good',
            'revise', 'absent' => 'red',
            'pending', 'submitted' => 'gold',
            'leave', 'acknowledged' => 'blue',
            default => '',
        },
    );
}
function icon(string $name, int $size = 20): string
{
    static $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2c2.2.7 3.5 2.8 3.5 5.8"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'roll' => '<rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'star' => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
        'megaphone' => '<path d="m3 10 13-5.5v15L3 14z"/><path d="M3 10v4M7 15l1.6 5H12"/><path d="M19 9.5a3 3 0 0 1 0 5"/>',
        'bell' => '<path d="M6 16.5h12l-1.5-2.3V10a4.5 4.5 0 0 0-9 0v4.2z"/><path d="M10 19.5a2 2 0 0 0 4 0"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'history' => '<path d="M4 12a8 8 0 1 0 2.3-5.6"/><path d="M4 4v4h4M12 8v4l3 2"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        'term' => '<path d="M4 6.5C4 5 5 4 6.5 4H20v14H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2M14 9l2 2"/>',
        'log' => '<path d="M5 4h14v16H5z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 6l2.6 1.5M17.2 16.5l2.6 1.5M4.2 18l2.6-1.5M17.2 7.5 19.8 6"/>',
        'reset' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.5"/>',
        'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'back' => '<path d="m15 6-6 6 6 6"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin' => '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.5 3 7.6 7 9 4-1.4 7-4.5 7-9V6z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'print' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'book' => '<path d="M12 6.5C10 5 7 4.5 3.5 5v13c3.5-.5 6.5 0 8.5 1.5 2-1.5 5-2 8.5-1.5V5c-3.5-.5-6.5 0-8.5 1.5z"/><path d="M12 6.5v13"/>',
        'alert' => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17v.5"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="10" r="1.8"/><path d="m4 18 5-5 3.5 3.5L15 14l5 4.5"/>',
        'grade' => '<rect x="4" y="3.5" width="16" height="17" rx="2.5"/><path d="M8 8h8M8 12h5M8 16h3"/><path d="m14 16 1.5 1.5L19 14"/>',
    ];
    return '<svg class="icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="' . $size . '" height="' . $size .
        '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' .
        ($paths[$name] ?? $paths['file']) . '</svg>';
}
function linkTo(string $url, string $label, string $class = ''): string
{
    return '<a' . ($class !== '' ? ' class="' . esc($class) . '"' : '') . ' href="' . esc($url) . '">' . esc($label) . '</a>';
}
function iconLink(string $url, string $icon, string $label, string $class = 'button secondary'): string
{
    return '<a class="' . esc($class) . '" href="' . esc($url) . '">' . icon($icon, 18) . '<span>' . esc($label) . '</span></a>';
}
function btn(string $text, string $class = 'primary'): string
{
    return '<button class="button ' . esc($class) . '" type="submit">' . esc($text) . '</button>';
}
function hidden(string $k, string|int $v): string
{
    return '<input type="hidden" name="' . esc($k) . '" value="' . esc($v) . '">';
}
function formStart(string $url = '', bool $files = false, string $id = ''): string
{
    return '<form method="post"' .
        ($id !== '' ? ' id="' . esc($id) . '"' : '') .
        ($url !== '' ? ' action="' . esc($url) . '"' : '') .
        ($files ? ' enctype="multipart/form-data"' : '') .
        '>' .
        hidden('_csrf', $_SESSION['csrf']);
}
function hint(string $text): string
{
    return $text !== '' ? '<small class="hint">' . esc($text) . '</small>' : '';
}
function field(
    string $key,
    string $label,
    string $type = 'text',
    mixed $value = '',
    bool $required = true,
    string $attrs = '',
    string $help = '',
): string {
    $input = '<input name="' . esc($key) . '" type="' . esc($type) . '" value="' . esc($value) . '"' .
        ($required ? ' required' : '') . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    if ($type === 'password') {
        $input = '<span class="input-wrap">' . $input .
            '<button type="button" class="pw-toggle" data-toggle-password aria-label="แสดงรหัสผ่าน" aria-pressed="false">' . icon('eye', 18) . '</button></span>';
    }
    return '<label class="field"><span class="label">' . esc($label) .
        (!$required && $type !== 'search' ? ' <em>(ไม่บังคับ)</em>' : '') . '</span>' . $input . hint($help) . '</label>';
}
function area(string $key, string $name, bool $required = true, string $value = '', string $help = '', int $limit = 12000): string
{
    return '<label class="field"><span class="label">' . esc($name) . (!$required ? ' <em>(ไม่บังคับ)</em>' : '') . '</span>' .
        '<textarea rows="4" maxlength="' . $limit . '" name="' . esc($key) . '"' . ($required ? ' required' : '') . '>' .
        esc($value) . '</textarea>' . hint($help) . '</label>';
}
function selectBox(string $key, string $label, array $options, mixed $selected = '', string $help = ''): string
{
    $out = '<label class="field"><span class="label">' . esc($label) . '</span><select name="' . esc($key) . '" required>';
    foreach ($options as $k => $v) {
        $out .= '<option value="' . esc($k) . '"' . ((string) $selected === (string) $k ? ' selected' : '') . '>' . esc($v) . '</option>';
    }
    return $out . '</select>' . hint($help) . '</label>';
}
function uploadBox(string $key, string $name, bool $images = false, bool $required = false): string
{
    return '<div class="field"><span class="label">' . esc($name) . (!$required ? ' <em>(ไม่บังคับ)</em>' : '') . '</span><label class="dropzone">' . icon('upload', 22) .
        '<span><b data-file-name>แตะเพื่อเลือกไฟล์</b><small>' .
        ($images ? 'JPG, PNG, WEBP' : 'JPG, PNG, WEBP หรือ PDF') . ' · ไม่เกิน 8 MB</small></span>' .
        '<input type="file" name="' . esc($key) . '" accept="image/jpeg,image/png,image/webp' . ($images ? '' : ',application/pdf') . '"' .
        ($required ? ' required' : '') . '></label></div>';
}
function checkbox(string $key, string $label, bool $checked = false): string
{
    return '<label class="check"><input type="checkbox" name="' . esc($key) . '" value="1"' . ($checked ? ' checked' : '') . '><span>' . esc($label) . '</span></label>';
}
function usernameField(string $value = ''): string
{
    return field('username', 'ชื่อผู้ใช้ (ใช้เข้าสู่ระบบ)', 'text', $value, true,
        'autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9_.\-]{3,60}" maxlength="60"',
        'ภาษาอังกฤษหรือตัวเลข 3–60 ตัว ใช้ . _ - ได้ เช่น nida.2569');
}
function identityForm(array $u = []): string
{
    return usernameField($u['username'] ?? '') .
        '<div class="pair">' .
        field('first_name', 'ชื่อจริง', 'text', $u['first_name'] ?? '', true, 'maxlength="100" autocomplete="given-name"') .
        field('last_name', 'นามสกุล', 'text', $u['last_name'] ?? '', true, 'maxlength="100" autocomplete="family-name"') .
        '</div><div class="pair">' .
        field('student_code', 'รหัสนักศึกษา', 'text', $u['student_code'] ?? '', true, 'maxlength="40"', 'ตามทะเบียนของศูนย์') .
        selectBox('level_name', 'ระดับชั้น', array_combine(LEVELS, LEVELS), $u['level_name'] ?? '') .
        '</div>' .
        selectBox('gender', 'เพศ (ไม่บังคับ)', array_combine(GENDERS, GENDERS), $u['gender'] ?? 'ไม่ระบุ');
}
function pwdFields(): string
{
    return '<div class="pair">' .
        field('password', 'รหัสผ่านใหม่', 'password', '', true, 'autocomplete="new-password" minlength="8" maxlength="72"', 'อย่างน้อย 8 ตัวอักษร') .
        field('confirm', 'พิมพ์รหัสผ่านอีกครั้ง', 'password', '', true, 'autocomplete="new-password" minlength="8" maxlength="72" data-match="password"') .
        '</div>';
}
function termOptions(): array
{
    $a = [];
    foreach (listTerms() as $t) {
        $a[$t['id']] = termName($t);
    }
    return $a;
}
function termFilter(array $term, array $extra = []): string
{
    $h = '<form class="filter term-filter" method="get">';
    foreach ($extra as $k => $v) {
        $h .= hidden($k, $v);
    }
    return $h . selectBox('term', 'ภาคเรียน', termOptions(), $term['id']) .
        '<noscript>' . btn('แสดง', 'secondary') . '</noscript></form>';
}
function studentOptions(): array
{
    $opts = [];
    foreach (rows("SELECT id,first_name,last_name,student_code FROM users WHERE role='student' ORDER BY first_name,last_name") as $s) {
        $opts[$s['id']] = fullname($s) . ' · ' . $s['student_code'];
    }
    return $opts;
}
function emptyState(string $text, string $iconName = 'info'): string
{
    return '<div class="empty">' . icon($iconName, 28) . '<p>' . esc($text) . '</p></div>';
}
function tableView(array $headers, array $body, string $empty = 'ยังไม่มีรายการ'): string
{
    if (!$body) {
        return emptyState($empty);
    }
    $h = '<div class="scroll"><table class="table"><thead><tr>';
    foreach ($headers as $c) {
        $h .= '<th scope="col">' . esc($c) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($body as $r) {
        $h .= '<tr>';
        foreach (array_values($r) as $i => $c) {
            $h .= '<td data-label="' . esc($headers[$i] ?? '') . '">' . $c . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}
function block(string $title, string $html, string $id = ''): string
{
    return '<section class="card section"' . ($id ? ' id="' . esc($id) . '"' : '') . '><h2>' . esc($title) . '</h2>' . $html . '</section>';
}
function documentTitle(string $t, string $desc = '', string $actions = ''): string
{
    return '<div class="page-head"><div><h1>' . esc($t) . '</h1>' . ($desc ? '<p>' . esc($desc) . '</p>' : '') . '</div>' .
        ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '') . '</div>';
}
function fileLink(mixed $id, string $name = 'เปิดไฟล์แนบ'): string
{
    return $id ? '<a class="filelink" href="/file.php?id=' . (int) $id . '">' . icon('file', 16) . '<span>' . esc($name) . '</span></a>' : '—';
}
function avatar(array $u, string $size = ''): string
{
    $cls = 'avatar' . ($size !== '' ? ' ' . $size : '');
    return !empty($u['avatar_id'])
        ? '<img class="' . esc($cls) . '" alt="" src="/file.php?id=' . (int) $u['avatar_id'] . '">'
        : '<span aria-hidden="true" class="' . esc($cls) . ' initial">' . esc(mb_substr((string) ($u['first_name'] ?? '?'), 0, 1)) . '</span>';
}
function meterCard(string $label, int $value, int $max, int $goal, string $unit, string $note = '', string $url = ''): string
{
    $shown = max(0, $max);
    $max = max(1, $max);
    $noGoal = $goal < 0;
    $ok = !$noGoal && $value >= $goal;
    $open = $url !== '' ? '<a class="meter-card' . ($ok ? ' ok' : '') . '" href="' . esc($url) . '">' : '<div class="meter-card' . ($ok ? ' ok' : '') . '">';
    return $open . '<span class="meter-label">' . esc($label) . '</span>' .
        '<strong>' . $value . ' <small>/ ' . $shown . ' ' . esc($unit) . '</small></strong>' .
        '<progress value="' . max(0, min($value, $max)) . '" max="' . $max . '" aria-label="' . esc($label) . '"></progress>' .
        '<span class="meter-note">' . ($noGoal ? esc($note) : ($ok ? icon('check', 16) . ' ถึงเกณฑ์แล้ว (' . $goal . ')' : 'เกณฑ์ ' . $goal . ' ' . esc($unit) . ' · ขาดอีก ' . max(0, $goal - $value)) .
        ($note !== '' ? ' · ' . esc($note) : '')) . '</span>' . ($url !== '' ? '</a>' : '</div>');
}
function progress(int $n, int $goal = 100): string
{
    return '<div class="score-summary">' . meterCard('คะแนน กพช. สะสมในภาคเรียน', $n, 200, $goal, 'คะแนน') . '</div>';
}
function dueText(?string $due): string
{
    if (!$due) {
        return '';
    }
    $now = new DateTimeImmutable();
    $d = new DateTimeImmutable($due);
    if ($d < $now) {
        return badge('เลยกำหนด', 'red');
    }
    $days = (int) $now->diff($d)->format('%a');
    return $days === 0 ? badge('ครบกำหนดวันนี้', 'gold') : badge('เหลือ ' . $days . ' วัน', $days <= 3 ? 'gold' : 'blue');
}
function navLinks(array $links, string $active): string
{
    $h = '';
    foreach ($links as $path => $name) {
        $h .= '<a href="' . esc($path) . '"' . ($path === $active ? ' aria-current="page"' : '') . '>' . esc($name) . '</a>';
    }
    return $h;
}
function navActive(string $route): string
{
    if (!empty($GLOBALS['sgr_nav'])) {
        return (string) $GLOBALS['sgr_nav'];
    }
    return match ($route) {
        '/', '/index.php', '/renew.php' => '/dashboard.php',
        '/roll.php', '/new-attendance.php' => '/attendance.php',
        '/student.php' => '/students.php',
        '/new-item.php' => (($_GET['kind'] ?? '') === 'assignment' ? '/assignments.php' : '/activities.php'),
        default => $route,
    };
}
function navMenu(bool $teacher, ?array $u): array
{
    $counts = ['notices' => 0, 'pending' => 0, 'requests' => 0];
    if ($u) {
        try {
            $term = (int) currentTerm()['id'];
            if ($teacher) {
                $counts['pending'] = (int) val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='pending'", [$term]);
                $counts['requests'] = (int) val('SELECT COUNT(*) FROM reset_requests WHERE used_at IS NULL AND code_hash IS NULL');
            } else {
                $counts['notices'] = (int) val('SELECT COUNT(*) FROM notices WHERE student_id=? AND term_id=? AND seen_at IS NULL', [$u['id'], $term]);
            }
        } catch (Throwable) {
            // Counters are decoration only; they must never break navigation.
        }
    }
    $groups = $teacher ? [
        '' => [['/dashboard.php', 'home', 'ภาพรวม', 0]],
        'นักศึกษา' => [
            ['/students.php', 'users', 'รายชื่อนักศึกษา', 0],
            ['/enrollments.php', 'check', 'อนุมัติลงทะเบียน', $counts['pending']],
            ['/behavior.php', 'flag', 'บันทึกพฤติกรรม', 0],
            ['/requests.php', 'key', 'คำขอรหัสผ่าน', $counts['requests']],
        ],
        'การเรียนการสอน' => [
            ['/attendance.php', 'roll', 'เช็กชื่อ / วันเรียน', 0],
            ['/activities.php', 'spark', 'กิจกรรม', 0],
            ['/activity-log.php', 'history', 'บันทึกกิจกรรม', 0],
            ['/assignments.php', 'file', 'งาน / ใบงาน', 0],
            ['/gradebook.php', 'grade', 'คะแนนงานในห้อง', 0],
            ['/scores.php', 'star', 'คะแนน กพช.', 0],
            ['/announcements.php', 'megaphone', 'ประกาศ', 0],
        ],
        'รายงานและระบบ' => [
            ['/reports.php', 'chart', 'รายงานและส่งออก', 0],
            ['/terms.php', 'term', 'ภาคเรียนและเกณฑ์', 0],
            ['/audit.php', 'log', 'ประวัติการจัดการ', 0],
            ['/feature-settings.php', 'settings', 'ตั้งค่าฟีเจอร์', 0],
            ['/account.php', 'image', 'บัญชีครู / วอลเปเปอร์', 0],
            ['/system-reset.php', 'reset', 'รีเซ็ตข้อมูล', 0],
        ],
    ] : [
        '' => [
            ['/dashboard.php', 'home', 'หน้าหลัก', 0],
            ['/notifications.php', 'bell', 'แจ้งเตือน', $counts['notices']],
        ],
        'การเรียนของฉัน' => [
            ['/assignments.php', 'file', 'งานที่ได้รับ', 0],
            ['/activities.php', 'spark', 'กิจกรรม', 0],
            ['/attendance.php', 'calendar', 'การมาเรียน', 0],
            ['/scores.php', 'star', 'คะแนน (กพช. / งาน)', 0],
            ['/history.php', 'history', 'ประวัติการศึกษา', 0],
        ],
        'ข่าวสารและบัญชี' => [
            ['/announcements.php', 'megaphone', 'ประกาศ', 0],
            ['/behavior.php', 'flag', 'บันทึกพฤติกรรม', 0],
            ['/profile.php', 'user', 'บัญชีของฉัน', 0],
            ['/info.php', 'info', 'เกี่ยวกับระบบ', 0],
        ],
    ];
    $tabs = $teacher ? [
        ['/dashboard.php', 'home', 'หน้าแรก', 0], ['/students.php', 'users', 'นักศึกษา', 0],
        ['/attendance.php', 'roll', 'เช็กชื่อ', 0], ['/scores.php', 'star', 'คะแนน', 0], ['/more.php', 'menu', 'เมนู', $counts['pending'] + $counts['requests']],
    ] : [
        ['/dashboard.php', 'home', 'หน้าแรก', 0], ['/assignments.php', 'file', 'งาน', 0],
        ['/attendance.php', 'calendar', 'มาเรียน', 0], ['/scores.php', 'star', 'คะแนน', 0], ['/more.php', 'menu', 'เมนู', $counts['notices']],
    ];
    return [$groups, $tabs, $counts];
}
function countDot(int $n): string
{
    return $n > 0 ? '<span class="count">' . ($n > 99 ? '99+' : $n) . '<span class="sr-only"> รายการใหม่</span></span>' : '';
}
function brandMark(): string
{
    return '<span class="brand-mark" aria-hidden="true">' . icon('book', 20) . '</span>';
}
function layout(string $title, string $body, ?array $u = null): string
{
    $teacher = PORTAL === 'teacher';
    $route = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $active = navActive($route);
    $v = esc(APP_VERSION);
    $flash = '';
    $flashText = '';
    if (isset($_SESSION['flash'])) {
        $flashText = (string) $_SESSION['flash'];
        $flash = '<div class="flash" role="status">' . icon('check', 18) . '<span>' . esc($flashText) . '</span><button type="button" class="flash-close" data-dismiss aria-label="ปิดข้อความ">×</button></div>';
        unset($_SESSION['flash']);
    }
    $portalName = $teacher ? 'ระบบสำหรับครู' : 'ระบบนักศึกษา';
    $head = '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' .
        '<meta name="theme-color" content="#16456e"><title>' . esc($title) . ' · SGR Library</title>' .
        '<link rel="stylesheet" href="/assets/site.css?v=' . $v . '">' .
        '<script src="/assets/site.js?v=' . $v . '" defer></script>' .
        ($teacher && $u ? '<script src="/assets/teacher-mobile.js?v=' . $v . '" defer></script>' : '') . '</head>';
    $footer = '<footer class="site-footer"><span><b>SGR Library</b> · ศกร.ระดับตำบลบ้านใหม่ อ.สามพราน จ.นครปฐม</span>' .
        (!$teacher ? linkTo('/info.php', 'เกี่ยวกับระบบ') : '') . '<span class="site-version">V' . $v . '</span></footer>';

    if (!$u && $teacher && !empty($GLOBALS['sgr_login_only'])) {
        // V3.3.2: the teacher portal opens straight into a single login popup (mobile and PC).
        return teacherLoginLayout($head, $flashText, $body);
    }
    if (!$u) {
        $nav = $teacher ? '' : linkTo('/info.php', 'เกี่ยวกับระบบ', 'navlink' . ($route === '/info.php' ? ' active' : ''));
        $cta = $route === '/login.php'
            ? (!$teacher ? linkTo('/register.php', 'สมัครสมาชิก', 'button primary small') : '')
            : linkTo('/login.php', $teacher ? 'เข้าสู่ระบบครู' : 'เข้าสู่ระบบ', 'button ' . ($route === '/register.php' ? 'primary' : 'secondary') . ' small');
        $landing = !empty($GLOBALS['sgr_landing']);
        return $head . '<body class="guest ' . ($teacher ? 'teacher' : 'student') . ($landing ? ' landing' : '') . '"><a class="skip" href="#main">ข้ามไปยังเนื้อหา</a>' .
            '<header class="guest-bar"><a class="brand" href="/">' . brandMark() . '<span><b>SGR Library</b><small>' . esc($portalName) . '</small></span></a>' .
            '<nav class="guest-nav" aria-label="เมนูหลัก">' . $nav . $cta . '</nav></header>' .
            '<main id="main" class="guest-main' . ($landing ? ' lp' : '') . '">' . $flash . $body . '</main>' . $footer . '</body></html>';
    }

    [$groups, $tabs, $counts] = navMenu($teacher, $u);
    $side = '';
    foreach ($groups as $heading => $links) {
        $side .= ($heading !== '' ? '<p class="side-heading">' . esc($heading) . '</p>' : '');
        foreach ($links as [$path, $ic, $label, $n]) {
            $side .= '<a href="' . esc($path) . '"' . ($path === $active ? ' aria-current="page"' : '') . '>' . icon($ic, 19) . '<span>' . esc($label) . '</span>' . countDot($n) . '</a>';
        }
    }
    $tabPaths = array_column($tabs, 0);
    $bottom = '<nav class="tabbar" aria-label="เมนูลัด">';
    foreach ($tabs as [$path, $ic, $label, $n]) {
        $on = $active === $path || ($path === '/more.php' && !in_array($active, $tabPaths, true));
        $bottom .= '<a href="' . esc($path) . '"' . ($on ? ' aria-current="page"' : '') . '><span class="tab-icon">' . icon($ic, 22) . countDot($n) . '</span><small>' . esc($label) . '</small></a>';
    }
    $bottom .= '</nav>';
    $term = currentTerm();
    $userCard = '<a class="side-user" href="' . ($teacher ? '/account.php' : '/profile.php') . '">' . avatar($u, 'sm') . '<span><b>' . esc(fullname($u)) . '</b><small>' .
        esc($teacher ? 'ครูผู้ดูแล' : ('รหัส ' . ($u['student_code'] ?? ''))) . '</small></span></a>';
    $bell = !$teacher
        ? '<a class="icon-button" href="/notifications.php" aria-label="แจ้งเตือน">' . icon('bell', 22) . countDot($counts['notices']) . '</a>'
        : '<a class="icon-button" href="/enrollments.php" aria-label="คำขอลงทะเบียนที่รออนุมัติ">' . icon('bell', 22) . countDot($counts['pending']) . '</a>';
    $topbar = '<header class="topbar"><a class="brand compact" href="/dashboard.php">' . brandMark() . '<span><b>SGR Library</b><small>' . esc($portalName) . '</small></span></a>' .
        '<span class="term-chip">' . icon('calendar', 16) . '<span>' . esc(termName($term)) . '</span></span>' .
        '<div class="top-actions">' . $bell .
        '<a class="top-user" href="' . ($teacher ? '/account.php' : '/profile.php') . '" aria-label="บัญชีของฉัน">' . avatar($u, 'sm') . '<span>' . esc($u['first_name']) . '</span></a>' .
        formStart('/logout.php') . '<button type="submit" class="button ghost small logout">' . icon('logout', 18) . '<span>ออกจากระบบ</span></button></form></div></header>';
    [$wallClass, $wallHtml] = $teacher ? wallpaperLayer(wallpaperValue($u)) : ['', ''];
    return $head . '<body class="signed ' . ($teacher ? 'teacher' : 'student') . $wallClass . '">' . $wallHtml . '<a class="skip" href="#main">ข้ามไปยังเนื้อหา</a>' .
        '<div class="shell"><aside class="sidebar"><a class="brand" href="/dashboard.php">' . brandMark() . '<span><b>SGR Library</b><small>' . esc($portalName) . '</small></span></a>' .
        $userCard . '<nav class="side-nav" aria-label="เมนูหลัก">' . $side . '</nav></aside>' .
        '<div class="workspace">' . $topbar . '<main id="main" class="content">' . $flash . $body . '</main>' . $footer . '</div></div>' . $bottom . '</body></html>';
}

function errorPage(int $status, string $message = '', ?array $u = null): string
{
    $titles = [
        400 => 'คำขอไม่ถูกต้อง',
        401 => 'กรุณาเข้าสู่ระบบ',
        403 => 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้',
        404 => 'ไม่พบหน้าที่คุณต้องการ',
        405 => 'วิธีส่งคำขอไม่ถูกต้อง',
        409 => 'รายการมีการเปลี่ยนแปลง',
        413 => 'ไฟล์มีขนาดใหญ่เกินไป',
        419 => 'แบบฟอร์มหมดอายุ',
        422 => 'กรุณาตรวจข้อมูลอีกครั้ง',
        429 => 'มีการลองบ่อยเกินไป',
        500 => 'ระบบขัดข้องชั่วคราว',
        503 => 'ระบบกำลังซ่อมแซมข้อมูล',
    ];
    $tips = [
        419 => 'หน้านี้เปิดค้างไว้นานเกินไป กดกลับไปแล้วส่งแบบฟอร์มอีกครั้ง',
        422 => 'กดกลับไปแก้ไข ข้อมูลที่กรอกไว้ส่วนใหญ่จะยังอยู่',
        429 => 'รอประมาณ 15 นาที แล้วลองใหม่',
        403 => 'หากคิดว่าเป็นความผิดพลาด โปรดติดต่อครูผู้ดูแล',
        500 => 'ลองใหม่อีกครั้งในอีกสักครู่ หากยังพบปัญหาโปรดแจ้งครูผู้ดูแล',
    ];
    $heading = $titles[$status] ?? $titles[500];
    $here = explode('?', $_SERVER['REQUEST_URI'] ?? '/')[0];
    $retry =
        ($status === 419 || $status === 422 || $status === 409) && preg_match('~^/[a-z-]+\.php$~D', $here)
            ? '<button type="button" class="button primary" data-back>' . icon('back', 18) . '<span>กลับไปแก้ไข</span></button>'
            : ($status === 401 ? linkTo('/login.php', 'เข้าสู่ระบบอีกครั้ง', 'button primary') : '');
    $home = linkTo($u ? '/dashboard.php' : '/', 'กลับหน้าหลัก', 'button ' . ($retry !== '' ? 'secondary' : 'primary'));
    return layout(
        $heading,
        '<div class="error card"><span class="error-icon">' . icon($status >= 500 ? 'alert' : 'info', 30) . '</span><p class="eyebrow">HTTP ' . (int) $status . '</p>' .
            '<h1>' . esc($heading) . '</h1>' . ($message !== '' ? '<p class="error-message">' . esc($message) . '</p>' : '') .
            (isset($tips[$status]) ? '<p class="muted">' . esc($tips[$status]) . '</p>' : '') .
            '<div class="actions center">' . $retry . $home . '</div></div>',
        $u,
    );
}
function newsList(int $limit = 0, bool $admin = false, ?int $termId = null): string
{
    $q =
        'SELECT * FROM announcements' .
        ($admin ? '' : ' WHERE publish_on<=CURDATE() AND (term_id IS NULL OR term_id=' . (int) ($termId ?? selectedTerm()['id']) . ')') .
        ' ORDER BY pinned DESC,publish_on DESC,id DESC' .
        ($limit ? ' LIMIT ' . (int) $limit : '');
    $out = '';
    $ackOn = PORTAL === 'student' && !empty($_SESSION['uid']) && $limit === 0 && v32Feature('announcement_ack');
    foreach (rows($q) as $n) {
        $ack = '';
        if ($ackOn) {
            $ack = (bool) val('SELECT 1 FROM announcement_reads WHERE announcement_id=? AND student_id=?', [$n['id'], (int) $_SESSION['uid']])
                ? '<span class="acked">' . icon('check', 16) . ' รับทราบแล้ว</span>'
                : formStart('/announcements.php') . hidden('action', 'ack') . hidden('announcement_id', (int) $n['id']) . btn('รับทราบประกาศ', 'secondary small') . '</form>';
        }
        $out .=
            '<article class="news' . ($n['pinned'] ? ' pinned' : '') . '">' .
            ($n['image_id'] ? '<img loading="lazy" alt="" src="/file.php?id=' . (int) $n['image_id'] . '">' : '') .
            '<div class="news-body"><div class="news-meta"><small>' . esc(thDate($n['publish_on'], false)) . '</small>' .
            ($n['pinned'] ? badge('ปักหมุด', 'gold') : '') .
            ($admin && $n['publish_on'] > date('Y-m-d') ? badge('รอเผยแพร่', 'blue') : '') . '</div>' .
            '<h3>' . esc($n['title']) . '</h3><p class="pre">' . esc($n['body']) . '</p>' .
            (($n['link_url'] || $ack !== '' || $admin) ? '<div class="news-actions">' .
            ($n['link_url'] ? '<a class="textlink" href="' . esc($n['link_url']) . '" target="_blank" rel="noopener noreferrer">' . icon('link', 16) . ' เปิดลิงก์ประกาศ</a>' : '') .
            $ack .
            ($admin ? formStart('/announcements.php') . hidden('action', 'delete_announcement') . hidden('announcement_id', (int) $n['id']) .
                '<button type="submit" class="button danger small" data-confirm-delete="ลบประกาศนี้อย่างถาวรหรือไม่?">ลบประกาศ</button></form>' : '') .
            '</div>' : '') . '</div></article>';
    }
    return $out !== '' ? '<div class="news-list">' . $out . '</div>' : emptyState('ยังไม่มีประกาศเผยแพร่', 'megaphone');
}
function welcome(): string
{
    if (PORTAL !== 'teacher') {
        return studentWelcomeV32();
    }
    $activities = rows("SELECT title,starts_at,place FROM work_items WHERE kind='activity' ORDER BY created_at DESC,id DESC LIMIT 4");
    $items = '';
    foreach ($activities as $a) {
        $items .= '<li><b>' . esc($a['title']) . '</b><small>' . esc(thDate($a['starts_at'])) . ($a['place'] !== '' ? ' · ' . esc($a['place']) : '') . '</small></li>';
    }
    return '<section class="hero"><div class="hero-copy"><p class="eyebrow">TEACHER PORTAL</p>' .
        '<h1>ห้องทำงานครู<span>ศกร.ระดับตำบลบ้านใหม่</span></h1>' .
        '<p>จัดการทะเบียนนักศึกษา เช็กชื่อ ตรวจงาน บันทึกคะแนน กพช. และออกรายงานรายภาคเรียน ได้ทั้งจากคอมพิวเตอร์และมือถือ</p>' .
        '<div class="actions">' . linkTo('/login.php', 'เข้าสู่ระบบครู', 'button primary large') . '</div>' .
        '<p class="muted small">สำหรับบุคลากรของศูนย์เท่านั้น · นักศึกษาใช้ระบบนักศึกษาแยกต่างหาก</p></div>' .
        '<figure class="hero-figure"><img src="/assets/reading-room.webp" alt="หนังสือบนโต๊ะอ่านหนังสือในห้องสมุด"></figure></section>' .
        '<div class="grid two">' . block('ประกาศล่าสุด', newsList(3)) .
        block('กิจกรรมล่าสุด', $items !== '' ? '<ul class="plain-list">' . $items . '</ul>' : emptyState('ยังไม่มีกิจกรรม', 'spark')) . '</div>';
}
function infoTeam(): string
{
    $people = [
        ['Pawarit Sosirikul', 'System Developer', 'ดูแล Server, Nginx, PHP, Cloudflare Tunnel, Git และ Deployment', [
            'Facebook' => 'https://www.facebook.com/icetyz6622.sh',
            'Website' => 'https://panda4477.online/',
            'GitHub' => 'https://github.com/panda4477',
        ]],
        ['Yuwapat Leelachat', 'UI / UX & Quality Assurance', 'ทดสอบประสบการณ์ผู้ใช้ การแสดงผลบนมือถือและคอมพิวเตอร์', [
            'Facebook' => 'https://www.facebook.com/yuwapat.leelachat',
        ]],
        ['ทศพร อัศวพรรค', 'Development & API Testing', 'PHP-XML, HTML, PHP Intl, cURL, jq, API Testing และ Image Structure Testing', []],
    ];
    $h = '';
    foreach ($people as [$name, $role, $desc, $links]) {
        $a = '';
        foreach ($links as $label => $url) {
            $a .= '<a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer">' . esc($label) . ' ↗</a>';
        }
        $h .= '<article class="person-card">' . avatar(['first_name' => $name, 'avatar_id' => null]) . '<div><small>' . esc($role) . '</small><h3>' . esc($name) . '</h3><p>' . esc($desc) . '</p>' .
            ($a !== '' ? '<div class="person-links">' . $a . '</div>' : '') . '</div></article>';
    }
    return '<div class="team-grid">' . $h . '</div>';
}
function infoPage(): string
{
    return studentInfoV32();
}
function authPage(string $r): array
{
    $t = PORTAL === 'teacher';
    if ($t && $r === '/login.php') {
        return teacherLoginPop();
    }
    $title = match ($r) {
        '/login.php' => $t ? 'เข้าสู่ระบบครู' : 'เข้าสู่ระบบ',
        '/register.php' => 'สมัครสมาชิกนักศึกษา',
        '/forgot.php' => 'ลืมรหัสผ่าน',
        default => 'ตั้งรหัสผ่านใหม่',
    };
    $lede = match ($r) {
        '/login.php' => $t ? 'สำหรับครูและบุคลากรของศูนย์' : 'ใช้ชื่อผู้ใช้หรือรหัสนักศึกษา พร้อมรหัสผ่านที่ตั้งไว้ตอนสมัคร',
        '/register.php' => 'ใช้เวลาประมาณ 2 นาที กรอกข้อมูลให้ตรงกับทะเบียนของศูนย์',
        '/forgot.php' => 'ส่งคำขอให้ครูออกรหัสสำหรับตั้งรหัสผ่านใหม่',
        default => 'ใช้รหัสที่ได้รับจากครู (ใช้ได้ครั้งเดียว ภายใน 15 นาที)',
    };
    $f = formStart($r, $r === '/register.php');
    $after = '';
    if ($r === '/login.php') {
        $last = (string) ($_SESSION['last_username'] ?? '');
        $f .= field('username', $t ? 'ชื่อผู้ใช้' : 'ชื่อผู้ใช้ หรือ รหัสนักศึกษา', 'text', $last, true,
                'autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60"' . ($last === '' ? ' autofocus' : '')) .
            field('password', 'รหัสผ่าน', 'password', '', true, 'autocomplete="current-password" maxlength="72"' . ($last !== '' ? ' autofocus' : '')) .
            btn('เข้าสู่ระบบ', 'primary block large') . '</form>';
        if (!$t) {
            $after = '<div class="auth-links">' . linkTo('/forgot.php', 'ลืมรหัสผ่าน?', 'textlink') . '</div>' .
                '<div class="auth-alt"><span>ยังไม่มีบัญชี?</span>' . linkTo('/register.php', 'สมัครสมาชิกนักศึกษา', 'button secondary block') . '</div>';
        }
    } elseif ($r === '/register.php') {
        $f .= '<fieldset class="step"><legend><span>1</span>ข้อมูลผู้เรียน</legend>' .
            '<div class="pair">' .
            field('first_name', 'ชื่อจริง', 'text', '', true, 'maxlength="100" autocomplete="given-name"') .
            field('last_name', 'นามสกุล', 'text', '', true, 'maxlength="100" autocomplete="family-name"') .
            '</div><div class="pair">' .
            field('student_code', 'รหัสนักศึกษา', 'text', '', true, 'maxlength="40"', 'ตามทะเบียนของศูนย์ ใช้เข้าสู่ระบบแทนชื่อผู้ใช้ได้') .
            selectBox('level_name', 'ระดับชั้นที่กำลังเรียน', array_combine(LEVELS, LEVELS), LEVELS[1]) .
            '</div>' . selectBox('gender', 'เพศ (ไม่บังคับ)', array_combine(GENDERS, GENDERS), 'ไม่ระบุ') . '</fieldset>' .
            '<fieldset class="step"><legend><span>2</span>ตั้งค่าบัญชี</legend>' . usernameField() . pwdFields() . '</fieldset>' .
            '<fieldset class="step"><legend><span>3</span>รูปโปรไฟล์</legend>' .
            uploadBox('avatar', 'รูปหน้าตรง ช่วยให้ครูจำได้ง่าย', true) . '</fieldset>' .
            '<p class="note">' . icon('info', 16) . ' สมัครเสร็จแล้ว ให้เข้าสู่ระบบ แล้วกด “ลงทะเบียนเรียน” ของภาคเรียนปัจจุบัน เพื่อให้ครูอนุมัติ</p>' .
            btn('สมัครสมาชิก', 'primary block large') . '</form>';
        $after = '<div class="auth-alt"><span>มีบัญชีอยู่แล้ว?</span>' . linkTo('/login.php', 'เข้าสู่ระบบ', 'button secondary block') . '</div>';
    } elseif ($r === '/forgot.php') {
        $f = '<ol class="mini-steps"><li class="on">ส่งคำขอ</li><li>ติดต่อครูเพื่อรับรหัส</li><li>ตั้งรหัสผ่านใหม่</li></ol>' . $f .
            field('username', 'ชื่อผู้ใช้', 'text', '', true, 'autocomplete="username" autocapitalize="none" maxlength="60"') .
            field('student_code', 'รหัสนักศึกษา', 'text', '', true, 'maxlength="40"') .
            btn('ส่งคำขอให้ครู', 'primary block large') . '</form>';
        $after = '<div class="auth-alt"><span>ได้รับรหัสจากครูแล้ว?</span>' . linkTo('/reset.php', 'ตั้งรหัสผ่านใหม่', 'button secondary block') . '</div>' .
            '<div class="auth-links">' . linkTo('/login.php', 'กลับไปหน้าเข้าสู่ระบบ', 'textlink') . '</div>';
    } else {
        $f = '<ol class="mini-steps"><li class="done">ส่งคำขอ</li><li class="done">รับรหัสจากครู</li><li class="on">ตั้งรหัสผ่านใหม่</li></ol>' . $f .
            field('username', 'ชื่อผู้ใช้', 'text', '', true, 'autocomplete="username" autocapitalize="none" maxlength="60"') .
            field('code', 'รหัสที่ได้รับจากครู', 'text', '', true, 'autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="50"', 'ตัวอักษรและตัวเลข 12 หลัก') .
            pwdFields() . btn('บันทึกรหัสผ่านใหม่', 'primary block large') . '</form>';
        $after = '<div class="auth-links">' . linkTo('/forgot.php', 'ยังไม่มีรหัส? ส่งคำขอ', 'textlink') . linkTo('/login.php', 'กลับไปเข้าสู่ระบบ', 'textlink') . '</div>';
    }
    $aside = $t
        ? '<h2>ห้องทำงานครู</h2><ul class="ticks"><li>อนุมัติการลงทะเบียนรายภาคเรียน</li><li>เช็กชื่อทั้งห้องในหน้าเดียว</li><li>ตรวจงานและให้คะแนน กพช.</li><li>ออกรายงาน PDF / Excel</li></ul>'
        : '<h2>เริ่มใช้งานใน 3 ขั้นตอน</h2><ol class="journey"><li><b>สมัครสมาชิก</b><span>กรอกชื่อ รหัสนักศึกษา และตั้งรหัสผ่าน</span></li>' .
          '<li><b>ลงทะเบียนภาคเรียน</b><span>เลือกระดับชั้น แล้วรอครูอนุมัติ</span></li><li><b>ติดตามการเรียน</b><span>ดูงาน กิจกรรม คะแนน กพช. และการมาเรียน</span></li></ol>';
    $contact = (string) (cfg()['app']['contact'] ?? '');
    return [
        $title,
        '<div class="auth' . ($r === '/register.php' ? ' wide' : '') . '"><section class="auth-card card"><p class="eyebrow">' . ($t ? 'TEACHER PORTAL' : 'STUDENT PORTAL') . '</p>' .
        '<h1>' . esc($title) . '</h1><p class="lede">' . esc($lede) . '</p>' . $f . $after . '</section>' .
        '<aside class="auth-aside">' . $aside . ($contact !== '' ? '<p class="contact">' . icon('info', 16) . '<span>ติดต่อศูนย์: ' . esc($contact) . '</span></p>' : '') . '</aside></div>',
    ];
}
