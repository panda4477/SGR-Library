# SGR Library V3.3.3

ระบบนักศึกษาและครูของ **ศกร.**
ทำงานบน Nginx + PHP-FPM 8.2 ขึ้นไป + MariaDB ใช้เวลาประเทศไทย
มีสองเว็บจากโค้ดชุดเดียว: **นักศึกษา (พอร์ต 8088)** และ **ครู (พอร์ต 8089)** แต่ละเว็บมีหน้าแรก หน้าเข้าสู่ระบบ และคุกกี้เซสชันแยกกัน

ตัวติดตั้ง `install.sh` ลงทุกอย่างให้ในคำสั่งเดียว ได้แก่แพ็กเกจระบบ ฐานข้อมูล Nginx PHP-FPM logrotate cron และ fail2ban จากนั้นทดสอบเว็บก่อนรายงานว่าสำเร็จ

---

## สารบัญ

1. [สิ่งที่ต้องมี](#1-สิ่งที่ต้องมี)
2. [ติดตั้งแบบเร็ว](#2-ติดตั้งแบบเร็ว)
3. [install.sh ทำอะไรบ้าง](#3-installsh-ทำอะไรบ้าง)
4. [แพ็กเกจและเครื่องมือที่ติดตั้ง](#4-แพ็กเกจและเครื่องมือที่ติดตั้ง)
5. [ตัวเลือกตอนติดตั้ง](#5-ตัวเลือกตอนติดตั้ง)
6. [ใช้งานครั้งแรก](#6-ใช้งานครั้งแรก)
7. [Firewall (ufw) และ fail2ban](#7-firewall-ufw-และ-fail2ban)
8. [เชื่อมโดเมนด้วย Cloudflare Tunnel](#8-เชื่อมโดเมนด้วย-cloudflare-tunnel)
9. [อัปเดตและย้อนรุ่น](#9-อัปเดตและย้อนรุ่น)
10. [สำรองและกู้คืนข้อมูล](#10-สำรองและกู้คืนข้อมูล)
11. [เครื่องมือในโฟลเดอร์ tools](#11-เครื่องมือในโฟลเดอร์-tools)
12. [คำสั่งดูแลระบบที่ใช้บ่อย](#12-คำสั่งดูแลระบบที่ใช้บ่อย)
13. [ตำแหน่งไฟล์บนเซิร์ฟเวอร์](#13-ตำแหน่งไฟล์บนเซิร์ฟเวอร์)
14. [แก้ปัญหา](#14-แก้ปัญหา)
15. [โครงสร้างซอร์สโค้ด](#15-โครงสร้างซอร์สโค้ด)

---

## 1. สิ่งที่ต้องมี

| รายการ | รายละเอียด |
|---|---|
| ระบบปฏิบัติการ | Debian 12/13 หรือ Ubuntu 24.04 ขึ้นไป แบบ systemd (Ubuntu 22.04 ใช้ได้เมื่อเพิ่มคลัง PHP 8.2+ ดู[หัวข้อ 14](#14-แก้ปัญหา)) |
| CPU | AMD64 (x86_64) หรือ ARM64 (เช่น Raspberry Pi 4/5 ที่ลงระบบ 64 บิต) |
| หน่วยความจำ/ดิสก์ | แนะนำ RAM 1 GB ขึ้นไป และพื้นที่ว่าง 2 GB ขึ้นไป (ไม่รวมไฟล์ที่นักศึกษาอัปโหลด) |
| สิทธิ์ | บัญชีที่ใช้ `sudo` ได้ และเครื่องต่ออินเทอร์เน็ตเพื่อลงแพ็กเกจ |
| พอร์ต | 8088 และ 8089 ต้องว่าง (ตัวติดตั้งจะตรวจและแจ้งถ้ามีเว็บอื่นใช้อยู่) |
| MariaDB | ถ้าเครื่องมี MariaDB อยู่แล้ว คำสั่ง `sudo mariadb` ต้องเข้าได้โดยไม่ถามรหัส (ค่าเริ่มต้นของ Debian/Ubuntu) ถ้ายังไม่มี ตัวติดตั้งจะลงให้ |

---

## 2. ติดตั้งแบบเร็ว

### วิธี A: ติดตั้งจาก GitHub

```bash
sudo apt-get update
sudo apt-get install -y git
git clone https://github.com/<ชื่อบัญชี>/<ชื่อ-repo>.git sgr-library
cd sgr-library
sudo bash install.sh
```

### วิธี B: ติดตั้งจากไฟล์ ZIP

```bash
sudo apt-get update
sudo apt-get install -y unzip
unzip SGR-Library-V3.3.3-Installer.zip
cd SGR-Library-V3.3.3
sudo bash install.sh
```

### สร้างบัญชีครูคนแรก

ถ้ารัน install.sh จากเทอร์มินัล ตัวติดตั้งจะถามให้สร้างบัญชีครูตอนจบ ถ้าข้ามไป สร้างภายหลังได้ด้วย

```bash
sudo /usr/bin/python3 /opt/sgr-library-v3/current/tools/create_teacher.py
```

คำสั่งจะถาม Username, Password (2 ครั้ง) และชื่อครู รหัสผ่านพิมพ์บนเทอร์มินัลเท่านั้น ไม่ถูกเก็บในประวัติคำสั่ง สร้างครูเพิ่มได้ด้วยคำสั่งเดิม

### เปิดเว็บ

ดูเลข IP ของเซิร์ฟเวอร์ด้วย `hostname -I` แล้วแทน `SERVER-IP`

| ผู้ใช้ | เปิดจากวงแลน | โดเมนเมื่อเชื่อม Tunnel (ค่าเริ่มต้น) |
|---|---|---|
| นักศึกษา | `http://SERVER-IP:8088/` | `https://panda4477.online/` |
| ครู | `http://SERVER-IP:8089/` | `https://teacher.panda4477.online/` |

ตัวอย่างการติดตั้งพร้อมตัวเลือก (โดเมนของตัวเอง + สำรองข้อมูลทุกคืน + เพิ่มกฎ ufw)

```bash
sudo SGR_STUDENT_DOMAIN=library.example.com \
     SGR_TEACHER_DOMAIN=teacher.example.com \
     SGR_DAILY_BACKUP=1 \
     SGR_OPEN_FIREWALL=1 \
     bash install.sh
```

---

## 3. install.sh ทำอะไรบ้าง

ตัวติดตั้งทำงาน 8 ขั้น และเก็บบันทึกทุกขั้นไว้ที่ `/opt/sgr-library-v3/backups/install-v3.3.3-<วันเวลา>/`

| ขั้น | งาน |
|---|---|
| 1/8 | `apt-get update` แล้วลงแพ็กเกจทั้งหมดใน[หัวข้อ 4](#4-แพ็กเกจและเครื่องมือที่ติดตั้ง) ตรวจว่า PHP เป็นรุ่น 8.2 ขึ้นไปและมีส่วนขยายครบ |
| 2/8 | เปิดบริการ MariaDB ตรวจ `nginx -t` ตรวจว่าพอร์ต 8088/8089 ไม่ถูกเว็บอื่นใช้ และไม่ทับไฟล์ตั้งค่าที่ระบบนี้ไม่ได้สร้าง |
| 3/8 | สร้างบัญชีระบบ `sgr-library` โฟลเดอร์ข้อมูล และ `/etc/sgr-library-v3/settings.ini` (รหัสฐานข้อมูลและกุญแจสุ่มใหม่) ถ้ามีไฟล์เดิมจะใช้ไฟล์เดิมและสำรองไว้ก่อน |
| 4/8 | สร้างฐานข้อมูล `sgr_library_v3` ถ้ามีข้อมูลเดิมจะ **สำรองฐานข้อมูลก่อนทุกครั้ง** แล้วปรับโครงสร้างเป็น V3.3.3 (เพิ่มเท่านั้น ไม่ลบข้อมูล) ให้บัญชีเว็บมีสิทธิ์เฉพาะอ่าน/เขียนตาราง |
| 5/8 | คัดลอกโค้ดไปที่ `/opt/sgr-library-v3/releases/v3.3.3-<วันเวลา>` ตรวจไวยากรณ์ PHP ทุกไฟล์ สร้างไฟล์ Nginx และพูล PHP-FPM แล้วตรวจก่อนใช้งาน |
| 6/8 | สลับ `/opt/sgr-library-v3/current` ไปรุ่นใหม่ แล้ว reload PHP-FPM และ Nginx |
| 7/8 | ทดสอบหน้าเว็บทั้งสองพอร์ทัล (smoke test) และทดสอบเต็มรูปแบบ: สร้างครูและนักศึกษาชั่วคราว เข้าระบบแบบคอมและมือถือ เปิดทุกหน้า บันทึกข้อมูล ส่งออก PDF/Excel แล้วลบบัญชีชั่วคราวทิ้ง ถ้าไม่ผ่าน **จะคืนค่ารุ่นก่อนหน้าให้อัตโนมัติ** |
| 8/8 | ตั้ง logrotate ให้ log ของระบบ เปิด cron เปิด fail2ban ป้องกันการเดารหัส SSH และตั้ง cron สำรองข้อมูล/กฎ ufw ถ้าเลือกไว้ ลบรุ่นเก่าเหลือ 5 รุ่นล่าสุด |

รัน install.sh ซ้ำได้อย่างปลอดภัย ข้อมูลนักศึกษา ไฟล์อัปโหลด และ settings.ini เดิมยังอยู่ครบ ใช้ติดตั้งทับเครื่องที่เป็น V3.0, V3.2 หรือ V3.3 อยู่แล้วเพื่ออัปเกรดได้เลย

---

## 4. แพ็กเกจและเครื่องมือที่ติดตั้ง

install.sh ลงแพ็กเกจชุดนี้ให้เอง ไม่ต้องรันเอง ถ้าต้องการลงล่วงหน้าด้วยมือ ใช้คำสั่งนี้

```bash
sudo apt-get update
sudo apt-get install -y \
  nginx \
  php-fpm \
  php-cli \
  php-common \
  php-mysql \
  php-mbstring \
  php-xml \
  php-curl \
  php-zip \
  php-gd \
  php-intl \
  php-opcache \
  mariadb-server \
  mariadb-client \
  composer \
  git \
  curl \
  wget \
  jq \
  unzip \
  zip \
  ca-certificates \
  openssl \
  rsync \
  cron \
  logrotate \
  ufw \
  fail2ban \
  apache2-utils
```

> บน Ubuntu 24.04 `php-opcache` เป็นชื่อเสมือน apt จะเลือก `php8.3-opcache` ให้เอง ถ้าคลังของเครื่องใดแจ้งว่าไม่พบ `php-opcache` ให้ใช้ชื่อตามรุ่น PHP แทน เช่น `php8.3-opcache` ตัวติดตั้งจัดการเรื่องนี้ให้เอง โดยลง `php<รุ่น>-opcache` ทุกครั้ง

นอกจากรายการข้างบน install.sh ยังลง `python3`, `python3-pymysql`, `python3-bcrypt` (ใช้กับเครื่องมือสร้างบัญชีครู), `python3-systemd` (ให้ fail2ban อ่าน log จาก journal), `gzip`, `tar` และส่วนขยาย PHP แบบระบุรุ่น เช่น `php8.3-fpm php8.3-mysql ...` เพื่อให้ตรงกับรุ่น PHP ที่ใช้จริง

แพ็กเกจหลัก (nginx, PHP, MariaDB, python3, curl, rsync, openssl) ต้องมีในคลัง ถ้าไม่มีตัวติดตั้งจะหยุด ส่วนเครื่องมือเสริมที่คลังไม่มี ตัวติดตั้งจะข้ามและแจ้งชื่อไว้

| แพ็กเกจ | ใช้ทำอะไร |
|---|---|
| `nginx` | เว็บเซิร์ฟเวอร์ เปิดพอร์ต 8088 (นักศึกษา) และ 8089 (ครู) |
| `php-fpm`, `php-cli`, `php-common` | รันโค้ด PHP ของระบบ และใช้รันคำสั่งตรวจไวยากรณ์ |
| `php-mysql` | เชื่อม MariaDB (PDO) |
| `php-mbstring`, `php-intl` | จัดการข้อความภาษาไทยและวันที่ |
| `php-xml`, `php-zip` | สร้างไฟล์ Excel (.xlsx) |
| `php-gd` | ตรวจและย่อรูปที่อัปโหลด |
| `php-curl` | ส่วนขยายมาตรฐานสำหรับเรียก HTTP |
| `php-opcache` | เร่งความเร็ว PHP |
| `mariadb-server`, `mariadb-client` | ฐานข้อมูล และคำสั่ง `mariadb` / `mariadb-dump` |
| `composer` | ตัวจัดการแพ็กเกจ PHP (ระบบนี้ไม่มีไลบรารีภายนอก จึงไม่ต้องรัน composer แต่ลงไว้สำหรับงานเสริม) |
| `git` | ดึงโค้ดและอัปเดตจาก GitHub |
| `curl`, `wget` | ดาวน์โหลดไฟล์ และใช้ทดสอบหน้าเว็บ |
| `jq` | อ่านผล JSON จาก API เช่น `curl -s http://127.0.0.1:8088/api/me \| jq` |
| `unzip`, `zip` | แตก/สร้างไฟล์ ZIP ของตัวติดตั้งและแพตช์อัปเดต |
| `ca-certificates`, `openssl` | ใบรับรอง HTTPS และสุ่มรหัสลับตอนติดตั้ง |
| `rsync` | คัดลอกโค้ดไปยังโฟลเดอร์รุ่นใหม่ |
| `cron` | ตั้งงานอัตโนมัติ เช่น สำรองข้อมูลทุกคืน |
| `logrotate` | หมุนไฟล์ log ไม่ให้เต็มดิสก์ |
| `ufw` | Firewall (ลงให้แต่ **ไม่เปิดเอง** ดูหัวข้อ 7) |
| `fail2ban` | บล็อก IP ที่เดารหัส SSH ซ้ำๆ |
| `apache2-utils` | คำสั่ง `ab` (ทดสอบโหลด) และ `htpasswd` (สร้างรหัสผ่านแบบ Basic Auth) |

---

## 5. ตัวเลือกตอนติดตั้ง

ใส่เป็นตัวแปรหน้า `bash install.sh` เช่น `sudo SGR_DAILY_BACKUP=1 bash install.sh`

| ตัวแปร | ค่าเริ่มต้น | ความหมาย |
|---|---|---|
| `SGR_STUDENT_DOMAIN` | `panda4477.online` | โดเมนของเว็บนักศึกษา (ใช้กับ Cloudflare Tunnel) |
| `SGR_TEACHER_DOMAIN` | `teacher.panda4477.online` | โดเมนของเว็บครู ต้องไม่ซ้ำกับของนักศึกษา |
| `SGR_PHP_VERSION` | รุ่น PHP หลักของระบบ | เลือกรุ่น PHP เอง เช่น `8.3` (ต้องเป็น 8.2 ขึ้นไป) |
| `SGR_CONTACT` | `ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม` | ข้อความติดต่อที่แสดงหน้าแรก (ใช้เฉพาะตอนสร้าง settings.ini ใหม่ ใช้ `·` คั่นแต่ละบรรทัด) |
| `SGR_DAILY_BACKUP=1` | ปิด | ตั้ง cron สำรองฐานข้อมูล + ไฟล์อัปโหลด + settings.ini ทุกคืน 02:30 เก็บ 14 ชุดล่าสุด |
| `SGR_OPEN_FIREWALL=1` | ปิด | เพิ่มกฎ ufw ให้ SSH (OpenSSH หรือ 22/tcp), 8088/tcp, 8089/tcp (เพิ่มกฎเท่านั้น ไม่เปิด ufw ให้) |
| `SGR_SKIP_FULL_TEST=1` | ปิด | ทดสอบแค่ smoke test หลังติดตั้ง (เร็วขึ้น) |

ตัวอย่างข้อความติดต่อหลายบรรทัด

```bash
sudo SGR_CONTACT='ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม · โทร 0xx-xxx-xxxx · LINE @xxxx' bash install.sh
```

---

## 6. ใช้งานครั้งแรก

1. สร้างบัญชีครูด้วย `create_teacher.py` ([หัวข้อ 2](#สร้างบัญชีครูคนแรก)) แล้วเข้า `http://SERVER-IP:8089/`
2. ครูเปิดเมนู **ภาคเรียน** ตรวจปีการศึกษาและเลือกภาคเรียนปัจจุบัน ครั้งแรกระบบประมาณจากเดือน (พฤษภาคม–ตุลาคม = ภาคเรียนที่ 1, พฤศจิกายน–เมษายน = ภาคเรียนที่ 2)
3. นักศึกษาสมัครเองที่ `http://SERVER-IP:8088/` แล้วรอครูอนุมัติในหน้าอนุมัติ นักศึกษาที่อนุมัติ ปฏิเสธ หรือนำออกแล้วจะไม่กลับมาที่หน้าอนุมัติอีก
4. ครูสร้าง **กิจกรรม** (ได้คะแนน กพช.) และ **งาน/ใบงาน** (คะแนนเก็บแยก ไม่นับเป็น กพช.)
5. กพช. เต็ม 200 คะแนนต่อภาคเรียน ผ่านที่ 100 คะแนน
6. ครูส่งออกรายงานเป็น PDF (รวมหรือรายคน) และ Excel (.xlsx) ได้จากหน้ารายงาน
7. แก้ข้อความติดต่อหน้าแรกที่ `/etc/sgr-library-v3/settings.ini` คีย์ `contact` แล้วรีเฟรชหน้าเว็บ

```bash
sudo nano /etc/sgr-library-v3/settings.ini
```

ไฟล์ที่อัปโหลดได้: รูป JPG, PNG, WEBP และเอกสาร PDF ไม่เกิน 8 MiB ต่อไฟล์

---

## 7. Firewall (ufw) และ fail2ban

### ufw

ตัวติดตั้งลง ufw ให้แต่ไม่เปิดเอง เพื่อไม่ให้ SSH ที่ใช้อยู่หลุด เปิดอย่างปลอดภัยตามลำดับนี้ (**อนุญาต SSH ก่อนเสมอ**) ถ้า `ufw allow OpenSSH` แจ้งว่าไม่พบโปรไฟล์ ให้ใช้ `sudo ufw allow 22/tcp` (หรือพอร์ต SSH ที่ตั้งไว้) แทน

```bash
sudo ufw allow OpenSSH
sudo ufw allow 8088/tcp
sudo ufw allow 8089/tcp
sudo ufw enable
sudo ufw status verbose
```

ถ้าต้องการให้เข้าได้เฉพาะในวงแลน (เช่น 192.168.1.0/24) และให้คนนอกเข้าผ่าน Cloudflare Tunnel เท่านั้น

```bash
sudo ufw allow OpenSSH
sudo ufw allow from 192.168.1.0/24 to any port 8088 proto tcp
sudo ufw allow from 192.168.1.0/24 to any port 8089 proto tcp
sudo ufw enable
```

Cloudflare Tunnel เชื่อมออกจากเครื่องเอง จึงไม่ต้องเปิดพอร์ต 8088/8089 สู่อินเทอร์เน็ต

### fail2ban

เปิดใช้งานให้อัตโนมัติ ป้องกันการเดารหัส SSH

```bash
sudo fail2ban-client status            # ดู jail ที่ทำงานอยู่
sudo fail2ban-client status sshd       # ดู IP ที่ถูกบล็อก
sudo fail2ban-client set sshd unbanip 203.0.113.10   # ปลดบล็อก IP
```

---

## 8. เชื่อมโดเมนด้วย Cloudflare Tunnel

ทดสอบเปิดผ่านวงแลนให้ได้ก่อน แล้วจึงเชื่อม Tunnel

**1) ลง cloudflared** (ข้ามได้ถ้ามีอยู่แล้ว)

```bash
sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt-get update && sudo apt-get install -y cloudflared
```

**2) สร้าง Tunnel** ใน Cloudflare Zero Trust (Networks → Tunnels → Create a tunnel) แล้วรันคำสั่งที่หน้าเว็บให้มา ซึ่งมีรูปแบบ

```bash
sudo cloudflared service install <TOKEN-จากหน้าเว็บ>
```

**3) เพิ่ม Public Hostname** ชี้ไปยังเครื่องเดียวกัน

| Hostname | Service |
|---|---|
| `panda4477.online` (หรือ SGR_STUDENT_DOMAIN) | `http://127.0.0.1:8088` |
| `teacher.panda4477.online` (หรือ SGR_TEACHER_DOMAIN) | `http://127.0.0.1:8089` |

Nginx เชื่อถือข้อมูล HTTPS จาก cloudflared บน loopback เฉพาะสองโดเมนนี้ ถ้าเปลี่ยนโดเมน ให้รัน install.sh ใหม่พร้อม `SGR_STUDENT_DOMAIN` และ `SGR_TEACHER_DOMAIN` ข้อมูลเดิมไม่หาย

```bash
sudo SGR_STUDENT_DOMAIN=library.example.com SGR_TEACHER_DOMAIN=teacher.example.com bash install.sh
```

---

## 9. อัปเดตและย้อนรุ่น

### อัปเดตจาก GitHub

```bash
cd ~/sgr-library
git pull
sudo bash install.sh
```

ใส่ตัวแปรเดิมทุกครั้งที่ติดตั้งซ้ำ (เช่นโดเมน) ถ้าเคยตั้งไว้ไม่ตรงกับค่าเริ่มต้น

### อัปเดตด้วยแพตช์ ZIP

แพตช์รุ่นถัดไปส่งเป็น ZIP ที่มี `update.sh` และ `README-UPDATE.txt`

```bash
unzip SGR-Library-V3.x.x.zip
cd SGR-Library-V3.x.x
sudo bash update.sh
```

### ย้อนโค้ดกลับรุ่นก่อน

ระบบเก็บโค้ด 5 รุ่นล่าสุดไว้ (ฐานข้อมูลไม่ย้อน เพราะการปรับฐานข้อมูลเป็นแบบเพิ่มเท่านั้น)

```bash
ls -lt /opt/sgr-library-v3/releases/
sudo ln -sfn /opt/sgr-library-v3/releases/<ชื่อรุ่นที่ต้องการ> /opt/sgr-library-v3/current
sudo systemctl reload php8.3-fpm nginx     # เปลี่ยน 8.3 เป็นรุ่น PHP ของเครื่อง
sudo bash /opt/sgr-library-v3/current/tools/smoke.sh
```

---

## 10. สำรองและกู้คืนข้อมูล

ข้อมูลที่ต้องสำรองมี 3 ส่วน: ฐานข้อมูล `sgr_library_v3`, โฟลเดอร์ไฟล์อัปโหลด และ `settings.ini` ใช้ `backup.sh` เก็บทั้งสามส่วนในจุดเวลาเดียวกันเป็นไฟล์เดียว

```bash
sudo bash /opt/sgr-library-v3/current/tools/backup.sh                  # → /opt/sgr-library-v3/backups/sgr-backup-<วันเวลา>.tar
sudo bash /opt/sgr-library-v3/current/tools/backup.sh --keep 14        # ลบไฟล์เก่า เก็บไว้ 14 ชุดล่าสุด
sudo bash /opt/sgr-library-v3/current/tools/backup.sh --dest /mnt/usb  # เก็บที่อื่น เช่น USB
```

ไฟล์สำรองมี `INFO.txt`, `database.sql.gz`, `uploads.tar.gz` และ `settings.ini` (ในไฟล์มีรหัสฐานข้อมูล เก็บให้ปลอดภัย)

### สำรองอัตโนมัติทุกคืน

ติดตั้งด้วย `SGR_DAILY_BACKUP=1` ระบบจะสร้าง `/etc/cron.d/sgr-library-v3-backup` สำรองเวลา 02:30 เก็บ 14 ชุด และเขียน log ที่ `/var/log/sgr-library-v3/backup.log` ควรคัดลอกไฟล์สำรองออกไปเก็บนอกเครื่องเป็นระยะ เช่น

```bash
scp user@SERVER-IP:/opt/sgr-library-v3/backups/sgr-backup-*.tar ./
```

### กู้คืน

```bash
sudo bash /opt/sgr-library-v3/current/tools/restore.sh /path/sgr-backup-<วันเวลา>.tar
```

restore.sh จะ:

1. ตรวจว่าไฟล์สำรองครบและไม่เสียหาย แล้วให้พิมพ์ `yes` ยืนยัน
2. สำรองข้อมูลปัจจุบันไว้ที่ `/opt/sgr-library-v3/backups/before-restore/` ก่อน
3. นำเข้าฐานข้อมูล ปรับโครงสร้างให้ตรงกับรุ่นที่ติดตั้ง (กู้ไฟล์สำรองของรุ่นเก่ากว่าได้) และคืนไฟล์อัปโหลด
4. ใช้ settings.ini ของเครื่องนี้ต่อ (รหัสฐานข้อมูลเป็นของเครื่องนี้)

### ย้ายไปเซิร์ฟเวอร์ใหม่

```bash
# เครื่องเก่า
sudo bash /opt/sgr-library-v3/current/tools/backup.sh
# คัดลอกไฟล์ .tar ไปเครื่องใหม่ แล้วบนเครื่องใหม่
sudo bash install.sh
sudo bash /opt/sgr-library-v3/current/tools/restore.sh ~/sgr-backup-<วันเวลา>.tar
```

บัญชีครูและนักศึกษาย้ายมาพร้อมฐานข้อมูล ไม่ต้องสร้างใหม่ ถ้าเคยแก้ `contact` ไว้ ให้คัดลอกค่าเดิมมาใส่ settings.ini ของเครื่องใหม่ (restore.sh แสดงค่าเดิมให้ตอนจบ)

---

## 11. เครื่องมือในโฟลเดอร์ tools

หลังติดตั้ง เครื่องมืออยู่ที่ `/opt/sgr-library-v3/current/tools/`

| เครื่องมือ | คำสั่ง | หน้าที่ |
|---|---|---|
| `create_teacher.py` | `sudo /usr/bin/python3 .../tools/create_teacher.py` | สร้างบัญชีครู |
| `backup.sh` | `sudo bash .../tools/backup.sh [--keep N] [--dest DIR]` | สำรองฐานข้อมูล + ไฟล์อัปโหลด + settings.ini |
| `restore.sh` | `sudo bash .../tools/restore.sh [--yes] FILE.tar` | กู้คืนจากไฟล์ของ backup.sh |
| `smoke.sh` | `sudo bash .../tools/smoke.sh` | ตรวจด่วน: หน้าแรก หน้าเข้าสู่ระบบ หน้าสมัคร API และหน้า 404 ของทั้งสองพอร์ทัล |
| `post_update_test.sh` | `sudo bash .../tools/post_update_test.sh` | ทดสอบเต็มรูปแบบกับระบบจริง (สร้างบัญชีชั่วคราวแล้วลบทิ้ง) |
| `schema_check.sh` | `sudo bash .../tools/schema_check.sh` | ตรวจว่าตารางและคอลัมน์ของ V3.3.3 ครบ |
| `render_nginx.py` | ใช้โดย install.sh | สร้างไฟล์ Nginx จากแม่แบบในโฟลเดอร์ `nginx/` |
| `test_stack.sh` | `sudo bash .../tools/test_stack.sh` | เปิด MariaDB + PHP-FPM + Nginx ชุดทดสอบแยกจากระบบจริงในโฟลเดอร์ชั่วคราว รัน smoke, ทดสอบเต็มรูปแบบ และวัดโหลด 20/50/120 เซสชัน (`load_test.py`, `qa_support.py`) ต้องมีพอร์ต 18088/18089 ว่าง ผลอยู่ที่ `/tmp/sgr-test-results.*` |

---

## 12. คำสั่งดูแลระบบที่ใช้บ่อย

เปลี่ยน `8.3` เป็นรุ่น PHP ของเครื่อง (ดูด้วย `php -v`)

```bash
# สถานะบริการ
sudo systemctl status nginx php8.3-fpm mariadb
sudo systemctl reload nginx php8.3-fpm
sudo nginx -t

# ดู log
sudo tail -f /var/log/sgr-library-v3/php.log
sudo tail -f /var/log/nginx/error.log
sudo journalctl -u php8.3-fpm -n 100

# ฐานข้อมูล
sudo mariadb sgr_library_v3
sudo mariadb sgr_library_v3 -e 'DELETE FROM rate_limits'   # ปลดล็อกการเข้าสู่ระบบที่ผิดหลายครั้ง

# ทดสอบ
sudo bash /opt/sgr-library-v3/current/tools/smoke.sh
curl -I http://127.0.0.1:8088/
ab -n 200 -c 20 http://127.0.0.1:8088/      # ทดสอบโหลด (apache2-utils)

# พื้นที่ดิสก์
df -h /
sudo du -sh /var/lib/sgr-library-v3/uploads /opt/sgr-library-v3/backups
```

---

## 13. ตำแหน่งไฟล์บนเซิร์ฟเวอร์

| ตำแหน่ง | รายการ |
|---|---|
| `/opt/sgr-library-v3/current` | รุ่นที่ใช้งานอยู่ (ลิงก์ไปยัง releases) |
| `/opt/sgr-library-v3/releases/` | โค้ดแต่ละรุ่น เก็บ 5 รุ่นล่าสุด |
| `/opt/sgr-library-v3/backups/` | สำรองก่อนติดตั้งแต่ละครั้ง ผลทดสอบ และไฟล์จาก backup.sh |
| `/etc/sgr-library-v3/settings.ini` | การเชื่อมฐานข้อมูล กุญแจระบบ ตำแหน่งข้อมูล และข้อความติดต่อ |
| `/var/lib/sgr-library-v3/uploads/` | ไฟล์ที่ผู้ใช้อัปโหลด (อยู่นอกรากเว็บ ดาวน์โหลดผ่านการตรวจสิทธิ์) |
| `/var/lib/sgr-library-v3/sessions/` | เซสชัน PHP |
| `/etc/nginx/conf.d/sgr-library-v3.conf` | ตั้งค่า Nginx สองพอร์ทัล |
| `/etc/php/<รุ่น>/fpm/pool.d/sgr-library-v3.conf` | พูล PHP-FPM ของระบบ |
| `/var/log/sgr-library-v3/` | `php.log` (ข้อผิดพลาด) และ `backup.log` |
| `/etc/logrotate.d/sgr-library-v3` | หมุน log ทุกสัปดาห์ เก็บ 8 รอบ |
| `/etc/cron.d/sgr-library-v3-backup` | งานสำรองทุกคืน (เมื่อใช้ `SGR_DAILY_BACKUP=1`) |

ไฟล์ตั้งค่าที่ระบบสร้างมีข้อความ `Managed by SGR Library V3` ตัวติดตั้งจะไม่แก้ไฟล์ที่ไม่มีข้อความนี้

---

## 14. แก้ปัญหา

**`$'\r': command not found` หรือ `bad interpreter` ตอนรัน install.sh**
ไฟล์ถูกแปลงบรรทัดเป็นแบบ Windows (CRLF) มักเกิดเมื่ออัปโหลดผ่าน Windows แก้ด้วย

```bash
sudo apt-get install -y dos2unix
find . -type f \( -name '*.sh' -o -name '*.py' -o -name '*.php' -o -name '*.sql' -o -name '*.in' -o -name '*.conf' -o -name '*.ini' \) -exec dos2unix {} +
```

repo นี้มีไฟล์ `.gitattributes` บังคับให้ git เก็บไฟล์แบบ LF อยู่แล้ว

**`nginx -t` ไม่ผ่านตั้งแต่ขั้น 2/8 (เช่น `socket() [::]:80 failed`)**
เครื่องปิด IPv6 แต่เว็บเริ่มต้นของ Nginx ฟัง `[::]:80` ให้ใส่ `#` หน้าบรรทัด `listen [::]:80 default_server;` ใน `/etc/nginx/sites-enabled/default` แล้วรัน install.sh ใหม่ ปัญหาอื่นของ `nginx -t` ให้แก้ตามข้อความที่แสดง

**พอร์ต 8088/8089 ถูกใช้อยู่**
ตัวติดตั้งแจ้งชื่อไฟล์ที่ใช้พอร์ตนั้น ย้ายเว็บนั้นไปพอร์ตอื่นหรือปิดก่อน ตรวจด้วย `sudo ss -ltnp | grep -E ':(8088|8089)'`

**ต้องมีแพ็กเกจ PHP 8.2 ขึ้นไป (Ubuntu 22.04)**

```bash
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo SGR_PHP_VERSION=8.3 bash install.sh
```

**เปิดจากเครื่องอื่นไม่ได้ แต่ smoke.sh ผ่าน**
ตรวจ IP ด้วย `hostname -I` และกฎ firewall ด้วย `sudo ufw status` ถ้า ufw เปิดอยู่ให้ `sudo ufw allow 8088/tcp && sudo ufw allow 8089/tcp`

**หน้าเว็บขึ้น 502 Bad Gateway**
PHP-FPM ไม่ทำงาน ตรวจด้วย `sudo systemctl status php8.3-fpm` และ `sudo journalctl -u php8.3-fpm -n 50`

**หน้าเว็บขึ้นข้อผิดพลาด 500**
ดูสาเหตุที่ `sudo tail -50 /var/log/sgr-library-v3/php.log` ถ้าเป็น `Missing SGR configuration` ให้ตรวจสิทธิ์ไฟล์ด้วย `sudo ls -l /etc/sgr-library-v3/settings.ini` (ต้องเป็น `root:sgr-library` โหมด 640) หรือรัน install.sh ซ้ำเพื่อให้ตั้งสิทธิ์ใหม่

**เข้าสู่ระบบไม่ได้หลังพิมพ์รหัสผิดหลายครั้ง**
รอสักครู่ หรือปลดล็อกด้วย `sudo mariadb sgr_library_v3 -e 'DELETE FROM rate_limits'`

**ครูลืมรหัสผ่าน**
สร้างบัญชีครูใหม่ด้วย `create_teacher.py` ส่วนนักศึกษาที่ลืมรหัส ให้ส่งคำขอจากหน้า "ลืมรหัสผ่าน" แล้วครูออกรหัสใช้ครั้งเดียว (15 นาที) ให้

**fail2ban ไม่ทำงาน**
ดูสาเหตุด้วย `sudo systemctl status fail2ban` และ `sudo fail2ban-client -d` ระบบเว็บใช้งานได้ตามปกติแม้ fail2ban ไม่ทำงาน

**ติดตั้งไม่ผ่านขั้น 7/8**
ตัวติดตั้งคืนค่ารุ่นก่อนหน้าให้แล้ว ดูรายละเอียดที่ `/opt/sgr-library-v3/backups/install-v3.3.3-<วันเวลา>/` (`smoke.log`, `full-test.log`, `php-lint.log`)

---

## 15. โครงสร้างซอร์สโค้ด

| ไฟล์หรือโฟลเดอร์ | หน้าที่ |
|---|---|
| `install.sh` | ตัวติดตั้ง (ติดตั้งใหม่ ติดตั้งซ้ำ และอัปเกรดจาก V3.x) |
| `public/student/`, `public/teacher/` | จุดเข้าเว็บของแต่ละพอร์ทัล กำหนดบทบาทจากฝั่งเซิร์ฟเวอร์ |
| `app/` | โค้ดหลัก: เข้าสู่ระบบ สิทธิ์ แบบฟอร์ม คะแนน กพช. การมาเรียน งาน รายงาน PDF/Excel และ API |
| `assets/` | CSS, JavaScript, ฟอนต์ไทย รูปหน้าแรก และหน้าข้อผิดพลาด (ไม่มี CSS/JS แบบ inline ตาม CSP) |
| `database/schema.sql` | โครงสร้างฐานข้อมูลตั้งต้น |
| `database/repair-v322.sql`, `database/repair-v332.sql` | ปรับโครงสร้างเป็น V3.3.3 (รันซ้ำได้ ไม่ลบข้อมูล) |
| `database/runtime-grants.sql.in` | สิทธิ์ของบัญชีเว็บ (อ่าน/เขียนตารางเท่านั้น) |
| `nginx/`, `config/` | แม่แบบ Nginx, PHP-FPM และ `settings.example.ini` |
| `storage/uploads/` | โครงโฟลเดอร์อัปโหลด (ของจริงอยู่ที่ `/var/lib/sgr-library-v3/`) |
| `tools/` | เครื่องมือตาม[หัวข้อ 11](#11-เครื่องมือในโฟลเดอร์-tools) |
| `TEST-RESULTS.md` | ผลทดสอบตัวติดตั้งรุ่นนี้ |

API แบบอ่านข้อมูล: `GET /api/me`, `GET /api/student/scores` และ `GET /api/teacher/summary` ผู้ที่ยังไม่เข้าสู่ระบบได้ 401 สิทธิ์ไม่ตรงได้ 403 และฟอร์มที่ CSRF token ไม่ถูกต้องได้ 419

---

SGR Library V3.3.3 · ศกร.ระดับตำบลบ้านใหม่ อำเภอสามพราน จังหวัดนครปฐม
