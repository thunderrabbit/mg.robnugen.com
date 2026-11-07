-- The panel sometimes lists a database as "Size unavailable". That is unknown,
-- not zero: EmptyDatabaseRule reads 0 as empty and would let a database whose
-- size nobody knows be marked for deletion (mg #803). NULL means unknown.

ALTER TABLE dh_database_usage MODIFY size_bytes BIGINT UNSIGNED NULL;
