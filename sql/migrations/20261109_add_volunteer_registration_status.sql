-- Add the volunteer profile state used by account approval on existing databases.
SELECT COUNT(*) INTO @volunteer_status_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'Volunteers'
  AND COLUMN_NAME = 'Status';

SET @volunteer_status_migration = IF(
    @volunteer_status_exists = 0,
    'ALTER TABLE Volunteers ADD COLUMN Status ENUM(''pending'', ''approved'', ''inactive'') NOT NULL DEFAULT ''pending'' AFTER AvailabilityStatus',
    'SELECT 1'
);
PREPARE volunteer_status_migration FROM @volunteer_status_migration;
EXECUTE volunteer_status_migration;
DEALLOCATE PREPARE volunteer_status_migration;

SET @volunteer_status_backfill = IF(
    @volunteer_status_exists = 0,
    'UPDATE Volunteers v INNER JOIN Users u ON u.UserID = v.UserID SET v.Status = CASE WHEN u.Status = ''active'' THEN ''approved'' WHEN u.Status = ''inactive'' THEN ''inactive'' ELSE ''pending'' END',
    'SELECT 1'
);
PREPARE volunteer_status_backfill_stmt FROM @volunteer_status_backfill;
EXECUTE volunteer_status_backfill_stmt;
DEALLOCATE PREPARE volunteer_status_backfill_stmt;
