<?php
declare(strict_types=1);
// V3.3.2 teacher features:
//  - classwork scores (assignments / worksheets / quizzes) kept separate from กพช. (กพช. = activity / supplementary points)
//  - teacher account: display name, username, password, workspace wallpaper, login background
//  - activity participation log, classwork gradebook
//  - Excel (.xlsx, no PHP extension needed) and printable PDF reports (combined or one page per student)

const WALLPAPERS = [
    'none' => 'สีพื้นเรียบ',
    'sky' => 'ฟ้าใส',
    'lagoon' => 'ทะเลสาบ',
    'sunset' => 'ยามเย็น',
    'forest' => 'ป่าเขียว',
    'night' => 'ท้องฟ้ายามค่ำ',
    'library' => 'ห้องสมุด',
];
const TEACHER_MARK_NOTE = 'ครูบันทึกคะแนน';

/* ---------- small settings store ---------- */
function appSetting(string $key, string $default = ''): string
{
    try {
        $v = val('SELECT setting_value FROM app_settings WHERE setting_key=?', [$key]);
    } catch (Throwable) {
        return $default; // table not created yet (update.sh creates it before switching code)
    }
    return $v === false || $v === null ? $default : (string) $v;
}
function saveAppSetting(string $key, string $value): void
{
    run('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$key, $value]);
}

/* ---------- wallpaper ---------- */
function wallpaperValue(?array $u): string
{
    $w = (string) ($u['wallpaper'] ?? '');
    if ($w === 'custom') {
        return !empty($u['wallpaper_id']) ? 'file:' . (int) $u['wallpaper_id'] : '';
    }
    return $w !== 'none' && isset(WALLPAPERS[$w]) ? $w : '';
}
/** @return array{0:string,1:string} body classes and the wallpaper element */
function wallpaperLayer(string $value): array
{
    if (preg_match('/^file:(\d+)$/D', $value, $m)) {
        return [' has-wall wall-custom', '<img class="wall-img" src="/file.php?id=' . (int) $m[1] . '" alt="" aria-hidden="true">'];
    }
    if ($value !== '' && $value !== 'none' && isset(WALLPAPERS[$value])) {
        return [' has-wall wall-' . $value, ''];
    }
    return ['', ''];
}
function loginWallpaperFileId(): int
{
    return preg_match('/^file:(\d+)$/D', appSetting('login_wallpaper'), $m) ? (int) $m[1] : 0;
}

/** True when a teacher declined or removed this enrollment (a student's own "not continuing" has no reviewer). */
function enrollmentClosedByTeacher(?array $en): bool
{
    return $en !== null && in_array($en['status'], ['declined', 'inactive'], true) && $en['reviewed_by'] !== null;
}

/* ---------- teacher login: popup only ---------- */
function teacherLoginPop(): array
{
    $GLOBALS['sgr_login_only'] = true;
    $last = (string) ($_SESSION['last_username'] ?? '');
    $form = formStart('/login.php') .
        field('username', 'ชื่อผู้ใช้', 'text', $last, true, 'autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60"' . ($last === '' ? ' autofocus' : '')) .
        field('password', 'รหัสผ่าน', 'password', '', true, 'autocomplete="current-password" maxlength="72"' . ($last !== '' ? ' autofocus' : '')) .
        btn('เข้าสู่ระบบ', 'primary block large') . '</form>';
    return ['เข้าสู่ระบบครู', $form];
}
function teacherLoginLayout(string $head, string $flashText, string $body): string
{
    [$wallClass, $wallHtml] = wallpaperLayer(appSetting('login_wallpaper'));
    $alert = $flashText !== '' ? '<div class="login-alert" role="alert">' . icon('alert', 18) . '<span>' . esc($flashText) . '</span></div>' : '';
    return $head . '<body class="guest teacher login-only' . $wallClass . '">' . $wallHtml .
        '<div class="login-orbs" aria-hidden="true"><span></span><span></span><span></span></div>' .
        '<main id="main" class="login-stage"><section class="login-pop' . ($alert !== '' ? ' shake' : '') . '" aria-labelledby="login-title">' .
        '<div class="login-brand">' . brandMark() . '<span><b>SGR Library</b><small>ศกร.ระดับตำบลบ้านใหม่</small></span></div>' .
        '<h1 id="login-title">เข้าสู่ระบบครู</h1><p class="lede">สำหรับครูและบุคลากรของศูนย์เท่านั้น</p>' . $alert . $body .
        '<p class="login-foot"><span>ลืมรหัสผ่าน? ติดต่อผู้ดูแลระบบของศูนย์</span><span class="site-version">V' . esc(APP_VERSION) . '</span></p></section></main></body></html>';
}

/* ---------- teacher account ---------- */
function teacherAccountPage(array $u): string
{
    $mine = wallpaperValue($u);
    $login = appSetting('login_wallpaper');
    $h = documentTitle('บัญชีครู', 'ชื่อที่แสดง ชื่อผู้ใช้ รหัสผ่าน และวอลเปเปอร์ของห้องทำงาน');
    $h .= '<section class="card profile-head">' . avatar($u, 'lg') . '<div><h2>' . esc(fullname($u)) . '</h2>' .
        '<p class="muted">ชื่อผู้ใช้ <b>' . esc($u['username']) . '</b></p><p class="muted small">เข้าสู่ระบบล่าสุด ' . esc(thDate($u['last_login'])) . '</p></div></section>';

    $cards = '';
    foreach (WALLPAPERS as $key => $label) {
        $on = $mine === $key || ($mine === '' && $key === 'none');
        $cards .= '<label class="wall-option"><input type="radio" name="wallpaper" value="' . esc($key) . '"' . ($on ? ' checked' : '') . '>' .
            '<span class="wall-swatch wall-' . esc($key) . '" aria-hidden="true"></span><span class="wall-name">' . esc($label) . '</span></label>';
    }
    $custom = str_starts_with($mine, 'file:');
    $cards .= '<label class="wall-option"><input type="radio" name="wallpaper" value="custom"' . ($custom ? ' checked' : '') . '>' .
        (!empty($u['wallpaper_id'])
            ? '<img class="wall-swatch" src="/file.php?id=' . (int) $u['wallpaper_id'] . '" alt="">'
            : '<span class="wall-swatch wall-upload" aria-hidden="true">' . icon('upload', 22) . '</span>') .
        '<span class="wall-name">รูปของฉัน</span></label>';
    $wall = '<section class="card section"><h2>' . icon('image', 20) . ' วอลเปเปอร์</h2><p class="muted">เลือกภาพพื้นหลังของห้องทำงานครู เปลี่ยนได้ทุกเมื่อ</p>' .
        formStart('/account.php', true) . hidden('action', 'wallpaper') .
        '<fieldset class="wall-grid"><legend class="sr-only">เลือกวอลเปเปอร์</legend>' . $cards . '</fieldset>' .
        uploadBox('wallpaper_file', 'อัปโหลดรูปของฉัน (เลือกรูปแล้วจะใช้ “รูปของฉัน” อัตโนมัติ)', true) .
        checkbox('login_too', 'ใช้เป็นพื้นหลังหน้าเข้าสู่ระบบครูด้วย', $login !== '' && $login === $mine) .
        btn('บันทึกวอลเปเปอร์') . '</form></section>';

    $profile = '<section class="card section"><h2>' . icon('user', 20) . ' ชื่อที่แสดง</h2>' . formStart('/account.php', true) . hidden('action', 'profile') .
        '<div class="pair">' . field('first_name', 'ชื่อ', 'text', (string) $u['first_name'], true, 'maxlength="100" autocomplete="given-name"') .
        field('last_name', 'นามสกุล', 'text', (string) $u['last_name'], true, 'maxlength="100" autocomplete="family-name"') . '</div>' .
        uploadBox('avatar', 'รูปโปรไฟล์', true) . btn('บันทึกชื่อและรูป', 'secondary') . '</form></section>';

    $name = '<details class="card panel"><summary>' . icon('users', 18) . ' เปลี่ยนชื่อผู้ใช้ (ใช้เข้าสู่ระบบ)</summary>' . formStart('/account.php') . hidden('action', 'username') .
        usernameField((string) $u['username']) .
        field('teacher_password', 'รหัสผ่านปัจจุบัน', 'password', '', true, 'autocomplete="current-password"', 'ใส่เพื่อยืนยันว่าเป็นเจ้าของบัญชี') .
        btn('เปลี่ยนชื่อผู้ใช้') . '</form></details>';
    $pass = '<details class="card panel"><summary>' . icon('key', 18) . ' เปลี่ยนรหัสผ่าน</summary>' . formStart('/account.php') . hidden('action', 'password') .
        field('teacher_password', 'รหัสผ่านปัจจุบัน', 'password', '', true, 'autocomplete="current-password"') . pwdFields() .
        '<p class="hint">เปลี่ยนแล้ว อุปกรณ์อื่นที่เข้าสู่ระบบด้วยบัญชีนี้ไว้จะถูกออกจากระบบ</p>' . btn('เปลี่ยนรหัสผ่าน') . '</form></details>';

    return $h . '<div class="grid two-one"><div class="stack">' . $wall . '</div><div class="stack">' . $profile . $name . $pass . '</div></div>';
}
function teacherAccountAction(int $teacher): never
{
    $action = choice('action', ['profile', 'username', 'password', 'wallpaper']);
    switch ($action) {
        case 'profile':
            $first = post('first_name', 100);
            $last = post('last_name', 100);
            tx(function () use ($teacher, $first, $last) {
                $old = row("SELECT avatar_id FROM users WHERE id=? AND role='teacher' FOR UPDATE", [$teacher]) ?? fail(403);
                $file = saveUpload('avatar', $teacher, true);
                run('UPDATE users SET first_name=?,last_name=?,avatar_id=? WHERE id=?', [$first, $last, $file ?? $old['avatar_id'], $teacher]);
                audit($teacher, 'teacher.profile', null, $teacher);
            });
            flash('บันทึกชื่อที่แสดงแล้ว');
            break;
        case 'username':
            $name = username();
            teacherConfirmV31($teacher);
            tx(function () use ($teacher, $name) {
                if (val('SELECT id FROM users WHERE username=? AND id<>?', [$name, $teacher])) {
                    fail(409, 'ชื่อผู้ใช้นี้มีคนใช้แล้ว กรุณาเลือกชื่ออื่น');
                }
                $old = (string) val("SELECT username FROM users WHERE id=? AND role='teacher' FOR UPDATE", [$teacher]);
                run('UPDATE users SET username=? WHERE id=?', [$name, $teacher]);
                audit($teacher, 'teacher.username', null, $teacher, ['from' => $old, 'to' => $name]);
            });
            flash('เปลี่ยนชื่อผู้ใช้เป็น ' . $name . ' แล้ว ครั้งหน้าใช้ชื่อนี้เข้าสู่ระบบ');
            break;
        case 'password':
            teacherConfirmV31($teacher);
            $hash = newPassword();
            tx(function () use ($teacher, $hash) {
                run("UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND role='teacher'", [$hash, $teacher]);
                audit($teacher, 'teacher.password', null, $teacher);
            });
            // Keep this browser signed in; every other session of this account is invalidated by auth_version.
            $_SESSION['version'] = (int) $_SESSION['version'] + 1;
            session_regenerate_id(true);
            flash('เปลี่ยนรหัสผ่านแล้ว');
            break;
        case 'wallpaper':
            $pick = choice('wallpaper', array_merge(array_keys(WALLPAPERS), ['custom']));
            $loginToo = isset($_POST['login_too']);
            tx(function () use ($teacher, $pick, $loginToo) {
                $old = row("SELECT wallpaper,wallpaper_id FROM users WHERE id=? AND role='teacher' FOR UPDATE", [$teacher]) ?? fail(403);
                $before = wallpaperValue($old);
                $file = saveUpload('wallpaper_file', $teacher, true);
                $choice = $file ? 'custom' : $pick;
                $fileId = $file ?? ($old['wallpaper_id'] !== null ? (int) $old['wallpaper_id'] : null);
                if ($choice === 'custom' && !$fileId) {
                    fail(422, 'เลือก “รูปของฉัน” แล้ว กรุณาอัปโหลดรูปภาพด้วย');
                }
                run('UPDATE users SET wallpaper=?,wallpaper_id=? WHERE id=?', [$choice, $fileId, $teacher]);
                $after = wallpaperValue(['wallpaper' => $choice, 'wallpaper_id' => $fileId]);
                $login = appSetting('login_wallpaper');
                if ($loginToo) {
                    saveAppSetting('login_wallpaper', $after);
                } elseif ($login !== '' && $login === $before) {
                    saveAppSetting('login_wallpaper', '');
                }
                audit($teacher, 'teacher.wallpaper', null, $teacher, ['wallpaper' => $choice, 'login' => $loginToo]);
            });
            flash('บันทึกวอลเปเปอร์แล้ว');
            break;
    }
    redirect('/account.php');
}

/* ---------- classwork (not กพช.) ---------- */
function classworkSummary(int $student, int $term): array
{
    $r = row(
        "SELECT COUNT(*) AS items, COALESCE(SUM(w.points),0) AS full_marks,
            COALESCE(SUM(CASE WHEN s.status='passed' THEN s.credited ELSE 0 END),0) AS got,
            COALESCE(SUM(s.status='passed'),0) AS graded, COALESCE(SUM(s.status='submitted'),0) AS waiting
         FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=?
         WHERE w.term_id=? AND w.kind='assignment'",
        [$student, $term],
    ) ?? [];
    return ['items' => (int) ($r['items'] ?? 0), 'max' => (int) ($r['full_marks'] ?? 0), 'got' => (int) ($r['got'] ?? 0),
        'graded' => (int) ($r['graded'] ?? 0), 'waiting' => (int) ($r['waiting'] ?? 0)];
}
function classworkMeter(int $student, array $term, string $url = ''): string
{
    $c = classworkSummary($student, (int) $term['id']);
    return meterCard('คะแนนงานในห้อง (ไม่นับ กพช.)', $c['got'], $c['max'], -1, 'คะแนน',
        'ตรวจแล้ว ' . $c['graded'] . ' จาก ' . $c['items'] . ' งาน' . ($c['waiting'] ? ' · รอตรวจ ' . $c['waiting'] : ''), $url);
}
function classworkTable(int $student, array $term): string
{
    $list = rows(
        "SELECT w.id,w.title,w.due_at,w.points,s.status,s.credited FROM work_items w
         LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=?
         WHERE w.term_id=? AND w.kind='assignment' ORDER BY w.due_at,w.id",
        [$student, $term['id']],
    );
    $data = [];
    $got = 0;
    $max = 0;
    foreach ($list as $r) {
        $graded = $r['status'] === 'passed';
        $got += $graded ? (int) $r['credited'] : 0;
        $max += (int) $r['points'];
        $data[] = [
            linkTo('/item.php?id=' . (int) $r['id'], (string) $r['title'], 'textlink'),
            esc(thDate($r['due_at'])),
            $graded ? badge('ตรวจแล้ว', 'good') : statusBadge($r['status']),
            $graded ? '<b>' . (int) $r['credited'] . '</b> / ' . (int) $r['points'] : '<span class="muted">– / ' . (int) $r['points'] . '</span>',
        ];
    }
    return tableView(['งาน / ใบงาน', 'กำหนดส่ง', 'สถานะ', 'คะแนน'], $data, 'ยังไม่มีงานในภาคเรียนนี้') .
        ($list ? '<p class="total-line">รวมคะแนนงานในห้อง <b>' . $got . '</b> / ' . $max . ' คะแนน</p>' : '');
}
function assignmentScoreInput(array $w, ?array $s): string
{
    $max = (int) $w['points'];
    if ($w['kind'] !== 'assignment' || $max < 1) {
        return '';
    }
    $value = ($s['status'] ?? '') === 'passed' ? (int) $s['credited'] : $max;
    return '<label class="field score-field"><span class="label">คะแนนที่ได้ (เต็ม ' . $max . ') · ใช้เมื่อเลือก “ผ่าน”</span>' .
        '<input type="number" name="score" value="' . $value . '" min="0" max="' . $max . '" step="1" inputmode="numeric"></label>';
}
function gradeSheetForm(array $item): string
{
    if ($item['kind'] !== 'assignment' || (int) $item['points'] < 1) {
        return '';
    }
    $students = rows(
        "SELECT u.id,u.first_name,u.last_name,u.student_code,e.level_name,s.status,s.credited
         FROM term_enrollments e JOIN users u ON u.id=e.student_id
         LEFT JOIN submissions s ON s.item_id=? AND s.student_id=u.id
         WHERE e.term_id=? AND e.status='approved' AND u.role='student' ORDER BY e.level_name,u.first_name,u.last_name,u.id",
        [$item['id'], $item['term_id']],
    );
    if (!$students) {
        return '';
    }
    $max = (int) $item['points'];
    $list = '';
    $done = 0;
    foreach ($students as $s) {
        $value = $s['status'] === 'passed' ? (string) (int) $s['credited'] : '';
        $done += $value !== '' ? 1 : 0;
        $state = $s['status'] && $s['status'] !== 'passed' ? ' · ' . statusName($s['status']) : '';
        $list .= '<label class="grade-row"><span class="grade-who"><b>' . esc(fullname($s)) . '</b><small>' . esc($s['student_code']) . ' · ' . esc($s['level_name']) . esc($state) . '</small></span>' .
            '<input type="number" name="score[' . (int) $s['id'] . ']" value="' . esc($value) . '" min="0" max="' . $max . '" step="1" inputmode="numeric" placeholder="–" aria-label="คะแนนของ ' . esc(fullname($s)) . '">' .
            '<small class="grade-max">/ ' . $max . '</small></label>';
    }
    return '<details class="card panel" id="grades"><summary>' . icon('star', 18) . ' กรอกคะแนนงานทั้งห้อง (กรอกแล้ว ' . $done . ' / ' . count($students) . ' คน)</summary>' .
        '<p class="muted">เหมาะกับใบงาน แบบฝึกหัด หรือสอบย่อยที่ตรวจบนกระดาษ ใส่คะแนน 0–' . $max . ' · ช่องว่างคือยังไม่ให้คะแนน · คะแนนงานในห้องแยกจาก กพช. และไม่ทำให้ กพช. เปลี่ยน</p>' .
        formStart('/item.php?id=' . (int) $item['id']) . hidden('action', 'grade_sheet') .
        '<div class="toolbar"><input class="search" type="search" data-grade-search placeholder="ค้นหาชื่อ / รหัส" aria-label="ค้นหารายชื่อ">' .
        '<button type="button" class="button secondary small" data-grade-fill="' . $max . '">ให้คะแนนเต็มทุกช่องที่ว่าง</button></div>' .
        '<div class="grade-sheet">' . $list . '</div>' .
        '<div class="sticky-actions"><span class="muted" data-grade-count>กรอกแล้ว ' . $done . ' คน</span>' . btn('บันทึกคะแนนทั้งห้อง') . '</div></form></details>';
}
function gradeSheetAction(int $teacher, int $itemId): never
{
    $work = row('SELECT id,kind,points,term_id,title FROM work_items WHERE id=?', [$itemId]) ?? fail(404);
    if ($work['kind'] !== 'assignment') {
        fail(422, 'การกรอกคะแนนทั้งห้องใช้ได้เฉพาะงานในห้อง');
    }
    $max = (int) $work['points'];
    $raw = $_POST['score'] ?? [];
    if (!is_array($raw) || count($raw) > 3000) {
        fail(422, 'ข้อมูลคะแนนไม่ถูกต้อง');
    }
    $valid = array_map(static fn(array $s): int => (int) $s['id'], v32EnrolledStudents((int) $work['term_id']));
    $scores = [];
    foreach ($raw as $key => $value) {
        $sid = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sid === false || !in_array($sid, $valid, true)) {
            fail(422, 'พบรายชื่อนักศึกษาที่ไม่อยู่ในภาคเรียนนี้');
        }
        if (!is_string($value)) {
            fail(422, 'ข้อมูลคะแนนไม่ถูกต้อง');
        }
        $value = trim($value);
        if ($value === '') {
            $scores[$sid] = null;
            continue;
        }
        if (!preg_match('/^\d{1,3}$/D', $value) || (int) $value > $max) {
            fail(422, 'คะแนนต้องเป็นจำนวนเต็ม 0–' . $max);
        }
        $scores[$sid] = (int) $value;
    }
    $changed = tx(function () use ($scores, $teacher, $work, $max): int {
        $n = 0;
        foreach ($scores as $sid => $score) {
            student($sid, true);
            $s = row('SELECT id,status,credited,file_id,note FROM submissions WHERE item_id=? AND student_id=? FOR UPDATE', [$work['id'], $sid]);
            if ($score === null) {
                if (!$s || $s['status'] !== 'passed') {
                    continue;
                }
                if ($s['file_id'] === null && $s['note'] === TEACHER_MARK_NOTE) {
                    run('DELETE FROM submissions WHERE id=?', [$s['id']]);
                } else {
                    run("UPDATE submissions SET status='submitted',credited=0,reviewed_by=?,reviewed_at=NOW(),review_version=review_version+1 WHERE id=?", [$teacher, $s['id']]);
                }
                audit($teacher, 'work.grade', $sid, (int) $work['id'], ['score' => null]);
                $n++;
                continue;
            }
            if ($s && $s['status'] === 'passed' && (int) $s['credited'] === $score) {
                continue;
            }
            if ($s) {
                run("UPDATE submissions SET status='passed',credited=?,reviewed_by=?,reviewed_at=NOW(),review_version=review_version+1 WHERE id=?", [$score, $teacher, $s['id']]);
            } else {
                run("INSERT INTO submissions(item_id,student_id,note,submitted_at,status,review_note,reviewed_by,reviewed_at,credited,review_version)
                     VALUES(?,?,?,NOW(),'passed','',?,NOW(),?,1)", [$work['id'], $sid, TEACHER_MARK_NOTE, $teacher, $score]);
            }
            audit($teacher, 'work.grade', $sid, (int) $work['id'], ['score' => $score, 'max' => $max]);
            notifyStudent($sid, 'คะแนนงาน · ' . $work['title'] . ' ได้ ' . $score . ' / ' . $max, '/item.php?id=' . (int) $work['id'], (int) $work['term_id']);
            $n++;
        }
        return $n;
    });
    flash('บันทึกคะแนนงานแล้ว ' . $changed . ' คน (ไม่กระทบคะแนน กพช.)');
    redirect('/item.php?id=' . $itemId . '#grades');
}

/* ---------- gradebook ---------- */
function gradebookData(int $termId, ?string $level = null): array
{
    $items = rows("SELECT id,title,points,due_at FROM work_items WHERE term_id=? AND kind='assignment' ORDER BY due_at,id", [$termId]);
    $students = v32EnrolledStudents($termId, $level);
    $cells = [];
    foreach (rows("SELECT s.student_id,s.item_id,s.status,s.credited FROM submissions s JOIN work_items w ON w.id=s.item_id WHERE w.term_id=? AND w.kind='assignment'", [$termId]) as $c) {
        $cells[(int) $c['student_id']][(int) $c['item_id']] = $c;
    }
    return [$items, $students, $cells];
}
function gradebookPage(array $term): string
{
    $tid = (int) $term['id'];
    [$items, $students, $cells] = gradebookData($tid);
    $actions = iconLink('/report.php?term=' . $tid . '&format=xlsx', 'download', 'ดาวน์โหลด Excel', 'button secondary') .
        iconLink('/new-item.php?kind=assignment', 'plus', 'เพิ่มงาน / ใบงาน', 'button primary');
    $h = documentTitle('คะแนนงานในห้อง', 'ใบงาน แบบฝึกหัด สอบย่อย · แยกจากคะแนน กพช. (กพช. = คะแนนกิจกรรม / คะแนนเสริม)', $actions) . termFilter($term);
    if (!$items) {
        return $h . emptyState('ยังไม่มีงานในภาคเรียนนี้ กด “เพิ่มงาน / ใบงาน” เพื่อเริ่ม', 'file');
    }
    if (!$students) {
        return $h . emptyState('ยังไม่มีนักศึกษาที่อนุมัติในภาคเรียนนี้', 'users');
    }
    $full = array_sum(array_map(static fn(array $i): int => (int) $i['points'], $items));
    $head = '<tr><th scope="col" class="sticky-col">นักศึกษา</th>';
    foreach ($items as $i) {
        $head .= '<th scope="col"><a href="/item.php?id=' . (int) $i['id'] . '#grades">' . esc(mb_strimwidth((string) $i['title'], 0, 34, '…')) . '</a><small>เต็ม ' . (int) $i['points'] . '</small></th>';
    }
    $head .= '<th scope="col">รวม<small>เต็ม ' . $full . '</small></th></tr>';
    $body = '';
    foreach ($students as $s) {
        $sum = 0;
        $line = '<tr class="gb-row"><th scope="row" class="sticky-col"><a href="/student.php?id=' . (int) $s['id'] . '&term=' . $tid . '">' . esc(fullname($s)) . '</a><small>' . esc($s['student_code']) . ' · ' . esc($s['level_name']) . '</small></th>';
        foreach ($items as $i) {
            $c = $cells[(int) $s['id']][(int) $i['id']] ?? null;
            if ($c && $c['status'] === 'passed') {
                $sum += (int) $c['credited'];
                $line .= '<td><b>' . (int) $c['credited'] . '</b></td>';
            } elseif ($c) {
                $line .= '<td>' . badge($c['status'] === 'submitted' ? 'รอตรวจ' : statusName($c['status']), $c['status'] === 'revise' ? 'red' : 'gold') . '</td>';
            } else {
                $line .= '<td class="muted">–</td>';
            }
        }
        $body .= $line . '<td class="gb-total"><b>' . $sum . '</b> / ' . $full . '</td></tr>';
    }
    return $h . '<section class="card section"><div class="section-head"><h2>' . count($students) . ' คน · ' . count($items) . ' งาน</h2>' .
        '<input type="search" class="search" data-row-search=".gb-row" placeholder="ค้นหาชื่อ / รหัส" aria-label="ค้นหานักศึกษา"></div>' .
        '<div class="scroll gradebook-wrap"><table class="gradebook"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>' .
        '<p class="hint">แตะชื่องานเพื่อกรอกคะแนนทั้งห้อง · “–” คือยังไม่มีคะแนน</p></section>';
}

/* ---------- activity log ---------- */
function activityParticipation(int $termId): array
{
    $acts = rows("SELECT * FROM work_items WHERE term_id=? AND kind='activity' ORDER BY COALESCE(starts_at,due_at) DESC,id DESC", [$termId]);
    $people = rows(
        "SELECT s.item_id,s.status,s.reviewed_at,s.submitted_at,s.note,s.file_id,s.credited,u.id AS student_id,u.first_name,u.last_name,u.student_code,u.avatar_id,e.level_name
         FROM submissions s JOIN work_items w ON w.id=s.item_id JOIN users u ON u.id=s.student_id
         LEFT JOIN term_enrollments e ON e.student_id=u.id AND e.term_id=w.term_id
         WHERE w.term_id=? AND w.kind='activity' AND s.status IN ('passed','submitted')
         ORDER BY COALESCE(s.reviewed_at,s.submitted_at),u.first_name,u.last_name",
        [$termId],
    );
    $byItem = [];
    $byStudent = [];
    foreach ($people as $p) {
        $byItem[(int) $p['item_id']][] = $p;
        if ($p['status'] === 'passed') {
            $byStudent[(int) $p['student_id']][(int) $p['item_id']] = $p;
        }
    }
    return [$acts, $byItem, $byStudent];
}
function activityLogPage(array $term): string
{
    $tid = (int) $term['id'];
    $view = ($_GET['view'] ?? '') === 'students' ? 'students' : 'activities';
    [$acts, $byItem, $byStudent] = activityParticipation($tid);
    $enrolled = v32EnrolledStudents($tid);
    $actions = iconLink('/report.php?term=' . $tid . '&format=xlsx', 'download', 'Excel', 'button secondary') .
        iconLink('/new-item.php?kind=activity', 'plus', 'เพิ่มกิจกรรม', 'button primary');
    $h = documentTitle('บันทึกกิจกรรม', 'ประวัติว่านักศึกษาคนไหนเข้าร่วมกิจกรรมอะไร เมื่อไร และได้ กพช. เท่าไร', $actions) . termFilter($term, ['view' => $view]);
    $h .= '<nav class="seg-tabs" aria-label="มุมมอง">' .
        '<a href="/activity-log.php?term=' . $tid . '"' . ($view === 'activities' ? ' aria-current="page"' : '') . '>' . icon('spark', 18) . '<span>ตามกิจกรรม</span></a>' .
        '<a href="/activity-log.php?term=' . $tid . '&view=students"' . ($view === 'students' ? ' aria-current="page"' : '') . '>' . icon('users', 18) . '<span>ตามนักศึกษา</span></a></nav>';
    if (!$acts) {
        return $h . emptyState('ยังไม่มีกิจกรรมในภาคเรียนนี้', 'spark');
    }
    if ($view === 'activities') {
        $out = '';
        foreach ($acts as $a) {
            $list = $byItem[(int) $a['id']] ?? [];
            $joined = array_values(array_filter($list, static fn(array $p): bool => $p['status'] === 'passed'));
            $waiting = count($list) - count($joined);
            $people = '';
            foreach ($list as $p) {
                $how = $p['status'] === 'submitted' ? badge('รอตรวจหลักฐาน', 'gold') : ($p['note'] === 'ครูบันทึกการเข้าร่วม' ? badge('ครูบันทึก', 'blue') : badge('ส่งหลักฐาน', 'good'));
                $people .= '<li><a class="person-link" href="/student.php?id=' . (int) $p['student_id'] . '&term=' . $tid . '">' . avatar($p, 'sm') .
                    '<span><b>' . esc(fullname($p)) . '</b><small>' . esc($p['student_code']) . ' · ' . esc($p['level_name'] ?? '') . ' · ' . esc(thDate($p['reviewed_at'] ?? $p['submitted_at'])) . '</small></span></a>' .
                    $how . ($p['status'] === 'passed' ? '<span class="delta pos">+' . (int) $p['credited'] . '</span>' : '') . '</li>';
            }
            $out .= '<article class="card activity-log"><div class="log-head"><span class="todo-icon">' . icon('spark', 20) . '</span>' .
                '<div class="log-title"><h3><a href="/item.php?id=' . (int) $a['id'] . '">' . esc($a['title']) . '</a></h3><small>' .
                esc(thDate($a['starts_at'] ?? $a['due_at'])) . ($a['place'] !== '' ? ' · ' . esc($a['place']) : '') . ' · กพช. ' . (int) $a['points'] . ' คะแนน</small></div>' .
                '<div class="log-count"><strong>' . count($joined) . '</strong><small>/ ' . count($enrolled) . ' คน</small></div></div>' .
                '<progress value="' . min(count($joined), max(1, count($enrolled))) . '" max="' . max(1, count($enrolled)) . '" aria-label="สัดส่วนผู้เข้าร่วม"></progress>' .
                ($people !== ''
                    ? '<details class="log-people"><summary>รายชื่อผู้เข้าร่วม ' . count($joined) . ' คน' . ($waiting ? ' · รอตรวจ ' . $waiting : '') . '</summary><ul class="people-list">' . $people . '</ul></details>'
                    : '<p class="muted small">ยังไม่มีผู้เข้าร่วม</p>') .
                '<div class="actions">' . linkTo('/item.php?id=' . (int) $a['id'] . '#participants', 'บันทึก / แก้รายชื่อผู้เข้าร่วม', 'button secondary small') . '</div></article>';
        }
        return $h . '<div class="stack">' . $out . '</div>';
    }
    $titles = array_column($acts, 'title', 'id');
    $cards = '';
    foreach ($enrolled as $s) {
        $mine = $byStudent[(int) $s['id']] ?? [];
        $points = array_sum(array_map(static fn(array $p): int => (int) $p['credited'], $mine));
        $names = '';
        foreach ($mine as $itemId => $p) {
            $names .= '<li>' . esc($titles[$itemId] ?? '') . ' <small>' . esc(thDate($p['reviewed_at'] ?? $p['submitted_at'], false)) . '</small></li>';
        }
        $cards .= '<article class="card log-person"><a class="person-link" href="/student.php?id=' . (int) $s['id'] . '&term=' . $tid . '">' . avatar($s, 'sm') .
            '<span><b>' . esc(fullname($s)) . '</b><small>' . esc($s['student_code']) . ' · ' . esc($s['level_name']) . '</small></span></a>' .
            '<div class="log-count"><strong>' . count($mine) . '</strong><small>/ ' . count($acts) . ' กิจกรรม</small></div>' .
            '<span class="delta pos">กพช. +' . $points . '</span>' .
            ($names !== '' ? '<ul class="log-names">' . $names . '</ul>' : '<p class="muted small">ยังไม่ได้เข้าร่วมกิจกรรม</p>') . '</article>';
    }
    return $h . '<div class="toolbar"><input type="search" class="search" data-row-search=".log-person" placeholder="ค้นหาชื่อ / รหัส" aria-label="ค้นหานักศึกษา"></div>' .
        ($cards !== '' ? '<div class="log-grid">' . $cards . '</div>' : emptyState('ยังไม่มีนักศึกษาที่อนุมัติในภาคเรียนนี้', 'users'));
}
function activityHistoryForStudent(int $student, array $term): string
{
    $list = rows(
        "SELECT w.id,w.title,w.starts_at,w.due_at,w.place,w.points,s.status,s.credited,s.reviewed_at,s.submitted_at FROM work_items w
         LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=?
         WHERE w.term_id=? AND w.kind='activity' ORDER BY COALESCE(w.starts_at,w.due_at) DESC,w.id DESC",
        [$student, $term['id']],
    );
    $data = [];
    foreach ($list as $r) {
        $state = match ($r['status']) {
            'passed' => badge('เข้าร่วมแล้ว', 'good'),
            'submitted' => badge('รอตรวจหลักฐาน', 'gold'),
            'revise' => badge('ให้ส่งหลักฐานใหม่', 'red'),
            default => badge('ไม่ได้เข้าร่วม'),
        };
        $data[] = [
            linkTo('/item.php?id=' . (int) $r['id'], (string) $r['title'], 'textlink') . ($r['place'] !== '' ? '<small class="block muted">' . esc($r['place']) . '</small>' : ''),
            esc(thDate($r['starts_at'] ?? $r['due_at'])),
            $state,
            $r['status'] === 'passed' ? '<span class="delta pos">+' . (int) $r['credited'] . '</span>' : '<span class="muted">–</span>',
        ];
    }
    return tableView(['กิจกรรม', 'วันที่', 'การเข้าร่วม', 'กพช.'], $data, 'ยังไม่มีกิจกรรมในภาคเรียนนี้');
}

/* ---------- Excel (.xlsx) writer: plain PHP, no zip extension required ---------- */
function xlsxColumn(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}
function xlsxEsc(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function xlsxSheetName(string $name, array $used): string
{
    $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)) ?: 'Sheet';
    $name = mb_substr($name, 0, 31);
    $base = $name;
    for ($i = 2; in_array(mb_strtolower($name), $used, true); $i++) {
        $name = mb_substr($base, 0, 28) . ' ' . $i;
    }
    return $name;
}
function xlsxSheetXml(array $rows, array $widths): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="18"/>';
    if ($widths) {
        $x .= '<cols>';
        foreach (array_values($widths) as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . number_format((float) $w, 1, '.', '') . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach (array_values($rows) as $r => $cells) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($cells) as $c => $v) {
            $ref = xlsxColumn($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) {
                $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } elseif ($v !== null && $v !== '') {
                // Inline strings are never evaluated as formulas, so user text such as "=..." stays plain text.
                $x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . xlsxEsc((string) $v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}
function zipBuild(array $files): string
{
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    $data = '';
    $central = '';
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $method = 0;
        $stored = $content;
        if (function_exists('gzdeflate')) {
            $z = gzdeflate($content, 6);
            if (is_string($z) && strlen($z) < strlen($content)) {
                $method = 8;
                $stored = $z;
            }
        }
        $offset = strlen($data);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($stored), strlen($content), strlen($name), 0) . $name . $stored;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($stored), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
}
/** @param array<string,array{rows:array,widths?:array}> $sheets */
function xlsxBuild(array $sheets): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $types = '';
    $list = '';
    $rels = '';
    $parts = [];
    $used = [];
    $i = 0;
    foreach ($sheets as $name => $sheet) {
        $i++;
        $safe = xlsxSheetName((string) $name, $used);
        $used[] = mb_strtolower($safe);
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $list .= '<sheet name="' . xlsxEsc($safe) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $parts['xl/worksheets/sheet' . $i . '.xml'] = xlsxSheetXml($sheet['rows'], $sheet['widths'] ?? []);
    }
    $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $files = [
        '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $types . '</Types>',
        '_rels/.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $xml . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $list . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
        'xl/styles.xml' => $xml . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font></fonts>' .
            '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF16456E"/><bgColor indexed="64"/></patternFill></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    ];
    return zipBuild($files + $parts);
}
function xlsxSend(string $filename, array $sheets): never
{
    $bin = xlsxBuild($sheets);
    session_write_close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit();
}

/* ---------- report data shared by Excel, CSV and PDF ---------- */
function reportStudents(int $termId, ?int $only = null, ?string $level = null): array
{
    if ($only !== null) {
        return array_values(array_filter(v32EnrolledStudents($termId, null, false), static fn(array $s): bool => (int) $s['id'] === $only));
    }
    return v32EnrolledStudents($termId, $level);
}
function termWorkbook(array $term, ?string $level = null): array
{
    $tid = (int) $term['id'];
    [, $data] = v32ReportData($tid, null, $level);
    $summary = [['รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'ชื่อผู้ใช้', 'ระดับชั้น', 'เพศ', 'มา (วัน)', 'ลา (วัน)', 'ขาด (วัน)', 'เกณฑ์มาเรียน',
        'คะแนน กพช.', 'เกณฑ์ กพช.', 'คะแนนงานในห้อง', 'คะแนนเต็มงาน', 'กิจกรรมที่เข้าร่วม (ครั้ง)', 'ประเมินตามเกณฑ์']];
    foreach ($data as $d) {
        $summary[] = [$d['code'], $d['name'], $d['username'], $d['level'], $d['gender'], $d['present'], $d['leave'], $d['absent'], (int) $term['min_attendance'],
            $d['points'], (int) $term['min_points'], $d['classwork'], $d['classwork_max'], $d['activities'], $d['pass']];
    }
    [$items, $students, $cells] = gradebookData($tid, $level);
    $gb = [array_merge(['รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'ระดับชั้น'], array_map(static fn(array $i): string => $i['title'] . ' (เต็ม ' . (int) $i['points'] . ')', $items), ['รวม', 'คะแนนเต็ม'])];
    $full = array_sum(array_map(static fn(array $i): int => (int) $i['points'], $items));
    foreach ($students as $s) {
        $line = [(string) $s['student_code'], fullname($s), (string) $s['level_name']];
        $sum = 0;
        foreach ($items as $i) {
            $c = $cells[(int) $s['id']][(int) $i['id']] ?? null;
            if ($c && $c['status'] === 'passed') {
                $sum += (int) $c['credited'];
                $line[] = (int) $c['credited'];
            } else {
                $line[] = $c ? ($c['status'] === 'submitted' ? 'รอตรวจ' : statusName($c['status'])) : '';
            }
        }
        $line[] = $sum;
        $line[] = $full;
        $gb[] = $line;
    }
    [$acts, , $byStudent] = activityParticipation($tid);
    $acts = array_reverse($acts);
    $ac = [array_merge(['รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'ระดับชั้น'], array_map(static fn(array $a): string => $a['title'] . ' (' . thDate($a['starts_at'] ?? $a['due_at'], false) . ')', $acts), ['เข้าร่วม (ครั้ง)', 'กพช. จากกิจกรรม'])];
    foreach ($students as $s) {
        $mine = $byStudent[(int) $s['id']] ?? [];
        $line = [(string) $s['student_code'], fullname($s), (string) $s['level_name']];
        foreach ($acts as $a) {
            $line[] = isset($mine[(int) $a['id']]) ? 'เข้าร่วม' : '';
        }
        $line[] = count($mine);
        $line[] = array_sum(array_map(static fn(array $p): int => (int) $p['credited'], $mine));
        $ac[] = $line;
    }
    $ids = array_map(static fn(array $s): int => (int) $s['id'], $students);
    $ledger = [['วันที่', 'รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'คะแนน', 'เหตุผล / แหล่งที่มา', 'ผู้บันทึก']];
    if ($ids) {
        foreach (rows('SELECT e.created_at,e.delta_points,e.reason,s.student_code,s.first_name,s.last_name,t.first_name AS tf,t.last_name AS tl FROM score_entries e
            JOIN users s ON s.id=e.student_id JOIN users t ON t.id=e.teacher_id WHERE e.term_id=? AND e.student_id IN (' . implode(',', $ids) . ') ORDER BY e.created_at,e.id', [$tid]) as $e) {
            $ledger[] = [thDate($e['created_at']), (string) $e['student_code'], fullname($e), (int) $e['delta_points'], (string) $e['reason'], trim($e['tf'] . ' ' . $e['tl'])];
        }
    }
    $sessions = rows('SELECT id,title,opens_at,no_class FROM attendance_sessions WHERE term_id=? ORDER BY opens_at,id', [$tid]);
    $marks = [];
    foreach (rows('SELECT r.session_id,r.student_id,r.status FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id WHERE a.term_id=?', [$tid]) as $m) {
        $marks[(int) $m['student_id']][(int) $m['session_id']] = $m['status'];
    }
    $at = [array_merge(['รหัสนักศึกษา', 'ชื่อ-นามสกุล'], array_map(static fn(array $x): string => thDate($x['opens_at'], false) . ' ' . $x['title'], $sessions), ['มา', 'ลา', 'ขาด'])];
    foreach ($students as $s) {
        $line = [(string) $s['student_code'], fullname($s)];
        $count = ['present' => 0, 'leave' => 0, 'absent' => 0];
        foreach ($sessions as $x) {
            $st = $marks[(int) $s['id']][(int) $x['id']] ?? null;
            if ((int) $x['no_class'] === 1) {
                $line[] = 'งดเรียน';
                continue;
            }
            if (isset($count[$st ?? ''])) {
                $count[$st]++;
            }
            $line[] = $st ? statusName($st) : '';
        }
        $gbTail = [$count['present'], $count['leave'], $count['absent']];
        $at[] = array_merge($line, $gbTail);
    }
    $wide = static fn(int $fixed, int $n, int $tail): array => array_merge([14, 26, 18], array_fill(0, max(0, $fixed - 3), 12), array_fill(0, $n, 14), array_fill(0, $tail, 12));
    return [
        'สรุปผลภาคเรียน' => ['rows' => $summary, 'widths' => [14, 26, 16, 20, 10, 9, 9, 9, 12, 11, 11, 14, 13, 18, 22]],
        'คะแนนงานในห้อง' => ['rows' => $gb, 'widths' => $wide(3, count($items), 2)],
        'กิจกรรม (กพช.)' => ['rows' => $ac, 'widths' => $wide(3, count($acts), 2)],
        'ประวัติคะแนน กพช.' => ['rows' => $ledger, 'widths' => [18, 14, 26, 9, 48, 20]],
        'การมาเรียน' => ['rows' => $at, 'widths' => array_merge([14, 26], array_fill(0, count($sessions), 16), [7, 7, 7])],
    ];
}
function studentReportSheet(array $term, array $d, bool $teacher): string
{
    $tid = (int) $term['id'];
    $id = (int) $d['id'];
    $s = student($id);
    $kpc = rows('SELECT created_at,delta_points,reason FROM score_entries WHERE student_id=? AND term_id=? ORDER BY created_at,id', [$id, $tid]);
    $k = [];
    foreach ($kpc as $e) {
        $k[] = [esc(thDate($e['created_at'], false)), ((int) $e['delta_points'] > 0 ? '+' : '') . (int) $e['delta_points'], esc($e['reason'])];
    }
    $cw = [];
    foreach (rows("SELECT w.title,w.points,s.status,s.credited FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=? WHERE w.term_id=? AND w.kind='assignment' ORDER BY w.due_at,w.id", [$id, $tid]) as $r) {
        $cw[] = [esc($r['title']), $r['status'] === 'passed' ? (int) $r['credited'] . ' / ' . (int) $r['points'] : '– / ' . (int) $r['points']];
    }
    $ac = [];
    foreach (rows("SELECT w.title,w.starts_at,w.due_at,s.credited FROM submissions s JOIN work_items w ON w.id=s.item_id WHERE s.student_id=? AND w.term_id=? AND w.kind='activity' AND s.status='passed' ORDER BY COALESCE(w.starts_at,w.due_at),w.id", [$id, $tid]) as $r) {
        $ac[] = [esc($r['title']), esc(thDate($r['starts_at'] ?? $r['due_at'], false)), '+' . (int) $r['credited']];
    }
    $at = [];
    foreach (rows('SELECT a.title,a.opens_at,a.no_class,r.status FROM attendance_sessions a LEFT JOIN attendance_records r ON r.session_id=a.id AND r.student_id=? WHERE a.term_id=? ORDER BY a.opens_at,a.id', [$id, $tid]) as $r) {
        $at[] = [esc(thDate($r['opens_at'], false)), esc($r['title']), (int) $r['no_class'] === 1 ? 'งดเรียน' : esc($r['status'] ? statusName($r['status']) : '–')];
    }
    $box = static fn(string $label, string $value, string $note): string => '<div class="box"><span>' . esc($label) . '</span><b>' . $value . '</b><small>' . esc($note) . '</small></div>';
    return '<section class="sheet"><header><p>SGR Library · ศกร.ระดับตำบลบ้านใหม่ อ.สามพราน จ.นครปฐม</p><h1>รายงานผลการเรียนรายบุคคล · ' . esc(termName($term)) . '</h1></header>' .
        '<dl class="who"><div><dt>ชื่อ–นามสกุล</dt><dd>' . esc(fullname($s)) . '</dd></div><div><dt>รหัสนักศึกษา</dt><dd>' . esc($s['student_code']) . '</dd></div>' .
        '<div><dt>ระดับชั้น</dt><dd>' . esc($d['level']) . '</dd></div><div><dt>เพศ</dt><dd>' . esc($s['gender']) . '</dd></div>' .
        '<div><dt>สถานะ</dt><dd>' . esc($d['status']) . '</dd></div>' . ($teacher ? '<div><dt>ชื่อผู้ใช้</dt><dd>' . esc($s['username']) . '</dd></div>' : '') . '</dl>' .
        '<div class="boxes">' . $box('มาเรียน', $d['present'] . ' วัน', 'ลา ' . $d['leave'] . ' · ขาด ' . $d['absent'] . ' · เกณฑ์ ' . (int) $term['min_attendance']) .
        $box('คะแนน กพช.', (string) $d['points'], 'เกณฑ์ ' . (int) $term['min_points'] . ' คะแนน') .
        $box('คะแนนงานในห้อง', $d['classwork'] . ' / ' . $d['classwork_max'], 'ไม่นับรวมใน กพช.') .
        $box('กิจกรรมที่เข้าร่วม', $d['activities'] . ' ครั้ง', $d['pass']) . '</div>' .
        '<div class="cols"><div><h2>กิจกรรมที่เข้าร่วม (กพช.)</h2>' . tableView(['กิจกรรม', 'วันที่', 'กพช.'], $ac, 'ยังไม่ได้เข้าร่วมกิจกรรม') .
        '<h2>คะแนนงานในห้อง</h2>' . tableView(['งาน / ใบงาน', 'คะแนน'], $cw, 'ยังไม่มีงาน') . '</div>' .
        '<div><h2>ประวัติคะแนน กพช.</h2>' . tableView(['วันที่', 'คะแนน', 'เหตุผล'], $k, 'ยังไม่มีรายการ') .
        '<h2>การมาเรียน</h2>' . tableView(['วันที่', 'วันเรียน', 'สถานะ'], $at, 'ยังไม่มีวันเรียน') . '</div></div>' .
        '<div class="sign"><span>ลงชื่อ ...................................................... ครูผู้สอน</span><span>วันที่ ........./........./.........</span></div></section>';
}
