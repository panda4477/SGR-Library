#!/usr/bin/env bash
# Disposable local acceptance environment, independent of the installed site and its data.
set -Eeuo pipefail
export PATH="$PATH:/usr/sbin:/sbin"
if [[ ${1:-} != --inside ]]; then
    root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
    work=$(mktemp -d /tmp/sgr-check.XXXXXXXX)
    report=$(mktemp -d /tmp/sgr-test-results.XXXXXXXX)
    trap 'rm -rf -- "$work"' EXIT
    mkdir "$work/src" "$work/results"
    cp -R "$root"/{app,assets,database,nginx,public,tools} "$work/src/"
    cmd=(bash "$work/src/tools/test_stack.sh" --inside "$work")
    if [[ $EUID == 0 ]]; then
        chown -R nobody:nogroup "$work"
        cmd=(runuser -u nobody -- "${cmd[@]}")
    fi
    status=0
    "${cmd[@]}" || status=$?
    cp -R "$work/results/." "$report/"
    echo "ผลทดสอบอยู่ที่ $report"
    exit "$status"
fi
q=$2
app="$q/src"
output="$q/results"
umask 077
mkdir -p "$q/db" "$q/storage"/{sessions,uploads} "$q/tmp"
pids=()
finish() {
    local rc=$?
    trap - EXIT
    for (( i=${#pids[@]}-1; i>=0; i-- )); do kill "${pids[i]}" 2>/dev/null || true; done
    wait || true
    exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
phpbin=${PHP_BIN:-php}
ver=$("$phpbin" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
fpm="/usr/sbin/php-fpm$ver"
export STUDENT_URL=http://127.0.0.1:18088 TEACHER_URL=http://127.0.0.1:18089
export SGR_CONFIG="$q/settings.ini"
# Fail early on occupied test ports. Traffic is always loopback-only.
python3 - <<'PY'
import socket
for port in (18088,18089):
    with socket.socket() as s:
        # SO_REUSEADDR ignores TIME_WAIT left by a previous run but still fails on a live listener.
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        s.bind(("127.0.0.1",port))
PY
mariadb-install-db --no-defaults --datadir="$q/db" --auth-root-authentication-method=normal --skip-test-db >"$output/db-init.log" 2>&1
mariadbd --no-defaults --datadir="$q/db" --socket="$q/db.sock" --skip-networking --pid-file="$q/db.pid" --log-error="$output/mariadb.log" --tmpdir="$q/tmp" --innodb-buffer-pool-size=96M &
pids+=($!)
export SGR_QA_DB_PID=$!
admin=(mariadb --no-defaults --protocol=socket --socket="$q/db.sock" -uroot)
for _ in {1..100}; do if "${admin[@]}" -e 'SELECT 1' >/dev/null 2>&1; then break; fi; sleep .1; done
secret=$(openssl rand -hex 32)
"${admin[@]}" <<SQL
CREATE DATABASE sgr_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sgr_qa'@'localhost' IDENTIFIED BY '$secret';
SQL
"${admin[@]}" sgr_qa < "$app/database/schema.sql"
"${admin[@]}" sgr_qa < "$app/database/repair-v322.sql" >/dev/null
"${admin[@]}" sgr_qa < "$app/database/repair-v332.sql"
sed -e 's/@DB@/sgr_qa/g' -e 's/@USER@/sgr_qa/g' -e 's/@HOST@/localhost/g' "$app/database/runtime-grants.sql.in" | "${admin[@]}"
cat > "$SGR_CONFIG" <<INI
[database]
socket=$q/db.sock
name=sgr_qa
user=sgr_qa
password=$secret
[app]
storage=$q/storage
key=$(openssl rand -hex 32)
contact=ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม
INI
# Fixture-only credentials live in a separate private file. PHP sees only the runtime account.
export SGR_QA_FIXTURES="$q/fixtures.ini"
cp "$SGR_CONFIG" "$SGR_QA_FIXTURES"
cat >> "$SGR_QA_FIXTURES" <<INI
[qa_database]
socket=$q/db.sock
name=sgr_qa
user=root
password=
INI
cat > "$q/fpm.conf" <<INI
[global]
pid=$q/fpm.pid
error_log=$output/fpm.log
[qa]
listen=$q/fpm.sock
pm=dynamic
pm.max_children=12
pm.start_servers=3
pm.min_spare_servers=2
pm.max_spare_servers=5
env[SGR_CONFIG]=$SGR_CONFIG
php_admin_flag[display_errors]=off
php_admin_flag[log_errors]=on
php_admin_value[error_log]=$output/php.log
php_admin_value[upload_max_filesize]=8M
php_admin_value[post_max_size]=10M
php_admin_value[upload_tmp_dir]=$q/tmp
INI
"$fpm" -t -y "$q/fpm.conf" >"$output/fpm-config.log" 2>&1
"$fpm" -F -y "$q/fpm.conf" >>"$output/fpm.log" 2>&1 &
pids+=($!)
export SGR_QA_FPM_PID=$!
python3 "$app/tools/render_nginx.py" --root "$app" --loopback --student-port 18088 --teacher-port 18089 --fpm "unix:$q/fpm.sock" > "$q/site.conf"
cat > "$q/nginx.conf" <<NGINX
worker_processes 2;
pid $q/nginx.pid;
error_log $output/nginx.log;
events { worker_connections 512; }
http {
    include /etc/nginx/mime.types;
    access_log off;
    client_body_temp_path $q/tmp/body;
    proxy_temp_path $q/tmp/proxy;
    fastcgi_temp_path $q/tmp/fastcgi;
    uwsgi_temp_path $q/tmp/uwsgi;
    scgi_temp_path $q/tmp/scgi;
    include $q/site.conf;
}
NGINX
nginx -e stderr -t -p "$q" -c "$q/nginx.conf" >"$output/nginx-config.log" 2>&1
nginx -e stderr -p "$q" -c "$q/nginx.conf" -g 'daemon off;' >>"$output/nginx.log" 2>&1 &
pids+=($!)
for _ in {1..100}; do if curl --noproxy '*' -fsS "$STUDENT_URL/" >/dev/null 2>&1; then break; fi; sleep .1; done
PHP_BIN="$phpbin" bash "$app/tools/smoke.sh" | tee "$output/smoke.log"
# The same end-to-end check install.sh runs on a real server, pointed at this disposable stack.
SGR_STUDENT_PORT=18088 SGR_TEACHER_PORT=18089 SGR_DB_SOCKET="$q/db.sock" \
    bash "$app/tools/post_update_test.sh" sgr_qa "$phpbin" 2>&1 | tee "$output/full-test.log"
/usr/bin/python3 "$app/tools/load_test.py" --output "$output/load.json" 2>&1 | tee "$output/load.log"
