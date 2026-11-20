#!/usr/bin/env bash
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
DB=${1:-sgr_library_v3}
PHPBIN=${2:-php}
# Defaults test the installed site. tools/test_stack.sh points these at its own disposable stack.
sp=${SGR_STUDENT_PORT:-8088}
tp=${SGR_TEACHER_PORT:-8089}
db_cli=(mariadb --protocol=socket)
[[ -z ${SGR_DB_SOCKET:-} ]] || db_cli=(mariadb --no-defaults --protocol=socket --socket="$SGR_DB_SOCKET" -uroot)
pass="V322test-$(date +%s)-$$-Aa9!"
suffix="$(date +%s)$$"
teacher="v322t_${suffix}"
student="v322s_${suffix}"
code="V322${suffix}"
hash=$($PHPBIN -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost"=>12]);' "$pass")
term=$("${db_cli[@]}" -N "$DB" -e 'SELECT current_term_id FROM site_state WHERE id=1')
[[ $term =~ ^[0-9]+$ ]] || { echo 'missing current term' >&2; exit 1; }
cleanup(){
  "${db_cli[@]}" "$DB" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM behavior_students WHERE student_id IN (SELECT id FROM users WHERE username='$student') OR event_id IN (SELECT id FROM behavior_events WHERE teacher_id IN (SELECT id FROM users WHERE username='$teacher')); DELETE FROM behavior_events WHERE teacher_id IN (SELECT id FROM users WHERE username='$teacher'); DELETE FROM attendance_records WHERE student_id IN (SELECT id FROM users WHERE username='$student') OR session_id IN (SELECT id FROM attendance_sessions WHERE teacher_id IN (SELECT id FROM users WHERE username='$teacher')); DELETE FROM attendance_sessions WHERE teacher_id IN (SELECT id FROM users WHERE username='$teacher'); DELETE FROM score_entries WHERE student_id IN (SELECT id FROM users WHERE username='$student') OR teacher_id IN (SELECT id FROM users WHERE username='$teacher'); DELETE FROM announcement_reads WHERE student_id IN (SELECT id FROM users WHERE username='$student'); DELETE FROM term_enrollments WHERE student_id IN (SELECT id FROM users WHERE username='$student') OR reviewed_by IN (SELECT id FROM users WHERE username='$teacher'); DELETE FROM reset_requests WHERE student_id IN (SELECT id FROM users WHERE username='$student') OR issued_by IN (SELECT id FROM users WHERE username='$teacher'); DELETE FROM notices WHERE student_id IN (SELECT id FROM users WHERE username='$student'); DELETE FROM audit_log WHERE actor_id IN (SELECT id FROM users WHERE username IN ('$teacher','$student')) OR student_id IN (SELECT id FROM users WHERE username='$student'); DELETE FROM users WHERE username IN ('$teacher','$student'); SET FOREIGN_KEY_CHECKS=1;" >/dev/null 2>&1 || true
  rm -f /tmp/v322-*.$$ >/dev/null 2>&1 || true
}
trap cleanup EXIT
"${db_cli[@]}" "$DB" <<SQL
INSERT INTO users(role,username,password_hash,first_name,last_name,student_code,level_name,gender)
VALUES('teacher','$teacher','$hash','ครู','ทดสอบ',NULL,'','ไม่ระบุ');
INSERT INTO users(role,username,password_hash,first_name,last_name,student_code,level_name,gender)
VALUES('student','$student','$hash','นักศึกษา','ทดสอบ','$code','มัธยมศึกษาตอนต้น','ไม่ระบุ');
INSERT INTO term_enrollments(student_id,term_id,level_name,status,reviewed_at,reviewed_by)
SELECT s.id,$term,'มัธยมศึกษาตอนต้น','approved',NOW(),t.id
FROM users s JOIN users t ON t.username='$teacher'
WHERE s.username='$student';
SQL

login_and_check(){
  local portal=$1 port=$2 user=$3 ua=$4 tag=$5
  local cookie=/tmp/v322-cookie-${portal}-${tag}.$$
  local login=/tmp/v322-login-${portal}-${tag}.$$
  local headers=/tmp/v322-headers-${portal}-${tag}.$$
  local body=/tmp/v322-body-${portal}-${tag}.$$
  curl --noproxy '*' -A "$ua" -fsS -c "$cookie" "http://127.0.0.1:$port/login.php" -o "$login"
  local token status location
  token=$(sed -nE 's/.*name="_csrf" value="([0-9a-f]+)".*/\1/p' "$login" | head -1)
  [[ $token =~ ^[0-9a-f]{64}$ ]] || { echo "missing csrf $portal/$tag" >&2; return 1; }
  status=$(curl --noproxy '*' -A "$ua" -sS -o /dev/null -D "$headers" -b "$cookie" -c "$cookie" \
    --data-urlencode "_csrf=$token" --data-urlencode "username=$user" --data-urlencode "password=$pass" \
    -w '%{http_code}' "http://127.0.0.1:$port/login.php")
  [[ $status == 303 ]] || { echo "login $portal/$tag expected 303 got $status" >&2; return 1; }
  location=$(awk 'BEGIN{IGNORECASE=1}/^Location:/{gsub("\r","");print $2}' "$headers" | tail -1)
  [[ $location == /dashboard.php ]] || { echo "login $portal/$tag wrong location $location" >&2; return 1; }

  local paths
  if [[ $portal == teacher ]]; then
    paths='/dashboard.php /students.php /students.php?filter=low /scores.php /attendance.php /new-attendance.php /terms.php /behavior.php /reports.php /feature-settings.php /enrollments.php /announcements.php /activities.php /assignments.php /requests.php /more.php /system-reset.php /audit.php /report.php /account.php /gradebook.php /activity-log.php /activity-log.php?view=students /report.php?mode=each'
  else
    paths='/dashboard.php /profile.php /history.php /notifications.php /announcements.php /attendance.php /scores.php /behavior.php /activities.php /assignments.php /more.php /renew.php /info.php'
  fi
  for path in $paths; do
    status=$(curl --noproxy '*' -A "$ua" -sS -b "$cookie" -o "$body" -w '%{http_code}' "http://127.0.0.1:$port$path")
    [[ $status == 200 ]] || { echo "$portal/$tag $path got $status" >&2; sed -n '1,20p' "$body" >&2; return 1; }
    if grep -Fq 'HTTP 500' "$body" || grep -Fq 'ระบบขัดข้องชั่วคราว' "$body"; then
      echo "$portal/$tag $path rendered application error" >&2; return 1
    fi
    grep -Fq 'V3.3.3' "$body" || { echo "$portal/$tag $path missing V3.3.3 marker" >&2; return 1; }
  done
}

UA_DESKTOP='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131 Safari/537.36'
UA_ANDROID='Mozilla/5.0 (Linux; Android 16; Mobile) AppleWebKit/537.36 Chrome/131 Mobile Safari/537.36'
login_and_check student "$sp" "$student" "$UA_DESKTOP" desktop
login_and_check student "$sp" "$student" "$UA_ANDROID" android
login_and_check teacher "$tp" "$teacher" "$UA_DESKTOP" desktop
login_and_check teacher "$tp" "$teacher" "$UA_ANDROID" android


# Write-flow test with temporary accounts: score, attendance create/edit/roll, behavior.
TC=/tmp/v322-action-teacher.$$
TL=/tmp/v322-action-login.$$
TH=/tmp/v322-action-headers.$$
TB=/tmp/v322-action-body.$$
curl --noproxy '*' -A "$UA_ANDROID" -fsS -c "$TC" "http://127.0.0.1:$tp/login.php" -o "$TL"
token=$(sed -nE 's/.*name="_csrf" value="([0-9a-f]+)".*/\1/p' "$TL" | head -1)
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -D "$TH" -b "$TC" -c "$TC" --data-urlencode "_csrf=$token" --data-urlencode "username=$teacher" --data-urlencode "password=$pass" -w '%{http_code}' "http://127.0.0.1:$tp/login.php")
[[ $status == 303 ]] || { echo "action login teacher got $status" >&2; exit 1; }
student_id=$("${db_cli[@]}" -N "$DB" -e "SELECT id FROM users WHERE username='$student'")
teacher_id=$("${db_cli[@]}" -N "$DB" -e "SELECT id FROM users WHERE username='$teacher'")
[[ $student_id =~ ^[0-9]+$ && $teacher_id =~ ^[0-9]+$ ]] || { echo 'missing temp ids' >&2; exit 1; }
get_csrf(){
  local path=$1
  curl --noproxy '*' -A "$UA_ANDROID" -fsS -b "$TC" "http://127.0.0.1:$tp$path" -o "$TB"
  sed -nE 's/.*name="_csrf" value="([0-9a-f]+)".*/\1/p' "$TB" | head -1
}
csrf=$(get_csrf '/scores.php')
request_key=$(printf '%048x' "$suffix")
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode "term_id=$term" --data-urlencode 'points=1' \
  --data-urlencode 'reason=V3.2.2 automated test' --data-urlencode "request_key=$request_key" --data-urlencode "student_ids[]=$student_id" \
  "http://127.0.0.1:$tp/scores.php")
[[ $status == 303 ]] || { echo "score action got $status" >&2; exit 1; }
[[ $("${db_cli[@]}" -N "$DB" -e "SELECT COALESCE(SUM(delta_points),0) FROM score_entries WHERE student_id=$student_id AND term_id=$term") == 1 ]] || { echo 'score write verification failed' >&2; exit 1; }

open=$(date -d '+1 day' '+%Y-%m-%dT10:00')
close=$(date -d '+1 day' '+%Y-%m-%dT12:00')
csrf=$(get_csrf '/new-attendance.php')
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -D "$TH" -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'title=V322 attendance test' --data-urlencode "term_id=$term" --data-urlencode 'level_name=all' \
  --data-urlencode "opens_at=$open" --data-urlencode "closes_at=$close" "http://127.0.0.1:$tp/new-attendance.php")
[[ $status == 303 ]] || { echo "new attendance action got $status" >&2; exit 1; }
location=$(awk 'BEGIN{IGNORECASE=1}/^Location:/{gsub("\r","");print $2}' "$TH" | tail -1)
[[ $location =~ ^/roll\.php\?id=([0-9]+)$ ]] || { echo "bad roll location $location" >&2; exit 1; }
session_id=${BASH_REMATCH[1]}
csrf=$(get_csrf "/roll.php?id=$session_id")
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'action=bulk_roll' --data-urlencode "present[]=$student_id" \
  "http://127.0.0.1:$tp/roll.php?id=$session_id")
[[ $status == 303 ]] || { echo "bulk roll action got $status" >&2; exit 1; }
[[ $("${db_cli[@]}" -N "$DB" -e "SELECT status FROM attendance_records WHERE session_id=$session_id AND student_id=$student_id") == present ]] || { echo 'attendance write verification failed' >&2; exit 1; }

csrf=$(get_csrf "/roll.php?id=$session_id")
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'action=no_class' --data-urlencode 'no_class=1' \
  "http://127.0.0.1:$tp/roll.php?id=$session_id")
[[ $status == 303 ]] || { echo "no_class action got $status" >&2; exit 1; }
csrf=$(get_csrf "/roll.php?id=$session_id")
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'action=edit_session' --data-urlencode 'title=V322 attendance edited' \
  --data-urlencode "opens_at=$open" --data-urlencode "closes_at=$close" "http://127.0.0.1:$tp/roll.php?id=$session_id")
[[ $status == 303 ]] || { echo "edit_session action got $status" >&2; exit 1; }

csrf=$(get_csrf '/behavior.php')
today=$(date '+%Y-%m-%d')
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -o /dev/null -b "$TC" -c "$TC" -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'action=save' --data-urlencode "term_id=$term" --data-urlencode 'title=V322 behavior test' \
  --data-urlencode 'details=automated temporary record' --data-urlencode "occurred_on=$today" --data-urlencode 'visibility=private' --data-urlencode 'state=open' \
  --data-urlencode "student_ids[]=$student_id" "http://127.0.0.1:$tp/behavior.php")
[[ $status == 303 ]] || { echo "behavior action got $status" >&2; exit 1; }
[[ $("${db_cli[@]}" -N "$DB" -e "SELECT COUNT(*) FROM behavior_events WHERE teacher_id=$teacher_id AND title='V322 behavior test'") == 1 ]] || { echo 'behavior write verification failed' >&2; exit 1; }

# V3.3.2: Excel export must be a real .xlsx (ZIP) file.
status=$(curl --noproxy '*' -A "$UA_ANDROID" -sS -b "$TC" -o "$TB" -w '%{http_code}' "http://127.0.0.1:$tp/report.php?term=$term&format=xlsx")
[[ $status == 200 ]] || { echo "xlsx export got $status" >&2; exit 1; }
[[ $(head -c 2 "$TB") == PK ]] || { echo 'xlsx export is not a zip file' >&2; exit 1; }
# Teacher portal home is the login popup.
curl --noproxy '*' -fsS "http://127.0.0.1:$tp/" -o "$TB"
grep -Fq 'login-pop' "$TB" || { echo 'teacher home is not the login popup' >&2; exit 1; }
# V3.3.3: student main page uses the new layout and the corrected organisation name.
curl --noproxy '*' -fsS "http://127.0.0.1:$sp/" -o "$TB"
grep -Fq 'lp-hero' "$TB" && grep -Fq 'ศกร.ระดับตำบลบ้านใหม่' "$TB" || { echo 'student main page is not the V3.3.3 layout' >&2; exit 1; }

echo 'V3.3.3 login + desktop/mobile route + write-flow + export test passed'

