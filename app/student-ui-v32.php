<?php
declare(strict_types=1);
// V3.3 student pages: public welcome, home dashboard and about page.
function studentWelcomeV32(): string
{
    // V3.3.3: redesigned public main page (student portal). Same links and data as before, new layout.
    $GLOBALS['sgr_landing'] = true;
    $contact = (string) (cfg()['app']['contact'] ?? '');
    $term = selectedTerm();
    $features = [
        ['file', 'blue', 'งานและกิจกรรม', 'เห็นกำหนดส่ง ส่งงานผ่านมือถือ และดูผลตรวจจากครู'],
        ['star', 'gold', 'คะแนน กพช.', 'คะแนนกิจกรรมสะสม เกณฑ์ผ่าน และประวัติการได้คะแนน'],
        ['grade', 'green', 'คะแนนงานในห้อง', 'คะแนนใบงานและแบบฝึกหัดที่ครูตรวจแล้ว'],
        ['calendar', 'violet', 'การมาเรียน', 'สถานะ มา ลา ขาด ที่ครูบันทึกในแต่ละครั้ง'],
        ['megaphone', 'rose', 'ประกาศจากครู', 'ข่าวสาร วันสอบ และนัดหมายของศูนย์'],
        ['history', 'teal', 'ประวัติการศึกษา', 'ดูย้อนหลังทุกภาคเรียน และพิมพ์รายงานเป็น PDF'],
    ];
    $f = '';
    foreach ($features as $i => [$ic, $tone, $t, $d]) {
        $f .= '<article class="lp-feature tone-' . $tone . '"><span class="lp-feature-icon">' . icon($ic, 22) . '</span><div><h3>' . esc($t) . '</h3><p>' . esc($d) . '</p></div></article>';
    }
    $steps = [
        ['user', 'สมัครสมาชิก', 'กรอกชื่อ รหัสนักศึกษา ระดับชั้น และตั้งรหัสผ่าน ใช้เวลาประมาณ 2 นาที', linkTo('/register.php', 'สมัครเลย', 'lp-step-link')],
        ['check', 'ลงทะเบียนภาคเรียน', 'เข้าสู่ระบบแล้วกด “ลงทะเบียนเรียน” ครูจะตรวจสอบและอนุมัติ', linkTo('/login.php', 'เข้าสู่ระบบ', 'lp-step-link')],
        ['book', 'เริ่มเรียน', 'ดูงาน ส่งงาน ติดตามคะแนนและการมาเรียนได้ทันทีหลังครูอนุมัติ', ''],
    ];
    // "โทร … · LINE …" from settings.ini is shown one channel per line.
    $parts = array_values(array_filter(array_map('trim', explode('·', $contact)), static fn($x) => $x !== ''));
    $contactLines = $parts ? implode('', array_map(static fn($x) => '<span>' . esc($x) . '</span>', $parts)) : 'ติดต่อครูผู้ดูแลผ่านช่องทางของศูนย์';
    $st = '';
    foreach ($steps as $i => [$ic, $t, $d, $link]) {
        $st .= '<li class="lp-step"><span class="lp-step-no">' . ($i + 1) . '</span><span class="lp-step-icon">' . icon($ic, 20) . '</span>' .
            '<h3>' . esc($t) . '</h3><p>' . esc($d) . '</p>' . $link . '</li>';
    }
    return '<section class="lp-hero"><div class="lp-orbs" aria-hidden="true"><span></span><span></span><span></span></div>' .
        '<div class="lp-wrap lp-hero-in"><div class="lp-copy">' .
        '<p class="lp-badge">' . icon('pin', 16) . '<span>ศกร.ระดับตำบลบ้านใหม่ · อ.สามพราน จ.นครปฐม</span></p>' .
        '<h1>ติดตามการเรียนของคุณ<span>ได้ในที่เดียว</span></h1>' .
        '<p class="lp-lede">งาน กิจกรรม คะแนน กพช. การมาเรียน และประกาศจากครู ใช้งานได้ทั้งมือถือและคอมพิวเตอร์</p>' .
        '<div class="lp-cta">' . iconLink('/register.php', 'plus', 'สมัครสมาชิกใหม่', 'button large lp-primary') . iconLink('/login.php', 'arrow', 'เข้าสู่ระบบ', 'button large lp-outline') . '</div>' .
        '<p class="lp-note">เคยสมัครแล้ว? เข้าสู่ระบบด้วยชื่อผู้ใช้หรือรหัสนักศึกษา · ' . linkTo('/forgot.php', 'ลืมรหัสผ่าน?') . '</p></div>' .
        '<div class="lp-visual"><figure class="lp-figure"><img src="/assets/reading-room.webp" alt="หนังสือบนโต๊ะอ่านหนังสือในห้องสมุด" width="1120" height="747"></figure>' .
        '<span class="lp-chip c1">' . icon('check', 16) . 'ส่งงานผ่านมือถือ</span>' .
        '<span class="lp-chip c2">' . icon('star', 16) . 'คะแนน กพช. สะสม</span>' .
        '<span class="lp-chip c3">' . icon('calendar', 16) . esc(termName($term)) . '</span></div>' .
        '</div></section>' .
        '<section class="lp-wrap lp-steps-card" aria-labelledby="lp-steps-title"><div class="lp-head"><p class="lp-eyebrow">เริ่มต้นใช้งาน</p><h2 id="lp-steps-title">3 ขั้นตอนก็เริ่มเรียนได้</h2></div>' .
        '<ol class="lp-steps">' . $st . '</ol></section>' .
        '<section class="lp-wrap lp-section" aria-labelledby="lp-features-title"><div class="lp-head"><p class="lp-eyebrow">ในระบบมีอะไรบ้าง</p>' .
        '<h2 id="lp-features-title">ทุกเรื่องการเรียน ดูได้จากมือถือเครื่องเดียว</h2></div><div class="lp-features">' . $f . '</div></section>' .
        '<section class="lp-wrap lp-section lp-bottom"><div class="lp-news"><div class="lp-head row"><div><p class="lp-eyebrow">ข่าวสาร</p><h2>ประกาศล่าสุด</h2></div></div>' . newsList(3) . '</div>' .
        '<aside class="lp-contact"><span class="lp-contact-icon">' . icon('info', 22) . '</span><h2>ติดต่อศูนย์การเรียนรู้</h2>' .
        '<p class="lp-contact-line">' . $contactLines . '</p>' .
        '<p class="lp-contact-sub">ศกร.ระดับตำบลบ้านใหม่ อ.สามพราน จ.นครปฐม</p>' .
        '<div class="lp-contact-actions">' . iconLink('/forgot.php', 'key', 'ลืมรหัสผ่าน', 'button lp-light') . iconLink('/info.php', 'info', 'เกี่ยวกับระบบ', 'button lp-outline') . '</div></aside></section>' .
        '<section class="lp-wrap lp-final"><div><h2>พร้อมเริ่มเรียนแล้วหรือยัง?</h2><p>สมัครสมาชิกแล้วลงทะเบียนภาคเรียน ครูจะอนุมัติให้เร็วที่สุด</p></div>' .
        '<div class="lp-cta">' . linkTo('/register.php', 'สมัครสมาชิกใหม่', 'button large lp-primary') . linkTo('/login.php', 'เข้าสู่ระบบ', 'button large lp-outline') . '</div></section>';
}
function studentTodoV33(int $id, int $termId): string
{
    $items = rows(
        "SELECT w.id,w.kind,w.title,w.due_at,s.status FROM work_items w
         LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=?
         WHERE w.term_id=? AND ((s.id IS NULL AND w.due_at>=NOW()) OR s.status='revise')
         ORDER BY w.due_at,w.id LIMIT 6",
        [$id, $termId],
    );
    if (!$items) {
        return emptyState('ไม่มีงานค้าง ส่งครบทุกงานที่เปิดอยู่แล้ว', 'check');
    }
    $h = '<ul class="todo">';
    foreach ($items as $w) {
        $h .= '<li><a href="/item.php?id=' . (int) $w['id'] . '"><span class="todo-icon">' . icon($w['kind'] === 'activity' ? 'spark' : 'file', 18) . '</span>' .
            '<span class="todo-main"><b>' . esc($w['title']) . '</b><small>' . ($w['kind'] === 'activity' ? 'กิจกรรม' : 'งาน') . ' · กำหนด ' . esc(thDate($w['due_at'])) . '</small></span>' .
            ($w['status'] === 'revise' ? badge('ต้องแก้ไข', 'red') : dueText($w['due_at'])) . '</a></li>';
    }
    return $h . '</ul>';
}
function studentHomeV32(array $u, array $term): string
{
    $id = (int) $u['id'];
    $termId = (int) $term['id'];
    $en = v32Enrollment($id, $termId);
    $approved = $en && $en['status'] === 'approved';
    $level = $en['level_name'] ?? $u['level_name'];
    $state = $en ? v32EnrollmentText($en['status']) : 'ยังไม่ลงทะเบียน';
    $tone = $approved ? 'good' : ($en && $en['status'] === 'pending' ? 'gold' : '');
    $h = '<section class="welcome card">' . avatar($u, 'lg') . '<div><p class="eyebrow">' . esc(termName($term)) . '</p><h1>สวัสดี, ' . esc($u['first_name']) . '</h1>' .
        '<p>' . esc($level) . ' · รหัส ' . esc($u['student_code']) . ' ' . badge($state, $tone) . '</p></div></section>';
    if (!$approved && enrollmentClosedByTeacher($en)) {
        // V3.3.2: a teacher's decision is final for this term; no request form is shown again.
        $h .= v32CurrentPrompt($u) . '<p class="note">' . icon('info', 16) . ' คุณยังดูประวัติภาคเรียนก่อนหน้า ประกาศ และแก้ไขบัญชีได้ตามปกติ</p>';
    } elseif (!$approved) {
        $sent = $en && $en['status'] === 'pending';
        $h .= '<section class="card section"><h2>ขั้นตอนเริ่มเรียนภาคเรียนนี้</h2><ol class="progress-steps">' .
            '<li class="done"><b>สมัครสมาชิก</b><span>เรียบร้อยแล้ว</span></li>' .
            '<li class="' . ($sent ? 'done' : 'on') . '"><b>ส่งคำขอลงทะเบียน</b><span>' . ($sent ? 'ส่งแล้วเมื่อ ' . esc(thDate($en['requested_at'])) : 'เลือกระดับชั้นด้านล่าง') . '</span></li>' .
            '<li class="' . ($sent ? 'on' : '') . '"><b>ครูอนุมัติ</b><span>' . ($sent ? 'กำลังรอครูตรวจสอบ' : 'หลังส่งคำขอ') . '</span></li></ol></section>' .
            v32CurrentPrompt($u) .
            '<p class="note">' . icon('info', 16) . ' ระหว่างรออนุมัติ คุณยังดูประวัติภาคเรียนก่อนหน้า ประกาศ และแก้ไขบัญชีได้ตามปกติ</p>';
    } else {
        $p = scoreTotal($id, $termId);
        $att = v32AttendanceStats($id, $termId);
        $h .= '<div class="grid three">' .
            meterCard('คะแนน กพช. ภาคเรียนนี้', $p, 200, (int) $term['min_points'], 'คะแนน', '', '/scores.php') .
            classworkMeter($id, $term, '/scores.php#classwork') .
            meterCard('วันมาเรียน', $att['present'], max((int) $term['expected_days'], $att['present']), (int) $term['min_attendance'], 'วัน', 'ลา ' . $att['leave'] . ' · ขาด ' . $att['absent'], '/attendance.php') .
            '</div>';
        $h .= '<div class="grid two">' .
            '<section class="card section"><div class="section-head"><h2>สิ่งที่ต้องทำ</h2>' . linkTo('/assignments.php', 'ดูงานทั้งหมด', 'textlink') . '</div>' . studentTodoV33($id, $termId) . '</section>' .
            '<section class="card section"><div class="section-head"><h2>แจ้งเตือนล่าสุด</h2>' . linkTo('/notifications.php', 'ดูทั้งหมด', 'textlink') . '</div>' . noticeList($id, 4, $termId) . '</section>' .
            '</div>';
    }
    $services = [
        ['/assignments.php', 'file', 'งานที่ได้รับ'], ['/activities.php', 'spark', 'กิจกรรม'], ['/scores.php', 'star', 'คะแนน กพช.'],
        ['/attendance.php', 'calendar', 'การมาเรียน'], ['/history.php', 'history', 'ประวัติการศึกษา'], ['/announcements.php', 'megaphone', 'ประกาศ'],
        ['/behavior.php', 'flag', 'บันทึกพฤติกรรม'], ['/profile.php', 'user', 'บัญชีของฉัน'],
    ];
    $s = '';
    foreach ($services as [$url, $ic, $label]) {
        $s .= '<a class="tile" href="' . esc($url) . '"><span class="tile-icon">' . icon($ic, 22) . '</span><span>' . esc($label) . '</span></a>';
    }
    $h .= '<section class="card section"><h2>เมนูบริการ</h2><div class="tiles">' . $s . '</div></section>';
    $h .= '<section class="card section"><div class="section-head"><h2>ประกาศจากศูนย์</h2>' . linkTo('/announcements.php', 'ดูทั้งหมด', 'textlink') . '</div>' . newsList(2, false, $termId) . '</section>';
    return $h;
}
function studentInfoV32(): string
{
    return documentTitle('เกี่ยวกับ SGR Library', 'ระบบข้อมูลการเรียนรู้ของ ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม') .
        '<section class="card section"><h2>ระบบนี้ทำอะไรได้</h2><div class="grid two">' .
        '<div><h3>สำหรับนักศึกษา</h3><ul class="ticks"><li>ลงทะเบียนเรียนรายภาคเรียน</li><li>ดูและส่งงาน กิจกรรม</li><li>ติดตามคะแนน กพช. และการมาเรียน</li><li>ดูประวัติย้อนหลังและพิมพ์รายงาน</li></ul></div>' .
        '<div><h3>สำหรับครู</h3><ul class="ticks"><li>อนุมัติลงทะเบียนและจัดการรายชื่อ</li><li>เช็กชื่อทั้งห้อง ตรวจงาน ให้คะแนน</li><li>บันทึกพฤติกรรมและประกาศข่าว</li><li>ออกรายงาน PDF / Excel</li></ul></div>' .
        '</div></section>' .
        '<section class="card section"><h2>ความเป็นส่วนตัว</h2><p>นักศึกษาเห็นเฉพาะข้อมูลของตนเอง บันทึกพฤติกรรมบางรายการเป็นข้อมูลที่ครูดูได้เท่านั้น รหัสผ่านถูกเข้ารหัสและครูไม่สามารถมองเห็นได้</p></section>' .
        '<section class="card section"><h2>ทีมผู้พัฒนา</h2>' . infoTeam() . '</section>' .
        '<p class="muted center small">SGR Library · เวอร์ชัน ' . esc(APP_VERSION) . '</p>' .
        '<div class="actions center">' . linkTo(empty($_SESSION['uid']) ? '/' : '/dashboard.php', 'กลับหน้าหลัก', 'button secondary') . '</div>';
}
