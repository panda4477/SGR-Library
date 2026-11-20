#!/usr/bin/env bash
set -Eeuo pipefail
DB=${1:-sgr_library_v3}
MARIADB=${MARIADB:-mariadb}
q(){ "$MARIADB" --protocol=socket -N "$DB" -e "$1"; }
missing=0
for t in term_enrollments behavior_events behavior_students announcement_reads feature_flags app_settings; do
  n=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'")
  if [[ $n != 1 ]]; then echo "missing table: $t" >&2; missing=1; fi
done
while IFS=: read -r t c; do
  n=$(q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' AND column_name='$c'")
  if [[ $n != 1 ]]; then echo "missing column: $t.$c" >&2; missing=1; fi
done <<'EOF'
users:phone
users:line_contact
terms:expected_days
terms:min_attendance
terms:min_points
attendance_sessions:level_name
attendance_sessions:no_class
notices:term_id
announcements:term_id
users:wallpaper
users:wallpaper_id
EOF
[[ $missing == 0 ]]
q "SELECT CONCAT('schema=',version_no) FROM sgr_schema WHERE id=1"
