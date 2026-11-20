#!/usr/bin/env bash
# Back up everything a restore needs at one point in time: the MariaDB database,
# the uploaded files and settings.ini, packed into one archive.
#
#   sudo bash tools/backup.sh                 # -> /opt/sgr-library-v3/backups/sgr-backup-YYYYmmdd-HHMMSS.tar
#   sudo bash tools/backup.sh --keep 14       # also delete older archives, keeping the 14 newest
#   sudo bash tools/backup.sh --dest /mnt/usb # write somewhere else
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
die() { echo "[ERROR] $*" >&2; exit 1; }
dest=/opt/sgr-library-v3/backups
keep=0
settings=${SGR_CONFIG:-/etc/sgr-library-v3/settings.ini}
while (( $# )); do
    case $1 in
        --dest) dest=${2:?}; shift 2 ;;
        --keep) keep=${2:?}; shift 2 ;;
        -h|--help) sed -n '2,8p' "$0"; exit 0 ;;
        *) die "ไม่รู้จักตัวเลือก $1" ;;
    esac
done
[[ $EUID == 0 ]] || die 'กรุณารันด้วย sudo'
[[ $keep =~ ^[0-9]+$ ]] || die '--keep ต้องเป็นตัวเลข'
[[ -r $settings ]] || die "ไม่พบ $settings"
read -r db storage < <(python3 - "$settings" <<'PY'
import configparser, sys
c = configparser.ConfigParser(interpolation=None)
c.read(sys.argv[1], encoding="utf-8")
print(c["database"]["name"], c["app"]["storage"])
PY
)
[[ $db =~ ^[A-Za-z0-9_]+$ && -d $storage ]] || die 'อ่านชื่อฐานข้อมูลหรือโฟลเดอร์ข้อมูลจาก settings.ini ไม่ได้'
install -d -m 700 "$dest"
stamp=$(date +%Y%m%d-%H%M%S)
work=$(mktemp -d "$dest/.backup-$stamp.XXXXXX")
trap 'rm -rf -- "$work"' EXIT
mariadb-dump --protocol=socket --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "$db" | gzip -c >"$work/database.sql.gz"
tar -C "$storage" -czf "$work/uploads.tar.gz" uploads
cp -p "$settings" "$work/settings.ini"
printf 'SGR Library backup\ncreated=%s\ndatabase=%s\nstorage=%s\nversion=%s\n' "$(date -Is)" "$db" "$storage" \
    "$(sed -nE "s/^const APP_VERSION = '([^']+)';/\1/p" /opt/sgr-library-v3/current/app/core.php 2>/dev/null || echo unknown)" >"$work/INFO.txt"
out="$dest/sgr-backup-$stamp.tar"
tar -C "$work" -cf "$out.part" INFO.txt database.sql.gz uploads.tar.gz settings.ini
chmod 600 "$out.part"
mv -f "$out.part" "$out"
echo "สำรองแล้ว: $out ($(du -h "$out" | cut -f1))"
if (( keep > 0 )); then
    mapfile -t old < <(ls -1t "$dest"/sgr-backup-*.tar 2>/dev/null | tail -n +$((keep + 1)))
    for f in "${old[@]}"; do rm -f -- "$f"; echo "ลบไฟล์สำรองเก่า: $f"; done
fi
