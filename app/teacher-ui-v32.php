<?php
declare(strict_types=1);
// V3.3 teacher pages: directory, batch scores, roll call, terms and session creation.
function v32TeacherDirectory(array $term): string {
    $query=arg($_GET,'q',100,false);
    $level=arg($_GET,'level',70,false);
    if($level!=='' && !in_array($level,LEVELS,true))fail(422,'ระดับชั้นไม่ถูกต้อง');
    $status=arg($_GET,'status',20,false);
    if(!in_array($status,['','pending','approved','declined','inactive','unregistered'],true))fail(422);
    // Dashboard shortcuts link here with ?filter=online|low; V3.2 ignored the parameter.
    $filter=arg($_GET,'filter',20,false);
    if(!in_array($filter,['','all','online','low'],true))$filter='';
    $sql="SELECT u.id,u.first_name,u.last_name,u.username,u.student_code,u.last_seen,u.avatar_id,u.level_name AS base_level,
        e.level_name,e.status,COALESCE(s.total,0) AS total
        FROM users u LEFT JOIN term_enrollments e ON e.student_id=u.id AND e.term_id=?
        LEFT JOIN (SELECT student_id,SUM(delta_points) AS total FROM score_entries WHERE term_id=? GROUP BY student_id) s ON s.student_id=u.id
        WHERE u.role='student'";
    $args=[(int)$term['id'],(int)$term['id']];
    if($query!==''){$p='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $sql.=" AND (CONCAT_WS(' ',u.first_name,u.last_name) LIKE ? ESCAPE '!' OR u.student_code LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";
        array_push($args,$p,$p,$p);
    }
    if($level!==''){$sql.=' AND COALESCE(e.level_name,u.level_name)=?';$args[]=$level;}
    if($status==='unregistered'){$sql.=' AND e.id IS NULL';}
    elseif($status!==''){$sql.=' AND e.status=?';$args[]=$status;}
    else{$sql.=" AND (e.id IS NULL OR e.status NOT IN ('declined','inactive'))";} // V3.3.2: declined / removed are hidden unless asked for
    if($filter==='online'){$sql.=' AND u.last_seen>=NOW()-INTERVAL 5 MINUTE';}
    if($filter==='low'){$sql.=" AND e.status='approved' AND COALESCE(s.total,0)<?";$args[]=(int)$term['min_points'];}
    $records=rows($sql.' ORDER BY COALESCE(e.level_name,u.level_name),u.first_name,u.last_name,u.id LIMIT 600',$args);
    $all=(int)val("SELECT COUNT(*) FROM users WHERE role='student'");
    $studying=(int)val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='approved'",[$term['id']]);
    $pending=(int)val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status='pending'",[$term['id']]);
    $h=documentTitle('รายชื่อนักศึกษา',termName($term),$pending?iconLink('/enrollments.php?term='.$term['id'],'check','รออนุมัติ '.$pending.' คน','button primary'):iconLink('/enrollments.php?term='.$term['id'],'check','การลงทะเบียน'));
    $h.='<div class="stats"><a class="stat" href="/students.php?term='.(int)$term['id'].'"><span>บัญชีทั้งหมด</span><strong>'.$all.'</strong><small>คน</small></a>'.
        '<a class="stat good" href="/students.php?term='.(int)$term['id'].'&status=approved"><span>กำลังเรียนภาคนี้</span><strong>'.$studying.'</strong><small>คน</small></a>'.
        '<a class="stat gold" href="/students.php?term='.(int)$term['id'].'&status=pending"><span>รออนุมัติ</span><strong>'.$pending.'</strong><small>คน</small></a>'.
        '<a class="stat red" href="/students.php?term='.(int)$term['id'].'&filter=low"><span>กพช. ต่ำกว่าเกณฑ์</span><strong>'.(int)val("SELECT COUNT(*) FROM term_enrollments e LEFT JOIN (SELECT student_id,SUM(delta_points) t FROM score_entries WHERE term_id=? GROUP BY student_id) s ON s.student_id=e.student_id WHERE e.term_id=? AND e.status='approved' AND COALESCE(s.t,0)<?",[$term['id'],$term['id'],(int)$term['min_points']]).'</strong><small>คน</small></a></div>';
    $h.='<form class="filter card" method="get">'.hidden('term',$term['id']) .
        field('q','ค้นหา','search',$query,false,'placeholder="ชื่อ รหัสนักศึกษา หรือ Username"') .
        selectBox('level','ระดับ',[''=>'ทุกระดับ']+array_combine(LEVELS,LEVELS),$level) .
        selectBox('status','สถานะ',[''=>'ทุกสถานะ','approved'=>'กำลังเรียน','pending'=>'รออนุมัติ','inactive'=>'ออกจากภาคเรียน','declined'=>'ไม่อนุมัติ / ไม่เรียนต่อ','unregistered'=>'ไม่ได้ลงทะเบียน'],$status) .
        ($filter!==''?hidden('filter',$filter):'').btn('ค้นหา','secondary').'</form>';
    $hiddenCount=$status===''?(int)val("SELECT COUNT(*) FROM term_enrollments WHERE term_id=? AND status IN ('declined','inactive')",[$term['id']]):0;
    if($hiddenCount)$h.='<p class="chip-line">'.badge('ซ่อนผู้ที่ไม่อนุมัติ / ออกจากภาคเรียนแล้ว '.$hiddenCount.' คน').' '.linkTo('/students.php?term='.(int)$term['id'].'&status=inactive','ดูผู้ที่ออกจากภาคเรียน','textlink').' '.linkTo('/students.php?term='.(int)$term['id'].'&status=declined','ดูผู้ที่ไม่อนุมัติ','textlink').'</p>';
    if($filter==='online')$h.='<p class="chip-line">'.badge('กำลังแสดง: ใช้งานใน 5 นาที','blue').' '.linkTo('/students.php?term='.$term['id'],'ล้างตัวกรอง','textlink').'</p>';
    if($filter==='low')$h.='<p class="chip-line">'.badge('กำลังแสดง: กพช. ต่ำกว่า '.(int)$term['min_points'].' คะแนน','red').' '.linkTo('/students.php?term='.$term['id'],'ล้างตัวกรอง','textlink').'</p>';
    $data=[];
    foreach($records as $r){
        $st=$r['status']===null?badge('ไม่ได้ลงทะเบียน'):badge(v32EnrollmentText($r['status']),match($r['status']){'approved'=>'good','pending'=>'gold','declined','inactive'=>'red',default=>''});
        $data[]=['<label class="check"><input type="checkbox" name="student_ids[]" form="v32-bulk-remove" value="'.(int)$r['id'].'"'.($r['status']===null?' disabled':'').'><span class="sr-only">เลือก '.esc(fullname($r)).'</span></label>',
            '<a class="person-link" href="/student.php?id='.(int)$r['id'].'&term='.(int)$term['id'].'">'.avatar($r,'sm').'<span><b>'.esc(fullname($r)).'</b><small>'.esc($r['username']).'</small></span></a>',
            esc($r['student_code']),esc($r['level_name']??$r['base_level']),$st,'<b>'.esc($r['total']).'</b> / 200'];
    }
    $h.='<section class="card section"><div class="section-head"><h2>พบ '.count($records).' คน</h2></div>'.tableView(['เลือก','ชื่อ','รหัส','ระดับ','สถานะ','กพช.'],$data,'ไม่พบนักศึกษาที่ค้นหา').'</section>';
    $h.='<details class="card panel danger-zone"><summary>'.icon('users',18).' นำรายชื่อที่ติ๊กออกจากภาคเรียน (เก็บบัญชีและประวัติ)</summary>'.
        '<p class="muted">ติ๊กช่องหน้าชื่อในตารางก่อน แล้วใส่รหัสผ่านครูเพื่อยืนยัน การดำเนินการนี้ไม่ลบประวัติภาคเรียนเก่า</p>' .
        formStart('/students.php',false,'v32-bulk-remove').hidden('term_id',$term['id']).field('teacher_password','รหัสผ่านครู','password','',true,'autocomplete="current-password"') .
        '<button type="submit" class="button danger" data-confirm-delete="นำรายชื่อที่ติ๊กออกจากภาคเรียนนี้หรือไม่?">ยืนยันนำออกจากภาคเรียน</button></form></details>';
    return $h;
}
function v32TeacherScores(array $term): string {
    $students=v32EnrolledStudents((int)$term['id']);
    $h=documentTitle('คะแนน กพช.','คะแนนกิจกรรม / คะแนนเสริม · เพิ่มหรือหักให้หลายคนพร้อมกัน (คะแนนใบงานให้ที่ “คะแนนงานในห้อง”)',iconLink('/gradebook.php?term='.(int)$term['id'],'grade','คะแนนงานในห้อง','button secondary')) . termFilter($term);
    if(!$students) return $h.emptyState('ยังไม่มีนักศึกษาที่อนุมัติในภาคเรียนนี้ อนุมัติการลงทะเบียนก่อนจึงจะบันทึกคะแนนได้','users');
    $h.='<div class="grid two-one"><section class="card section score-batch-panel"><h2>บันทึกคะแนนแบบกลุ่ม</h2>';
    $h.='<form method="post" id="score-batch-form">'.hidden('_csrf',$_SESSION['csrf']).hidden('term_id',$term['id']) .
       '<div class="field"><span class="label">1. เลือกนักศึกษา</span><button type="button" class="button secondary block" data-open-dialog="score-chooser">'.icon('users',18).'<span>เลือกนักศึกษา (<span data-score-count>0</span> คน)</span></button>' .
       '<small id="score-selected" class="hint">ยังไม่ได้เลือกนักศึกษา</small></div>' .
       '<div class="field"><span class="label">2. จำนวนคะแนน</span><div class="quick-points">'.
       '<button type="button" class="chip" data-points="5">+5</button><button type="button" class="chip" data-points="10">+10</button><button type="button" class="chip" data-points="20">+20</button><button type="button" class="chip" data-points="-5">-5</button></div>'.
       '<input name="points" type="number" value="" required min="-200" max="200" step="1" aria-label="คะแนนเพิ่มหรือลด" placeholder="เช่น 10 หรือ -5"><small class="hint">ใส่ค่าลบเพื่อหักคะแนน · ยอดรวมต่อคนต้องอยู่ระหว่าง 0–200</small></div>' .
       area('reason','3. เหตุผล (นักศึกษาจะเห็นข้อความนี้)',true,'','',500) .hidden('request_key',bin2hex(random_bytes(24))).
       '<p class="form-error" data-score-error hidden>กรุณาเลือกนักศึกษาอย่างน้อย 1 คน</p>'.btn('บันทึกคะแนน','primary block large').'</form></section>';
    $list=rows("SELECT u.first_name,u.last_name,e.delta_points,e.reason,e.created_at FROM score_entries e JOIN users u ON u.id=e.student_id WHERE e.term_id=? ORDER BY e.id DESC LIMIT 8",[$term['id']]);
    $recent='';
    foreach($list as $r){$recent.='<li><b>'.esc(fullname($r)).'</b> <span class="delta '.((int)$r['delta_points']<0?'neg':'pos').'">'.((int)$r['delta_points']>0?'+':'').(int)$r['delta_points'].'</span><small>'.esc($r['reason']).' · '.esc(thDate($r['created_at'])).'</small></li>';}
    $h.='<section class="card section"><h2>บันทึกล่าสุด</h2>'.($recent!==''?'<ul class="plain-list">'.$recent.'</ul>':emptyState('ยังไม่มีการบันทึกคะแนน','star')).'</section></div>';
    $h.='<dialog id="score-chooser" class="student-dialog" aria-labelledby="score-chooser-title"><div class="dialog-head"><h2 id="score-chooser-title">เลือกนักศึกษา</h2><button type="button" class="button ghost small" data-close-dialog>ปิด</button></div>' .
       '<div class="dialog-tools"><input type="search" class="search" placeholder="ค้นหาชื่อ รหัส ระดับ" data-student-search aria-label="ค้นหานักศึกษา">' .
       '<div class="dialog-actions"><button type="button" class="button secondary small" data-select-visible>เลือกทั้งหมดที่แสดง</button>' .
       '<button type="button" class="button ghost small" data-clear-visible>ล้างที่แสดง</button></div></div><div class="student-choices">';
    foreach($students as $s){$h.='<label class="student-choice"><input type="checkbox" data-score-student name="student_ids[]" form="score-batch-form" value="'.(int)$s['id'].'"><span>'.esc(fullname($s)).'<small>'.esc($s['student_code']).' · '.esc($s['level_name']).' · '.scoreTotal((int)$s['id'],(int)$term['id']).' คะแนน</small></span></label>';}
    $h.='</div><div class="dialog-foot"><span>เลือกแล้ว <b data-score-count>0</b> คน</span><button type="button" class="button primary" data-close-dialog>ใช้รายชื่อนี้</button></div></dialog>';
    return $h;
}
function v32TeacherRoll(array $term): string {
    $id=number($_GET['id']??null);
    $session=row('SELECT * FROM attendance_sessions WHERE id=?',[$id])??fail(404);
    $students=rows("SELECT u.id,u.first_name,u.last_name,u.student_code,u.avatar_id,e.level_name,r.status
        FROM term_enrollments e JOIN users u ON u.id=e.student_id
        LEFT JOIN attendance_records r ON r.student_id=u.id AND r.session_id=?
        WHERE e.term_id=? AND e.status='approved' AND (? IS NULL OR e.level_name=?)
        ORDER BY e.level_name,u.first_name,u.last_name,u.id",[$id,$session['term_id'],$session['level_name'],$session['level_name']]);
    $noClass=(int)$session['no_class']===1;
    $sessionTerm=row('SELECT * FROM terms WHERE id=?',[(int)$session['term_id']])??fail(404);
    $h='<p class="crumb">'.linkTo('/attendance.php?term='.(int)$session['term_id'],'← วันเรียนทั้งหมด','textlink').'</p>';
    $h.=documentTitle($session['title'],thDate($session['opens_at']).' ถึง '.thDate($session['closes_at']).' · '.termName($sessionTerm).' · '.($session['level_name']??'ทุกระดับ'));
    $h.='<details class="card panel"><summary>'.icon('settings',18).' แก้ไขวันเวลา / ตั้งเป็นวันงดเรียน</summary><div class="grid two">'.
        formStart('/roll.php?id='.$id).hidden('action','edit_session') .
        field('title','ชื่อวันเรียน','text',$session['title'],true,'maxlength="180"') .
        field('opens_at','เริ่ม','datetime-local',str_replace(' ','T',substr($session['opens_at'],0,16))) .
        field('closes_at','สิ้นสุด','datetime-local',str_replace(' ','T',substr($session['closes_at'],0,16))) .btn('บันทึกวันเวลา','secondary').'</form>' .
        formStart('/roll.php?id='.$id).hidden('action','no_class').checkbox('no_class','วันนี้งดเรียน (ไม่นับเป็นวันมาเรียนหรือวันขาด)',$noClass).btn('บันทึกสถานะวันเรียน','secondary').'</form></div></details>';
    if($noClass)return $h.'<div class="callout gold">'.icon('info',22).'<div><b>วันงดเรียน</b><p>ปิดการบันทึก มา / ลา / ขาด ของรอบนี้ ยกเลิกได้ที่ “แก้ไขวันเวลา / ตั้งเป็นวันงดเรียน”</p></div></div>';
    if(!$students)return $h.emptyState('ยังไม่มีนักศึกษาที่อนุมัติในภาคเรียน / ระดับนี้','users');
    $saved=(int)val('SELECT COUNT(*) FROM attendance_records WHERE session_id=?',[$id]);
    $h.='<form method="post" id="v32-roll-form" action="/roll.php?id='.$id.'">'.hidden('_csrf',$_SESSION['csrf']).hidden('action','bulk_roll') .
        '<section class="card section"><div class="roll-head"><div class="roll-tally" aria-live="polite"><span class="good">มา <b data-tally="present">0</b></span><span class="blue">ลา <b data-tally="leave">0</b></span><span class="red">ขาด <b data-tally="absent">0</b></span></div>'.
        '<div class="roll-actions"><button type="button" class="button secondary small" data-present-all>'.icon('check',16).'<span>มาทั้งหมด</span></button><button type="button" class="button ghost small" data-roll-clear>ล้าง</button></div></div>' .
        '<p class="hint">'.($saved?'บันทึกไว้แล้ว '.$saved.' คน · ':'ยังไม่เคยบันทึก · ').'แตะ “มา” หรือ “ลา” คนที่ไม่ได้เลือกจะถูกบันทึกเป็น “ขาด” เมื่อกดบันทึก</p>' .
        '<input type="search" class="search" data-roll-search placeholder="ค้นหาชื่อ / รหัส" aria-label="ค้นหานักศึกษา"><div class="v32-roll-list">';
    foreach($students as $s){$p=$s['status']==='present';$l=$s['status']==='leave';
        $h.='<div class="roll-student" data-person="'.(int)$s['id'].'">'.avatar($s,'sm').'<div class="roll-name"><strong>'.esc(fullname($s)).'</strong><small>'.esc($s['student_code']).' · '.esc($s['level_name']).'</small></div>' .
        '<div class="seg"><label class="seg-present"><input type="checkbox" data-roll-status="present" name="present[]" value="'.(int)$s['id'].'"'.($p?' checked':'').'><span>มา</span></label>' .
        '<label class="seg-leave"><input type="checkbox" data-roll-status="leave" name="leave[]" value="'.(int)$s['id'].'"'.($l?' checked':'').'><span>ลา</span></label></div>' .
        '<span class="roll-state" data-roll-indicator>'.($p?'มา':($l?'ลา':'ขาด')).'</span></div>';
    }
    $h.='</div></section><div class="sticky-actions">'.btn('บันทึก มา / ลา / ขาด ทั้งห้อง ('.count($students).' คน)','primary block large').'</div></form>';
    return $h;
}
function v32TeacherTerms(array $term): string {
    $h=documentTitle('ภาคเรียนและเกณฑ์','แยกข้อมูลรายปี / ภาคเรียน และเก็บประวัติย้อนหลังทั้งหมด');
    $all=listTerms();$table=[];$curr=(int)currentTerm()['id'];
    foreach($all as $t){
        $table[]=['<b>'.esc(termName($t)).'</b>',(int)$t['id']===$curr?badge('ภาคเรียนปัจจุบัน','good'):badge('ประวัติ'),
        esc((int)$t['min_attendance'].' / '.(int)$t['expected_days'].' วัน · กพช. '.(int)$t['min_points']),
        (int)$t['id']===(int)$term['id']?badge('กำลังแก้ไข','blue'):linkTo('/terms.php?term='.$t['id'],'ตั้งค่า','button secondary small')];
    }
    $h.='<section class="card section"><h2>ภาคเรียนทั้งหมด</h2>'.tableView(['ภาคเรียน','สถานะ','เกณฑ์','จัดการ'],$table).'</section>';
    $h.='<div class="grid two">';
    $h.=block('เกณฑ์ · '.termName($term),formStart('/terms.php').hidden('action','settings').hidden('term_id',$term['id']) .
        field('expected_days','จำนวนวันเรียนตามแผน','number',$term['expected_days'],true,'min="1" max="366"') .
        field('min_attendance','ต้องมาเรียนอย่างน้อย (วัน)','number',$term['min_attendance'],true,'min="0" max="366"','ต้องไม่เกินจำนวนวันเรียนตามแผน') .
        field('min_points','คะแนน กพช. ขั้นต่ำ','number',$term['min_points'],true,'min="0" max="200"') .btn('บันทึกเกณฑ์').'</form>');
    $h.=block('เพิ่มภาคเรียน / เปลี่ยนภาคเรียนปัจจุบัน',formStart('/terms.php').
        '<div class="pair">'.field('school_year','ปีการศึกษา (พ.ศ.)','number',$term['school_year'],true,'min="2500" max="2800"') .
        selectBox('semester','ภาคเรียน',[1=>'1',2=>'2'],$term['semester']).'</div>' .
        checkbox('make_current','ตั้งเป็นภาคเรียนปัจจุบัน') .'<p class="hint">เมื่อเปลี่ยนภาคเรียนปัจจุบัน นักศึกษาจะเห็นปุ่ม “ยืนยันการเรียนต่อ” ที่หน้าหลัก</p>'.btn('บันทึกภาคเรียน').'</form>');
    $h.='</div>';
    if((int)$term['id']!==$curr){$h.='<details class="card panel danger-zone"><summary>'.icon('reset',18).' ลบภาคเรียนว่าง</summary><p class="muted">ลบได้เฉพาะภาคเรียนที่ไม่เคยมีผู้ลงทะเบียน งาน คะแนน การมาเรียน ประกาศ หรือประวัติ</p>'.
        formStart('/terms.php').hidden('action','delete').hidden('term_id',$term['id']).
        field('teacher_password','รหัสผ่านครู','password','',true,'autocomplete="current-password"') .
        '<button type="submit" class="button danger" data-confirm-delete="ยืนยันลบภาคเรียนที่ไม่มีข้อมูลหรือไม่?">ลบภาคเรียนว่าง</button></form></details>';}
    return $h;
}
function v32TeacherCreateSession(array $term): string {
    $today=date('Y-m-d');
    return '<p class="crumb">'.linkTo('/attendance.php?term='.(int)$term['id'],'← วันเรียนทั้งหมด','textlink').'</p>'.documentTitle('สร้างวันเรียน','สร้างรอบเรียนแล้วเช็กชื่อทั้งห้องได้ทันที') .
        '<section class="card section narrow">'.formStart('/new-attendance.php').
        field('title','ชื่อวันเรียน / หัวข้อ','text','',true,'maxlength="180" placeholder="เช่น พบกลุ่มสัปดาห์ที่ 3"') .
        '<div class="pair">'.selectBox('term_id','ภาคเรียน',termOptions(),$term['id']) .
        selectBox('level_name','สำหรับระดับชั้น',['all'=>'ทุกระดับ']+array_combine(LEVELS,LEVELS),'all').'</div>' .
        '<div class="pair">'.field('opens_at','เริ่ม','datetime-local',$today.'T09:00') .field('closes_at','สิ้นสุด','datetime-local',$today.'T12:00').'</div>' .
        btn('สร้างวันเรียนและเช็กชื่อ','primary block large').'</form></section>';
}
