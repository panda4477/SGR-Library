#!/usr/bin/env bash
set -Eeuo pipefail
base=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
student_url=${STUDENT_URL:-http://127.0.0.1:8088}
teacher_url=${TEACHER_URL:-http://127.0.0.1:8089}
php_bin=${PHP_BIN:-php}
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
while IFS= read -r -d '' file; do "$php_bin" -l "$file" >/dev/null; done < <(find "$base" -type f -name '*.php' -print0)
check_route() {
    local portal=$1 url=$2 route=$3 expected=$4 marker=${5:-} status
    status=$(curl --noproxy '*' --silent --show-error --max-time 12 --output "$tmp/body" --dump-header "$tmp/headers" --write-out '%{http_code}' "$url$route")
    if [[ "$status" != "$expected" ]] || ! grep -iq "^X-SGR-Portal: $portal" "$tmp/headers" || { [[ -n "$marker" ]] && ! grep -Fq -- "$marker" "$tmp/body"; }; then
        echo "FAIL $portal $route expected $expected, got $status, or wrong portal/content" >&2
        return 1
    fi
    echo "$portal $route $status"
}
for portal in student teacher; do
    url=$student_url
    [[ $portal == teacher ]] && url=$teacher_url
    check_route "$portal" "$url" / 200 'ศกร.ระดับตำบลบ้านใหม่'
    check_route "$portal" "$url" /login.php 200 'name="password"'
    check_route "$portal" "$url" /api/me 401 '"error":401'
    check_route "$portal" "$url" /__missing_sgr__ 404 'ไม่พบหน้าที่คุณต้องการ'
    check_route "$portal" "$url" /login.php 200 'site.css?v=3.3.3'
    if [[ $portal == student ]]; then
        check_route "$portal" "$url" / 200 'lp-hero'
        check_route "$portal" "$url" /info.php 200 'Pawarit Sosirikul'
        check_route "$portal" "$url" /register.php 200 'name="student_code"'
    else
        # The teacher portal opens straight into the login popup and has no public pages.
        check_route "$portal" "$url" / 200 'login-pop'
        check_route "$portal" "$url" /register.php 404 'ไม่พบหน้าที่คุณต้องการ'
    fi
done
echo 'PHP syntax and portal smoke tests passed.'
