<?php
declare(strict_types=1);

/** Check selected student IDs (strict typing, bounded list, consistent lock ordering). */
function selectedCheckboxIdsV31(mixed $input): array
{
    if (!is_array($input) || count($input) > 500) {
        fail(422, 'รายการนักศึกษาไม่ถูกต้อง');
    }
    $ids = [];
    foreach ($input as $raw) {
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,18}$/D', $raw)) {
            fail(422, 'ข้อมูลรายชื่อนักศึกษาไม่ถูกต้อง');
        }
        $ids[] = number($raw);
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}
function selectedStudentsV31(mixed $input): array
{
    $ids = selectedCheckboxIdsV31($input);
    if (!$ids) { fail(422, 'กรุณาเลือกนักศึกษาอย่างน้อยหนึ่งคน'); }
    return $ids;
}
function teacherConfirmV31(int $teacher, bool $usernameNeeded = false): void
{
    $subject = clientIp() . '|' . $teacher;
    $failedKey = hash_hmac('sha256', 'teacher-destructive' . "\n" . $subject, cfg()['app']['key']);
    $failed = (int) val('SELECT hits FROM rate_limits WHERE rate_key=? AND expires_at>NOW()', [$failedKey]);
    if ($failed >= 8) { fail(429, 'ลองยืนยันรหัสผ่านใหม่หลังจาก 15 นาที'); }
    $password = post('teacher_password', 256);
    $user = row("SELECT username,password_hash FROM users WHERE role='teacher' AND id=?", [$teacher]) ?? fail(403);
    if (($usernameNeeded && !hash_equals($user['username'], post('teacher_username', 60))) || !verifyStoredPassword($password, (string)$user['password_hash'])) {
        limitAttempts('teacher-destructive', $subject, 8, 900);
        fail(403, 'ชื่อผู้ใช้หรือรหัสผ่านครูไม่ถูกต้อง');
    }
}
function deleteStudentV31(int $teacher, int $studentId): never
{
    if (post('confirmation', 10) !== 'DELETE') { fail(422, 'กรุณาพิมพ์ DELETE'); }
    teacherConfirmV31($teacher);
    $diskFiles = tx(function () use ($teacher, $studentId) {
        student($studentId, true);
        $disk = rows('SELECT disk_name FROM files WHERE owner_id=?', [$studentId]);
        run('DELETE FROM score_entries WHERE student_id=?', [$studentId]);
        run('DELETE FROM submissions WHERE student_id=?', [$studentId]);
        run('DELETE FROM attendance_records WHERE student_id=?', [$studentId]);
        run('DELETE FROM notices WHERE student_id=?', [$studentId]);
        run('DELETE FROM reset_requests WHERE student_id=?', [$studentId]);
        run('UPDATE audit_log SET student_id=NULL WHERE student_id=?', [$studentId]);
        run('UPDATE audit_log SET actor_id=NULL WHERE actor_id=?', [$studentId]);
        run('UPDATE users SET avatar_id=NULL WHERE id=?', [$studentId]);
        run('DELETE FROM files WHERE owner_id=?', [$studentId]);
        run("DELETE FROM users WHERE id=? AND role='student'", [$studentId]);
        audit($teacher, 'student.delete', null, $studentId);
        return array_column($disk, 'disk_name');
    });
    purgeDiskFilesV31($diskFiles);
    flash('ลบบัญชีนักศึกษาและข้อมูลที่เกี่ยวข้องแล้ว');
    redirect('/students.php');
}
function deleteWorkV31(int $teacher, int $itemId): never
{
    if (post('confirmation', 10) !== 'DELETE') { fail(422, 'กรุณาพิมพ์ DELETE'); }
    $kind = tx(function () use ($teacher, $itemId) {
        $work = row('SELECT id,kind FROM work_items WHERE id=? FOR UPDATE', [$itemId]) ?? fail(404);
        run('DELETE FROM score_entries WHERE submission_id IN (SELECT id FROM submissions WHERE item_id=?)', [$itemId]);
        run('DELETE FROM submissions WHERE item_id=?', [$itemId]);
        run('DELETE FROM work_items WHERE id=?', [$itemId]);
        audit($teacher, 'work.delete', null, $itemId, ['kind' => $work['kind']]);
        return $work['kind'];
    });
    flash('ลบรายการและคะแนนที่เกิดจากการตรวจรายการนั้นแล้ว');
    redirect($kind === 'activity' ? '/activities.php' : '/assignments.php');
}
function purgeDiskFilesV31(array $diskNames): void
{
    foreach ($diskNames as $name) {
        if (is_string($name) && preg_match('/^[0-9a-f]{64}$/D', $name)) {
            $path = cfg()['app']['storage'] . '/uploads/' . $name;
            if (is_file($path) && !@unlink($path)) {
                error_log('SGR unable to unlink orphaned upload ' . $name);
            }
        }
    }
}
function resetPageV31(array $teacher): string
{
    return documentTitle('รีเซ็ตข้อมูลระบบ', 'เฉพาะครู · ใช้เมื่อต้องการเริ่มใช้งานใหม่ทั้งหมดเท่านั้น') .
        '<div class="callout red">' . icon('alert', 24) . '<div><b>ล้างข้อมูลนักศึกษาทั้งหมดอย่างถาวร</b>' .
        '<p>จะลบ <strong>บัญชีนักศึกษาทุกคน</strong> คะแนน กพช. งาน กิจกรรม ผลงาน การมาเรียน ประกาศ การแจ้งเตือน และไฟล์ที่เกี่ยวข้อง ไม่สามารถย้อนกลับได้</p>' .
        '<p><strong>เก็บไว้:</strong> บัญชีครู รหัสผ่านครู ประวัติการจัดการของครู และข้อมูลภาคเรียนทั้งหมด</p>' .
        '<p>กรุณาสำรองฐานข้อมูลและไฟล์อัปโหลดจากเซิร์ฟเวอร์ก่อนทุกครั้ง</p></div></div>' .
        '<section class="card section narrow danger-zone">' . formStart('/system-reset.php') .
        field('teacher_username', 'ชื่อผู้ใช้ครู', 'text', '', true, 'autocomplete="username" autocapitalize="none"') .
        field('teacher_password', 'รหัสผ่านครู', 'password', '', true, 'autocomplete="current-password"') .
        checkbox('acknowledge', 'ฉันสำรองข้อมูลแล้ว และเข้าใจว่าข้อมูลจะถูกลบอย่างถาวร') .
        '<button type="submit" class="button danger block" data-confirm-delete="ยืนยันรีเซ็ตข้อมูลทั้งหมด? ไม่สามารถย้อนกลับได้">ยืนยันรีเซ็ตข้อมูลทั้งหมด</button></form></section>';
}
function resetAllV31(int $teacher): never
{
    if (!isset($_POST['acknowledge']) || $_POST['acknowledge'] !== '1') {
        fail(422, 'กรุณายืนยันการสำรองข้อมูล');
    }
    teacherConfirmV31($teacher, true);
    $disks = tx(function () use ($teacher) {
        // Lock student accounts before clearing their related records.
        rows("SELECT id FROM users WHERE role='student' ORDER BY id FOR UPDATE");
        $disk = rows("SELECT f.disk_name FROM files f WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.role='teacher' AND u.avatar_id=f.id)");
        run('DELETE FROM announcement_reads');
        run('DELETE FROM behavior_students');
        run('DELETE FROM behavior_events');
        run('DELETE FROM term_enrollments');
        run('DELETE FROM score_entries');
        run('DELETE FROM submissions');
        run('DELETE FROM attendance_records');
        run('DELETE FROM attendance_sessions');
        run('DELETE FROM work_items');
        run('DELETE FROM announcements');
        run('DELETE FROM notices');
        run('DELETE FROM reset_requests');
        run('UPDATE audit_log SET student_id=NULL WHERE student_id IS NOT NULL');
        run("DELETE a FROM audit_log a INNER JOIN users u ON u.id=a.actor_id WHERE u.role='student'");
        run("UPDATE users SET avatar_id=NULL WHERE role='student'");
        run("DELETE f FROM files f WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.role='teacher' AND u.avatar_id=f.id)");
        run("DELETE FROM users WHERE role='student'");
        // Preserve teacher-generated audit entries and the entire terms/site_state tables.
        audit($teacher, 'system.reset', null, null, ['version' => APP_VERSION]);
        return array_column($disk, 'disk_name');
    });
    purgeDiskFilesV31($disks);
    flash('รีเซ็ตข้อมูลเรียบร้อยแล้ว · บัญชีครูและภาคเรียนยังอยู่ครบ');
    redirect('/dashboard.php');
}
