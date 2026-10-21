-- ShortURL Manager v6 performance update
-- Run this once on an existing installation.
-- Speeds up the 30-second repeat-click check on the clicks table.
ALTER TABLE clicks
    ADD INDEX idx_clicks_dedupe (link_id, ip_hash, counted, created_at);
