-- Email verification now lives in the requesting browser's PHP session.
-- Retired rows are transient credentials, not account data; request new codes after upgrading.
DROP TABLE IF EXISTS email_codes;
