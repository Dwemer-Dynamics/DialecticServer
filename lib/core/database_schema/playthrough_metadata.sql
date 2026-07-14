CREATE SCHEMA IF NOT EXISTS dialectic_meta;

CREATE TABLE IF NOT EXISTS dialectic_meta.playthrough_profiles (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    size_bytes BIGINT NOT NULL,
    notes TEXT,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    player_name TEXT,
    game TEXT,
    eventlog_count BIGINT,
    worldknowledge_count BIGINT,
    last_gamets BIGINT,
    schema_name TEXT,
    storage_type TEXT DEFAULT 'schema'
);

ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS player_name TEXT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS game TEXT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS eventlog_count BIGINT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS worldknowledge_count BIGINT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS last_gamets BIGINT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS schema_name TEXT;
ALTER TABLE dialectic_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS storage_type TEXT DEFAULT 'schema';
ALTER TABLE dialectic_meta.playthrough_profiles ALTER COLUMN storage_type SET DEFAULT 'schema';

CREATE TABLE IF NOT EXISTS dialectic_meta.settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
