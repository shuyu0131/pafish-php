-- Existing rows remain Markdown. Editor plugins never own this metadata.
ALTER TABLE posts ADD COLUMN content_format VARCHAR(16) NOT NULL DEFAULT 'markdown' AFTER content;
ALTER TABLE pages ADD COLUMN content_format VARCHAR(16) NOT NULL DEFAULT 'markdown' AFTER content;
