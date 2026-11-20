#!/usr/bin/env bash
# Restore an archive made by tools/backup.sh onto an installed server (same or new machine).
# The current database and uploads are backed up first, so a restore can itself be undone.
#
#   sudo bash /opt/sgr-library-v3/current/tools/restore.sh /path/sgr-backup-YYYYmmdd-HHMMSS.tar
#   sudo bash .../restore.sh --yes backup.tar     # no confirmation prompt (for scripts)
#
# settings.ini of this server is kept (its database password belongs to this server). The copy
# inside the archive is only used to read the old contact text, which is printed for reference.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
die() { echo "[ERROR] $*" >&2; exit 1; }
yes=0; archive=
while (( $# )); do
    case $1 in
        --yes|-y) yes=1; shift ;;
        -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
        -*) die "ไม่รู้จักตัวเลือก $1" ;;
        *) [[ -z $archive ]] || die 'ระบุไฟล์สำรองได้ไฟล์เดียว'; archive=$1; shift ;;
    esac
done
[[ $EUID == 0 ]] || die 'กรุณารันด้วย sudo'
[[ -n $archive && -r $archive ]] || die 'ระบุไฟล์ sgr-backup-*.tar ที่ต้องการกู้คืน'
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
settings=${SGR_CONFIG:-/etc/sgr-library-v3/settings.ini}
[[ -r $settings && -r $here/database/schema.sql ]] || die 'ต้องติดตั้งระบบด้วย install.sh ก่อน แล้วจึงกู้คืนข้อมูล'
read -r db storage < <(python3 - "$settings" <<'PY'
import configparser, sys
c = configparser.ConfigParser(interpolation=None)
c.read(sys.argv[1], encoding="utf-8")
print(c["database"]["name"], c["app"]["storage"])
PY
)
[[ $db =~ ^[A-Za-z0-9_]+$ && -d $storage ]] || die 'อ่านชื่อฐานข้อมูลหรือโฟลเดอร์ข้อมูลจาก settings.ini ไม่ได้'
members=$(tar -tf "$archive" 2>/dev/null | sort | tr '\n' ' ') || die 'เปิดไฟล์สำรองไม่ได้'
[[ $members == 'INFO.txt database.sql.gz settings.ini uploads.tar.gz ' ]] || die 'ไฟล์นี้ไม่ใช่ไฟล์สำรองจาก tools/backup.sh'
work=$(mktemp -d /var/tmp/sgr-restore.XXXXXX)
trap 'rm -rf -- "$work"' EXIT
chmod 700 "$work"
tar -C "$work" -xf "$archive"
gzip -t "$work/database.sql.gz" || die 'database.sql.gz ในไฟล์สำรองเสียหาย'
tar -tzf "$work/uploads.tar.gz" >/dev/null || die 'uploads.tar.gz ในไฟล์สำรองเสียหาย'
# Members must stay inside uploads/ (no absolute paths, no ..).
if tar -tzf "$work/uploads.tar.gz" | grep -Ev '^uploads(/|$)' | grep -q . ||
   tar -tzf "$work/uploads.tar.gz" | grep -Eq '(^|/)\.\.(/|$)'; then
    die 'uploads.tar.gz มีพาธนอกโฟลเดอร์ uploads'
fi
echo '--- ข้อมูลไฟล์สำรอง ---'
cat "$work/INFO.txt"
echo '-----------------------'
if (( ! yes )); then
    [[ -t 0 ]] || die 'ต้องยืนยันการกู้คืน ใช้ --yes เมื่อรันจากสคริปต์'
    read -r -p "จะเขียนทับฐานข้อมูล $db และไฟล์อัปโหลดปัจจุบันทั้งหมด พิมพ์ yes เพื่อยืนยัน: " answer
    [[ $answer == yes ]] || die 'ยกเลิกการกู้คืน'
fi
echo 'สำรองข้อมูลปัจจุบันก่อนกู้คืน...'
bash "$here/tools/backup.sh" --dest /opt/sgr-library-v3/backups/before-restore

admin=(mariadb --protocol=socket --batch --skip-column-names)
echo "นำเข้าฐานข้อมูล $db..."
gunzip -c "$work/database.sql.gz" | "${admin[@]}" "$db"
# An older backup (V3.0/V3.2) is brought up to this version's schema; all scripts are idempotent.
"${admin[@]}" "$db" <"$here/database/schema.sql"
"${admin[@]}" "$db" <"$here/database/repair-v322.sql"
"${admin[@]}" "$db" <"$here/database/repair-v332.sql"
dbuser=$(python3 -c 'import configparser,sys; c=configparser.ConfigParser(interpolation=None); c.read(sys.argv[1],encoding="utf-8"); print(c["database"]["user"])' "$settings")
[[ $dbuser =~ ^[A-Za-z0-9_]+$ ]] || die 'อ่านชื่อผู้ใช้ฐานข้อมูลจาก settings.ini ไม่ได้'
sed -e "s/@DB@/$db/g" -e "s/@USER@/$dbuser/g" -e 's/@HOST@/localhost/g' "$here/database/runtime-grants.sql.in" | "${admin[@]}"
bash "$here/tools/schema_check.sh" "$db" >/dev/null || die 'โครงสร้างฐานข้อมูลหลังกู้คืนยังไม่ครบ'
# Login attempt counters from the old server are not useful here.
"${admin[@]}" "$db" -e 'DELETE FROM rate_limits' || true

echo 'กู้คืนไฟล์อัปโหลด...'
stamp=$(date +%Y%m%d-%H%M%S)
staging="$storage/.uploads-restore-$stamp"
mkdir "$staging"
tar -C "$staging" -xzf "$work/uploads.tar.gz" --no-same-owner
chown -R sgr-library:sgr-library "$staging/uploads"
chmod 700 "$staging/uploads"
if [[ -d $storage/uploads ]]; then mv -T "$storage/uploads" "$staging/uploads.old"; fi
mv -T "$staging/uploads" "$storage/uploads"
rm -rf -- "$staging"

old_contact=$(python3 -c 'import configparser,sys; c=configparser.ConfigParser(interpolation=None); c.read(sys.argv[1],encoding="utf-8"); print(c["app"].get("contact",""))' "$work/settings.ini" || true)
echo 'กู้คืนสำเร็จ'
echo "  ข้อมูลก่อนกู้คืนถูกสำรองไว้ที่ /opt/sgr-library-v3/backups/before-restore/"
echo "  settings.ini ของเครื่องนี้ใช้ต่อตามเดิม (contact เดิมในไฟล์สำรอง: ${old_contact:-ไม่มี})"
echo "  ตรวจระบบ: sudo bash $here/tools/smoke.sh"
