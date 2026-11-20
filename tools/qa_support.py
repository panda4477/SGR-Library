"""HTTP and database fixtures for a disposable, loopback-only acceptance environment."""
import configparser
import http.cookiejar
import json
import os
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request

import pymysql


def test_config():
    path = os.environ.get("SGR_CONFIG", "")
    cfg = configparser.ConfigParser(interpolation=None)
    if not path or not cfg.read(path, encoding="utf-8") or not cfg["database"]["name"].startswith("sgr_qa"):
        raise SystemExit("ชุดทดสอบสร้างข้อมูลตัวอย่าง ใช้เฉพาะ SGR_CONFIG ของฐานทดสอบที่ชื่อขึ้นต้นด้วย sgr_qa")
    return cfg


def connect(use_fixtures=True):
    config = test_config()
    fixture_file = os.environ.get("SGR_QA_FIXTURES")
    if use_fixtures and fixture_file:
        alt = configparser.ConfigParser(interpolation=None)
        with open(fixture_file, encoding="utf-8") as source:
            alt.read_file(source)
        cfg = alt["qa_database"]
    else:
        cfg = config["database"]
    for key in ("name", "socket", "port"):
        if cfg.get(key) != config["database"].get(key):
            raise ValueError("QA fixture connection must address the same disposable database as the app")
    transport = ({"unix_socket": cfg["socket"]} if cfg.get("socket") else
                 {"host": "127.0.0.1", "port": cfg.getint("port", 3306)})
    conn = pymysql.connect(user=cfg["user"], password=cfg["password"], database=cfg["name"],
                           charset="utf8mb4", autocommit=True, **transport)
    with conn.cursor() as cur:
        cur.execute("SET time_zone='+07:00'")
    return conn


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Response:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.body = body

    @property
    def text(self):
        return self.body.decode("utf-8")

    def json(self):
        return json.loads(self.body)


class Client:
    def __init__(self, portal="student"):
        self.base = os.environ.get(portal.upper()+"_URL", "http://127.0.0.1:"+("8088" if portal=="student" else "8089")).rstrip("/")
        url = urllib.parse.urlsplit(self.base)
        if url.scheme != "http" or url.hostname not in {"127.0.0.1", "localhost", "::1"}:
            raise ValueError("QA HTTP endpoint must use loopback")
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}),
                                                   urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, fields=None, files=None, headers=None):
        if not path.startswith("/") or path.startswith("//"):
            raise ValueError("Expected a portal-relative path")
        headers = dict(headers or {})
        data = None
        if files:
            boundary = "SGR" + secrets.token_hex(16)
            chunks = []
            for key, value in (fields or {}).items():
                chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            for key, (name, mime, content) in files.items():
                chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b"\r\n")
            data = b"".join(chunks) + f"--{boundary}--\r\n".encode()
            headers["Content-Type"] = "multipart/form-data; boundary="+boundary
        elif fields is not None:
            data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base+path, data=data, headers=headers)
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return Response(response.getcode(),response.headers,response.read())

    def token(self, path):
        r = self.request(path)
        expect(r, 200, path)
        match = re.search(r'name="_csrf" value="([0-9a-f]+)"', r.text)
        if not match:
            raise AssertionError("Missing CSRF field on "+path)
        return match[1]

    def post(self, path, data, files=None, token_from=None, status=303):
        payload = {"_csrf": self.token(token_from or path), **data}
        result = self.request(path, payload, files)
        expect(result, status, path)
        if status == 303 and not re.match(r"^/(?!/)",result.headers.get("Location","")):
            raise AssertionError("Redirect must stay portal-relative")
        return result

    def login(self, username, password):
        return self.post("/login.php", {"username":username, "password":password})


def expect(response, status, label):
    if response.status != status:
        clean = " ".join(re.sub("<[^>]+>"," ",response.text).split())
        raise AssertionError(f"{label} expected HTTP {status}, got {response.status}: {clean[-420:]}")
    return response


def unique_names():
    suffix = secrets.token_hex(5)
    return "qa_"+suffix, "Qa!"+secrets.token_urlsafe(15)
