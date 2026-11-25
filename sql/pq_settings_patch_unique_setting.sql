-- One row per setting, so that saving a setting updates it in place.
-- Duplicate rows must be removed first (see maintenance/PostDatabaseUpdate/dedupeSettings.php).
CREATE UNIQUE INDEX /*i*/pq_settings_setting ON /*_*/pq_settings (setting);
