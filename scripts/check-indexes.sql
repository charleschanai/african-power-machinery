-- ===========================================================================
-- E-Card System - index and table health
--
-- Paste into the MySQL client, or run with:
--   mysql -u root -p ecard_system < scripts/check-indexes.sql
--
-- Everything here is read-only.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. Duplicate indexes
--
-- Two indexes over the same leading column cost a write and return nothing
-- extra. Foreign keys auto-create their own index, so declaring the same
-- column again in a migration is a common way to end up here.
-- ---------------------------------------------------------------------------
SELECT
    table_name,
    index_name,
    group_concat(column_name ORDER BY seq_in_index) AS columns,
    non_unique
FROM information_schema.statistics
WHERE table_schema = DATABASE()
GROUP BY table_name, index_name, non_unique
HAVING COUNT(*) > 0
ORDER BY table_name, index_name;

-- ---------------------------------------------------------------------------
-- 2. Redundant indexes (one is a prefix of another)
-- ---------------------------------------------------------------------------
SELECT
    a.table_name,
    a.index_name AS redundant_index,
    b.index_name AS covered_by
FROM information_schema.statistics a
JOIN information_schema.statistics b
    ON  a.table_name = b.table_name
    AND a.seq_in_index = b.seq_in_index
    AND a.column_name = b.column_name
    AND a.index_name <> b.index_name
    AND (
        (SELECT group_concat(column_name ORDER BY seq_in_index)
         FROM information_schema.statistics x
         WHERE x.table_name = a.table_name AND x.index_name = a.index_name)
        =
        (SELECT group_concat(column_name ORDER BY seq_in_index)
         FROM information_schema.statistics y
         WHERE y.table_name = b.table_name AND y.index_name = b.index_name)
    )
WHERE a.table_schema = DATABASE()
  AND a.table_name NOT IN ('migrations')
GROUP BY a.table_name, a.index_name, b.index_name;

-- ---------------------------------------------------------------------------
-- 3. Table sizes
--
-- entry_logs, whatsapp_messages and audit_logs all grow without bound during
-- an event. This is the query to watch before the disk fills.
-- ---------------------------------------------------------------------------
SELECT
    table_name,
    table_rows AS approx_rows,
    ROUND((data_length + index_length) / 1048576, 2) AS total_mb,
    ROUND(data_length / 1048576, 2) AS data_mb,
    ROUND(index_length / 1048576, 2) AS index_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC;

-- ---------------------------------------------------------------------------
-- 4. Columns with no index at all, in columns this app filters on
-- ---------------------------------------------------------------------------
SELECT
    c.table_name,
    c.column_name,
    c.column_type
FROM information_schema.columns c
LEFT JOIN information_schema.statistics s
    ON  s.table_schema = c.table_schema
    AND s.table_name = c.table_name
    AND s.column_name = c.column_name
WHERE c.table_schema = DATABASE()
  AND c.table_name IN (
      'guests', 'cards', 'entry_logs', 'invitation_sends',
      'whatsapp_conversations', 'whatsapp_messages',
      'weekly_reminder_logs', 'audit_logs', 'system_metrics', 'users'
  )
  AND s.index_name IS NULL
  AND c.column_name NOT IN ('created_at', 'updated_at', 'id')
ORDER BY c.table_name, c.column_name;

-- ---------------------------------------------------------------------------
-- 5. Index usage counters
--
-- Reads the values `innodb_index_stats` has cached, so run the workload first
-- and come back after. `rows_read = 0` on a large table is the signal.
-- ---------------------------------------------------------------------------
SELECT
    object_name AS table_name,
    index_name,
    count_read,
    count_write
FROM mysql.innodb_index_stats
WHERE stat_name = 'size'
  AND database_name = DATABASE()
ORDER BY count_read ASC;

-- ---------------------------------------------------------------------------
-- 6. Queue backlog
--
-- A non-zero count on `whatsapp` or `sms` with nothing moving means the
-- worker is not listening on those queues.
-- ---------------------------------------------------------------------------
SELECT queue, COUNT(*) AS waiting, MIN(available_at) AS oldest_available_at
FROM jobs
GROUP BY queue;

SELECT COUNT(*) AS failed_jobs, MAX(failed_at) AS most_recent
FROM failed_jobs;
