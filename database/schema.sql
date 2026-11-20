SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS sgr_schema (
  id TINYINT PRIMARY KEY, version_no INT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_year SMALLINT UNSIGNED NOT NULL, semester TINYINT UNSIGNED NOT NULL,
  UNIQUE (school_year, semester), CHECK (semester IN (1,2))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS site_state (
  id TINYINT PRIMARY KEY, current_term_id INT UNSIGNED NOT NULL,
  FOREIGN KEY (current_term_id) REFERENCES terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('student','teacher') NOT NULL, username VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL DEFAULT '',
  student_code VARCHAR(40) DEFAULT NULL, level_name VARCHAR(60) NOT NULL DEFAULT '',
  gender VARCHAR(40) NOT NULL DEFAULT '', avatar_id BIGINT UNSIGNED DEFAULT NULL,
  auth_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL, last_seen DATETIME NULL,
  UNIQUE KEY username_unique (username), UNIQUE (student_code), INDEX (role, last_seen)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, disk_name CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
  original_name VARCHAR(190) NOT NULL, mime VARCHAR(64) NOT NULL, byte_size INT UNSIGNED NOT NULL,
  owner_id BIGINT UNSIGNED NULL, FOREIGN KEY(owner_id) REFERENCES users(id),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS work_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term_id INT UNSIGNED NOT NULL,
  kind ENUM('activity','assignment') NOT NULL, title VARCHAR(200) NOT NULL, details TEXT NOT NULL,
  starts_at DATETIME NULL, place VARCHAR(180) NOT NULL DEFAULT '', due_at DATETIME NOT NULL,
  points SMALLINT UNSIGNED NOT NULL DEFAULT 0, needs_file TINYINT NOT NULL DEFAULT 1,
  resource_id BIGINT UNSIGNED NULL, solution TEXT NOT NULL,
  solution_mode ENUM('hidden','now','after_due') NOT NULL DEFAULT 'hidden', teacher_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(term_id) REFERENCES terms(id), FOREIGN KEY(teacher_id) REFERENCES users(id),
  FOREIGN KEY(resource_id) REFERENCES files(id), CHECK(points <= 200), INDEX(term_id,kind,due_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, item_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NULL, note TEXT NOT NULL, submitted_at DATETIME NOT NULL,
  status ENUM('submitted','acknowledged','passed','revise') NOT NULL DEFAULT 'submitted',
  review_note TEXT NOT NULL, reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
  credited SMALLINT NOT NULL DEFAULT 0, review_version INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE(item_id,student_id), INDEX(student_id), INDEX(status),
  FOREIGN KEY(item_id) REFERENCES work_items(id), FOREIGN KEY(student_id) REFERENCES users(id),
  FOREIGN KEY(file_id) REFERENCES files(id), FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS score_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,
  term_id INT UNSIGNED NOT NULL, delta_points SMALLINT NOT NULL, reason VARCHAR(500) NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NULL, review_version INT UNSIGNED NULL,
  request_key CHAR(48) CHARACTER SET ascii NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(submission_id,review_version), UNIQUE(teacher_id, request_key), INDEX(student_id,term_id),
  FOREIGN KEY(student_id) REFERENCES users(id), FOREIGN KEY(term_id) REFERENCES terms(id),
  FOREIGN KEY(teacher_id) REFERENCES users(id), FOREIGN KEY(submission_id) REFERENCES submissions(id),
  CHECK(delta_points <> 0 AND delta_points BETWEEN -200 AND 200)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term_id INT UNSIGNED NOT NULL, title VARCHAR(180) NOT NULL,
  opens_at DATETIME NOT NULL, closes_at DATETIME NOT NULL, teacher_id BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY(term_id) REFERENCES terms(id), FOREIGN KEY(teacher_id) REFERENCES users(id),
  CHECK(closes_at > opens_at), INDEX(term_id, opens_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS attendance_records (
  session_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','present','absent','leave') NOT NULL, signed_at DATETIME NULL,
  teacher_id BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL,
  PRIMARY KEY(session_id, student_id), INDEX(student_id),
  FOREIGN KEY(session_id) REFERENCES attendance_sessions(id), FOREIGN KEY(student_id) REFERENCES users(id),
  FOREIGN KEY(teacher_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL,
  publish_on DATE NOT NULL, pinned TINYINT NOT NULL DEFAULT 0, link_url VARCHAR(1500) NOT NULL DEFAULT '',
  image_id BIGINT UNSIGNED NULL, teacher_id BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY(image_id) REFERENCES files(id), FOREIGN KEY(teacher_id) REFERENCES users(id),
  INDEX(pinned,publish_on)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL UNIQUE,
  requested_at DATETIME NOT NULL, issued_by BIGINT UNSIGNED NULL, code_hash CHAR(64) NULL,
  expires_at DATETIME NULL, used_at DATETIME NULL,
  FOREIGN KEY(student_id) REFERENCES users(id), FOREIGN KEY(issued_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL, target VARCHAR(180) NOT NULL, seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(student_id) REFERENCES users(id),
  INDEX(student_id, seen_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NULL, event_name VARCHAR(70) NOT NULL,
  student_id BIGINT UNSIGNED NULL, entity_id BIGINT UNSIGNED NULL, details TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(actor_id) REFERENCES users(id), FOREIGN KEY(student_id) REFERENCES users(id), INDEX(student_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key CHAR(64) CHARACTER SET ascii PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
  INDEX(expires_at)
) ENGINE=InnoDB;
SET @sgr_now = UTC_TIMESTAMP() + INTERVAL 7 HOUR;
SET @sgr_year = YEAR(@sgr_now) + 543 - IF(MONTH(@sgr_now) < 5,1,0);
SET @sgr_sem = IF(MONTH(@sgr_now) BETWEEN 5 AND 10,1,2);
INSERT INTO terms(school_year,semester) VALUES (@sgr_year,@sgr_sem) ON DUPLICATE KEY UPDATE id=id;
INSERT INTO site_state(id,current_term_id) SELECT 1,id FROM terms WHERE school_year=@sgr_year AND semester=@sgr_sem
  ON DUPLICATE KEY UPDATE id=site_state.id;
INSERT INTO sgr_schema(id,version_no) VALUES (1,1) ON DUPLICATE KEY UPDATE id=id;
