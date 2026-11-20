<?php
declare(strict_types=1);
// V3.2 shared domain functions. Pages are owned by teacher-ui-v32.php / student-ui-v32.php.
function v32Feature(string $name): bool {
    return (int) (val('SELECT enabled FROM feature_flags WHERE flag_name=?', [$name]) ?: 0) === 1;
}
function v32Enrollment(int $studentId, int $termId): ?array {
    return row('SELECT * FROM term_enrollments WHERE student_id=? AND term_id=?', [$studentId,$termId]);
}
function v32Approved(int $studentId, int $termId): bool {
    return (bool) val("SELECT id FROM term_enrollments WHERE student_id=? AND term_id=? AND status='approved'", [$studentId,$termId]);
}
function v32RequireApproved(int $studentId,int $termId): void {
    if (!v32Approved($studentId,$termId)) fail(403,'ยังไม่ได้รับอนุมัติให้เรียนในภาคเรียนนี้');
}
function v32StudentSelectedTerm(int $studentId): array {
    $term=selectedTerm();
    $current=(int)currentTerm()['id'];
    if((int)$term['id']!==$current && !v32Enrollment($studentId,(int)$term['id'])) {
        fail(403,'ไม่มีประวัติของคุณในภาคเรียนนี้');
    }
    return $term;
}
function v32EnrolledStudents(int $termId, ?string $level = null, bool $approvedOnly=true): array {
    $where = $approvedOnly ? " AND e.status='approved'" : '';
    $where .= $level !== null ? ' AND e.level_name=?' : '';
    return rows("SELECT u.id,u.username,u.first_name,u.last_name,u.student_code,u.gender,u.avatar_id,e.level_name,e.status,e.requested_at,e.reviewed_by
        FROM term_enrollments e JOIN users u ON u.id=e.student_id
        WHERE u.role='student' AND e.term_id=?".$where.' ORDER BY e.level_name,u.first_name,u.last_name,u.id',
        $level !== null ? [$termId,$level] : [$termId]);
}
function v32RosterChoices(int $term, string $prefix, string $formId, array $selected=[], string $class='v32-choice'): string {
    $h='';
    foreach(v32EnrolledStudents($term) as $s) {
        $yes=in_array((int)$s['id'],$selected,true) ? ' checked' : '';
        $h.='<label class="'.$class.'" data-level="'.esc($s['level_name']).'"><input type="checkbox" name="'.esc($prefix).'[]" form="'.esc($formId).'" value="'.(int)$s['id'].'"'.$yes.'>' .
            '<span><strong>'.esc(fullname($s)).'</strong><small>'.esc($s['student_code']).' · '.esc($s['level_name']).'</small></span></label>';
    }
    return $h;
}
function v32StudentHistory(array $u): string {
    $studentId=(int)$u['id'];
    $terms=rows("SELECT e.*,t.school_year,t.semester,t.expected_days,t.min_attendance,t.min_points
        FROM term_enrollments e JOIN terms t ON t.id=e.term_id WHERE e.student_id=? ORDER BY t.school_year DESC,t.semester DESC",[$studentId]);
    $viewId = isset($_GET['term']) ? number($_GET['term']) : (int)($terms[0]['term_id'] ?? currentTerm()['id']);
    $selected=null; $options=[];
    foreach($terms as $entry){$options[(int)$entry['term_id']]=termName($entry);if((int)$entry['term_id']===$viewId)$selected=$entry;}
    if(!$selected) return documentTitle('ประวัติการศึกษา') . emptyState('ยังไม่มีข้อมูลภาคเรียนที่เคยลงทะเบียน เมื่อลงทะเบียนและครูอนุมัติแล้ว ประวัติจะแสดงที่นี่','history');
    $term=['id'=>$viewId,'school_year'=>$selected['school_year'],'semester'=>$selected['semester']];
    $p=scoreTotal($studentId,$viewId);
    $tot= v32AttendanceStats($studentId,$viewId);
    $h=documentTitle('ประวัติการศึกษาของฉัน','ข้อมูลแยกตามภาคเรียน คะแนนไม่รวมข้ามภาคเรียน',
        '<a class="button secondary" href="/report.php?term='.$viewId.'" target="_blank" rel="noopener">'.icon('print',18).'<span>พิมพ์ / บันทึก PDF</span></a>');
    $h.='<form method="get" class="filter term-filter">'.selectBox('term','ปีการศึกษา / ภาคเรียน',$options,$viewId).'<noscript>'.btn('แสดง','secondary').'</noscript></form>';
    $h.='<div class="summary-line card"><strong>'.esc(termName($term)).'</strong><span>'.esc($selected['level_name']).'</span>'.badge(v32EnrollmentText($selected['status']),$selected['status']==='approved'?'good':'').'</div>';
    $h.='<div class="grid two">'.meterCard('คะแนน กพช.',$p,200,(int)$selected['min_points'],'คะแนน').
        meterCard('วันมาเรียน',$tot['present'],max((int)$selected['expected_days'],$tot['present']),(int)$selected['min_attendance'],'วัน','ลา '.$tot['leave'].' · ขาด '.$tot['absent']).'</div>';
    $h.=block('ประวัติคะแนน',scoreHistory($studentId,$term,false));
    $h.=block('ประวัติการมาเรียน',attendanceHistory($studentId,$term,false));
    return $h;
}
function v32EnrollmentText(string $state): string {
    return ['pending'=>'รอครูอนุมัติ','approved'=>'กำลังศึกษา','declined'=>'ไม่ได้เรียนต่อ','inactive'=>'ออกจากภาคเรียน'][$state] ?? $state;
}
function v32AttendanceStats(int $studentId,int $term): array {
    $s=row("SELECT
        COALESCE(SUM(r.status='present'),0) AS present,
        COALESCE(SUM(r.status='leave'),0) AS leave_days,
        COALESCE(SUM(r.status='absent'),0) AS absent
        FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id
        WHERE r.student_id=? AND a.term_id=? AND a.no_class=0",[$studentId,$term]);
    return ['present'=>(int)($s['present']??0),'leave'=>(int)($s['leave_days']??0),'absent'=>(int)($s['absent']??0)];
}
function v32CurrentPrompt(array $u): string {
    $t=currentTerm();$en=v32Enrollment((int)$u['id'],(int)$t['id']);
    if($en && $en['status']==='approved')return '';
    if($en && $en['status']==='pending')return '<div class="callout gold" role="status">'.icon('clock',22).'<div><b>ส่งคำขอลงทะเบียนแล้ว · รอครูอนุมัติ</b><p>'.esc(termName($t)).' · '.esc($en['level_name']).' — เมื่อครูอนุมัติ คุณจะได้รับแจ้งเตือนและเห็นงาน คะแนน และการมาเรียนทันที</p></div></div>';
    // V3.3.2: once a teacher declines or removes a student, the request form is not offered again,
    // so the same request never returns to the approval list of the teacher.
    if(enrollmentClosedByTeacher($en)){
        return '<div class="callout red" role="status">'.icon('alert',22).'<div><b>'.($en['status']==='inactive'?'ครูนำคุณออกจากภาคเรียนนี้แล้ว':'คำขอลงทะเบียนไม่ได้รับอนุมัติ').'</b><p>'.esc(termName($t)).
            ' · หากคิดว่าผิดพลาด โปรดติดต่อครูผู้ดูแลโดยตรง ประวัติภาคเรียนก่อนหน้ายังดูได้ตามปกติ</p></div></div>';
    }
    $first=!(int)val('SELECT COUNT(*) FROM term_enrollments WHERE student_id=?',[(int)$u['id']]);
    $selected=$en['level_name']??$u['level_name'];
    $heading=$first?'ลงทะเบียนเรียน '.termName($t):'ยืนยันการเรียนต่อ '.termName($t);
    $text=$first?'เลือกระดับชั้นที่กำลังเรียน แล้วกดส่งคำขอ ครูจะตรวจสอบและอนุมัติให้':'เปิดภาคเรียนใหม่แล้ว โปรดยืนยันว่าจะเรียนต่อหรือไม่ ประวัติภาคเรียนเก่ายังอยู่ครบ';
    if($en && $en['status']==='declined'){$text='คุณเคยแจ้งว่าไม่เรียนต่อในภาคเรียนนี้ · หากเปลี่ยนใจ ส่งคำขอใหม่ได้ด้านล่าง';}
    $h='<section class="card section renew" aria-label="ลงทะเบียนภาคเรียน"><h2>'.esc($heading).'</h2><p>'.esc($text).'</p>';
    $h.=formStart('/renew.php').selectBox('level_name','ระดับชั้นที่กำลังเรียน',array_combine(LEVELS,LEVELS),$selected);
    $h.='<div class="actions"><button type="submit" class="button primary" name="decision" value="continue">'.icon('check',18).'<span>'.($first?'ส่งคำขอลงทะเบียน':'เรียนต่อ · ส่งให้ครูตรวจ').'</span></button>' .
       (!$first?'<button type="submit" class="button ghost" name="decision" value="decline" data-confirm-delete="ยืนยันว่าจะไม่เรียนต่อในภาคเรียนนี้?">ไม่ได้เรียนต่อ</button>':'').'</div></form></section>';
    return $h;
}
function v32StudentBehavior(array $u, ?array $term=null): string {
    $term=$term??v32StudentSelectedTerm((int)$u['id']);$id=(int)$u['id'];
    $items=rows("SELECT b.* FROM behavior_events b JOIN behavior_students bs ON bs.event_id=b.id
         WHERE bs.student_id=? AND b.visibility='student' AND b.term_id=? ORDER BY b.occurred_on DESC,b.id DESC",[$id,$term['id']]);
    $h=documentTitle('บันทึกพฤติกรรมของฉัน','แสดงเฉพาะเรื่องที่ครูเปิดเผยให้คุณทราบ').termFilter($term);
    if(!$items)return $h.emptyState('ไม่มีบันทึกที่เปิดเผยในภาคเรียนนี้','flag');
    $h.='<div class="stack">';
    foreach($items as $r){$h.='<article class="card listing"><div class="news-meta"><small>'.esc(thDate($r['occurred_on'],false)).'</small>'.badge($r['state']==='resolved'?'แก้ไขแล้ว':'อยู่ระหว่างดำเนินการ',$r['state']==='resolved'?'good':'gold').'</div><h3>'.esc($r['title']).'</h3><p class="pre">'.esc($r['details']).'</p></article>';}
    return $h.'</div>';
}
function v32StudentProfile(array $u): string {
    $h=documentTitle('บัญชีของฉัน','แก้ชื่อผู้ใช้ รหัสผ่าน รูป และข้อมูลติดต่อได้เอง');
    $h.='<section class="card profile-head">'.avatar($u,'lg').'<div><h2>'.esc(fullname($u)).'</h2><p class="muted">รหัส '.esc($u['student_code']).' · '.esc($u['level_name']).'</p>'.
        '<p class="muted small">เข้าสู่ระบบล่าสุด '.esc(thDate($u['last_login'])).'</p></div></section>';
    $h.='<div class="grid two"><section class="card section"><h2>ข้อมูลบัญชีและการติดต่อ</h2>'.formStart('/profile.php').hidden('action','profile') .
      usernameField($u['username']) .
      field('phone','เบอร์โทรศัพท์','tel',$u['phone']??'',false,'maxlength="35" autocomplete="tel" inputmode="tel"') .
      field('line_contact','LINE ID','text',$u['line_contact']??'',false,'maxlength="100"') .
      field('current_password','รหัสผ่านปัจจุบัน','password','',true,'autocomplete="current-password"','ใส่เพื่อยืนยันว่าเป็นเจ้าของบัญชี') .
      btn('บันทึกข้อมูล') . '</form></section>';
    $h.='<div class="stack"><details class="card panel"><summary>'.icon('key',18).' เปลี่ยนรหัสผ่าน</summary>'.formStart('/profile.php').hidden('action','password') .
      field('current_password','รหัสผ่านปัจจุบัน','password','',true,'autocomplete="current-password"') . pwdFields().btn('เปลี่ยนรหัสผ่าน').'</form></details>';
    $h.='<details class="card panel"><summary>'.icon('user',18).' เปลี่ยนรูปโปรไฟล์</summary>'.formStart('/profile.php',true).hidden('action','avatar').uploadBox('avatar','เลือกรูปภาพ',true,true).btn('บันทึกรูป').'</form></details>';
    $h.='<div class="callout">'.icon('info',20).'<div><b>ชื่อจริงหรือรหัสนักศึกษาไม่ถูกต้อง?</b><p>ข้อมูลส่วนนี้ครูเป็นผู้แก้ไข โปรดแจ้งครูผู้ดูแลผ่านช่องทางของศูนย์</p></div></div></div></div>';
    return $h;
}
function v32StudentAction(string $route,int $id): never {
    if($route==='/renew.php') {
        $choice=choice('decision',['continue','decline']);
        $level=choice('level_name',LEVELS);
        $t=currentTerm();
        tx(function()use($id,$choice,$level,$t){
            student($id,true);
            $old=v32Enrollment($id,(int)$t['id']);
            if($old && $old['status']==='approved') fail(409,'คุณได้รับอนุมัติในภาคเรียนนี้แล้ว');
            if(enrollmentClosedByTeacher($old)) fail(409,'ครูปิดการลงทะเบียนของคุณในภาคเรียนนี้แล้ว หากคิดว่าผิดพลาดโปรดติดต่อครู');
            run("INSERT INTO term_enrollments(student_id,term_id,level_name,status,requested_at,reviewed_at,reviewed_by)
                VALUES(?,?,?,?,NOW(),NULL,NULL)
                ON DUPLICATE KEY UPDATE level_name=VALUES(level_name),status=VALUES(status),requested_at=NOW(),reviewed_at=NULL,reviewed_by=NULL",
                [$id,$t['id'],$level,$choice==='continue'?'pending':'declined']);
            audit($id,'enrollment.request',$id,(int)$t['id'],['decision'=>$choice,'level'=>$level]);
        });
        flash($choice==='continue'?'ส่งคำขอเรียนต่อให้ครูแล้ว':'บันทึกว่าไม่ได้เรียนต่อแล้ว');redirect('/dashboard.php');
    }
    if($route==='/profile.php'){
        $action=choice('action',['profile','password','avatar']);
        if($action==='avatar') {
            tx(function()use($id){student($id,true);$image=saveUpload('avatar',$id,true,true);run('UPDATE users SET avatar_id=? WHERE id=?',[$image,$id]);audit($id,'profile.avatar',$id,$id);});
        }else{
            $old=row("SELECT password_hash,username FROM users WHERE id=? AND role='student'",[$id])??fail(404);
            $password=post('current_password',256);
            limitAttempts('profile-password',clientIp().'|'.$id,15,900);
            if(!verifyStoredPassword($password,(string)$old['password_hash']))fail(403,'รหัสผ่านปัจจุบันไม่ถูกต้อง');
            if($action==='password'){
                $hash=newPassword();
                tx(function()use($id,$hash){run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[$hash,$id]);audit($id,'profile.password',$id,$id);});
                $_SESSION['version']=(int)$_SESSION['version']+1; session_regenerate_id(true);
            } else {
                $name=username();$phone=post('phone',35,false);$line=post('line_contact',100,false);
                tx(function()use($id,$name,$phone,$line){run('UPDATE users SET username=?,phone=?,line_contact=? WHERE id=?',[$name,$phone,$line,$id]);audit($id,'profile.update',$id,$id);});
            }
        }
        flash('บันทึกข้อมูลเรียบร้อยแล้ว');redirect('/profile.php');
    }
    if($route==='/announcements.php' && ($_POST['action']??'')==='ack') {
        if(!v32Feature('announcement_ack'))fail(403);
        $aid=number($_POST['announcement_id']??null);
        $term=currentTerm();
        $ok=val('SELECT id FROM announcements WHERE id=? AND publish_on<=CURDATE() AND (term_id=? OR term_id IS NULL)',[$aid,$term['id']]);
        if(!$ok)fail(404);
        run('INSERT IGNORE INTO announcement_reads(announcement_id,student_id) VALUES(?,?)',[$aid,$id]);
        flash('รับทราบประกาศแล้ว');redirect('/announcements.php');
    }
    fail(405);
}
function v32CheckSelectedTermStudents(int $term, array $ids): void {
    if(!$ids)fail(422,'กรุณาเลือกนักศึกษาอย่างน้อย 1 คน');
    $found=v32EnrolledStudents($term);
    $valid=array_map(static fn($s)=>(int)$s['id'],$found);
    if(array_diff($ids,$valid))fail(422,'นักศึกษาบางคนไม่ได้ลงทะเบียนหรือยังไม่ผ่านอนุมัติในภาคเรียนที่เลือก');
}
function v32TeacherAction(string $route,int $teacher): never {
    switch($route){
        case '/enrollments.php':
            $term=number($_POST['term_id']??null);requireTerm($term);
            // V3.3.2: one-tap buttons per row (quick=approved:ID) or the bulk buttons for ticked rows.
            $quick=is_string($_POST['quick']??null)?$_POST['quick']:'';
            if(preg_match('/^(approved|declined):([1-9][0-9]{0,18})$/D',$quick,$m)){$decision=$m[1];$ids=[(int)$m[2]];}
            else{$ids=selectedStudentsV31($_POST['student_ids']??[]);$decision=choice('decision',['approved','declined']);}
            $done=tx(function()use($term,$ids,$decision,$teacher){
                $n=0;
                foreach($ids as $id){
                    student($id,true);
                    $entry=row('SELECT * FROM term_enrollments WHERE student_id=? AND term_id=? FOR UPDATE',[$id,$term]);
                    if(!$entry)fail(422,'ไม่พบคำขอลงทะเบียน');
                    if($entry['status']!=='pending')continue; // already decided (e.g. from another tab): never re-open it
                    run('UPDATE term_enrollments SET status=?,reviewed_at=NOW(),reviewed_by=? WHERE student_id=? AND term_id=?',[$decision,$teacher,$id,$term]);
                    if($decision==='approved') run('UPDATE users SET level_name=? WHERE id=?',[$entry['level_name'],$id]);
                    audit($teacher,'enrollment.review',$id,$term,['status'=>$decision]);
                    notifyStudent($id,$decision==='approved'?'ครูอนุมัติการลงทะเบียนแล้ว เริ่มเรียนได้เลย':'คำขอลงทะเบียนไม่ได้รับอนุมัติ โปรดติดต่อครู',$decision==='approved'?'/dashboard.php':'/renew.php',$term);
                    $n++;
                }
                return $n;
            });
            flash($done?($decision==='approved'?'อนุมัติแล้ว ':'ไม่อนุมัติแล้ว ').$done.' คน · รายชื่อที่ดำเนินการแล้วจะไม่แสดงในหน้านี้อีก':'รายชื่อที่เลือกได้รับการดำเนินการไปแล้ว');
            redirect('/enrollments.php?term='.$term);
        case '/students.php':
            $term=number($_POST['term_id']??null);requireTerm($term);
            $ids=selectedStudentsV31($_POST['student_ids']??[]);
            teacherConfirmV31($teacher);
            tx(function()use($teacher,$ids,$term){
                foreach($ids as $id){student($id,true);$entry=v32Enrollment($id,$term);if(!$entry)fail(422,'นักศึกษาไม่อยู่ในภาคเรียน');
                    run("UPDATE term_enrollments SET status='inactive',reviewed_by=?,reviewed_at=NOW() WHERE student_id=? AND term_id=?",[$teacher,$id,$term]);
                    audit($teacher,'enrollment.inactive',$id,$term);
                }
            });
            flash('นำออกจากภาคเรียน '.count($ids).' คนแล้ว โดยเก็บประวัติเดิม');redirect('/students.php?term='.$term);
        case '/student.php':
            $id=number($_GET['id']??null);
            $action=choice('action',['term_status']);
            $term=number($_POST['term_id']??null);requireTerm($term);
            $status=choice('status',['approved','inactive','declined']);
            tx(function()use($teacher,$id,$term,$status){
                student($id,true);
                run('UPDATE term_enrollments SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE student_id=? AND term_id=?',[$status,$teacher,$id,$term]);
                if(!val('SELECT id FROM term_enrollments WHERE student_id=? AND term_id=?',[$id,$term]))fail(422,'ไม่พบข้อมูลลงทะเบียน');
                audit($teacher,'enrollment.status',$id,$term,['to'=>$status]);
            });
            flash('เปลี่ยนสถานะในภาคเรียนแล้ว');redirect('/student.php?id='.$id.'&term='.$term);
        case '/terms.php':
            $action=choice('action',['settings','delete']);
            $term=number($_POST['term_id']??null);requireTerm($term);
            if($action==='settings'){
                $days=number($_POST['expected_days']??null,1,366);
                $minimum=number($_POST['min_attendance']??null,0,$days);
                $points=number($_POST['min_points']??null,0,200);
                tx(function()use($teacher,$term,$days,$minimum,$points){
                    run('UPDATE terms SET expected_days=?,min_attendance=?,min_points=? WHERE id=?',[$days,$minimum,$points,$term]);
                    audit($teacher,'term.criteria',null,$term,['days'=>$days,'minimum'=>$minimum,'points'=>$points]);
                });
                flash('บันทึกเกณฑ์ภาคเรียนแล้ว');redirect('/terms.php?term='.$term);
            }
            teacherConfirmV31($teacher);
            tx(function()use($teacher,$term){
                if((int)currentTerm()['id']===$term)fail(422,'ไม่สามารถลบภาคเรียนปัจจุบัน');
                foreach(['term_enrollments','score_entries','work_items','attendance_sessions','behavior_events'] as $table){
                    if((int)val("SELECT COUNT(*) FROM $table WHERE term_id=?",[$term])>0)fail(422,'ภาคเรียนนี้มีประวัติจึงไม่สามารถลบได้');
                }
                if((int)val('SELECT COUNT(*) FROM notices WHERE term_id=?',[$term])>0 || (int)val('SELECT COUNT(*) FROM announcements WHERE term_id=?',[$term])>0) fail(422,'ภาคเรียนนี้มีประกาศหรือแจ้งเตือนอยู่');
                run('DELETE FROM terms WHERE id=?',[$term]);audit($teacher,'term.delete',null,$term);
            });
            flash('ลบภาคเรียนว่างแล้ว');redirect('/terms.php');
        case '/behavior.php':
            $action=choice('action',['save','delete']);
            if($action==='delete'){
                $id=number($_POST['event_id']??null);teacherConfirmV31($teacher);
                tx(function()use($teacher,$id){
                    row('SELECT id FROM behavior_events WHERE id=? FOR UPDATE',[$id])??fail(404);
                    run('DELETE FROM behavior_students WHERE event_id=?',[$id]);run('DELETE FROM behavior_events WHERE id=?',[$id]);
                    audit($teacher,'behavior.delete',null,$id);
                });
                flash('ลบบันทึกพฤติกรรมแล้ว');redirect('/behavior.php');
            }
            $term=number($_POST['term_id']??null);requireTerm($term);
            $id=isset($_POST['event_id']) && $_POST['event_id']!=='' ? number($_POST['event_id']) : null;
            $title=post('title',200);$details=post('details',12000);
            $day=dateInput('occurred_on',true);
            $visibility=choice('visibility',['private','student']);
            $state=choice('state',['open','resolved']);
            $ids=selectedStudentsV31($_POST['student_ids']??[]);
            v32CheckSelectedTermStudents($term,$ids);
            tx(function()use($teacher,$term,$id,$title,$details,$day,$visibility,$state,$ids){
                if($id){
                    $old=row('SELECT id FROM behavior_events WHERE id=? AND term_id=? FOR UPDATE',[$id,$term])??fail(404);
                    run('UPDATE behavior_events SET title=?,details=?,occurred_on=?,visibility=?,state=? WHERE id=?',[$title,$details,$day,$visibility,$state,$id]);
                    run('DELETE FROM behavior_students WHERE event_id=?',[$id]);
                }else{
                    run('INSERT INTO behavior_events(term_id,teacher_id,title,details,occurred_on,visibility,state) VALUES(?,?,?,?,?,?,?)',[$term,$teacher,$title,$details,$day,$visibility,$state]);
                    $id=inserted();
                }
                foreach($ids as $studentId){run('INSERT INTO behavior_students(event_id,student_id) VALUES(?,?)',[$id,$studentId]);}
                audit($teacher,'behavior.save',null,$id,['students'=>count($ids),'visibility'=>$visibility]);
            });
            flash('บันทึกเหตุการณ์พฤติกรรมแล้ว');redirect('/behavior.php?term='.$term);
        case '/roll.php':
            $id=number($_GET['id']??null);
            $action=choice('action',['no_class']);
            tx(function()use($id,$teacher){
                $session=row('SELECT * FROM attendance_sessions WHERE id=? FOR UPDATE',[$id])??fail(404);
                $value=isset($_POST['no_class']) ? 1 : 0;
                run('UPDATE attendance_sessions SET no_class=? WHERE id=?',[$value,$id]);
                audit($teacher,'attendance.no_class',null,$id,['value'=>$value]);
            });
            flash('อัปเดตสถานะวันงดเรียนแล้ว');redirect('/roll.php?id='.$id);
        case '/announcements.php':
            fail(405); // The original announcement handler handles create/delete and safely scopes new posts.
        case '/feature-settings.php':
            $allowed=['issue_reporting','assignment_upload','announcement_ack'];
            tx(function()use($allowed,$teacher){
                foreach($allowed as $flag){
                    run('INSERT INTO feature_flags(flag_name,enabled) VALUES(?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)',[$flag,(int)isset($_POST[$flag])]);
                }
                audit($teacher,'feature.settings',null,null);
            });
            flash('บันทึกการตั้งค่าแล้ว');redirect('/feature-settings.php');
        default: fail(405);
    }
}
function v32TeacherEnrollments(array $term): string {
    // V3.3.2: only requests that still need a decision are listed. Approved, declined and removed
    // students leave this page for good (they remain in the student directory and history).
    $records=array_values(array_filter(v32EnrolledStudents((int)$term['id'],null,false),static fn($x)=>$x['status']==='pending'));
    $h=documentTitle('อนุมัติการลงทะเบียน',termName($term),iconLink('/students.php?term='.(int)$term['id'].'&status=approved','users','รายชื่อที่อนุมัติแล้ว','button secondary')).termFilter($term);
    if(!$records){
        return $h.'<div class="callout good">'.icon('check',22).'<div><b>ไม่มีคำขอที่รออนุมัติ</b><p>รายชื่อที่อนุมัติ ไม่อนุมัติ หรือนำออกแล้วจะไม่แสดงที่หน้านี้อีก ดูผู้เรียนทั้งหมดได้ที่ “รายชื่อนักศึกษา”</p></div></div>';
    }
    $h.='<div class="callout gold">'.icon('clock',22).'<div><b>รออนุมัติ '.count($records).' คน</b><p>กด “อนุมัติ” หรือ “ไม่อนุมัติ” ทีละคน หรือติ๊กหลายคนแล้วใช้ปุ่มด้านล่าง ดำเนินการแล้วรายชื่อจะหายจากหน้านี้</p></div></div>';
    $h.='<section class="card section">'.formStart('/enrollments.php',false,'v32-enroll').hidden('term_id',$term['id']) .
        '<div class="toolbar"><input type="search" class="search" placeholder="ค้นหาชื่อ / รหัส" data-roster-search aria-label="ค้นหาชื่อหรือรหัส">' .
        '<button type="button" class="button secondary small" data-check="all">เลือกทั้งหมด</button><button type="button" class="button ghost small" data-check="none">ล้างที่เลือก</button></div>' .
        '<div class="v32-checklist enroll-list">';
    foreach($records as $s){
        $id=(int)$s['id'];
        $h.='<div class="v32-choice enroll-row" data-level="'.esc($s['level_name']).'" data-status="pending"><label class="enroll-pick"><input type="checkbox" name="student_ids[]" value="'.$id.'">' .
            '<span><strong>'.esc(fullname($s)).'</strong><small>รหัส '.esc($s['student_code']).' · '.esc($s['username']).' · '.esc($s['level_name']).' · ส่งเมื่อ '.esc(thDate($s['requested_at'])).'</small></span></label>' .
            '<span class="row-actions"><button type="submit" class="button primary small" name="quick" value="approved:'.$id.'">อนุมัติ</button>' .
            '<button type="submit" class="button ghost small" name="quick" value="declined:'.$id.'" data-confirm-delete="ไม่อนุมัติคำขอของ '.esc(fullname($s)).' ใช่หรือไม่?">ไม่อนุมัติ</button></span></div>';
    }
    $h.='</div><div class="sticky-actions"><span class="muted" data-checked-count>ยังไม่ได้เลือก</span>' .
        '<button type="submit" class="button primary" name="decision" value="approved">'.icon('check',18).'<span>อนุมัติที่เลือก</span></button>' .
        '<button type="submit" class="button ghost" name="decision" value="declined" data-confirm-delete="ไม่อนุมัติรายชื่อที่เลือกทั้งหมดใช่หรือไม่?">ไม่อนุมัติที่เลือก</button></div></form></section>';
    return $h;
}
function v32TeacherBehavior(array $term): string {
    $rows=rows("SELECT b.*,u.first_name,u.last_name FROM behavior_events b JOIN users u ON u.id=b.teacher_id WHERE b.term_id=? ORDER BY b.occurred_on DESC,b.id DESC",[$term['id']]);
    $editing=isset($_GET['edit'])?number($_GET['edit']):null;
    $old=$editing?row('SELECT * FROM behavior_events WHERE id=? AND term_id=?',[$editing,$term['id']]):null;
    if($editing && !$old)fail(404);
    $selected=$old?array_map('intval',array_column(rows('SELECT student_id FROM behavior_students WHERE event_id=?',[$editing]),'student_id')):[];
    $h=documentTitle('บันทึกพฤติกรรมนักศึกษา','บันทึกได้หลายคนต่อเหตุการณ์ · ค่าเริ่มต้นเห็นเฉพาะครู').termFilter($term);
    $h.='<details class="card panel"'.($old?' open':'').'><summary>'.icon($old?'flag':'plus',18).' '.($old?'แก้ไขเหตุการณ์':'เพิ่มบันทึกพฤติกรรม').'</summary>'.formStart('/behavior.php',false,'v32-behavior').
        hidden('action','save').hidden('term_id',$term['id']).($editing?hidden('event_id',$editing):'') .
        field('title','ชื่อเหตุการณ์','text',$old['title']??'',true,'maxlength="200"') .
        area('details','รายละเอียด',true,(string)($old['details']??'')) .
        '<div class="pair">'.field('occurred_on','วันที่เกิดเหตุการณ์','date',$old['occurred_on']??date('Y-m-d')).
        selectBox('state','สถานะ',['open'=>'ระหว่างดำเนินการ','resolved'=>'แก้ไขแล้ว'],$old['state']??'open').'</div>' .
        selectBox('visibility','ใครเห็นได้',['private'=>'เฉพาะครู','student'=>'ให้นักศึกษาที่เกี่ยวข้องเห็น'],$old['visibility']??'private') .
        '<p class="label">เลือกนักศึกษาที่เกี่ยวข้อง</p><input data-roster-search type="search" placeholder="ค้นหาชื่อ / รหัส" aria-label="ค้นหาชื่อหรือรหัส" class="search">' .
        '<div class="v32-checklist">'.v32RosterChoices((int)$term['id'],'student_ids','v32-behavior',$selected).'</div>'.
        '<div class="actions"><button type="submit" class="button primary">บันทึกเหตุการณ์</button>'.($old?linkTo('/behavior.php?term='.$term['id'],'ยกเลิก','button ghost'):'').'</div></form></details>';
    if(!$rows)return $h.emptyState('ยังไม่มีบันทึกในภาคเรียนนี้','flag');
    $h.='<div class="stack">';
    foreach($rows as $r){
        $people=rows('SELECT u.first_name,u.last_name FROM behavior_students bs JOIN users u ON u.id=bs.student_id WHERE bs.event_id=?',[$r['id']]);
        $names=implode(', ',array_map('fullname',$people));
        $h.='<article class="card listing"><div class="news-meta"><small>'.esc(thDate($r['occurred_on'],false)).' · บันทึกโดย '.esc(fullname($r)).'</small>'.
            badge($r['state']==='resolved'?'แก้ไขแล้ว':'ระหว่างดำเนินการ',$r['state']==='resolved'?'good':'gold').badge($r['visibility']==='private'?'เฉพาะครู':'นักศึกษาเห็นได้',$r['visibility']==='private'?'':'blue').'</div>'.
            '<h3>'.esc($r['title']).'</h3><p class="muted">'.icon('users',16).' '.esc($names?:'—').'</p><p class="pre">'.esc($r['details']).'</p>'.
            '<div class="row-actions">'.linkTo('/behavior.php?term='.$term['id'].'&edit='.$r['id'],'แก้ไข','button secondary small').
            '<details class="inline-confirm"><summary class="button ghost small danger-text">ลบ</summary>'.formStart('/behavior.php').hidden('action','delete').hidden('event_id',$r['id']).
            field('teacher_password','รหัสผ่านครูเพื่อยืนยัน','password','',true,'autocomplete="current-password"') .
            '<button type="submit" class="button danger small" data-confirm-delete="ลบประวัติเหตุการณ์นี้หรือไม่?">ยืนยันลบ</button></form></details></div></article>';
    }
    return $h.'</div>';
}
function v32TeacherFeatures(): string {
    $flags=rows('SELECT * FROM feature_flags ORDER BY flag_name');$on=array_column($flags,'enabled','flag_name');
    return documentTitle('ตั้งค่าฟีเจอร์เสริม','เปิด / ปิดความสามารถตามแนวทางของสถานศึกษา') . '<section class="card section">'.formStart('/feature-settings.php') .
      '<div class="setting">'.checkbox('assignment_upload','ระบบส่งงานผ่านเว็บ',(bool)($on['assignment_upload']??1)).'<p class="hint">นักศึกษาแนบไฟล์และส่งงานจากมือถือได้ ถ้าปิด นักศึกษาจะเห็นงานแต่ส่งผ่านเว็บไม่ได้</p></div>' .
      '<div class="setting">'.checkbox('announcement_ack','ให้นักศึกษากดรับทราบประกาศ',(bool)($on['announcement_ack']??1)).'<p class="hint">แสดงปุ่ม “รับทราบประกาศ” ใต้ประกาศแต่ละรายการ</p></div>' .
      '<div class="setting off"><b>ระบบแจ้งข้อมูลผิดพลาด</b><p class="hint">ยังไม่เปิดใช้งานในรุ่นนี้ ให้นักศึกษาติดต่อครูทาง LINE ตามปกติ</p></div>' .
      btn('บันทึกการตั้งค่า').'</form></section>';
}
function v32ReportData(int $termId,?int $onlyStudent=null,?string $level=null): array {
    $term=row('SELECT * FROM terms WHERE id=?',[$termId])??fail(404);
    $data=[];
    foreach(reportStudents($termId,$onlyStudent,$level) as $s){
        $id=(int)$s['id'];
        $points=scoreTotal($id,$termId);
        $a=v32AttendanceStats($id,$termId);
        $cw=classworkSummary($id,$termId);
        $acts=(int)val("SELECT COUNT(*) FROM submissions s JOIN work_items w ON w.id=s.item_id WHERE s.student_id=? AND w.term_id=? AND w.kind='activity' AND s.status='passed'",[$id,$termId]);
        $passed=$a['present'] >= (int)$term['min_attendance'] && $points >= (int)$term['min_points'];
        $data[]=['id'=>$id,'code'=>(string)$s['student_code'],'name'=>fullname($s),'username'=>(string)$s['username'],'level'=>(string)$s['level_name'],'gender'=>(string)($s['gender']??''),
            'status'=>v32EnrollmentText($s['status']),'present'=>$a['present'],'leave'=>$a['leave'],'absent'=>$a['absent'],'points'=>$points,
            'classwork'=>$cw['got'],'classwork_max'=>$cw['max'],'activities'=>$acts,'pass'=>$passed?'ผ่านเกณฑ์ตัวเลข':'ยังไม่ผ่านเกณฑ์ตัวเลข'];
    }
    return [$term,$data];
}
function v32Report(?array $u): never {
    if(!$u)fail(401);
    $teacher=$u['role']==='teacher';
    $termId=isset($_GET['term'])?number($_GET['term']):(int)currentTerm()['id'];
    $studentId=!$teacher?(int)$u['id']:(isset($_GET['student'])&&$_GET['student']!==''?number($_GET['student']):null);
    if(!$teacher && !v32Enrollment($studentId,$termId))fail(403,'ไม่มีข้อมูลของคุณในภาคเรียนนี้');
    $level=$teacher?arg($_GET,'level',70,false):'';
    if($level!=='' && !in_array($level,LEVELS,true))fail(422,'ระดับชั้นไม่ถูกต้อง');
    $format=(string)($_GET['format']??'html');
    $term=row('SELECT * FROM terms WHERE id=?',[$termId])??fail(404);
    $base='sgr-'.(int)$term['school_year'].'-'.(int)$term['semester'].($level!==''?'-level'.((int)array_search($level,LEVELS,true)+1):'');
    if($format==='xlsx'){
        if(!$teacher)fail(403);
        xlsxSend($base.'.xlsx',termWorkbook($term,$level!==''?$level:null));
    }
    [$term,$data]=v32ReportData($termId,$studentId,$level!==''?$level:null);
    if($format==='csv'){
        if(!$teacher)fail(403);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$base.'.csv"');
        echo "\xEF\xBB\xBF";
        $handle=fopen('php://output','w');
        fputcsv($handle,['รหัสนักศึกษา','ชื่อ-นามสกุล','ระดับชั้น','สถานะลงทะเบียน','มา','ลา','ขาด','คะแนน กพช.','คะแนนงานในห้อง','คะแนนเต็มงาน','กิจกรรมที่เข้าร่วม','ประเมินตามเกณฑ์']);
        foreach($data as $d){
            $cells=[$d['code'],$d['name'],$d['level'],$d['status'],$d['present'],$d['leave'],$d['absent'],$d['points'],$d['classwork'],$d['classwork_max'],$d['activities'],$d['pass']];
            // Protect Excel/LibreOffice formula evaluation of user-controlled text.
            foreach($cells as &$cell){if(is_string($cell)&&preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',$cell))$cell="'".$cell;}
            unset($cell);
            fputcsv($handle,$cells);
        }
        fclose($handle);exit();
    }
    $each=!$teacher || $studentId!==null || ($_GET['mode']??'')==='each';
    $v=esc(APP_VERSION);
    $back=$teacher?($studentId!==null?'/student.php?id='.$studentId.'&term='.(int)$term['id']:'/reports.php?term='.(int)$term['id']):'/history.php?term='.(int)$term['id'];
    if($each){
        $content='';
        foreach($data as $d){$content.=studentReportSheet($term,$d,$teacher);}
        if($content==='')$content='<p class="empty">ไม่มีข้อมูลในภาคเรียนนี้</p>';
    }else{
        $table=[];
        foreach($data as $d){$table[]=array_map(static fn($x)=>esc((string)$x),[$d['code'],$d['name'],$d['level'],$d['present'],$d['leave'],$d['absent'],$d['points'],$d['classwork'].' / '.$d['classwork_max'],$d['activities'],$d['pass']]);}
        $content='<header><p>SGR Library · ศกร.ระดับตำบลบ้านใหม่</p><h1>รายงานผลการศึกษา · '.esc(termName($term)).($level!==''?' · '.esc($level):'').'</h1>' .
            '<p>เกณฑ์วันมาเรียน '.(int)$term['min_attendance'].' / '.(int)$term['expected_days'].' วัน · คะแนน กพช. ขั้นต่ำ '.(int)$term['min_points'].' คะแนน · คะแนนงานในห้องไม่นับรวมใน กพช. · พิมพ์เมื่อ '.esc(thDate(date('Y-m-d H:i:s'))).'</p></header>' .
            tableView(['รหัส','นักศึกษา','ระดับ','มา','ลา','ขาด','กพช.','คะแนนงาน','กิจกรรม','สถานะตามเกณฑ์'],$table,'ไม่มีข้อมูลในภาคเรียนนี้');
    }
    // CSP forbids inline <style> and onclick, so the report uses its own stylesheet and site.js handles [data-print].
    $html='<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>รายงาน SGR '.esc(termName($term)).'</title>' .
        '<link rel="stylesheet" href="/assets/report.css?v='.$v.'"><script src="/assets/site.js?v='.$v.'" defer></script></head><body class="report'.($each?' each':'').'">' .
        '<div class="print-bar"><button type="button" data-print>พิมพ์ / บันทึกเป็น PDF</button><a href="'.esc($back).'">กลับ</a>' .
        ($teacher && $studentId===null?'<span>'.($each?'แยกรายคน '.count($data).' หน้า':'ตารางรวม '.count($data).' คน').'</span>':'').'</div>' .
        $content .
        '<p class="foot">จัดทำโดย SGR Library V'.$v.' · สถานะผ่านเกณฑ์เป็นการประเมินเบื้องต้นจากตัวเลข ให้ครูรับรองผลตามระเบียบจริง</p></body></html>';
    header('Content-Type: text/html; charset=UTF-8');echo $html;exit();
}
function v32TeacherReports(array $term): string {
    $tid=(int)$term['id'];
    $h=documentTitle('รายงานและส่งออก','ส่งออกเป็น Excel หรือพิมพ์ / บันทึกเป็น PDF · แยกระดับชั้นได้').termFilter($term);
    [$t,$data]=v32ReportData($tid);
    $pass=count(array_filter($data,static fn($x)=>$x['pass']==='ผ่านเกณฑ์ตัวเลข'));
    $h.='<div class="stats">';
    foreach(LEVELS as $level){$h.='<div class="stat"><span>'.esc($level).'</span><strong>'.count(array_filter($data,static fn($x)=>$x['level']===$level)).'</strong><small>คน</small></div>';}
    $h.='<div class="stat good"><span>ผ่านเกณฑ์ตัวเลข</span><strong>'.$pass.'</strong><small>จาก '.count($data).' คน</small></div></div>';
    $levels=[''=>'ทุกระดับชั้น']+array_combine(LEVELS,LEVELS);
    $h.='<div class="grid two"><section class="card section export-card"><div class="export-head"><span class="export-icon xl">'.icon('download',24).'</span><div><h2>Excel (.xlsx)</h2>' .
        '<p class="muted">ไฟล์เดียว 5 แผ่นงาน: สรุปผล · คะแนนงานในห้อง · กิจกรรม (กพช.) · ประวัติคะแนน กพช. · การมาเรียน</p></div></div>' .
        '<form method="get" action="/report.php" class="export-form">'.hidden('term',$tid).hidden('format','xlsx').selectBox('level','ระดับชั้น',$levels).btn('ดาวน์โหลด Excel','primary').'</form>' .
        '<p class="small">'.linkTo('/report.php?term='.$tid.'&format=csv','หรือดาวน์โหลด CSV (ตารางสรุปอย่างเดียว)','textlink').'</p></section>';
    $h.='<section class="card section export-card"><div class="export-head"><span class="export-icon pdf">'.icon('print',24).'</span><div><h2>PDF / พิมพ์</h2>' .
        '<p class="muted">เปิดหน้ารายงานแล้วกด “พิมพ์ / บันทึกเป็น PDF” เลือกได้ทั้งแบบรวมและแยกรายคน</p></div></div>' .
        '<form method="get" action="/report.php" target="_blank" class="export-form">'.hidden('term',$tid) .
        selectBox('mode','รูปแบบ',['summary'=>'รวมทุกคน (ตารางเดียว)','each'=>'แยกรายคน (คนละหน้า)']) .
        selectBox('level','ระดับชั้น',$levels).btn('เปิดหน้ารายงาน','primary').'</form>' .
        '<p class="small muted">พิมพ์รายงานของนักศึกษาคนเดียวได้จากหน้าข้อมูลนักศึกษา</p></section></div>';
    $h.='<section class="card section"><h2>ดูข้อมูลบนหน้าจอ</h2><div class="tiles three">' .
        '<a class="tile" href="/gradebook.php?term='.$tid.'"><span class="tile-icon">'.icon('grade',22).'</span><span>คะแนนงานในห้อง</span></a>' .
        '<a class="tile" href="/activity-log.php?term='.$tid.'"><span class="tile-icon">'.icon('history',22).'</span><span>บันทึกกิจกรรม</span></a>' .
        '<a class="tile" href="/scores.php?term='.$tid.'"><span class="tile-icon">'.icon('star',22).'</span><span>คะแนน กพช.</span></a></div></section>';
    return $h;
}
function v32StudentReportLink(array $u): string {
    return '<div class="actions">'.linkTo('/report.php?term='.selectedTerm()['id'],'พิมพ์ / บันทึก PDF','button secondary').'</div>';
}
function v32MorePage(string $role): string {
    $teacher=$role==='teacher';
    $u=$GLOBALS['u']??null;
    [$groups]=navMenu($teacher,is_array($u)?$u:null);
    if(!$teacher){$groups['ข่าวสารและบัญชี'][]=['/renew.php','check','ลงทะเบียน / เรียนต่อ',0];}
    $h=documentTitle('เมนูทั้งหมด',$teacher?'เครื่องมือสำหรับครู':'บริการสำหรับนักศึกษา');
    if(is_array($u)){$h.='<a class="card profile-head link" href="'.($teacher?'/account.php':'/profile.php').'">'.avatar($u,'lg').'<div><h2>'.esc(fullname($u)).'</h2><p class="muted">'.esc($teacher?'ครูผู้ดูแล · '.$u['username']:'รหัส '.$u['student_code'].' · '.$u['level_name']).'</p></div>'.icon('chevron',20).'</a>';}
    foreach($groups as $heading=>$links){
        $h.='<section class="menu-group"><h2>'.esc($heading!==''?$heading:'หลัก').'</h2><div class="menu-list card">';
        foreach($links as [$url,$ic,$name,$n]){$h.='<a href="'.esc($url).'"><span class="tile-icon">'.icon($ic,20).'</span><span>'.esc($name).'</span>'.countDot((int)$n).icon('chevron',18).'</a>';}
        $h.='</div></section>';
    }
    return $h.formStart('/logout.php').'<button type="submit" class="button ghost block">'.icon('logout',18).'<span>ออกจากระบบ</span></button></form>';
}
function v32ActivityRosterForm(array $item): string {
    $term=(int)$item['term_id'];
    $students=v32EnrolledStudents($term);
    if(!$students)return '';
    $data=[];$count=0;
    foreach($students as $s){
       $exists=val('SELECT status FROM submissions WHERE student_id=? AND item_id=?',[$s['id'],$item['id']]);
       $checked=$exists==='passed'?' checked':'';if($checked)$count++;
       $data[]='<label class="v32-choice"><input type="checkbox" form="v32-activity-form" name="student_ids[]" value="'.(int)$s['id'].'"'.$checked.'><span><strong>'.esc(fullname($s)).'</strong><small>'.esc($s['student_code']).' · '.esc($s['level_name']).'</small></span>'.($exists==='passed'?badge('เข้าร่วมแล้ว','good'):($exists==='submitted'?badge('ส่งหลักฐานแล้ว','gold'):'')).'</label>';
    }
    return '<details class="card panel" id="participants"'.($count===0?' open':'').'><summary>'.icon('users',18).' บันทึกผู้เข้าร่วมกิจกรรม (ตอนนี้ '.$count.' คน)</summary><p class="muted">ติ๊กนักศึกษาที่เข้าร่วมแล้วกดบันทึก คนที่ติ๊กจะได้ '.(int)$item['points'].' คะแนน กพช. และขึ้นใน “บันทึกกิจกรรม” · ถ้าเอาติ๊กออก ระบบจะคืนคะแนนที่เคยให้จากกิจกรรมนี้</p>' .
       formStart('/item.php?id='.(int)$item['id'],false,'v32-activity-form') .
       hidden('action','activity_roster').'<div class="toolbar"><input class="search" type="search" data-roster-search placeholder="ค้นหาชื่อ/รหัส" aria-label="ค้นหารายชื่อ"><button type="button" class="button secondary small" data-check="all">เลือกทั้งหมด</button><button type="button" class="button ghost small" data-check="none">ล้าง</button></div>' .
       '<div class="v32-checklist">'.implode('',$data).'</div><div class="sticky-actions"><span class="muted" data-checked-count>เลือกแล้ว '.$count.' คน</span>'.btn('บันทึกผู้เข้าร่วมกิจกรรม').'</div></form></details>';
}
function v32ActivityRosterAction(int $teacher,int $itemId): never {
    $ids=selectedCheckboxIdsV31($_POST['student_ids']??[]);
    $work=row("SELECT id,kind,points,term_id,title FROM work_items WHERE id=?",[$itemId])??fail(404);
    if($work['kind']!=='activity')fail(422,'เลือกใช้เฉพาะกิจกรรม');
    $all=v32EnrolledStudents((int)$work['term_id']);
    $valid=array_map(static fn($s)=>(int)$s['id'],$all);
    if(array_diff($ids,$valid))fail(422,'พบรายชื่อนักศึกษาที่ไม่อยู่ในภาคเรียนนี้');
    tx(function()use($ids,$valid,$teacher,$work){
       foreach($valid as $id){
         student($id,true);
         $existing=row('SELECT id,status,credited,review_version FROM submissions WHERE item_id=? AND student_id=? FOR UPDATE',[$work['id'],$id]);
         $selected=in_array($id,$ids,true);
         if($selected){
           if($existing && $existing['status']==='passed' && (int)$existing['credited']===(int)$work['points']) continue;
           if($existing){
             $delta=(int)$work['points']-(int)$existing['credited'];
             $ver=(int)$existing['review_version']+1;
             run("UPDATE submissions SET status='passed',reviewed_by=?,reviewed_at=NOW(),review_note='',credited=?,review_version=? WHERE id=?",[$teacher,$work['points'],$ver,$existing['id']]);
             $submissionId=(int)$existing['id'];
           }else{
             $delta=(int)$work['points'];$ver=1;
             // review_note is NOT NULL without a default (same as submitWork); V3.2 omitted it and the roster save failed with HTTP 500.
             run("INSERT INTO submissions(item_id,student_id,note,submitted_at,status,review_note,reviewed_by,reviewed_at,credited,review_version) VALUES(?,?,'ครูบันทึกการเข้าร่วม',NOW(),'passed','',?,NOW(),?,?)",[$work['id'],$id,$teacher,$work['points'],$ver]);
             $submissionId=inserted();
           }
           if($delta!==0)addPoints($teacher,$id,(int)$work['term_id'],$delta,'เข้าร่วมกิจกรรม · '.$work['title'],$submissionId,$ver);
           audit($teacher,'activity.participant',$id,(int)$work['id'],['selected'=>true]);
         }elseif($existing && $existing['status']==='passed'){
           $delta=-(int)$existing['credited'];
           $ver=(int)$existing['review_version']+1;
           if($delta!==0)addPoints($teacher,$id,(int)$work['term_id'],$delta,'แก้ไขการเข้าร่วมกิจกรรม · '.$work['title'],(int)$existing['id'],$ver);
           run("UPDATE submissions SET status='acknowledged',reviewed_by=?,reviewed_at=NOW(),review_note='ครูแก้ไขว่าไม่ได้เข้าร่วม',credited=0,review_version=? WHERE id=?",[$teacher,$ver,$existing['id']]);
           audit($teacher,'activity.participant',$id,(int)$work['id'],['selected'=>false]);
         }
       }
    });
    flash('บันทึกรายชื่อผู้เข้าร่วมกิจกรรมแล้ว');redirect('/item.php?id='.$itemId);
}
