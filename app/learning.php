<?php
declare(strict_types=1);

function workList(string $kind, array $u, array $term): string
{
    $teacher = $u['role'] === 'teacher';
    $title = $kind === 'activity' ? 'กิจกรรม' : ($teacher ? 'งาน / ใบงาน' : 'งานที่ได้รับมอบหมาย');
    $actions = $teacher ? ($kind === 'activity' ? iconLink('/activity-log.php?term=' . (int) $term['id'], 'history', 'บันทึกกิจกรรม', 'button secondary') : iconLink('/gradebook.php?term=' . (int) $term['id'], 'grade', 'คะแนนงานในห้อง', 'button secondary')) .
        iconLink('/new-item.php?kind=' . $kind, 'plus', $kind === 'activity' ? 'เพิ่มกิจกรรม' : 'เพิ่มงาน / ใบงาน', 'button primary') : '';
    $sub = termName($term) . ($kind === 'activity' ? ' · กิจกรรมให้คะแนน กพช.' : ' · คะแนนงานในห้อง แยกจาก กพช.');
    $body = documentTitle($title, $sub, $actions) . termFilter($term);
    if (!$teacher && !v32Enrollment((int) $u['id'], (int) $term['id'])) {
        return $body . emptyState('ไม่มีข้อมูลของคุณในภาคเรียนนี้', 'info');
    }
    if ($teacher) {
        $items = rows(
            "SELECT w.*,COUNT(s.id) AS sent,COALESCE(SUM(s.status='submitted'),0) AS waiting,COALESCE(SUM(s.status='passed'),0) AS passed
             FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id WHERE w.kind=? AND w.term_id=? GROUP BY w.id ORDER BY w.due_at DESC,w.id DESC",
            [$kind, $term['id']],
        );
        $enrolled = (int) val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='approved'", [$term['id']]);
        $table = [];
        foreach ($items as $w) {
            $table[] = [
                '<a class="textlink strong" href="/item.php?id=' . (int) $w['id'] . '">' . esc($w['title']) . '</a>' . ($w['kind'] === 'activity' && $w['place'] !== '' ? '<small class="block">' . icon('pin', 14) . ' ' . esc($w['place']) . '</small>' : ''),
                esc(thDate($w['due_at'])) . '<br>' . dueText($w['due_at']),
                (int) $w['points'] . ($kind === 'activity' ? ' กพช.' : ' คะแนน'),
                '<b>' . (int) $w['sent'] . '</b> / ' . $enrolled . ((int) $w['waiting'] > 0 ? ' ' . badge('รอตรวจ ' . (int) $w['waiting'], 'gold') : ''),
                linkTo('/item.php?id=' . (int) $w['id'] . '#roster', (int) $w['waiting'] > 0 ? 'ตรวจงาน' : 'เปิด', 'button secondary small'),
            ];
        }
        return $body . '<section class="card section">' . tableView(['ชื่อรายการ', 'กำหนดส่ง', $kind === 'activity' ? 'กพช.' : 'คะแนนเต็ม', $kind === 'activity' ? 'ส่ง / เข้าร่วม' : 'ส่ง / ได้คะแนน', ''], $table, 'ยังไม่มีรายการในภาคเรียนนี้') . '</section>';
    }
    $items = rows('SELECT w.*,s.status,s.submitted_at,s.credited FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=? WHERE w.kind=? AND w.term_id=? ORDER BY (s.id IS NULL AND w.due_at>=NOW()) DESC,w.due_at ASC,w.id DESC', [$u['id'], $kind, $term['id']]);
    if (!$items) {
        return $body . emptyState('ยังไม่มีรายการในภาคเรียนนี้', $kind === 'activity' ? 'spark' : 'file');
    }
    $h = '<div class="work-list">';
    foreach ($items as $w) {
        $open = !$w['status'] && strtotime((string) $w['due_at']) >= time();
        $h .= '<a class="work card' . ($open ? ' open' : '') . '" href="/item.php?id=' . (int) $w['id'] . '"><span class="todo-icon">' . icon($kind === 'activity' ? 'spark' : 'file', 20) . '</span>' .
            '<span class="work-main"><b>' . esc($w['title']) . '</b><small>' . icon('clock', 14) . ' กำหนด ' . esc(thDate($w['due_at'])) . ' · ' .
            ($kind === 'activity' ? 'กพช. ' . (int) $w['points'] . ' คะแนน' : ($w['status'] === 'passed' ? 'ได้ ' . (int) $w['credited'] . ' / ' . (int) $w['points'] : 'คะแนนเต็ม ' . (int) $w['points'])) .
            ($kind === 'activity' && $w['place'] !== '' ? ' · ' . esc($w['place']) : '') . '</small></span>' .
            '<span class="work-state">' . statusBadge($w['status']) . ($open ? dueText($w['due_at']) : '') . '</span></a>';
    }
    return $body . $h . '</div>';
}
function newWork(): string
{
    $k = ($_GET['kind'] ?? 'activity') === 'assignment' ? 'assignment' : 'activity';
    $activity = $k === 'activity';
    $GLOBALS['sgr_nav'] = $activity ? '/activities.php' : '/assignments.php';
    $due = date('Y-m-d', strtotime('+7 days')) . 'T16:00';
    $form =
        formStart('', true) . hidden('kind', $k) .
        '<div class="pair">' . selectBox('term_id', 'ภาคเรียน', termOptions(), currentTerm()['id']) .
        ($activity
            ? field('points', 'คะแนน กพช. ที่ได้เมื่อเข้าร่วม', 'number', 0, true, 'min="0" max="200"', 'กพช. คือคะแนนกิจกรรม / คะแนนเสริม ใส่ 0 ถ้าไม่มี')
            : field('points', 'คะแนนเต็มของงาน', 'number', 10, true, 'min="0" max="200"', 'คะแนนงานในห้อง (ใบงาน แบบฝึกหัด สอบย่อย) ไม่นับรวมใน กพช.')) . '</div>' .
        field('title', $activity ? 'ชื่อกิจกรรม' : 'ชื่องาน / ใบงาน', 'text', '', true, 'maxlength="200"') .
        area('details', 'รายละเอียด / คำสั่ง', true, '', 'อธิบายสิ่งที่นักศึกษาต้องทำ และสิ่งที่ต้องส่ง');
    if ($activity) {
        $form .= '<div class="pair">' . field('starts_at', 'วันเวลาเริ่มกิจกรรม', 'datetime-local', date('Y-m-d') . 'T09:00') . field('place', 'สถานที่', 'text', '', true, 'maxlength="180"') . '</div>';
    }
    $form .= field('due_at', $activity ? 'ปิดรับหลักฐาน' : 'กำหนดส่ง', 'datetime-local', $due) .
        uploadBox('resource', 'เอกสารหรือภาพประกอบ');
    if ($activity) {
        $form .= checkbox('needs_file', 'นักศึกษาต้องแนบรูป / หลักฐาน', true);
    } else {
        $form .= '<details class="panel inline"><summary>เพิ่มเฉลย (ไม่บังคับ)</summary>' . area('solution', 'เฉลย', false) .
            selectBox('solution_mode', 'การแสดงเฉลย', ['hidden' => 'ซ่อนเฉลย', 'now' => 'แสดงทันที', 'after_due' => 'แสดงหลังวันกำหนดส่ง']) . '</details>';
    }
    return '<p class="crumb">' . linkTo($activity ? '/activities.php' : '/assignments.php', '← ' . ($activity ? 'กิจกรรมทั้งหมด' : 'งานทั้งหมด'), 'textlink') . '</p>' .
        documentTitle($activity ? 'เพิ่มกิจกรรม' : 'เพิ่มงาน / ใบงาน', 'นักศึกษาที่อนุมัติในภาคเรียนที่เลือกจะเห็นทันทีหลังเผยแพร่') .
        '<section class="card section narrow">' . $form . btn('เผยแพร่', 'primary block large') . '</form></section>';
}
function scoreHistory(int $id, array $term, bool $meter = true): string
{
    $entries = rows(
        'SELECT e.*,u.first_name,u.last_name FROM score_entries e JOIN users u ON u.id=e.teacher_id WHERE student_id=? AND term_id=? ORDER BY e.id DESC',
        [$id, $term['id']],
    );
    $data = [];
    foreach ($entries as $e) {
        $d = (int) $e['delta_points'];
        $data[] = [
            esc(thDate($e['created_at'])),
            '<span class="delta ' . ($d < 0 ? 'neg' : 'pos') . '">' . ($d > 0 ? '+' : '') . $d . '</span>',
            esc($e['reason']),
            esc(fullname($e)),
        ];
    }
    $goal = isset($term['min_points']) ? (int) $term['min_points'] : (int) (val('SELECT min_points FROM terms WHERE id=?', [$term['id']]) ?: 100);
    return ($meter ? progress(scoreTotal($id, (int) $term['id']), $goal) : '') .
        tableView(['วันที่', 'คะแนน', 'เหตุผล / แหล่งที่มา', 'ผู้บันทึก'], $data, 'ยังไม่มีรายการคะแนนในภาคเรียนนี้');
}
function attendanceHistory(int $id, array $term, bool $sign): string
{
    $a = rows(
        'SELECT a.*,r.status,r.signed_at,r.updated_at FROM attendance_sessions a LEFT JOIN attendance_records r ON r.session_id=a.id AND r.student_id=? WHERE a.term_id=? ORDER BY opens_at DESC',
        [$id, $term['id']],
    );
    $data = [];
    foreach ($a as $x) {
        $state = (int) $x['no_class'] === 1 ? badge('งดเรียน') : ($x['status'] ? statusBadge($x['status']) : badge('ยังไม่บันทึก'));
        $data[] = [
            '<b>' . esc($x['title']) . '</b>',
            esc(thDate($x['opens_at'])) . ' – ' . esc(substr((string) $x['closes_at'], 11, 5)),
            $state,
            esc(thDate($x['updated_at'] ?? $x['signed_at'])),
        ];
    }
    if ($sign) {
        $data = array_map(static fn(array $line): array => array_slice($line, 0, 3), $data);
        return tableView(['วันเรียน', 'วันเวลา', 'สถานะ'], $data, 'ยังไม่มีวันเรียนในภาคเรียนนี้');
    }
    return tableView(['วันเรียน', 'วันเวลา', 'สถานะ', 'บันทึกเมื่อ'], $data, 'ยังไม่มีวันเรียนในภาคเรียนนี้');
}
function itemPage(array $u): array
{
    $id = number($_GET['id'] ?? 0);
    $w =
        row(
            'SELECT w.*,t.school_year,t.semester,NOW()>w.due_at AS late FROM work_items w JOIN terms t ON t.id=w.term_id WHERE w.id=?',
            [$id],
        ) ?? fail(404);
    $teacher = $u['role'] === 'teacher';
    if (!$teacher && !v32Enrollment((int) $u['id'], (int) $w['term_id'])) {
        fail(403, 'ไม่มีข้อมูลของคุณในภาคเรียนนี้');
    }
    $activity = $w['kind'] === 'activity';
    $GLOBALS['sgr_nav'] = $activity ? '/activities.php' : '/assignments.php';
    $back = '<p class="crumb">' . linkTo(($activity ? '/activities.php' : '/assignments.php') . '?term=' . (int) $w['term_id'], '← ' . ($activity ? 'กิจกรรมทั้งหมด' : 'งานทั้งหมด'), 'textlink') . '</p>';
    $facts = '<dl class="facts"><div><dt>' . ($activity ? 'ปิดรับหลักฐาน' : 'กำหนดส่ง') . '</dt><dd>' . esc(thDate($w['due_at'])) . ' ' . dueText($w['due_at']) . '</dd></div>' .
        '<div><dt>' . ($activity ? 'คะแนน กพช.' : 'คะแนนเต็ม (คะแนนงาน ไม่นับ กพช.)') . '</dt><dd>' . (int) $w['points'] . ' คะแนน</dd></div>';
    if ($activity) {
        $facts .= '<div><dt>เริ่มกิจกรรม</dt><dd>' . esc(thDate($w['starts_at'])) . '</dd></div><div><dt>สถานที่</dt><dd>' . esc($w['place']) . '</dd></div>' .
            '<div><dt>หลักฐาน</dt><dd>' . ($w['needs_file'] ? 'ต้องแนบไฟล์' : 'ยืนยันการเข้าร่วมได้') . '</dd></div>';
    }
    if ($w['resource_id']) {
        $facts .= '<div><dt>ไฟล์ประกอบ</dt><dd>' . fileLink($w['resource_id']) . '</dd></div>';
    }
    $facts .= '</dl>';
    $body = $back . documentTitle($w['title'], ($activity ? 'กิจกรรม' : 'งานที่ได้รับมอบหมาย') . ' · ' . termName($w)) .
        '<section class="card section">' . $facts . '<p class="pre intro">' . esc($w['details']) . '</p></section>';
    if (!$activity && $w['solution'] !== '' && ($teacher || $w['solution_mode'] === 'now' || ($w['solution_mode'] === 'after_due' && $w['late']))) {
        $body .= block('เฉลย', ($teacher ? '<p class="hint">นักศึกษาเห็น: ' . esc(['hidden' => 'ซ่อนไว้', 'now' => 'ทันที', 'after_due' => 'หลังกำหนดส่ง'][$w['solution_mode']] ?? '') . '</p>' : '') . '<p class="pre">' . esc($w['solution']) . '</p>');
    }
    if ($teacher) {
        $body .= $activity ? v32ActivityRosterForm($w) : gradeSheetForm($w);
        $filter = arg($_GET, 'status', 20, false);
        $options = [
            'all' => 'ทั้งหมด',
            'missing' => 'ยังไม่ส่ง',
            'sent' => 'ส่งแล้วทั้งหมด',
            'submitted' => 'รอตรวจ',
            'acknowledged' => 'รับทราบแล้ว',
            'passed' => 'ผ่าน',
            'revise' => 'ต้องแก้ไข',
        ];
        if (!isset($options[$filter])) {
            $filter = 'all';
        }
        $all = rows(
            "SELECT u.id,u.first_name,u.last_name,u.student_code,u.avatar_id,s.status,s.note,s.file_id,s.submitted_at,s.review_note,s.credited
            FROM term_enrollments e JOIN users u ON u.id=e.student_id
            LEFT JOIN submissions s ON s.student_id=u.id AND s.item_id=?
            WHERE e.term_id=? AND e.status='approved' AND u.role='student' ORDER BY (s.status='submitted') DESC,u.first_name,u.last_name",
            [$id, (int) $w['term_id']],
        );
        $tally = ['sent' => 0, 'waiting' => 0];
        foreach ($all as $s) {
            $tally['sent'] += $s['status'] !== null ? 1 : 0;
            $tally['waiting'] += $s['status'] === 'submitted' ? 1 : 0;
        }
        $tb = [];
        foreach ($all as $s) {
            if (
                ($filter === 'missing' && $s['status'] !== null) ||
                ($filter === 'sent' && $s['status'] === null) ||
                (!in_array($filter, ['all', 'missing', 'sent'], true) && $s['status'] !== $filter)
            ) {
                continue;
            }
            $review = '<span class="muted">—</span>';
            if ($s['status']) {
                $review = '<details class="review"' . ($s['status'] === 'submitted' ? ' open' : '') . '><summary>' . ($s['status'] === 'submitted' ? 'ตรวจผลงาน' : 'แก้ผลตรวจ') . '</summary>' .
                    formStart('/item.php?id=' . $id) . hidden('student_id', $s['id']) .
                    '<div class="seg choice">' .
                    '<label><input type="radio" name="status" value="passed"' . ($s['status'] === 'passed' ? ' checked' : '') . ' required><span>' . ($activity ? 'ผ่าน (+' . (int) $w['points'] . ' กพช.)' : 'ผ่าน / ให้คะแนน') . '</span></label>' .
                    '<label><input type="radio" name="status" value="revise"' . ($s['status'] === 'revise' ? ' checked' : '') . '><span>ให้แก้ไข</span></label>' .
                    '<label><input type="radio" name="status" value="acknowledged"' . ($s['status'] === 'acknowledged' ? ' checked' : '') . '><span>รับทราบ</span></label></div>' .
                    assignmentScoreInput($w, $s) .
                    '<label class="field"><span class="label">ข้อเสนอแนะถึงนักศึกษา <em>(ไม่บังคับ)</em></span><textarea name="review_note" rows="2" maxlength="2000">' . esc($s['review_note']) . '</textarea></label>' .
                    btn('บันทึกผลตรวจ', 'primary small') . '</form></details>';
            }
            $tb[] = [
                '<a class="person-link" href="/student.php?id=' . (int) $s['id'] . '">' . avatar($s, 'sm') . '<span><b>' . esc(fullname($s)) . '</b><small>' . esc($s['student_code']) . '</small></span></a>',
                statusBadge($s['status']) . (!$activity && $s['status'] === 'passed' && (int) $w['points'] > 0 ? ' <b class="score-chip">' . (int) $s['credited'] . ' / ' . (int) $w['points'] . '</b>' : ''),
                esc(thDate($s['submitted_at'])),
                ($s['file_id'] ? fileLink($s['file_id'], 'ดูไฟล์ที่ส่ง') : '') . ($s['note'] !== null && $s['note'] !== '' ? '<div class="pre small">' . esc($s['note']) . '</div>' : ($s['file_id'] ? '' : '—')),
                $review,
            ];
        }
        $form = '<form class="filter inline" method="get">' . hidden('id', $id) . selectBox('status', 'แสดง', $options, $filter) . '<noscript>' . btn('แสดง', 'secondary') . '</noscript></form>';
        $body .= '<section class="card section" id="roster"><div class="section-head"><h2>ผลงานที่ส่ง · ' . $tally['sent'] . ' / ' . count($all) . ' คน' .
            ($tally['waiting'] ? ' ' . badge('รอตรวจ ' . $tally['waiting'], 'gold') : '') . '</h2>' . $form . '</div>' .
            tableView(['นักศึกษา', 'สถานะ', 'ส่งเมื่อ', 'ผลงาน', 'ผลตรวจ'], $tb, 'ไม่มีรายการตามตัวกรองนี้') . '</section>';
        $body .= '<details class="card panel danger-zone"><summary>' . icon('reset', 18) . ' ลบ' . ($activity ? 'กิจกรรม' : 'งาน') . 'นี้</summary><p class="muted">ลบรายการ ผลงานที่ส่ง และคะแนนที่ได้จากรายการนี้อย่างถาวร</p>' .
            formStart('/item.php?id=' . $id) . hidden('action', 'delete_item') . hidden('confirmation', 'DELETE') .
            '<button type="submit" class="button danger" data-confirm-delete="ลบรายการนี้ ผลงานที่ส่ง และคะแนนที่ได้จากรายการนี้อย่างถาวรหรือไม่?">ยืนยันลบรายการ</button></form></details>';
    } else {
        $s = row('SELECT * FROM submissions WHERE item_id=? AND student_id=?', [$id, $u['id']]);
        $h = '<div class="status-line">' . statusBadge($s['status'] ?? null) .
            (!$activity && ($s['status'] ?? '') === 'passed' && (int) $w['points'] > 0 ? '<b class="score-chip">ได้ ' . (int) $s['credited'] . ' / ' . (int) $w['points'] . ' คะแนน</b>' : '') .
            ($s ? '<small>' . ($s['note'] === TEACHER_MARK_NOTE ? 'ครูบันทึกคะแนนเมื่อ ' . esc(thDate($s['reviewed_at'])) : 'ส่งเมื่อ ' . esc(thDate($s['submitted_at']))) . '</small>' : '') . '</div>';
        if ($s) {
            $h .= ($s['note'] !== '' ? '<p class="pre">' . esc($s['note']) . '</p>' : '') .
                ($s['file_id'] ? '<p>' . fileLink($s['file_id'], 'ไฟล์ที่ส่ง') . '</p>' : '') .
                ($s['review_note'] ? '<div class="callout' . ($s['status'] === 'revise' ? ' red' : '') . '">' . icon('info', 20) . '<div><b>ความเห็นจากครู</b><p class="pre">' . esc($s['review_note']) . '</p></div></div>' : '');
        }
        $approved = v32Approved((int) $u['id'], (int) $w['term_id']);
        $canSend = (!$s && !$w['late']) || (($s['status'] ?? '') === 'revise');
        if ($approved && v32Feature('assignment_upload') && $canSend) {
            $h .= formStart('/item.php?id=' . $id, true) .
                ($s ? '<p class="hint">ครูให้แก้ไข ส่งใหม่ได้ด้านล่าง</p>' : '') .
                uploadBox('evidence', $activity ? 'รูปหรือหลักฐานการเข้าร่วม' : 'ไฟล์งาน', false, (bool) $w['needs_file']) .
                area('note', 'ข้อความถึงครู', false, '', '', 4000) .
                btn($activity && !$w['needs_file'] ? 'ยืนยันการเข้าร่วม' : ($s ? 'ส่งงานที่แก้ไขแล้ว' : 'ส่งผลงาน'), 'primary block large') .
                '</form>';
        } elseif (!$s && $w['late']) {
            $h .= '<div class="callout red">' . icon('clock', 20) . '<div><b>ปิดรับผลงานแล้ว</b><p>เลยกำหนดส่ง หากมีเหตุจำเป็นโปรดติดต่อครูผู้ดูแล</p></div></div>';
        } elseif (!$approved) {
            $h .= '<div class="callout gold">' . icon('clock', 20) . '<div><b>ยังส่งงานไม่ได้</b><p>รอครูอนุมัติการลงทะเบียนภาคเรียนนี้ก่อน</p></div></div>';
        } elseif (!v32Feature('assignment_upload') && $canSend) {
            $h .= '<div class="callout">' . icon('info', 20) . '<div><b>ส่งงานกับครูโดยตรง</b><p>สถานศึกษาปิดระบบส่งงานผ่านเว็บชั่วคราว</p></div></div>';
        }
        $body .= block('การส่งของฉัน', $h);
    }
    return [$w['title'], $body];
}
function workForStudent(
    int $id,
    int $term,
    string $kind,
    bool $pendingOnly = false,
    bool $latest = false,
): string {
    $query =
        'SELECT w.id,w.title,w.due_at,s.status FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=?
        WHERE w.term_id=? AND w.kind=?' .
        ($pendingOnly ? ' AND s.id IS NULL' : '') .
        ($latest
            ? ' ORDER BY w.created_at DESC,w.id DESC LIMIT 5'
            : ' ORDER BY w.due_at,w.id' . ($pendingOnly ? ' LIMIT 6' : ''));
    $a = [];
    foreach (rows($query, [$id, $term, $kind]) as $r) {
        $a[] = [
            linkTo('/item.php?id=' . $r['id'], $r['title'], 'textlink'),
            esc(thDate($r['due_at'])),
            statusBadge($r['status']),
        ];
    }
    return tableView(
        ['ชื่อรายการ', 'กำหนดส่ง', 'สถานะ'],
        $a,
        $pendingOnly ? 'ส่งงานครบแล้ว หรือยังไม่มีงานมอบหมาย' : 'ยังไม่มีรายการ',
    );
}
function noticeList(int $id, int $limit = 0, ?int $termId = null): string
{
    $ns = rows(
        'SELECT * FROM notices WHERE student_id=? AND term_id=? ORDER BY id DESC' . ($limit ? ' LIMIT ' . (int) $limit : ''),
        [$id, $termId ?? (int) selectedTerm()['id']],
    );
    if (!$ns) {
        return emptyState('ยังไม่มีการแจ้งเตือน', 'bell');
    }
    $h = '<ul class="notices">';
    foreach ($ns as $n) {
        $target = (string) $n['target'];
        if (!preg_match('~^/[A-Za-z0-9._/-]*(\?[A-Za-z0-9=&%._-]*)?(#[A-Za-z0-9_-]*)?$~D', $target)) {
            $target = '/notifications.php';
        }
        $h .= '<li' . (!$n['seen_at'] ? ' class="unread"' : '') . '><a href="' . esc($target) . '"><span class="dot" aria-hidden="true"></span><span><b>' . esc($n['body']) . '</b><small>' .
            esc(thDate($n['created_at'])) . (!$n['seen_at'] ? ' · ใหม่' : '') . '</small></span>' . icon('chevron', 16) . '</a></li>';
    }
    return $h . '</ul>';
}
require_once __DIR__ . '/student-ui.php';

function studentPage(string $r, array $u): array
{
    $id = (int) $u['id'];
    $t = $r === '/dashboard.php' || $r === '/renew.php' ? currentTerm() : v32StudentSelectedTerm($id);
    switch ($r) {
        case '/more.php':
            return ['เมนูทั้งหมด', v32MorePage('student')];
        case '/dashboard.php':
            return ['หน้าหลัก', studentHomeV32($u, $t)];
        case '/profile.php':
            return ['บัญชีของฉัน', v32StudentProfile($u)];
        case '/renew.php':
            $prompt = v32CurrentPrompt($u);
            if ($prompt === '') {
                // V3.2 rendered an empty page once the enrollment was approved.
                $en = v32Enrollment($id, (int) $t['id']);
                $prompt = '<div class="callout good">' . icon('check', 22) . '<div><b>ลงทะเบียนเรียบร้อยแล้ว</b><p>' . esc(termName($t)) . ' · ' . esc($en['level_name'] ?? '') .
                    ' · ครูอนุมัติแล้ว ไม่ต้องดำเนินการเพิ่ม</p></div></div><div class="actions">' . linkTo('/dashboard.php', 'กลับหน้าหลัก', 'button primary') . '</div>';
            }
            return ['ลงทะเบียนภาคเรียน', documentTitle('ลงทะเบียน / ยืนยันเรียนต่อ', termName($t)) . $prompt];
        case '/history.php':
            return ['ประวัติการศึกษา', v32StudentHistory($u)];
        case '/behavior.php':
            return ['บันทึกพฤติกรรม', v32StudentBehavior($u, $t)];
        case '/scores.php':
            return ['คะแนนของฉัน', documentTitle('คะแนนของฉัน', 'กพช. คือคะแนนกิจกรรม / คะแนนเสริม · คะแนนงานในห้อง (ใบงาน แบบฝึกหัด) แยกต่างหาก ไม่นับรวมใน กพช.') . termFilter($t) .
                '<div class="grid two">' . meterCard('คะแนน กพช. (กิจกรรม)', scoreTotal($id, (int) $t['id']), 200, (int) $t['min_points'], 'คะแนน') . classworkMeter($id, $t) . '</div>' .
                block('ประวัติคะแนน กพช.', scoreHistory($id, $t, false), 'kpc') . block('คะแนนงานในห้อง', classworkTable($id, $t), 'classwork')];
        case '/attendance.php':
            $att = v32AttendanceStats($id, (int) $t['id']);
            return [
                'การมาเรียน',
                documentTitle('การมาเรียนของฉัน', 'ครูเป็นผู้บันทึกสถานะทุกครั้ง') . termFilter($t) .
                '<div class="stats"><div class="stat good"><span>มา</span><strong>' . $att['present'] . '</strong><small>วัน · เกณฑ์ ' . (int) $t['min_attendance'] . '</small></div>' .
                '<div class="stat blue"><span>ลา</span><strong>' . $att['leave'] . '</strong><small>วัน</small></div><div class="stat red"><span>ขาด</span><strong>' . $att['absent'] . '</strong><small>วัน</small></div></div>' .
                '<section class="card section">' . attendanceHistory($id, $t, false) . '</section>',
            ];
        case '/notifications.php':
            $unread = (int) val('SELECT COUNT(*) FROM notices WHERE student_id=? AND term_id=? AND seen_at IS NULL', [$id, $t['id']]);
            return [
                'แจ้งเตือน',
                documentTitle('การแจ้งเตือน', termName($t) . ' · ยังไม่อ่าน ' . $unread . ' รายการ',
                    $unread ? formStart('/notifications.php') . hidden('term_id', $t['id']) . btn('อ่านทั้งหมดแล้ว', 'secondary') . '</form>' : '') .
                termFilter($t) . '<section class="card section">' . noticeList($id, 0, (int) $t['id']) . '</section>',
            ];
        case '/announcements.php':
            return ['ประกาศ', documentTitle('ประกาศจากศูนย์', termName($t)) . newsList(0, false, (int) $t['id'])];
    }
    fail(404);
}
