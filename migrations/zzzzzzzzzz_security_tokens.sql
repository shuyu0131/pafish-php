-- Revoke outstanding links when removing the legacy plaintext-token fallback.
-- Hashes and old plaintext tokens have the same shape, so revoke both once.
UPDATE users SET reset_token = NULL, reset_token_expires = NULL
WHERE reset_token IS NOT NULL OR reset_token_expires IS NOT NULL;
