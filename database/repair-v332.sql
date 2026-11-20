-- SGR Library V3.3.2 idempotent schema repair. Additive only; no historical data is dropped.
SET NAMES utf8mb4;

-- Teacher account: personal wallpaper (preset name or 'custom' + uploaded image id).
ALTER TABLE users ADD COLUMN IF NOT EXISTS wallpaper VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS wallpaper_id BIGINT UNSIGNED NULL;

-- Small key/value store for portal-wide settings (e.g. the teacher login background).
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(40) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V3.3.2 rule: classwork (assignments, worksheets, quizzes) is scored separately and is NOT กพช.
-- กพช. is the activity / supplementary score only. Older versions credited passed assignments to กพช.;
-- add one visible offsetting ledger line per such submission (the classwork score itself stays on
-- submissions.credited). Re-running is safe: the unique (teacher_id,request_key) key and the
-- HAVING clause both stop duplicates.
INSERT IGNORE INTO score_entries(student_id,term_id,delta_points,reason,teacher_id,submission_id,review_version,request_key)
SELECT x.student_id, x.term_id, -x.linked,
       LEFT(CONCAT('ย้ายเป็นคะแนนงานในห้อง (ไม่นับ กพช.) · ', x.title), 500),
       x.teacher_id, x.submission_id, NULL, CONCAT('v332-classwork-', x.submission_id)
FROM (
  SELECT e.student_id, e.term_id, e.submission_id, MAX(e.teacher_id) AS teacher_id, SUM(e.delta_points) AS linked, MAX(w.title) AS title
  FROM score_entries e
  JOIN submissions s ON s.id = e.submission_id
  JOIN work_items w ON w.id = s.item_id
  WHERE w.kind = 'assignment'
  GROUP BY e.student_id, e.term_id, e.submission_id
  HAVING SUM(e.delta_points) <> 0
) x;

