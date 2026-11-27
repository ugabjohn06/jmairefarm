-- ============================================================
-- J'Maire Farm: admin controls (run once on an existing jmaire_farm database)
--   1. Add-ons get an optional per-day stock (NULL = unlimited)
--   2. Accommodations can be archived (kept for records, hidden everywhere else)
-- Fresh installs don't need this file: 02_reservation_system.sql already has both columns.
-- ============================================================
USE jmaire_farm;

ALTER TABLE addons
    ADD COLUMN daily_stock INT DEFAULT NULL AFTER unit_label;

ALTER TABLE accommodations
    ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_active;
