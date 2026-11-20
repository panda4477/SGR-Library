<?php
declare(strict_types=1);

function hubIconV31(string $name): string
{
    $paths = [
        'score' => '<circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'file' => '<path d="M6 3h8l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/>',
        'spark' => '<path d="m12 2 2.3 7.7L22 12l-7.7 2.3L12 22l-2.3-7.7L2 12l7.7-2.3L12 2Z"/>',
        'megaphone' => '<path d="m3 10 14-6v16L3 14zM3 10v4M6 15l2 6h3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3-7 8-7s8 3 8 7"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'bell' => '<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5zM10 21h4"/>',
    ];
    return '<svg aria-hidden="true" viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['file']) . '</svg>';
}
function hubLinksV31(): string
{
    $links = [
        ['/scores.php', 'score', 'คะแนน กพช.', 'ตรวจสอบคะแนนของคุณ'],
        ['/attendance.php', 'calendar', 'การมาเรียน', 'เช็กสถานะรายวัน'],
        ['/assignments.php', 'file', 'งาน', 'รายการงานที่ได้รับ'],
        ['/activities.php', 'spark', 'กิจกรรม', 'ติดตามกิจกรรมล่าสุด'],
        ['/announcements.php', 'megaphone', 'ประกาศ', 'ข่าวสารที่ควรรู้'],
        ['/profile.php', 'user', 'โปรไฟล์', 'ข้อมูลส่วนตัว'],
    ];
    $html = '';
    foreach ($links as [$url, $icon, $title, $subtitle]) {
        $html .= '<a class="hub-tile" href="' . esc($url) . '"><span class="hub-tile-icon">' . hubIconV31($icon) . '</span>' .
            '<strong>' . esc($title) . '</strong><small>' . esc($subtitle) . '</small><span class="hub-tile-arrow">' . hubIconV31('arrow') . '</span></a>';
    }
    return '<div class="hub-tiles">' . $html . '</div>';
}
function studentWelcomeV31(): string
{
    $announcements = (int) val('SELECT COUNT(*) FROM announcements WHERE publish_on<=CURDATE()');
    $activities = (int) val("SELECT COUNT(*) FROM work_items WHERE kind='activity'");
    return '<div class="student-v31"><section class="hub-guest-hero"><div class="hub-guest-copy">' .
        '<span class="hub-kicker"><span class="hub-live"></span> SGR STUDENT PORTAL · V3.1</span>' .
        '<h1>เรียนรู้ในแบบ<br><em>ของคุณเอง</em></h1>' .
        '<p>ศูนย์รวมการเรียนรู้ งาน กิจกรรม คะแนน กพช. และข่าวสารสำคัญของนักศึกษา ศกร.ระดับตำบลบ้านใหม่</p>' .
        '<div class="actions">' . linkTo('/login.php', 'เข้าสู่ระบบนักศึกษา', 'button primary') .
        linkTo('/register.php', 'สมัครสมาชิก', 'button secondary') . '</div>' .
        '<div class="hub-guest-stats"><div><strong>' . $activities . '</strong><small>กิจกรรมในระบบ</small></div>' .
        '<div><strong>' . $announcements . '</strong><small>ประกาศที่เผยแพร่</small></div><div><strong>3.1</strong><small>เวอร์ชันระบบ</small></div></div></div>' .
        '<div class="hub-guest-visual" aria-hidden="true"><div class="hub-orbit hub-orbit-a"></div><div class="hub-orbit hub-orbit-b"></div>' .
        '<div class="hub-visual-card"><span>STUDENT CENTER</span><strong>SGR.</strong><small>YOUR LEARNING SPACE</small>' .
        '<div class="hub-visual-lines"><i></i><i></i><i></i></div></div></div></section>' .
        '<div class="hub-section-title"><div><span class="hub-kicker">EXPLORE</span><h2>ทุกอย่างอยู่ที่นี่</h2></div>' .
        linkTo('/info.php', 'รู้จักระบบ', 'textlink') . '</div>' .
        '<div class="hub-feature-row"><article>' . hubIconV31('file') . '<h3>การเรียนและกิจกรรม</h3><p>ติดตามรายการในแต่ละภาคเรียนได้จากที่เดียว</p></article>' .
        '<article>' . hubIconV31('score') . '<h3>คะแนนของคุณ</h3><p>ดูคะแนน กพช. และประวัติการบันทึกย้อนหลัง</p></article>' .
        '<article>' . hubIconV31('bell') . '<h3>ข่าวสารไม่ตกหล่น</h3><p>รับข้อมูลประกาศและสิ่งที่ครูแจ้ง</p></article></div>' .
        '<div class="columns hub-guest-latest">' . block('ประกาศล่าสุด', newsList(3)) .
        block('ติดต่อศูนย์การเรียนรู้', '<p>' . esc(cfg()['app']['contact']) . '</p>' . linkTo('/info.php', 'เกี่ยวกับระบบ', 'textlink')) . '</div></div>';
}
function studentHomeV31(array $u, array $term): string
{
    $id = (int) $u['id'];
    $termId = (int) $term['id'];
    $points = scoreTotal($id, $termId);
    $pending = (int) val("SELECT COUNT(*) FROM work_items w LEFT JOIN submissions s ON s.item_id=w.id AND s.student_id=? WHERE w.term_id=? AND w.kind='assignment' AND s.id IS NULL", [$id, $termId]);
    $unseen = (int) val('SELECT COUNT(*) FROM notices WHERE student_id=? AND seen_at IS NULL', [$id]);
    $present = (int) val("SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions ats ON ats.id=ar.session_id WHERE ar.student_id=? AND ats.term_id=? AND ar.status='present'", [$id, $termId]);
    $name = fullname($u);
    $h = '<div class="student-v31"><section class="hub-user-hero"><div><span class="hub-kicker"><span class="hub-live"></span> LEARNING DASHBOARD · V3.1</span>' .
        '<h1>สวัสดี, ' . esc($u['first_name']) . '<span class="hub-hello"> ✦</span></h1>' .
        '<p>ยินดีต้อนรับกลับ ' . esc($name) . ' · ' . esc(termName($term)) . '</p>' .
        '<div class="hub-hero-actions">' . linkTo('/assignments.php', 'ไปที่งานของฉัน', 'button primary') .
        linkTo('/notifications.php', 'ดูแจ้งเตือน', 'button secondary') . '</div></div><div class="hub-hero-badge">' . avatar($u) .
        '<small>MY LEARNING</small><strong>SPACE</strong></div></section>' .
        '<div class="hub-stat-grid">' .
        '<a href="/scores.php"><span>' . hubIconV31('score') . ' คะแนน กพช.</span><strong>' . $points . '<small> / 200</small></strong><em>' . ($points >= 100 ? 'ผ่านเกณฑ์แล้ว' : 'เป้าหมาย 100 คะแนน') . '</em></a>' .
        '<a href="/assignments.php"><span>' . hubIconV31('file') . ' งานยังไม่ส่ง</span><strong>' . $pending . '<small> รายการ</small></strong><em>ดูงานที่ต้องทำ</em></a>' .
        '<a href="/attendance.php"><span>' . hubIconV31('calendar') . ' มาเรียน</span><strong>' . $present . '<small> ครั้ง</small></strong><em>ในภาคเรียนนี้</em></a>' .
        '<a href="/notifications.php"><span>' . hubIconV31('bell') . ' แจ้งเตือนใหม่</span><strong>' . $unseen . '<small> รายการ</small></strong><em>อัปเดตล่าสุด</em></a></div>' .
        '<div class="hub-section-title"><div><span class="hub-kicker">QUICK ACCESS</span><h2>เมนูของฉัน</h2></div><span class="hub-version">V3.1</span></div>' .
        hubLinksV31() . '<div class="hub-section-title"><div><span class="hub-kicker">ACTIVITY FEED</span><h2>ความเคลื่อนไหวล่าสุด</h2></div></div>' .
        '<div class="columns hub-feed">' . block('งานที่ยังไม่ส่ง', workForStudent($id, $termId, 'assignment', true)) .
        block('การแจ้งเตือน', noticeList($id, 3)) . '</div>' .
        '<div class="columns hub-feed">' . block('กิจกรรมล่าสุด', workForStudent($id, $termId, 'activity', false, true)) .
        block('การมาเรียน', attendanceHistory($id, $term, true)) . '</div>' .
        block('ประกาศล่าสุด', newsList(3)) . '</div>';
    return $h;
}
