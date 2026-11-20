#!/usr/bin/env python3
"""Write the two origin servers and their trusted-tunnel map to standard output."""
import argparse
import re
from pathlib import Path

DOMAIN = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$")

parser = argparse.ArgumentParser()
parser.add_argument("--root", required=True, type=Path)
parser.add_argument("--fpm", required=True, help="unix:/path/to/fpm.sock or loopback host:port")
parser.add_argument("--params", default="/etc/nginx/fastcgi_params")
parser.add_argument("--student-port", type=int, default=8088)
parser.add_argument("--teacher-port", type=int, default=8089)
parser.add_argument("--student-domain", default="panda4477.online", help="public domain of the student portal (Cloudflare Tunnel)")
parser.add_argument("--teacher-domain", default="teacher.panda4477.online", help="public domain of the teacher portal (Cloudflare Tunnel)")
parser.add_argument("--loopback", action="store_true", help="Bind only localhost for an isolated test stack")
args = parser.parse_args()
if not 1 <= args.student_port <= 65535 or not 1 <= args.teacher_port <= 65535 or args.student_port == args.teacher_port:
    parser.error("Two different TCP ports in the range 1-65535 are required")
if not DOMAIN.match(args.student_domain) or not DOMAIN.match(args.teacher_domain) or args.student_domain == args.teacher_domain:
    parser.error("Two different valid domain names are required")
nginx = Path(__file__).resolve().parents[1] / "nginx"
print((nginx / "proxy-map.conf").read_text().replace("@STUDENT_DOMAIN@", args.student_domain).replace("@TEACHER_DOMAIN@", args.teacher_domain))
template = (nginx / "portal.conf.in").read_text()
for kind, port, domain in [("student", args.student_port, args.student_domain), ("teacher", args.teacher_port, args.teacher_domain)]:
    result = template
    for token, value in {"APP": str(args.root), "PORT": ("127.0.0.1:" if args.loopback else "") + str(port), "DOMAIN": domain, "PORTAL": kind,
                         "FPM": args.fpm, "FASTCGI_PARAMS": args.params}.items():
        result = result.replace("@" + token + "@", value)
    print(result)
