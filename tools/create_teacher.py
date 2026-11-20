#!/usr/bin/env python3
"""Create a teacher on the server. The password is read from the terminal only."""
import argparse
import configparser
import getpass
import os
import re
import sys

import bcrypt
import pymysql


def create_teacher(config_path, username, password, display_name):
    if not re.fullmatch(r"[A-Za-z0-9_.-]{3,60}", username):
        raise ValueError("ชื่อผู้ใช้ต้องมี 3–60 ตัว ใช้อักษรอังกฤษ ตัวเลข หรือ . _ -")
    raw = password.encode("utf-8")
    if len(password) < 8 or len(raw) > 72 or b"\0" in raw:
        raise ValueError("รหัสผ่านต้องมีอย่างน้อย 8 ตัว และไม่เกิน 72 ไบต์")
    if not display_name or len(display_name) > 100:
        raise ValueError("กรุณาระบุชื่อครูไม่เกิน 100 ตัว")
    config = configparser.ConfigParser(interpolation=None)
    with open(config_path, encoding="utf-8") as source:
        config.read_file(source)
    settings = config["database"]
    transport = ({"unix_socket": settings["socket"]} if settings.get("socket") else
                 {"host": "127.0.0.1", "port": settings.getint("port", 3306)})
    conn = pymysql.connect(user=settings["user"], password=settings["password"], database=settings["name"],
                           charset="utf8mb4", **transport)
    try:
        hashed = bcrypt.hashpw(raw, bcrypt.gensalt(rounds=12)).decode("ascii")
        with conn.cursor() as cur:
            cur.execute("INSERT INTO users(role,username,password_hash,first_name) VALUES('teacher',%s,%s,%s)",
                        (username, hashed, display_name))
            teacher_id = cur.lastrowid
            cur.execute("INSERT INTO audit_log(actor_id,event_name,entity_id,details) VALUES(%s,'teacher.cli_create',%s,'{}')",
                        (teacher_id, teacher_id))
        conn.commit()
    except BaseException:
        conn.rollback()
        raise
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser(description="สร้างบัญชีครู SGR Library")
    parser.add_argument("--config", default=os.environ.get("SGR_CONFIG", "/etc/sgr-library-v3/settings.ini"))
    args = parser.parse_args()
    username = input("Username: ").strip()
    password = getpass.getpass("Password: ")
    if password != getpass.getpass("ยืนยัน Password: "):
        sys.exit("รหัสผ่านทั้งสองช่องไม่ตรงกัน")
    name = input("ชื่อครู: ").strip()
    try:
        create_teacher(args.config, username, password, name)
    except pymysql.err.IntegrityError:
        sys.exit("ชื่อผู้ใช้ครูนี้มีอยู่แล้ว")
    except (ValueError, OSError, pymysql.MySQLError) as exc:
        sys.exit(f"สร้างบัญชีไม่สำเร็จ ({type(exc).__name__}): " + (str(exc) if isinstance(exc, ValueError) else "ตรวจการตั้งค่าฐานข้อมูลและสิทธิ์บนเซิร์ฟเวอร์"))
    print("สร้างบัญชีครูแล้ว")


if __name__ == "__main__":
    main()
