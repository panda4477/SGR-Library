-- SGR Library V3.2.2 idempotent schema repair. Additive only; no historical data is dropped.
SET NAMES utf8mb4;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(35) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS line_contact VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE terms ADD COLUMN IF NOT EXISTS expected_days SMALLINT UNSIGNED NOT NULL DEFAULT 48;
ALTER TABLE terms ADD COLUMN IF NOT EXISTS min_attendance SMALLINT UNSIGNED NOT NULL DEFAULT 24;
ALTER TABLE terms ADD COLUMN IF NOT EXISTS min_points SMALLINT UNSIGNED NOT NULL DEFAULT 100;
ALTER TABLE attendance_sessions ADD COLUMN IF NOT EXISTS level_name VARCHAR(60) DEFAULT NULL;
ALTER TABLE attendance_sessions ADD COLUMN IF NOT EXISTS no_class TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE notices ADD COLUMN IF NOT EXISTS term_id INT UNSIGNED NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS term_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS term_enrollments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  term_id INT UNSIGNED NOT NULL,
  level_name VARCHAR(60) NOT NULL,
  status ENUM('pending','approved','declined','inactive') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  UNIQUE KEY unique_student_term(student_id,term_id),
  INDEX by_term_level(term_id,status,level_name),
  CONSTRAINT fk_term_enroll_student FOREIGN KEY(student_id) REFERENCES users(id),
  CONSTRAINT fk_term_enroll_term FOREIGN KEY(term_id) REFERENCES terms(id),
  CONSTRAINT fk_term_enroll_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS behavior_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term_id INT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  details TEXT NOT NULL,
  occurred_on DATE NOT NULL,
  visibility ENUM('private','student') NOT NULL DEFAULT 'private',
  state ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_behavior_term FOREIGN KEY(term_id) REFERENCES terms(id),
  CONSTRAINT fk_behavior_teacher FOREIGN KEY(teacher_id) REFERENCES users(id),
  INDEX(term_id,occurred_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS behavior_students (
  event_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(event_id,student_id),
  INDEX(student_id),
  CONSTRAINT fk_behavior_student_event FOREIGN KEY(event_id) REFERENCES behavior_events(id),
  CONSTRAINT fk_behavior_student_user FOREIGN KEY(student_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcement_reads (
  announcement_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(announcement_id,student_id),
  CONSTRAINT fk_announcement_read_announcement FOREIGN KEY(announcement_id) REFERENCES announcements(id),
  CONSTRAINT fk_announcement_read_student FOREIGN KEY(student_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feature_flags (
  flag_name VARCHAR(40) PRIMARY KEY,
  enabled TINYINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
INSERT INTO feature_flags(flag_name,enabled) VALUES
 ('issue_reporting',0),('assignment_upload',1),('announcement_ack',1)
ON DUPLICATE KEY UPDATE flag_name=VALUES(flag_name);

-- Normalize old password hashes that were copied with trailing CR/LF or spaces.
UPDATE users SET password_hash=TRIM(password_hash) WHERE password_hash<>TRIM(password_hash);

-- Recover historical enrollment links only where historical evidence exists.
INSERT IGNORE INTO term_enrollments(student_id,term_id,level_name,status)
SELECT h.student_id,h.term_id,u.level_name,'approved'
FROM (
  SELECT student_id,term_id FROM score_entries
  UNION
  SELECT ar.student_id,a.term_id FROM attendance_records ar JOIN attendance_sessions a ON a.id=ar.session_id
  UNION
  SELECT s.student_id,w.term_id FROM submissions s JOIN work_items w ON w.id=s.item_id
) h JOIN users u ON u.id=h.student_id
WHERE u.role='student';

INSERT INTO sgr_schema(id,version_no) VALUES(1,3)
ON DUPLICATE KEY UPDATE version_no=GREATEST(version_no,3);
