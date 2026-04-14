-- One-time MD backfill for MariaDB 10.3 (no CTE / no UPDATE...WITH).
-- Users: deleted=0, md_id=0, steps='HOME', and EXISTS active data_subscriptions.
-- Doctors: sys_users_admin DOCTOR + deleted=0, excluding id 176 (new MD — not in this pool).
--
-- Optional: wrap in START TRANSACTION; / COMMIT; to rollback on mistake.
-- If you only want the preview, stop after the SELECT and do not run UPDATE.

DROP TEMPORARY TABLE IF EXISTS tmp_md_docs;
CREATE TEMPORARY TABLE tmp_md_docs (
  seq INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  md_id INT NOT NULL
);

INSERT INTO tmp_md_docs (md_id)
SELECT id
FROM sys_users_admin
WHERE user_type = 'DOCTOR'
  AND deleted = 0
  AND id != 176
ORDER BY id;

DROP TEMPORARY TABLE IF EXISTS tmp_md_need;
CREATE TEMPORARY TABLE tmp_md_need (
  seq INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL
);

INSERT INTO tmp_md_need (user_id)
SELECT u.id
FROM sys_users u
WHERE u.deleted = 0
  AND u.md_id = 0
  AND u.steps = 'HOME'
  AND EXISTS (
    SELECT 1
    FROM data_subscriptions ds
    WHERE ds.user_id = u.id
      AND ds.deleted = 0
      AND ds.status = 'ACTIVE'
  )
ORDER BY u.id;

-- Preview mapping (user_id -> md_id)
SELECT
  n.user_id,
  d.md_id
FROM tmp_md_need n
INNER JOIN tmp_md_docs d
  ON d.seq = MOD(n.seq - 1, (SELECT COUNT(*) FROM tmp_md_docs)) + 1
WHERE (SELECT COUNT(*) FROM tmp_md_docs) > 0;

-- Apply (requires at least one row in tmp_md_docs; same connection as above)
UPDATE sys_users u
INNER JOIN tmp_md_need n ON u.id = n.user_id
INNER JOIN tmp_md_docs d
  ON d.seq = MOD(n.seq - 1, (SELECT COUNT(*) FROM tmp_md_docs)) + 1
SET u.md_id = d.md_id
WHERE (SELECT COUNT(*) FROM tmp_md_docs) > 0;

DROP TEMPORARY TABLE IF EXISTS tmp_md_need;
DROP TEMPORARY TABLE IF EXISTS tmp_md_docs;
