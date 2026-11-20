#!/usr/bin/env bash
# SGR Library V3.3.3 — full installer (fresh install, re-install and upgrade of an existing V3.x server).
#
#   sudo bash install.sh
#
# Optional settings (environment variables):
#   SGR_PHP_VERSION=8.3                   use a specific PHP version from APT (default: the distro's php-fpm)
#   SGR_STUDENT_DOMAIN=example.org        public domain of the student portal (Cloudflare Tunnel)
#   SGR_TEACHER_DOMAIN=teacher.example.org public domain of the teacher portal (Cloudflare Tunnel)
#   SGR_CONTACT='โทร 0xx · LINE @xxx'     contact text written to a NEW settings.ini
#   SGR_OPEN_FIREWALL=1                   allow TCP 8088/8089 in ufw when ufw is active
#   SGR_SKIP_FULL_TEST=1                  run only the quick smoke test after installing
#   SGR_DAILY_BACKUP=1                    add a cron job: back up DB + uploads + settings every night at 02:30
set -Eeuo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
sgr_version=3.3.3
die() { echo "[ERROR] $*" >&2; exit 1; }
step() { printf '\n[%s] %s\n' "$1" "$2"; }
[[ $EUID == 0 ]] || die 'กรุณารันด้วย sudo bash install.sh'
src=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
for f in app/index.php database/schema.sql database/repair-v322.sql database/repair-v332.sql tools/smoke.sh tools/post_update_test.sh; do
    [[ -r $src/$f ]] || die "ไฟล์ติดตั้งไม่ครบ ไม่พบ $f (แตก ZIP หรือ git clone ใหม่อีกครั้ง)"
done
grep -Fq "const APP_VERSION = '$sgr_version'" "$src/app/core.php" || die "app/core.php ไม่ใช่ V$sgr_version"
source /etc/os-release
case ${ID:-unknown} in debian|ubuntu) ;; *) die 'รองรับ Debian และ Ubuntu เท่านั้น';; esac
case $(dpkg --print-architecture) in arm64|amd64) ;; *) die 'รองรับ ARM64 และ AMD64 เท่านั้น';; esac
command -v systemctl >/dev/null && [[ -d /run/systemd/system ]] || die 'ต้องรันบนเซิร์ฟเวอร์ที่ใช้ systemd'
student_domain=${SGR_STUDENT_DOMAIN:-panda4477.online}
teacher_domain=${SGR_TEACHER_DOMAIN:-teacher.panda4477.online}
domain_re='^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$'
[[ $student_domain =~ $domain_re && $teacher_domain =~ $domain_re && $student_domain != "$teacher_domain" ]] \
    || die 'SGR_STUDENT_DOMAIN / SGR_TEACHER_DOMAIN ต้องเป็นชื่อโดเมนที่ถูกต้องและไม่ซ้ำกัน'
umask 022
exec 9>/run/lock/sgr-library-v3-install.lock
flock -n 9 || die 'มีตัวติดตั้งหรือตัวอัปเดต SGR ทำงานอยู่'
base=/opt/sgr-library-v3
settings=/etc/sgr-library-v3/settings.ini
state=/var/lib/sgr-library-v3
site=/etc/nginx/conf.d/sgr-library-v3.conf
install -d -m 755 "$base" "$base/releases"
install -d -m 700 "$base/backups"
stamp=$(date +%Y%m%d-%H%M%S)-$$
backup="$base/backups/install-v$sgr_version-$stamp"
release="$base/releases/v$sgr_version-$stamp"
mkdir -m 700 "$backup"
mkdir -m 755 "$release"
if [[ -e "$base/current" && ! -L "$base/current" ]]; then die "$base/current ต้องเป็นลิงก์ไปยังรุ่นที่ติดตั้ง"; fi
prior=$(readlink "$base/current" || true)
printf 'SGR Library V%s installer · %s %s · log: %s\n' "$sgr_version" "${PRETTY_NAME:-$ID}" "$(dpkg --print-architecture)" "$backup"

step 1/8 'ติดตั้งแพ็กเกจที่จำเป็น (Nginx, PHP-FPM, MariaDB, Python และเครื่องมือ)'
export DEBIAN_FRONTEND=noninteractive
apt_get() { apt-get -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef "$@"; }
apt_get update
default_ver=$(LC_ALL=C apt-cache depends php-fpm 2>/dev/null | sed -nE 's/.*Depends: php([0-9]+\.[0-9]+)-fpm.*/\1/p' | head -1)
ver=${SGR_PHP_VERSION:-$default_ver}
[[ $ver =~ ^[0-9]+\.[0-9]+$ ]] && dpkg --compare-versions "$ver" ge 8.2 || die 'ต้องมีแพ็กเกจ PHP 8.2 ขึ้นไป หากตั้งคลังแพ็กเกจไว้แล้ว เลือกรุ่นด้วย SGR_PHP_VERSION'
# Required to run the site. The installer stops if any of these cannot be installed.
required=(nginx mariadb-server mariadb-client python3 python3-pymysql python3-bcrypt curl rsync openssl gzip tar ca-certificates)
for mod in fpm cli common mysql mbstring xml curl zip gd intl; do required+=("php$ver-$mod"); done
# Server toolset (composer, firewall, fail2ban, cron, logrotate, htpasswd/ab ...). Installed when APT offers them.
optional=("php$ver-opcache" composer git wget jq unzip zip cron logrotate ufw fail2ban python3-systemd apache2-utils)
if [[ $ver == "$default_ver" ]]; then
    # Generic php-* meta packages follow the distro's default PHP, so add them only when that is the chosen version.
    for mod in fpm cli common mysql mbstring xml curl zip gd intl opcache; do optional+=("php-$mod"); done
fi
available() { [[ -n $(apt-cache policy "$1" 2>/dev/null | sed -nE 's/^ *Candidate: (.*)$/\1/p' | grep -v '(none)') ]]; }
installed() { [[ $(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true) == 'install ok installed' ]]; }
missing=()
for pkg in "${required[@]}"; do
    installed "$pkg" && continue
    available "$pkg" || die "คลัง APT ไม่มีแพ็กเกจ $pkg"
    missing+=("$pkg")
done
skipped=()
for pkg in "${optional[@]}"; do
    installed "$pkg" && continue
    if available "$pkg"; then
        missing+=("$pkg")
    elif [[ $pkg == php-* ]] && { installed "php$ver-${pkg#php-}" || [[ " ${missing[*]} " == *" php$ver-${pkg#php-} "* ]]; }; then
        :  # a virtual name (e.g. php-opcache on Ubuntu 24.04) already covered by php$ver-*
    else
        skipped+=("$pkg")
    fi
done
if (( ${#missing[@]} )); then
    echo "ติดตั้ง: ${missing[*]}"
    apt_get install -y --no-install-recommends "${missing[@]}"
else
    echo 'แพ็กเกจครบแล้ว'
fi
(( ${#skipped[@]} == 0 )) || echo "ข้าม (ไม่มีในคลัง APT ของเครื่องนี้): ${skipped[*]}"
php_bin="/usr/bin/php$ver"
"$php_bin" -r 'foreach (["pdo_mysql","mbstring","dom","curl","zip","gd","intl","fileinfo","zlib"] as $x) {if (!extension_loaded($x)) {fwrite(STDERR,"Missing PHP extension $x\n");exit(1);}}' \
    || die "PHP $ver ยังขาดส่วนขยาย"
"$php_bin" -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' || echo "หมายเหตุ: PHP $ver ไม่มี OPcache ระบบยังใช้งานได้แต่จะช้ากว่า"
echo "PHP $("$php_bin" -r 'echo PHP_VERSION;') · $(nginx -v 2>&1 | sed 's/.*: //') · $(mariadb --version | sed -E 's/.*(Distrib |from )//; s/[,-].*//')"

step 2/8 'เปิดบริการ MariaDB และตรวจพอร์ต 8088/8089'
systemctl enable --now mariadb
nginx -t || die 'การตั้งค่า Nginx เดิมของเครื่องนี้ยังไม่ผ่าน nginx -t (ระบบยังไม่ได้แก้ไฟล์ใด) ให้แก้ตามข้อความด้านบนก่อน ดู README หัวข้อแก้ปัญหา'
# Check which active files own the requested listen ports before changing any site.
nginx -T 2>/dev/null | python3 -c '
import re, sys
own = sys.argv[1]
source = ""
bad = set()
for line in sys.stdin:
    marker = re.match(r"# configuration file (.*):$",line.strip())
    if marker: source = marker.group(1)
    match = re.search(r"(?:^|[;{}])\s*listen\s+([^;\s]+)",line.split("#",1)[0])
    if match and source != own and match.group(1).rsplit(":",1)[-1] in ("8088","8089"):
        bad.add(source)
if bad:
    sys.exit("พอร์ต 8088/8089 ถูกใช้ใน " + ", ".join(sorted(bad)) + " โปรดจัดสรรพอร์ตเดิมก่อนติดตั้ง")
' "$site"
pool="/etc/php/$ver/fpm/pool.d/sgr-library-v3.conf"
for file in "$site" "$pool"; do
    if [[ -e "$file" || -L "$file" ]]; then
        [[ -f "$file" ]] && grep -Fq 'Managed by SGR Library V3' "$file" || die "มีไฟล์เดิมที่ระบบนี้ไม่ได้สร้าง $file"
    fi
done
if [[ -L "$site" || -L "$pool" ]]; then die 'พบลิงก์ Config ที่ต้องตรวจสอบก่อนติดตั้ง'; fi
# A pool of this app left behind by another PHP version would listen on the same socket.
for other in /etc/php/*/fpm/pool.d/sgr-library-v3.conf; do
    [[ -e $other && $other != "$pool" ]] && die "พบพูล SGR ของ PHP รุ่นอื่นที่ $other ให้ใช้ SGR_PHP_VERSION รุ่นเดิม หรือย้ายไฟล์นั้นออกก่อน"
done

step 3/8 'สร้างบัญชีระบบ โฟลเดอร์ข้อมูล และไฟล์ตั้งค่า'
getent group sgr-library >/dev/null || groupadd --system sgr-library
id -u sgr-library >/dev/null 2>&1 || useradd --system --gid sgr-library --no-create-home --home-dir "$state" --shell /usr/sbin/nologin sgr-library
install -d -m 750 -o root -g sgr-library /etc/sgr-library-v3 "$state"
install -d -m 700 -o sgr-library -g sgr-library "$state/uploads" "$state/sessions" "$state/tmp"
install -d -m 750 -o sgr-library -g sgr-library /var/log/sgr-library-v3
touch /var/log/sgr-library-v3/php.log
chown sgr-library:sgr-library /var/log/sgr-library-v3/php.log
chmod 640 /var/log/sgr-library-v3/php.log
admin=(mariadb --protocol=socket --batch --skip-column-names)
"${admin[@]}" -e 'SELECT 1' >/dev/null || die 'MariaDB ต้องยอมให้ root เข้าใช้งานผ่าน Unix socket (คำสั่ง sudo mariadb ต้องเข้าได้)'
dbname=sgr_library_v3
dbuser=sgr_library_v3
fresh_settings=0
if [[ ! -f "$settings" ]]; then
    db_exists=$("${admin[@]}" -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$dbname'")
    user_exists=$("${admin[@]}" -e "SELECT COUNT(*) FROM mysql.user WHERE User='$dbuser' AND Host='localhost'")
    [[ $db_exists == 0 && $user_exists == 0 ]] || die 'พบฐานข้อมูลหรือผู้ใช้เดิม แต่ไม่มี settings.ini กรุณากู้ไฟล์การตั้งค่าของเดิม'
    sock=$("${admin[@]}" -e 'SELECT @@socket')
    secret=$(openssl rand -hex 32)
    key=$(openssl rand -hex 32)
    contact=${SGR_CONTACT:-ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม}
    contact=${contact//$'\n'/ }
    tmp_settings=$(mktemp /etc/sgr-library-v3/.settings.XXXXXX)
    (umask 077; cat >"$tmp_settings" <<INI
[database]
socket=$sock
name=$dbname
user=$dbuser
password=$secret
[app]
storage=$state
key=$key
contact=$contact
INI
    )
    chown root:sgr-library "$tmp_settings"; chmod 640 "$tmp_settings"; mv -T "$tmp_settings" "$settings"
    fresh_settings=1
    echo "สร้าง $settings ใหม่ (รหัสฐานข้อมูลและกุญแจสุ่มใหม่)"
else
    cp -p "$settings" "$backup/settings.ini"
    # V3.3.3 renamed the centre to ศกร.ระดับตำบลบ้านใหม่; update only the untouched V3.0 default line.
    if grep -qx 'contact=สกร.ตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม' "$settings"; then
        sed -i 's/^contact=สกร.ตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม$/contact=ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม/' "$settings"
        echo 'ปรับชื่อศูนย์ใน contact ของ settings.ini เป็น ศกร.ระดับตำบลบ้านใหม่'
    fi
    # A settings.ini copied in by hand (e.g. from a backup) must stay readable by PHP-FPM only.
    chown root:sgr-library "$settings"; chmod 640 "$settings"
    echo "ใช้ $settings เดิม (สำรองไว้ที่ $backup/settings.ini)"
fi
# Generated DB credentials are hex. Read privately; never interpolate an arbitrary SQL identifier.
secret=$(python3 - "$settings" <<'PY'
import configparser, re, sys
c=configparser.ConfigParser(interpolation=None)
c.read(sys.argv[1],encoding="utf-8")
db=c["database"]
if (db["name"],db["user"])!=("sgr_library_v3","sgr_library_v3") or not re.fullmatch(r"[0-9a-fA-F]{64}",db["password"]):
    sys.exit("ตรวจชื่อฐานข้อมูลและรหัสฐานข้อมูลใน settings.ini ของตัวติดตั้ง")
print(db["password"])
PY
)

step 4/8 'สร้าง/ปรับฐานข้อมูล (สำรองของเดิมก่อนทุกครั้ง)'
"${admin[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS sgr_library_v3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sgr_library_v3'@'localhost' IDENTIFIED BY '$secret';
SQL
unset secret key
tables=$("${admin[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbname'")
if [[ $tables != 0 ]]; then
    # Only upgrade schemas this installer knows (V3.0 = 1, V3.2 = 2, V3.2.2+ = 3).
    known=$("${admin[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbname' AND table_name='sgr_schema'")
    if [[ $known == 1 ]]; then
        version=$("${admin[@]}" "$dbname" -e 'SELECT version_no FROM sgr_schema WHERE id=1')
        [[ -z $version || $version =~ ^[123]$ ]] || die "Schema version $version ใหม่กว่าตัวติดตั้งนี้ ต้องใช้ตัวติดตั้งรุ่นที่ตรงกัน"
    fi
    echo "พบฐานข้อมูลเดิม $tables ตาราง กำลังสำรอง..."
    mariadb-dump --protocol=socket --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "$dbname" \
        | gzip -c >"$backup/database.sql.gz"
    chmod 600 "$backup/database.sql.gz"
    echo "สำรองฐานข้อมูลไว้ที่ $backup/database.sql.gz"
fi
"${admin[@]}" "$dbname" < "$src/database/schema.sql"
"${admin[@]}" "$dbname" < "$src/database/repair-v322.sql" >"$backup/schema-repair.log" 2>&1 || { cat "$backup/schema-repair.log" >&2; die 'ปรับฐานข้อมูล (V3.2) ไม่สำเร็จ'; }
"${admin[@]}" "$dbname" < "$src/database/repair-v332.sql" >>"$backup/schema-repair.log" 2>&1 || { cat "$backup/schema-repair.log" >&2; die 'ปรับฐานข้อมูล (V3.3) ไม่สำเร็จ'; }
# The web account gets table-level rights only (no DDL).
sed -e 's/@DB@/sgr_library_v3/g' -e 's/@USER@/sgr_library_v3/g' -e 's/@HOST@/localhost/g' "$src/database/runtime-grants.sql.in" | "${admin[@]}"
bash "$src/tools/schema_check.sh" "$dbname" | tee "$backup/schema-check.txt" || die 'โครงสร้างฐานข้อมูลยังไม่ครบ'

step 5/8 "เตรียมโค้ด V$sgr_version ที่ $release"
for part in app assets config database nginx public tools storage; do rsync -a --delete "$src/$part/" "$release/$part/"; done
install -m 0644 "$src/README.md" "$release/README.md"
[[ ! -f $src/TEST-RESULTS.md ]] || install -m 0644 "$src/TEST-RESULTS.md" "$release/TEST-RESULTS.md"
install -m 0755 "$src/install.sh" "$release/install.sh"
find "$release" -type d -exec chmod 0755 {} +
find "$release" -type f -exec chmod 0644 {} +
chmod 755 "$release/install.sh" "$release/tools/"*.sh "$release/tools/"*.py
while IFS= read -r -d '' file; do "$php_bin" -l "$file"; done < <(find "$release" -type f -name '*.php' -print0) >"$backup/php-lint.log" \
    || { cat "$backup/php-lint.log" >&2; die 'ตรวจไวยากรณ์ PHP ไม่ผ่าน'; }
echo "ตรวจ PHP ผ่าน $(grep -c 'No syntax errors' "$backup/php-lint.log") ไฟล์"
# Prepare complete files before activation. The old site, pool and release are restored together.
[[ ! -e $site ]] || cp -a "$site" "$backup/site.conf"
[[ ! -e $pool ]] || cp -a "$pool" "$backup/pool.conf"
{ echo "# Managed by SGR Library V3 (V$sgr_version)"; python3 "$release/tools/render_nginx.py" --root "$base/current" --fpm unix:/run/php/sgr-library-v3.sock \
    --student-domain "$student_domain" --teacher-domain "$teacher_domain"; } >"$backup/new-site.conf"

step 6/8 'เปิดใช้งาน Nginx + PHP-FPM'
activated=0
success=0
restore() {
    local result=$?
    trap - EXIT
    if [[ $activated == 1 && $success == 0 ]]; then
        set +e
        echo "กำลังกู้คืน Nginx, PHP-FPM และรุ่นก่อนหน้า จาก $backup" >&2
        if [[ -e $backup/site.conf ]]; then cp -a "$backup/site.conf" "$site"; else rm -f -- "$site"; fi
        if [[ -e $backup/pool.conf ]]; then cp -a "$backup/pool.conf" "$pool"; else rm -f -- "$pool"; fi
        if [[ -n $prior ]]; then ln -sfnT "$prior" "$base/current"; else rm -f -- "$base/current"; fi
        if "/usr/sbin/php-fpm$ver" -t; then systemctl reload-or-restart "php$ver-fpm"; fi
        if nginx -t; then systemctl reload-or-restart nginx; fi
        [[ ! -f $backup/database.sql.gz ]] || echo "ฐานข้อมูลก่อนติดตั้งอยู่ที่ $backup/database.sql.gz (คืนค่าด้วย: zcat ไฟล์นี้ | sudo mariadb sgr_library_v3)" >&2
    fi
    exit "$result"
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
activated=1
ln -s "$release" "$base/.current-$stamp"; mv -Tf "$base/.current-$stamp" "$base/current"
install -m 644 "$backup/new-site.conf" "$site"; install -m 644 "$release/config/fpm-pool.conf" "$pool"
"/usr/sbin/php-fpm$ver" -t
nginx -t
systemctl enable "php$ver-fpm" nginx
systemctl reload-or-restart "php$ver-fpm"
systemctl reload-or-restart nginx

step 7/8 'ทดสอบระบบหลังติดตั้ง'
# Boot and graceful reload may briefly leave the previous worker responding.
passed=0
for _ in 1 2 3 4 5 6 7 8; do
    if PHP_BIN="$php_bin" bash "$release/tools/smoke.sh" >"$backup/smoke.log" 2>&1; then passed=1; break; fi
    sleep 1
done
cat "$backup/smoke.log"
[[ $passed == 1 ]] || die 'Smoke test ไม่ผ่าน การติดตั้งยังไม่สำเร็จ'
if [[ ${SGR_SKIP_FULL_TEST:-0} != 1 ]]; then
    # Signs in as a temporary teacher and student, opens every page, writes and exports, then removes them.
    bash "$release/tools/post_update_test.sh" "$dbname" "$php_bin" >"$backup/full-test.log" 2>&1 \
        || { cat "$backup/full-test.log" >&2; die 'ทดสอบระบบเต็มรูปแบบไม่ผ่าน'; }
    tail -1 "$backup/full-test.log"
fi
success=1

step 8/8 'ตั้งค่าเครื่องมือเซิร์ฟเวอร์ (logrotate, cron, fail2ban, ufw)'
managed_file() { [[ ! -e $1 ]] || grep -Fq 'Managed by SGR Library V3' "$1"; }
if [[ -d /etc/logrotate.d ]] && managed_file /etc/logrotate.d/sgr-library-v3; then
    cat >/etc/logrotate.d/sgr-library-v3 <<'ROTATE'
# Managed by SGR Library V3
/var/log/sgr-library-v3/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    su sgr-library sgr-library
    create 0640 sgr-library sgr-library
}
ROTATE
    echo 'logrotate: หมุนไฟล์ /var/log/sgr-library-v3/*.log ทุกสัปดาห์ เก็บ 8 รอบ'
fi
if installed cron; then systemctl enable --now cron >/dev/null 2>&1 || echo 'หมายเหตุ: เปิดบริการ cron ไม่สำเร็จ'; fi
if [[ ${SGR_DAILY_BACKUP:-0} == 1 ]] && managed_file /etc/cron.d/sgr-library-v3-backup; then
    cat >/etc/cron.d/sgr-library-v3-backup <<CRON
# Managed by SGR Library V3: nightly backup of database, uploads and settings (keeps 14 copies).
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
30 2 * * * root $base/current/tools/backup.sh --keep 14 >>/var/log/sgr-library-v3/backup.log 2>&1
CRON
    chmod 644 /etc/cron.d/sgr-library-v3-backup
    echo "cron: สำรองข้อมูลทุกคืน 02:30 ไปที่ $base/backups/"
fi
if installed fail2ban; then
    if [[ ! -e /var/log/auth.log ]] && installed python3-systemd && managed_file /etc/fail2ban/jail.d/sgr-library-sshd.local; then
        # Debian without rsyslog has no auth.log; read sshd logins from the systemd journal instead.
        printf '# Managed by SGR Library V3\n[sshd]\nbackend = systemd\n' >/etc/fail2ban/jail.d/sgr-library-sshd.local
    fi
    if systemctl enable fail2ban >/dev/null 2>&1 && systemctl restart fail2ban >/dev/null 2>&1; then
        echo 'fail2ban: เปิดใช้งาน (ป้องกันการเดารหัส SSH)'
    else
        echo 'หมายเหตุ: fail2ban ติดตั้งแล้วแต่ยังเริ่มทำงานไม่ได้ ตรวจด้วย sudo systemctl status fail2ban'
    fi
fi
if command -v ufw >/dev/null; then
    if [[ ${SGR_OPEN_FIREWALL:-0} == 1 ]]; then
        # Rules only: ufw is never switched on here, so an SSH session cannot be locked out by the installer.
        # The OpenSSH profile exists only when openssh-server is installed; fall back to the standard port.
        if ufw allow OpenSSH >/dev/null 2>&1; then ssh_rule=OpenSSH; else ufw allow 22/tcp comment 'SSH' >/dev/null; ssh_rule=22/tcp; fi
        ufw allow 8088/tcp comment 'SGR Library student' >/dev/null
        ufw allow 8089/tcp comment 'SGR Library teacher' >/dev/null
        echo "ufw: เพิ่มกฎ $ssh_rule, 8088/tcp, 8089/tcp แล้ว"
    fi
    if ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw status | grep -Eq '^(8088|8089)/tcp' || echo 'หมายเหตุ: ufw เปิดอยู่ หากเครื่องอื่นเข้าไม่ได้ ให้รัน sudo ufw allow 8088/tcp && sudo ufw allow 8089/tcp'
    else
        echo 'ufw: ติดตั้งแล้วแต่ยังไม่เปิด (ดูวิธีเปิดอย่างปลอดภัยใน README หัวข้อ Firewall)'
    fi
fi
# Keep the five newest releases; older ones are only code (data lives in MariaDB and /var/lib).
mapfile -t old_releases < <(find "$base/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | awk 'NR>5{print $2}')
for dir in "${old_releases[@]}"; do
    [[ $(readlink -f "$base/current") == "$dir" ]] || rm -rf -- "$dir"
done
ips=$(hostname -I 2>/dev/null | awk '{print $1}')
ips=${ips:-SERVER-IP}
echo "ติดตั้ง SGR Library V$sgr_version สำเร็จ"
echo "  นักศึกษา: http://$ips:8088/   (โดเมน: https://$student_domain/)"
echo "  ครู:      http://$ips:8089/   (โดเมน: https://$teacher_domain/)"
echo "  ไฟล์สำรองและผลทดสอบ: $backup"
teachers=$(mariadb --protocol=socket -N "$dbname" -e "SELECT COUNT(*) FROM users WHERE role='teacher'")
if [[ $teachers == 0 ]]; then
    if [[ -t 0 && -t 1 ]]; then
        read -r -p 'ยังไม่มีบัญชีครู สร้างตอนนี้เลยไหม? [Y/n] ' answer || answer=n
        if [[ ! $answer =~ ^[Nn] ]]; then /usr/bin/python3 "$base/current/tools/create_teacher.py" || true; fi
    fi
    teachers=$(mariadb --protocol=socket -N "$dbname" -e "SELECT COUNT(*) FROM users WHERE role='teacher'")
    [[ $teachers != 0 ]] || echo "สร้างบัญชีครูคนแรกด้วย: sudo /usr/bin/python3 $base/current/tools/create_teacher.py"
fi
[[ $fresh_settings == 0 ]] || echo "แก้ช่องทางติดต่อที่แสดงหน้าแรกได้ที่ $settings (คีย์ contact)"
