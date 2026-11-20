<?php
declare(strict_types=1);

function authAction(string $route): void
{
    switch ($route) {
        case '/login.php':
            $name = post('username', 60);
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || str_contains($password, "\0") || strlen($password) > 72) {
                $_SESSION['last_username'] = mb_substr($name, 0, 60);
                flash('กรุณาตรวจชื่อผู้ใช้และรหัสผ่าน');
                redirect('/login.php');
            }
            limitAttempts('login-ip-' . PORTAL, clientIp(), 150, 900);
            limitAttempts('login-' . PORTAL, clientIp() . '|' . strtolower($name), 10, 900);
            if (PORTAL === 'student') {
                $u = row("SELECT id,password_hash,auth_version FROM users WHERE role='student' AND (username=? OR student_code=?) ORDER BY username=? DESC LIMIT 1", [$name,$name,$name]);
            } else {
                $u = row("SELECT id,password_hash,auth_version FROM users WHERE role='teacher' AND username=? LIMIT 1", [$name]);
            }
            // This bcrypt value is used only for a timing-equivalent check when an account is missing.
            $dummy = '$2y$12$eImiTXuWVxfM37uY4JANjQe17zr6vPB03tTrSSjDVKcWUfK/NKiLq';
            $stored = (string)($u['password_hash'] ?? $dummy);
            if (!verifyStoredPassword($password, $stored) || !$u) {
                $_SESSION['last_username'] = mb_substr($name, 0, 60);
                flash('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
                redirect('/login.php');
            }
            $normalized = normalizedPasswordHash($stored);
            if ($normalized !== $stored || password_needs_rehash($normalized, PASSWORD_BCRYPT, ['cost'=>12])) {
                $normalized = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                run('UPDATE users SET password_hash=? WHERE id=?', [$normalized, $u['id']]);
            }
            session_regenerate_id(true);
            $_SESSION = [
                'uid' => (int) $u['id'],
                'role' => PORTAL,
                'version' => (int) $u['auth_version'],
                'csrf' => bin2hex(random_bytes(32)),
                'started' => time(),
                'active' => time(),
                'touched' => time(),
            ];
            run('UPDATE users SET last_login=NOW(),last_seen=NOW() WHERE id=?', [$u['id']]);
            redirect('/dashboard.php');
        case '/logout.php':
            $_SESSION = [];
            session_destroy();
            setcookie(sessionLabel(PORTAL), '', [
                'expires' => 1,
                'path' => '/',
                'secure' => isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            redirect('/');
        case '/register.php':
            limitAttempts('register', clientIp(), 240, 3600);
            $name = username();
            $hash = newPassword();
            $fields = identityFields();
            tx(function () use ($name, $hash, $fields) {
                run(
                    "INSERT INTO users(role,username,password_hash,first_name,last_name,student_code,level_name,gender) VALUES('student',?,?,?,?,?,?,?)",
                    [$name, $hash, ...$fields],
                );
                $id = inserted();
                $avatar = saveUpload('avatar', $id, true);
                if ($avatar) {
                    run('UPDATE users SET avatar_id=? WHERE id=?', [$avatar, $id]);
                }
                audit($id, 'student.register', $id, $id);
            });
            $_SESSION['last_username'] = $name;
            flash('สมัครสมาชิกเรียบร้อยแล้ว ใส่รหัสผ่านเพื่อเข้าสู่ระบบได้ทันที');
            redirect('/login.php');
        case '/forgot.php':
            limitAttempts('forgot', clientIp(), 16, 900);
            $name = post('username', 60);
            $code = post('student_code', 40);
            $u = row("SELECT id FROM users WHERE role='student' AND username=? AND student_code=?", [
                $name,
                $code,
            ]);
            if ($u) {
                run(
                    'INSERT INTO reset_requests(student_id,requested_at) VALUES(?,NOW())
            ON DUPLICATE KEY UPDATE requested_at=IF(used_at IS NOT NULL OR expires_at<=NOW(),VALUES(requested_at),requested_at),
            code_hash=IF(used_at IS NOT NULL OR expires_at<=NOW(),NULL,code_hash),
            issued_by=IF(code_hash IS NULL,NULL,issued_by),expires_at=IF(code_hash IS NULL,NULL,expires_at),used_at=NULL',
                    [$u['id']],
                );
            }
            flash('หากข้อมูลตรงกับบัญชี กรุณาติดต่อครูเพื่อรับรหัสตั้งรหัสผ่านใหม่');
            redirect('/reset.php');
        case '/reset.php':
            $name = post('username', 60);
            $code = strtoupper(post('code', 50));
            limitAttempts('reset-ip', clientIp(), 35, 900);
            limitAttempts('reset-user', strtolower($name), 10, 900);
            $hash = newPassword();
            tx(function () use ($name, $code, $hash) {
                $u = row("SELECT id FROM users WHERE role='student' AND username=? FOR UPDATE", [$name]);
                $r = $u
                    ? row(
                        'SELECT * FROM reset_requests WHERE student_id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',
                        [$u['id']],
                    )
                    : null;
                if (
                    !$r ||
                    !$r['code_hash'] ||
                    !hash_equals($r['code_hash'], hash_hmac('sha256', $code, cfg()['app']['key']))
                ) {
                    fail(422, 'รหัสไม่ถูกต้อง หมดอายุ หรือใช้ไปแล้ว');
                }
                run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?', [
                    $hash,
                    $u['id'],
                ]);
                run('UPDATE reset_requests SET used_at=NOW(),code_hash=NULL WHERE id=?', [$r['id']]);
                audit((int) $u['id'], 'password.reset', (int) $u['id'], (int) $r['id']);
            });
            flash('ตั้งรหัสผ่านใหม่แล้ว');
            redirect('/login.php');
    }
}
function scoreTotal(int $id, int $term): int
{
    return (int) val(
        'SELECT COALESCE(SUM(delta_points),0) FROM score_entries WHERE student_id=? AND term_id=?',
        [$id, $term],
    );
}
function addPoints(
    int $teacher,
    int $id,
    int $term,
    int $delta,
    string $why,
    ?int $submission = null,
    ?int $version = null,
    ?string $key = null,
): void {
    // All callers hold the student row lock and are in the same transaction as their audit entry.
    $balance = scoreTotal($id, $term) + $delta;
    // Deductions may not push a total below 0 and additions may not pass 200; a total that is already
    // below 0 (e.g. after the V3.3.2 classwork offset) can still receive new points.
    if (($delta < 0 && $balance < 0) || ($delta > 0 && $balance > 200)) {
        fail(422, 'รายการนี้จะทำให้คะแนนรวมอยู่นอกช่วง 0–200 กรุณาตรวจยอดก่อนบันทึก');
    }
    run(
        'INSERT INTO score_entries(student_id,term_id,delta_points,reason,teacher_id,submission_id,review_version,request_key)
        VALUES(?,?,?,?,?,?,?,?)',
        [$id, $term, $delta, $why, $teacher, $submission, $version, $key],
    );
    audit($teacher, 'score.entry', $id, inserted(), [
        'term' => $term,
        'change' => $delta,
        'balance' => $balance,
        'reason' => $why,
    ]);
    notifyStudent(
        $id,
        'คะแนน กพช. เปลี่ยน ' . ($delta > 0 ? '+' : '') . $delta . ' คะแนน',
        '/scores.php?term=' . $term,
        $term,
    );
}
function submitWork(int $id, int $itemId): void
{
    tx(function () use ($id, $itemId) {
        student($id, true);
        $w = row('SELECT *,due_at<NOW() AS late FROM work_items WHERE id=?', [$itemId]) ?? fail(404);
        v32RequireApproved($id,(int)$w['term_id']);
        if(!v32Feature('assignment_upload'))fail(403,'สถานศึกษาปิดระบบส่งงานผ่านเว็บ');
        $s = row('SELECT * FROM submissions WHERE item_id=? AND student_id=? FOR UPDATE', [$itemId, $id]);
        if ($s && $s['status'] !== 'revise') {
            fail(409, 'ส่งรายการนี้แล้ว');
        }
        if ((int) $w['late'] && (!$s || $s['status'] !== 'revise')) {
            fail(422, 'พ้นกำหนดส่งแล้ว');
        }
        $file = saveUpload('evidence', $id, false, (bool) $w['needs_file']);
        $note = post('note', 4000, false);
        if ($s) {
            run(
                "UPDATE submissions SET file_id=?,note=?,submitted_at=NOW(),status='submitted',review_note='',reviewed_at=NULL,reviewed_by=NULL WHERE id=?",
                [$file, $note, $s['id']],
            );
            $sid = (int) $s['id'];
        } else {
            run(
                "INSERT INTO submissions(item_id,student_id,file_id,note,submitted_at,review_note) VALUES(?,?,?,?,NOW(),'')",
                [$itemId, $id, $file, $note],
            );
            $sid = inserted();
        }
        audit($id, 'work.submit', $id, $sid, ['item' => $itemId]);
    });
    flash('ส่งเรียบร้อยแล้ว รอครูตรวจ');
    redirect('/item.php?id=' . $itemId);
}
function reviewWork(int $teacher, int $itemId): void
{
    $id = number($_POST['student_id'] ?? 0);
    $next = choice('status', ['acknowledged', 'passed', 'revise']);
    $note = post('review_note', 2000, false);
    $scoreRaw = $_POST['score'] ?? '';
    tx(function () use ($id, $teacher, $itemId, $next, $note, $scoreRaw) {
        student($id, true);
        $s =
            row(
                'SELECT s.*,w.term_id,w.points,w.title,w.kind FROM submissions s JOIN work_items w ON w.id=s.item_id WHERE item_id=? AND student_id=? FOR UPDATE',
                [$itemId, $id],
            ) ?? fail(422, 'ยังไม่มีผลงานส่งตรวจ');
        // V3.3.2: only activities earn กพช.; an assignment's score is classwork and stays on the submission.
        $activity = $s['kind'] === 'activity';
        $max = (int) $s['points'];
        $credit = 0;
        if ($next === 'passed') {
            $credit = $max;
            if (!$activity && is_string($scoreRaw) && trim($scoreRaw) !== '') {
                if (!preg_match('/^\d{1,3}$/D', trim($scoreRaw)) || (int) trim($scoreRaw) > $max) {
                    fail(422, 'คะแนนต้องเป็นจำนวนเต็ม 0–' . $max);
                }
                $credit = (int) trim($scoreRaw);
            }
        }
        if ($s['status'] === $next && $s['review_note'] === $note && $credit === (int) $s['credited']) {
            return;
        }
        $version = 1 + (int) $s['review_version'];
        $delta = $credit - (int) $s['credited'];
        if ($activity && $delta !== 0) {
            addPoints(
                $teacher,
                $id,
                (int) $s['term_id'],
                $delta,
                ($delta > 0 ? 'รับรองผลงาน · ' : 'ปรับผลตรวจ · ') . $s['title'],
                (int) $s['id'],
                $version,
            );
        }
        run(
            'UPDATE submissions SET status=?,review_note=?,reviewed_at=NOW(),reviewed_by=?,credited=?,review_version=? WHERE id=?',
            [$next, $note, $teacher, $credit, $version, $s['id']],
        );
        audit($teacher, 'work.review', $id, (int) $s['id'], [
            'from' => $s['status'],
            'to' => $next,
            'note' => $note,
            'score' => $activity ? null : $credit,
        ]);
        $scoreText = !$activity && $next === 'passed' && $max > 0 ? ' · ได้ ' . $credit . ' / ' . $max . ' คะแนน' : '';
        notifyStudent($id, $s['title'] . ' · ' . statusName($next) . $scoreText, '/item.php?id=' . $itemId, (int)$s['term_id']);
    });
    flash('บันทึกผลตรวจแล้ว');
    redirect('/item.php?id=' . $itemId . '#roster');
}
function studentAction(string $route, int $id): void
{
    if (in_array($route,['/profile.php','/renew.php'],true) || ($route==='/announcements.php' && ($_POST['action']??'')==='ack')) { v32StudentAction($route,$id); }
    if ($route==='/attendance.php') fail(405,'การเช็กชื่อทำโดยครูเท่านั้น');
    switch ($route) {
        case '/profile.php':
            $fields = identityFields();
            $name = username();
            tx(function () use ($id, $fields, $name) {
                $old = student($id, true);
                $file = saveUpload('avatar', $id, true);
                run(
                    'UPDATE users SET first_name=?,last_name=?,student_code=?,level_name=?,gender=?,username=?,avatar_id=? WHERE id=?',
                    [...$fields, $name, $file ?? $old['avatar_id'], $id],
                );
                audit($id, 'profile.update', $id, $id);
            });
            flash('บันทึกโปรไฟล์แล้ว');
            redirect('/profile.php');
        case '/item.php':
            submitWork($id, number($_GET['id'] ?? 0));
            break;
        case '/attendance.php':
            $sid = number($_POST['session_id'] ?? 0);
            tx(function () use ($id, $sid) {
                student($id, true);
                $a =
                    row(
                        'SELECT id,NOW() BETWEEN opens_at AND closes_at AS is_open FROM attendance_sessions WHERE id=? FOR UPDATE',
                        [$sid],
                    ) ?? fail(404);
                if (!(int) $a['is_open']) {
                    fail(422, 'ขณะนี้อยู่นอกช่วงลงชื่อ');
                }
                if (
                    !row('SELECT status FROM attendance_records WHERE session_id=? AND student_id=?', [
                        $sid,
                        $id,
                    ])
                ) {
                    run(
                        "INSERT INTO attendance_records(session_id,student_id,status,signed_at,updated_at) VALUES(?,?,'pending',NOW(),NOW())",
                        [$sid, $id],
                    );
                    audit($id, 'attendance.sign', $id, $sid);
                }
            });
            flash('บันทึกการลงชื่อแล้ว');
            redirect('/attendance.php');
        case '/notifications.php':
            $term = number($_POST['term_id'] ?? currentTerm()['id']);
            requireTerm($term);
            if (!v32Enrollment($id, $term) && $term !== (int)currentTerm()['id']) { fail(403); }
            run('UPDATE notices SET seen_at=NOW() WHERE student_id=? AND term_id=? AND seen_at IS NULL', [$id,$term]);
            flash('ทำเครื่องหมายว่าอ่านแจ้งเตือนของภาคเรียนนี้แล้ว');
            redirect('/notifications.php?term=' . $term);
        default:
            fail(405);
    }
}
function teacherAction(string $route, int $teacher): void
{
    if (in_array($route,['/students.php','/enrollments.php','/behavior.php','/reports.php','/feature-settings.php'],true)
        || ($route==='/student.php' && ($_POST['action']??'')==='term_status')
        || ($route==='/terms.php' && isset($_POST['action']))
        || ($route==='/roll.php' && ($_POST['action']??'')==='no_class')) { v32TeacherAction($route,$teacher); }
    if ($route === '/account.php') { teacherAccountAction($teacher); }
    switch ($route) {
        case '/new-item.php':
            $kind = choice('kind', ['activity', 'assignment']);
            $term = number($_POST['term_id'] ?? 0);
            requireTerm($term);
            $title = post('title', 200);
            $details = post('details', 12000);
            $points = number($_POST['points'] ?? '0', 0, 200);
            $due = dateInput('due_at');
            $start = $kind === 'activity' ? dateInput('starts_at') : null;
            $place = $kind === 'activity' ? post('place', 180) : '';
            $mode =
                $kind === 'assignment' ? choice('solution_mode', ['hidden', 'now', 'after_due']) : 'hidden';
            $solution = post('solution', 12000, false);
            $needed = $kind === 'assignment' || isset($_POST['needs_file']);
            $itemId = tx(function () use (
                $kind,
                $term,
                $title,
                $details,
                $points,
                $due,
                $start,
                $place,
                $mode,
                $solution,
                $needed,
                $teacher,
            ) {
                $file = saveUpload('resource', $teacher);
                run(
                    'INSERT INTO work_items(term_id,kind,title,details,starts_at,place,due_at,points,needs_file,resource_id,solution,solution_mode,teacher_id)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $term,
                        $kind,
                        $title,
                        $details,
                        $start,
                        $place,
                        $due,
                        $points,
                        (int) $needed,
                        $file,
                        $solution,
                        $mode,
                        $teacher,
                    ],
                );
                $i = inserted();
                audit($teacher, 'work.create', null, $i, ['kind' => $kind]);
                return $i;
            });
            flash('เผยแพร่รายการแล้ว');
            redirect('/item.php?id=' . $itemId);
        case '/item.php':
            if (($_POST['action'] ?? '') === 'delete_item') {
                deleteWorkV31($teacher, number($_GET['id'] ?? 0));
            }
            if (($_POST['action'] ?? '') === 'activity_roster') {
                v32ActivityRosterAction($teacher,number($_GET['id']??0));
            }
            if (($_POST['action'] ?? '') === 'grade_sheet') {
                gradeSheetAction($teacher, number($_GET['id'] ?? 0));
            }
            reviewWork($teacher, number($_GET['id'] ?? 0));
            break;
        case '/scores.php':
            $term = number($_POST['term_id'] ?? 0);
            requireTerm($term);
            $delta = number($_POST['points'] ?? null, -200, 200);
            if ($delta === 0) { fail(422, 'คะแนนที่ปรับต้องไม่เป็นศูนย์'); }
            $why = post('reason', 500);
            $key = post('request_key', 48);
            if (!preg_match('/^[a-f0-9]{48}$/D', $key)) { fail(422, 'กรุณาเปิดแบบฟอร์มใหม่'); }
            $ids = selectedStudentsV31($_POST['student_ids'] ?? []);
            v32CheckSelectedTermStudents($term,$ids);
            tx(function () use ($teacher, $ids, $term, $delta, $why, $key) {
                // Deterministic per-student keys make browser re-submissions idempotent.
                foreach ($ids as $id) {
                    student($id, true);
                    $derived = substr(hash_hmac('sha256', (string) $id, $key), 0, 48);
                    if (!val('SELECT id FROM score_entries WHERE teacher_id=? AND request_key=?', [$teacher, $derived])) {
                        addPoints($teacher, $id, $term, $delta, $why, null, null, $derived);
                    }
                }
            });
            flash('บันทึกคะแนน ' . count($ids) . ' คนเรียบร้อยแล้ว');
            redirect('/scores.php?term=' . $term);
        case '/terms.php':
            $year = number($_POST['school_year'] ?? null, 2500, 2800);
            $sem = number($_POST['semester'] ?? null, 1, 2);
            tx(function () use ($teacher, $year, $sem) {
                run(
                    'INSERT INTO terms(school_year,semester) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
                    [$year, $sem],
                );
                $id = inserted();
                if (isset($_POST['make_current'])) {
                    run('UPDATE site_state SET current_term_id=? WHERE id=1', [$id]);
                }
                audit($teacher, 'term.save', null, $id, ['current' => isset($_POST['make_current'])]);
            });
            flash('บันทึกภาคเรียนแล้ว');
            redirect('/terms.php');
        case '/new-attendance.php':
            $term = number($_POST['term_id'] ?? 0);
            requireTerm($term);
            $open = dateInput('opens_at');
            $close = dateInput('closes_at');
            $title = post('title', 180);
            $level=choice('level_name', array_merge(['all'],LEVELS));
            if ($close <= $open) {
                fail(422, 'เวลาปิดต้องอยู่หลังเวลาเปิด');
            }
            $sid = tx(function () use ($teacher, $term, $open, $close, $title, $level) {
                run(
                    'INSERT INTO attendance_sessions(term_id,title,opens_at,closes_at,teacher_id,level_name) VALUES(?,?,?,?,?,?)',
                    [$term, $title, $open, $close, $teacher, $level==='all'?null:$level],
                );
                $i = inserted();
                audit($teacher, 'attendance.create', null, $i);
                return $i;
            });
            flash('สร้างรอบลงชื่อแล้ว');
            redirect('/roll.php?id=' . $sid);
        case '/roll.php':
            $sid = number($_GET['id'] ?? 0);
            $action = choice('action', ['bulk_roll', 'edit_session']);
            if ($action === 'edit_session') {
                $title = post('title', 180);
                $open = dateInput('opens_at');
                $close = dateInput('closes_at');
                if ($close <= $open) { fail(422, 'เวลาเลิกต้องอยู่หลังเวลาเริ่ม'); }
                tx(function () use ($teacher, $sid, $title, $open, $close) {
                    row('SELECT id FROM attendance_sessions WHERE id=? FOR UPDATE', [$sid]) ?? fail(404);
                    run('UPDATE attendance_sessions SET title=?,opens_at=?,closes_at=? WHERE id=?', [$title, $open, $close, $sid]);
                    audit($teacher, 'attendance.session.update', null, $sid);
                });
                flash('แก้ไขรอบเรียนแล้ว');
                redirect('/roll.php?id=' . $sid);
            }
            $present = selectedCheckboxIdsV31($_POST['present'] ?? []);
            $leave = selectedCheckboxIdsV31($_POST['leave'] ?? []);
            if (array_intersect($present, $leave)) { fail(422, 'นักศึกษาหนึ่งคนไม่สามารถเลือกทั้งมาและลาได้'); }
            tx(function () use ($teacher, $sid, $present, $leave) {
                $session = row('SELECT * FROM attendance_sessions WHERE id=? FOR UPDATE', [$sid]) ?? fail(404);
                $students = rows("SELECT u.id FROM term_enrollments e JOIN users u ON u.id=e.student_id WHERE e.term_id=? AND e.status='approved' AND (? IS NULL OR e.level_name=?) ORDER BY u.id FOR UPDATE",[$session['term_id'],$session['level_name'],$session['level_name']]);
                if((int)$session['no_class']===1)fail(422,'วันงดเรียนไม่สามารถเช็กชื่อได้');
                $valid = array_map(static fn(array $x): int => (int) $x['id'], $students);
                if (array_diff(array_merge($present, $leave), $valid)) { fail(422, 'พบชื่อนักศึกษาที่ไม่ถูกต้อง'); }
                $existing = rows('SELECT student_id,status FROM attendance_records WHERE session_id=?', [$sid]);
                $old = [];
                foreach ($existing as $line) { $old[(int) $line['student_id']] = $line['status']; }
                foreach ($valid as $id) {
                    $status = in_array($id, $present, true) ? 'present' : (in_array($id, $leave, true) ? 'leave' : 'absent');
                    if (($old[$id] ?? null) === $status) { continue; }
                    run('INSERT INTO attendance_records(session_id,student_id,status,teacher_id,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status),teacher_id=VALUES(teacher_id),updated_at=NOW()', [$sid, $id, $status, $teacher]);
                    audit($teacher, 'attendance.correct', $id, $sid, ['from' => $old[$id] ?? null, 'to' => $status]);
                    notifyStudent($id, $session['title'] . ' · ' . statusName($status), '/attendance.php?term=' . $session['term_id'],(int)$session['term_id']);
                }
            });
            flash('บันทึกมา / ลา / ขาด ทั้งห้องแล้ว');
            redirect('/roll.php?id=' . $sid);
        case '/student.php':
            $id = number($_GET['id'] ?? 0);
            $action = choice('action', ['identity', 'issue_code', 'delete_student']);
            if ($action === 'delete_student') {
                // V3.2: remove from the current term; never delete historical grades or accounts.
                $term=(int)currentTerm()['id'];
                teacherConfirmV31($teacher);
                tx(function()use($id,$term,$teacher){
                    student($id,true);
                    run("UPDATE term_enrollments SET status='inactive',reviewed_at=NOW(),reviewed_by=? WHERE student_id=? AND term_id=?",[$teacher,$id,$term]);
                    audit($teacher,'student.term.remove',$id,$term);
                });
                flash('นำออกจากภาคเรียนแล้ว ประวัติย้อนหลังและบัญชียังอยู่');
                redirect('/student.php?id='.$id);
            }
            if ($action === 'issue_code') {
                $code = strtoupper(bin2hex(random_bytes(6)));
                tx(function () use ($teacher, $id, $code) {
                    student($id, true);
                    run(
                        'INSERT INTO reset_requests(student_id,requested_at,issued_by,code_hash,expires_at) VALUES(?,NOW(),?,?,NOW()+INTERVAL 15 MINUTE)
                    ON DUPLICATE KEY UPDATE issued_by=VALUES(issued_by),code_hash=VALUES(code_hash),expires_at=VALUES(expires_at),used_at=NULL',
                        [$id, $teacher, hash_hmac('sha256', $code, cfg()['app']['key'])],
                    );
                    audit($teacher, 'password.issue_code', $id, $id);
                });
                $_SESSION['reset_display'] = ['student' => $id, 'code' => $code];
            } else {
                $fields = identityFields();
                $name = username();
                $phone = post('phone', 35, false);
                $line = post('line_contact', 100, false);
                tx(function () use ($teacher, $id, $fields, $name, $phone, $line) {
                    $old = student($id, true);
                    if (val('SELECT id FROM users WHERE username=? AND id<>?', [$name, $id])) {
                        fail(409, 'ชื่อผู้ใช้นี้มีคนใช้แล้ว');
                    }
                    if (val('SELECT id FROM users WHERE student_code=? AND id<>?', [$fields[2], $id])) {
                        fail(409, 'รหัสนักศึกษานี้มีอยู่แล้วในระบบ');
                    }
                    run(
                        'UPDATE users SET first_name=?,last_name=?,student_code=?,level_name=?,gender=?,username=?,phone=?,line_contact=? WHERE id=?',
                        [...$fields, $name, $phone, $line, $id],
                    );
                    $changed = [];
                    foreach (['first_name' => $fields[0], 'last_name' => $fields[1], 'student_code' => $fields[2], 'level_name' => $fields[3], 'gender' => $fields[4], 'username' => $name, 'phone' => $phone, 'line_contact' => $line] as $k => $v) {
                        if ((string) ($old[$k] ?? '') !== $v) { $changed[$k] = ['from' => $old[$k] ?? null, 'to' => $v]; }
                    }
                    audit($teacher, 'student.update', $id, $id, $changed);
                });
                flash('บันทึกข้อมูลนักศึกษาแล้ว');
            }
            redirect('/student.php?id=' . $id);
        case '/system-reset.php':
            resetAllV31($teacher);
            break;
        case '/announcements.php':
            if (($_POST['action'] ?? '') === 'delete_announcement') {
                $aid = number($_POST['announcement_id'] ?? 0);
                tx(function () use ($teacher, $aid) {
                    row('SELECT id FROM announcements WHERE id=? FOR UPDATE', [$aid]) ?? fail(404);
                    run('DELETE FROM announcement_reads WHERE announcement_id=?', [$aid]);
                    run('DELETE FROM announcements WHERE id=?', [$aid]);
                    audit($teacher, 'announcement.delete', null, $aid);
                });
                flash('ลบประกาศแล้ว');
                redirect('/announcements.php');
            }
            $title = post('title', 200);
            $body = post('body', 12000);
            $link = post('link_url', 1500, false);
            $day = dateInput('publish_on', true);
            if (
                $link !== '' &&
                (!filter_var($link, FILTER_VALIDATE_URL) ||
                    !in_array(strtolower(parse_url($link, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true))
            ) {
                fail(422, 'ลิงก์ต้องเป็น URL แบบ https หรือ http');
            }
            tx(function () use ($teacher, $title, $body, $link, $day) {
                $file = saveUpload('image', $teacher, true);
                run(
                    'INSERT INTO announcements(title,body,publish_on,pinned,link_url,image_id,teacher_id,term_id) VALUES(?,?,?,?,?,?,?,?)',
                    [$title, $body, $day, (int) isset($_POST['pinned']), $link, $file, $teacher, (int)currentTerm()['id']],
                );
                audit($teacher, 'announcement.publish', null, inserted());
            });
            flash('บันทึกประกาศแล้ว');
            redirect('/announcements.php');
        default:
            fail(405);
    }
}
