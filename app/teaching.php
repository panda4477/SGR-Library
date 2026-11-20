<?php
declare(strict_types=1);
function teacherStats(int $term): array
{
    $n = (int) val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='approved'", [$term]);
    $jobs = (int) val("SELECT COUNT(*) FROM work_items WHERE kind='assignment' AND term_id=?", [$term]);
    $counts = rows(
        "SELECT w.kind,COUNT(*) AS sent,SUM(s.status='submitted') AS waiting FROM submissions s JOIN work_items w ON w.id=s.item_id WHERE w.term_id=? GROUP BY w.kind",
        [$term],
    );
    $totals = array_column($counts, null, 'kind');
    return [
        'students' => $n,
        'online' => (int) val(
            "SELECT COUNT(*) FROM users u JOIN term_enrollments e ON e.student_id=u.id WHERE e.term_id=? AND e.status='approved' AND u.last_seen>=NOW()-INTERVAL 5 MINUTE",
            [$term],
        ),
        'assign_pending' => (int) ($totals['assignment']['waiting'] ?? 0),
        'assign_missing' => max(0,$n * $jobs - (int) ($totals['assignment']['sent'] ?? 0)),
        'activity_pending' => (int) ($totals['activity']['waiting'] ?? 0),
        'attendance_pending' => (int) val(
            "SELECT COUNT(*) FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id WHERE a.term_id=? AND r.status='pending'",
            [$term],
        ),
        'below_pass' =>
            $n -
            (int) val(
                'SELECT COUNT(*) FROM (SELECT e.student_id FROM term_enrollments e LEFT JOIN score_entries s ON s.student_id=e.student_id AND s.term_id=e.term_id WHERE e.term_id=? AND e.status=\'approved\' GROUP BY e.student_id HAVING COALESCE(SUM(s.delta_points),0)>=(SELECT min_points FROM terms WHERE id=?)) qualified',
                [$term,$term],
            ),
    ];
}
function teacherDashboard(array $term, ?array $u = null): string
{
    $s = teacherStats((int) $term['id']);
    $pending = (int) val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='pending'", [$term['id']]);
    $requests = (int) val('SELECT COUNT(*) FROM reset_requests WHERE used_at IS NULL AND code_hash IS NULL');
    $todo = [
        [$pending, 'check', 'คำขอลงทะเบียนรออนุมัติ', '/enrollments.php?term=' . (int) $term['id']],
        [$s['assign_pending'], 'file', 'งานรอตรวจ', '/assignments.php?term=' . (int) $term['id']],
        [$s['activity_pending'], 'spark', 'หลักฐานกิจกรรมรอตรวจ', '/activities.php?term=' . (int) $term['id']],
        [$requests, 'key', 'คำขอตั้งรหัสผ่านใหม่', '/requests.php'],
        [$s['attendance_pending'], 'roll', 'การลงชื่อรอยืนยัน', '/attendance.php?term=' . (int) $term['id']],
    ];
    $items = '';
    foreach ($todo as [$n, $ic, $label, $url]) {
        if ($n > 0) {
            $items .= '<li><a href="' . esc($url) . '"><span class="todo-icon">' . icon($ic, 18) . '</span><span class="todo-main"><b>' . esc($label) . '</b></span>' . badge((string) $n, 'gold') . icon('chevron', 16) . '</a></li>';
        }
    }
    $todoHtml = $items !== '' ? '<ul class="todo">' . $items . '</ul>' : emptyState('ไม่มีงานค้าง ทุกอย่างเรียบร้อย', 'check');
    $stats = [
        ['นักศึกษาภาคเรียนนี้', $s['students'], 'คน', '/students.php?term=' . (int) $term['id'] . '&status=approved', ''],
        ['ใช้งานใน 5 นาที', $s['online'], 'คน', '/students.php?term=' . (int) $term['id'] . '&filter=online', 'blue'],
        ['งานที่ยังไม่ส่ง', $s['assign_missing'], 'ชิ้น', '/assignments.php?term=' . (int) $term['id'], 'gold'],
        ['กพช. ต่ำกว่าเกณฑ์', $s['below_pass'], 'คน', '/students.php?term=' . (int) $term['id'] . '&filter=low', 'red'],
    ];
    $st = '<div class="stats">';
    foreach ($stats as [$label, $n, $unit, $url, $tone]) {
        $st .= '<a class="stat ' . $tone . '" href="' . esc($url) . '"><span>' . esc($label) . '</span><strong>' . (int) $n . '</strong><small>' . esc($unit) . '</small></a>';
    }
    $st .= '</div>';
    $act = [
        ['/new-attendance.php', 'roll', 'เช็กชื่อวันนี้'],
        ['/scores.php', 'star', 'ให้คะแนน กพช.'],
        ['/gradebook.php', 'grade', 'คะแนนงาน'],
        ['/new-item.php?kind=activity', 'spark', 'เพิ่มกิจกรรม'],
        ['/activity-log.php', 'history', 'บันทึกกิจกรรม'],
        ['/reports.php', 'chart', 'รายงาน / Excel'],
    ];
    $tiles = '';
    foreach ($act as [$url, $ic, $label]) {
        $tiles .= '<a class="tile" href="' . esc($url) . '"><span class="tile-icon">' . icon($ic, 22) . '</span><span>' . esc($label) . '</span></a>';
    }
    $sessions = rows("SELECT a.*,COALESCE(SUM(r.status='present'),0) AS present,COUNT(r.student_id) AS saved FROM attendance_sessions a LEFT JOIN attendance_records r ON r.session_id=a.id WHERE a.term_id=? GROUP BY a.id ORDER BY a.opens_at DESC LIMIT 4", [$term['id']]);
    $sl = '';
    foreach ($sessions as $x) {
        $sl .= '<li><a href="/roll.php?id=' . (int) $x['id'] . '"><span class="todo-icon">' . icon('calendar', 18) . '</span><span class="todo-main"><b>' . esc($x['title']) . '</b><small>' . esc(thDate($x['opens_at'])) . '</small></span>' .
            ((int) $x['no_class'] ? badge('งดเรียน') : ((int) $x['saved'] ? badge('มา ' . (int) $x['present'], 'good') : badge('ยังไม่เช็กชื่อ', 'gold'))) . '</a></li>';
    }
    $name = $u ? 'สวัสดี คุณครู' . $u['first_name'] : 'ภาพรวมสำหรับครู';
    return documentTitle($name, termName($term) . ' · ' . thDate(date('Y-m-d'), false)) .
        $st .
        '<div class="grid two"><section class="card section"><h2>ต้องดำเนินการ</h2>' . $todoHtml . '</section>' .
        '<section class="card section"><h2>ทำรายการด่วน</h2><div class="tiles three">' . $tiles . '</div></section></div>' .
        '<div class="grid two"><section class="card section"><div class="section-head"><h2>วันเรียนล่าสุด</h2>' . linkTo('/attendance.php', 'ดูทั้งหมด', 'textlink') . '</div>' .
        ($sl !== '' ? '<ul class="todo">' . $sl . '</ul>' : emptyState('ยังไม่มีวันเรียน', 'calendar')) . '</section>' .
        '<section class="card section"><div class="section-head"><h2>ประกาศล่าสุด</h2>' . linkTo('/announcements.php', 'จัดการ', 'textlink') . '</div>' . newsList(3) . '</section></div>';
}
function studentDirectory(array $t): string
{
    $search = arg($_GET, 'q', 100, false);
    $filter = arg($_GET, 'filter', 20, false);
    $sql = "SELECT u.*,COALESCE(b.total,0) AS total FROM users u LEFT JOIN
        (SELECT student_id,SUM(delta_points) AS total FROM score_entries WHERE term_id=? GROUP BY student_id) b ON b.student_id=u.id WHERE role='student'";
    $args = [$t['id']];
    if ($search !== '') {
        $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $sql .=
            " AND (CONCAT_WS(' ',first_name,last_name) LIKE ? ESCAPE '!' OR student_code LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!' OR level_name LIKE ? ESCAPE '!')";
        array_push($args, $pattern, $pattern, $pattern, $pattern);
    }
    if ($filter === 'online') {
        $sql .= ' AND last_seen>=NOW()-INTERVAL 5 MINUTE';
    }
    if ($filter === 'low') {
        $sql .= ' AND COALESCE(b.total,0)<100';
    }
    $all = rows($sql . ' ORDER BY first_name,last_name,id', $args);
    $data = [];
    foreach ($all as $s) {
        $data[] = [
            linkTo('/student.php?id=' . $s['id'] . '&term=' . $t['id'], fullname($s), 'textlink'),
            esc($s['student_code']),
            esc($s['username']),
            esc($s['level_name']),
            esc($s['total']) . ' / 200',
            esc(thDate($s['last_seen'])),
        ];
    }
    $form =
        '<form method="get" class="filter">' .
        field('q', 'ค้นหาชื่อ รหัส ชื่อผู้ใช้ หรือระดับชั้น', 'search', $search, false) .
        selectBox(
            'filter',
            'แสดง',
            ['all' => 'ทุกคน', 'online' => 'ใช้งานใน 5 นาที', 'low' => 'คะแนนต่ำกว่า 100'],
            $filter,
        ) .
        btn('ค้นหา', 'secondary') .
        '</form>';
    return documentTitle('รายชื่อนักศึกษา', termName($t)) .
        $form .
        tableView(
            ['ชื่อ–นามสกุล', 'รหัสนักศึกษา', 'ชื่อผู้ใช้', 'ระดับชั้น', 'กพช.', 'ใช้งานล่าสุด'],
            $data,
            'ไม่พบนักศึกษาที่ตรงกับการค้นหา',
        );
}
function studentDetail(array $t): string
{
    $id = number($_GET['id'] ?? 0);
    $s = student($id);
    $tid = (int) $t['id'];
    $en = v32Enrollment($id, $tid);
    $joined = (string) val('SELECT created_at FROM users WHERE id=?', [$id]);
    $h = '<p class="crumb">' . linkTo('/students.php?term=' . $tid, '← รายชื่อนักศึกษา', 'textlink') . '</p>';
    $state = $en ? badge(v32EnrollmentText($en['status']), $en['status'] === 'approved' ? 'good' : ($en['status'] === 'pending' ? 'gold' : 'red')) : badge('ไม่ได้ลงทะเบียน');
    $fact = static fn(string $k, string $v): string => '<div><dt>' . esc($k) . '</dt><dd>' . ($v !== '' ? esc($v) : '<span class="muted">–</span>') . '</dd></div>';
    $h .= '<section class="card profile-head">' . avatar($s, 'lg') . '<div><h1>' . esc(fullname($s)) . '</h1><p class="muted">' . esc($s['level_name']) . '</p></div>' .
        '<div class="profile-state">' . $state . '<small>' . esc(termName($t)) . '</small></div>' .
        '<dl class="facts profile-facts">' . $fact('รหัสนักศึกษา', (string) $s['student_code']) . $fact('ชื่อบัญชี (ใช้เข้าสู่ระบบ)', (string) $s['username']) .
        $fact('เพศ', (string) $s['gender']) . $fact('ระดับชั้น', (string) ($en['level_name'] ?? $s['level_name'])) .
        $fact('โทรศัพท์', (string) $s['phone']) . $fact('LINE', (string) $s['line_contact']) .
        $fact('สมัครเมื่อ', thDate($joined, false)) . $fact('เข้าสู่ระบบล่าสุด', thDate($s['last_login'])) . '</dl>' .
        '<div class="actions profile-actions"><a class="button primary" href="#edit" data-open-details="edit">' . icon('user', 18) . '<span>แก้ข้อมูลนักศึกษา</span></a>' .
        '<a class="button secondary" href="/report.php?term=' . $tid . '&student=' . $id . '" target="_blank" rel="noopener">' . icon('print', 18) . '<span>พิมพ์รายงานรายบุคคล (PDF)</span></a></div></section>';
    if (($_SESSION['reset_display']['student'] ?? null) === $id) {
        $h .= '<div class="callout gold code-box" role="status">' . icon('key', 22) . '<div><b>รหัสตั้งรหัสผ่านใหม่ (ใช้ได้ครั้งเดียว ภายใน 15 นาที)</b><p class="code" id="reset-code">' .
            esc($_SESSION['reset_display']['code']) . '</p><p>ส่งให้นักศึกษาหลังยืนยันตัวตน แล้วให้นักศึกษาไปที่หน้า “ตั้งรหัสผ่านใหม่”</p></div></div>';
        unset($_SESSION['reset_display']);
    }
    $p = scoreTotal($id, $tid);
    $att = v32AttendanceStats($id, $tid);
    $h .= termFilter($t, ['id' => $id]);
    $h .= '<div class="grid three">' . meterCard('คะแนน กพช. (กิจกรรม)', $p, 200, (int) $t['min_points'], 'คะแนน', '', '#kpc') .
        classworkMeter($id, $t, '#classwork') .
        meterCard('วันมาเรียน', $att['present'], max((int) $t['expected_days'], $att['present']), (int) $t['min_attendance'], 'วัน', 'ลา ' . $att['leave'] . ' · ขาด ' . $att['absent'], '#attendance') . '</div>';
    $form = usernameField((string) $s['username']) .
        '<div class="pair">' . field('first_name', 'ชื่อจริง', 'text', (string) $s['first_name'], true, 'maxlength="100"') .
        field('last_name', 'นามสกุล', 'text', (string) $s['last_name'], true, 'maxlength="100"') . '</div>' .
        '<div class="pair">' . field('student_code', 'รหัสนักศึกษา', 'text', (string) $s['student_code'], true, 'maxlength="40"', 'ตามทะเบียนของศูนย์ ใช้เข้าสู่ระบบแทนชื่อผู้ใช้ได้') .
        selectBox('level_name', 'ระดับชั้น', array_combine(LEVELS, LEVELS), $s['level_name']) . '</div>' .
        '<div class="pair">' . selectBox('gender', 'เพศ', array_combine(GENDERS, GENDERS), $s['gender'] ?: 'ไม่ระบุ') .
        field('phone', 'เบอร์โทรศัพท์', 'tel', (string) $s['phone'], false, 'maxlength="35" inputmode="tel"') . '</div>' .
        field('line_contact', 'LINE ID', 'text', (string) $s['line_contact'], false, 'maxlength="100"');
    $tools = '<details class="card panel" id="edit"><summary>' . icon('user', 18) . ' แก้ข้อมูลนักศึกษา (ชื่อ รหัส ชื่อบัญชี เพศ ระดับ ช่องทางติดต่อ)</summary>' .
        formStart('/student.php?id=' . $id) . hidden('action', 'identity') . $form .
        '<p class="hint">ระดับชั้นของภาคเรียนที่ลงทะเบียนไว้แล้วไม่เปลี่ยนตาม ให้เปลี่ยนเฉพาะข้อมูลประจำตัว</p>' . btn('บันทึกข้อมูล') . '</form></details>';
    if ($en) {
        $tools .= '<details class="card panel"><summary>' . icon('check', 18) . ' สถานะในภาคเรียนนี้</summary>' . formStart('/student.php?id=' . $id) . hidden('action', 'term_status') . hidden('term_id', $tid) .
            selectBox('status', 'สถานะ', ['approved' => 'กำลังศึกษา (อนุมัติ)', 'declined' => 'ไม่อนุมัติ / ไม่เรียนต่อ', 'inactive' => 'ออกจากภาคเรียน'], $en['status']) .
            '<p class="hint">เปลี่ยนกลับเป็น “กำลังศึกษา” ได้ถ้าเคยไม่อนุมัติหรือนำออกโดยไม่ตั้งใจ</p>' . btn('บันทึกสถานะ', 'secondary') . '</form></details>';
    }
    $tools .= '<details class="card panel"><summary>' . icon('key', 18) . ' ออกรหัสตั้งรหัสผ่านใหม่</summary><p class="muted">ครูมองไม่เห็นรหัสผ่านของนักศึกษา ให้ตรวจสอบตัวตนก่อน แล้วส่งรหัสใช้ครั้งเดียว (15 นาที) ทางช่องทางส่วนตัว</p>' .
        formStart('/student.php?id=' . $id) . hidden('action', 'issue_code') . btn('ออกรหัสใช้ครั้งเดียว', 'secondary') . '</form></details>';
    $tools .= '<details class="card panel danger-zone"><summary>' . icon('reset', 18) . ' นำออกจากภาคเรียนปัจจุบัน</summary>' .
        '<p class="muted">นำรายชื่อนี้ออกจากภาคเรียนปัจจุบันเท่านั้น บัญชีและประวัติทุกภาคเรียนยังเก็บไว้</p>' .
        formStart('/student.php?id=' . $id) . hidden('action', 'delete_student') .
        field('teacher_password', 'รหัสผ่านครู', 'password', '', true, 'autocomplete="current-password"') .
        '<button type="submit" class="button danger" data-confirm-delete="นำนักศึกษาคนนี้ออกจากภาคเรียนปัจจุบันหรือไม่?">นำออกจากภาคเรียน</button></form></details>';
    return $h . '<div class="stack">' . $tools . '</div>' .
        block('กิจกรรมที่เข้าร่วม (กพช.)', activityHistoryForStudent($id, $t), 'activities') .
        block('คะแนนงานในห้อง (ไม่นับ กพช.)', classworkTable($id, $t), 'classwork') .
        block('ประวัติคะแนน กพช.', scoreHistory($id, $t, false), 'kpc') .
        block('ประวัติการมาเรียน', attendanceHistory($id, $t, false), 'attendance');
}
function teacherPage(string $r, array $u): array
{
    $t = selectedTerm();
    switch ($r) {
        case '/more.php': return ['เมนูทั้งหมด', v32MorePage('teacher')];
        case '/students.php': return ['รายชื่อนักศึกษา', v32TeacherDirectory($t)];
        case '/scores.php': return ['คะแนน กพช.', v32TeacherScores($t)];
        case '/roll.php': return ['เช็กชื่อ', v32TeacherRoll($t)];
        case '/terms.php': return ['ภาคเรียน', v32TeacherTerms($t)];
        case '/new-attendance.php': return ['สร้างวันเรียน', v32TeacherCreateSession($t)];
        case '/enrollments.php': return ['อนุมัติลงทะเบียน', v32TeacherEnrollments($t)];
        case '/behavior.php': return ['บันทึกพฤติกรรม', v32TeacherBehavior($t)];
        case '/reports.php': return ['รายงาน', v32TeacherReports($t)];
        case '/feature-settings.php': return ['ตั้งค่าฟีเจอร์', v32TeacherFeatures()];
        case '/account.php': return ['บัญชีครู', teacherAccountPage($u)];
        case '/gradebook.php': return ['คะแนนงานในห้อง', gradebookPage($t)];
        case '/activity-log.php': return ['บันทึกกิจกรรม', activityLogPage($t)];
        case '/dashboard.php':
            return ['ภาพรวมสำหรับครู', teacherDashboard($t, $u)];
        case '/student.php':
            return ['ข้อมูลนักศึกษา', studentDetail($t)];
        case '/new-item.php':
            return ['สร้างรายการเรียน', newWork()];
        case '/attendance.php':
            $list = rows("SELECT a.*,COALESCE(SUM(r.status='present'),0) AS present,COALESCE(SUM(r.status='leave'),0) AS on_leave,COALESCE(SUM(r.status='absent'),0) AS absent,COUNT(r.student_id) AS saved
                FROM attendance_sessions a LEFT JOIN attendance_records r ON r.session_id=a.id WHERE a.term_id=? GROUP BY a.id ORDER BY a.opens_at DESC", [$t['id']]);
            $h = '';
            foreach ($list as $x) {
                $h .= '<a class="work card" href="/roll.php?id=' . (int) $x['id'] . '"><span class="todo-icon">' . icon('calendar', 20) . '</span><span class="work-main"><b>' . esc($x['title']) . '</b><small>' .
                    esc(thDate($x['opens_at'])) . ' – ' . esc(substr((string) $x['closes_at'], 11, 5)) . ' · ' . esc($x['level_name'] ?? 'ทุกระดับ') . '</small></span><span class="work-state">' .
                    ((int) $x['no_class'] ? badge('งดเรียน') : ((int) $x['saved'] ? badge('มา ' . (int) $x['present'], 'good') . badge('ลา ' . (int) $x['on_leave'], 'blue') . badge('ขาด ' . (int) $x['absent'], 'red') : badge('ยังไม่เช็กชื่อ', 'gold'))) .
                    '</span></a>';
            }
            return [
                'เช็กชื่อ',
                documentTitle('เช็กชื่อ / วันเรียน', termName($t), iconLink('/new-attendance.php?term=' . (int) $t['id'], 'plus', 'สร้างวันเรียน', 'button primary')) .
                termFilter($t) . ($h !== '' ? '<div class="work-list">' . $h . '</div>' : emptyState('ยังไม่มีวันเรียนในภาคเรียนนี้ กด “สร้างวันเรียน” เพื่อเริ่มเช็กชื่อ', 'calendar')),
            ];
        case '/announcements.php':
            $f =
                formStart('/announcements.php', true) .
                field('title', 'หัวข้อ', 'text', '', true, 'maxlength="200"') .
                area('body', 'รายละเอียด') .
                '<div class="pair">' . field('publish_on', 'วันที่เผยแพร่', 'date', date('Y-m-d'), true, '', 'ตั้งวันล่วงหน้าได้ ประกาศจะแสดงเมื่อถึงวัน') .
                field('link_url', 'ลิงก์ หรือ Facebook Post URL', 'url', '', false, 'maxlength="1500" placeholder="https://"') . '</div>' .
                uploadBox('image', 'ภาพประกาศ', true) .
                checkbox('pinned', 'ปักหมุดไว้ด้านบน (ประกาศสำคัญ)') .
                btn('เผยแพร่ประกาศ') .
                '</form>';
            $count = (int) val('SELECT COUNT(*) FROM announcements');
            return [
                'ประกาศ',
                documentTitle('ประกาศ', 'ประกาศใหม่จะแสดงในภาคเรียนปัจจุบัน (' . termName(currentTerm()) . ')') .
                '<details class="card panel" id="new"' . ($count === 0 ? ' open' : '') . '><summary>' . icon('plus', 18) . ' เขียนประกาศใหม่</summary>' . $f . '</details>' .
                newsList(0, true),
            ];
        case '/requests.php':
            $a = [];
            foreach (
                rows('SELECT r.*,u.first_name,u.last_name,u.student_code,u.avatar_id FROM reset_requests r JOIN users u ON u.id=r.student_id WHERE r.used_at IS NULL ORDER BY r.requested_at DESC')
                as $x
            ) {
                $active = $x['code_hash'] && $x['expires_at'] && strtotime((string) $x['expires_at']) > time();
                $a[] = [
                    '<a class="person-link" href="/student.php?id=' . (int) $x['student_id'] . '">' . avatar($x, 'sm') . '<span><b>' . esc(fullname($x)) . '</b><small>' . esc($x['student_code']) . '</small></span></a>',
                    esc(thDate($x['requested_at'])),
                    $active ? badge('ออกรหัสแล้ว · หมดอายุ ' . substr((string) $x['expires_at'], 11, 5), 'blue') : ($x['code_hash'] ? badge('รหัสหมดอายุ', 'red') : badge('รอออกรหัส', 'gold')),
                    formStart('/student.php?id=' . (int) $x['student_id']) . hidden('action', 'issue_code') . btn($x['code_hash'] ? 'ออกรหัสใหม่' : 'ออกรหัส', 'secondary small') . '</form>',
                ];
            }
            return [
                'คำขอรหัสผ่าน',
                documentTitle('คำขอตั้งรหัสผ่านใหม่', 'ตรวจสอบตัวตนนักศึกษาก่อน แล้วกด “ออกรหัส” เพื่อรับรหัสใช้ครั้งเดียว') .
                '<section class="card section">' . tableView(['นักศึกษา', 'วันที่ขอ', 'สถานะ', ''], $a, 'ไม่มีคำขอค้าง') . '</section>',
            ];
        case '/system-reset.php':
            return ['รีเซ็ตข้อมูลระบบ', resetPageV31($u)];
        case '/audit.php':
            $before = isset($_GET['before']) ? number($_GET['before']) : PHP_INT_MAX;
            $logs = rows(
                'SELECT a.*,u.first_name,u.last_name,s.student_code FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id LEFT JOIN users s ON s.id=a.student_id WHERE a.id<? ORDER BY a.id DESC LIMIT 101',
                [$before],
            );
            $more = count($logs) > 100;
            if ($more) {
                array_pop($logs);
            }
            $a = [];
            foreach ($logs as $l) {
                $a[] = [
                    esc(thDate($l['created_at'])),
                    esc(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: '—'),
                    '<b>' . esc(auditLabel((string) $l['event_name'])) . '</b><small class="block muted">' . esc($l['event_name']) . '</small>',
                    esc($l['student_code'] ?? '—'),
                    '<code class="small">' . esc($l['details']) . '</code>',
                ];
            }
            return [
                'ประวัติการจัดการ',
                documentTitle('ประวัติการจัดการ', 'บันทึกทุกการเปลี่ยนแปลงในระบบ ล่าสุดอยู่บนสุด') .
                '<section class="card section">' . tableView(['เวลา', 'ผู้ดำเนินการ', 'รายการ', 'รหัสนักศึกษา', 'รายละเอียด'], $a) .
                ($more ? '<div class="actions">' . linkTo('/audit.php?before=' . end($logs)['id'], 'ดูรายการก่อนหน้า', 'button secondary') . '</div>' : '') . '</section>',
            ];
    }
    fail(404);
}
function auditLabel(string $event): string
{
    return [
        'student.register' => 'นักศึกษาสมัครสมาชิก', 'password.reset' => 'ตั้งรหัสผ่านใหม่', 'score.entry' => 'บันทึกคะแนน กพช.',
        'work.submit' => 'ส่งงาน', 'work.review' => 'ตรวจงาน', 'work.create' => 'สร้างงาน/กิจกรรม', 'work.delete' => 'ลบงาน/กิจกรรม',
        'term.save' => 'บันทึกภาคเรียน', 'term.criteria' => 'แก้เกณฑ์ภาคเรียน', 'term.delete' => 'ลบภาคเรียน',
        'attendance.create' => 'สร้างวันเรียน', 'attendance.correct' => 'เช็กชื่อ', 'attendance.session.update' => 'แก้วันเรียน', 'attendance.no_class' => 'ตั้งวันงดเรียน',
        'student.update' => 'แก้ข้อมูลนักศึกษา', 'student.term.remove' => 'นำออกจากภาคเรียน', 'password.issue_code' => 'ออกรหัสรีเซ็ต',
        'announcement.publish' => 'เผยแพร่ประกาศ', 'announcement.delete' => 'ลบประกาศ', 'enrollment.request' => 'ขอลงทะเบียน',
        'enrollment.review' => 'อนุมัติ/ปฏิเสธลงทะเบียน', 'enrollment.inactive' => 'นำออกจากภาคเรียน', 'enrollment.status' => 'เปลี่ยนสถานะลงทะเบียน',
        'behavior.save' => 'บันทึกพฤติกรรม', 'behavior.delete' => 'ลบบันทึกพฤติกรรม', 'feature.settings' => 'ตั้งค่าฟีเจอร์',
        'profile.update' => 'แก้ไขโปรไฟล์', 'profile.password' => 'เปลี่ยนรหัสผ่าน', 'profile.avatar' => 'เปลี่ยนรูปโปรไฟล์',
        'activity.participant' => 'บันทึกผู้เข้าร่วมกิจกรรม', 'system.reset' => 'รีเซ็ตระบบ', 'student.delete' => 'ลบบัญชีนักศึกษา',
        'work.grade' => 'กรอกคะแนนงาน', 'teacher.profile' => 'ครูแก้ชื่อที่แสดง', 'teacher.username' => 'ครูเปลี่ยนชื่อผู้ใช้',
        'teacher.password' => 'ครูเปลี่ยนรหัสผ่าน', 'teacher.wallpaper' => 'ครูเปลี่ยนวอลเปเปอร์',
    ][$event] ?? $event;
}
