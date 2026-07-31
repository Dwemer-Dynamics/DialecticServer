CREATE TABLE IF NOT EXISTS public.visual_context (
    id BIGSERIAL PRIMARY KEY,
    capture_id TEXT NOT NULL UNIQUE,
    subject_type TEXT NOT NULL DEFAULT 'scene',
    subject_key TEXT NOT NULL,
    subject_name TEXT NOT NULL DEFAULT '',
    plugin TEXT NOT NULL DEFAULT '',
    baseid TEXT NOT NULL DEFAULT '',
    refid TEXT NOT NULL DEFAULT '',
    cell_id TEXT NOT NULL DEFAULT '',
    worldspace_id TEXT NOT NULL DEFAULT '',
    location_name TEXT NOT NULL DEFAULT '',
    worldspace_name TEXT NOT NULL DEFAULT '',
    image_path TEXT NOT NULL DEFAULT '',
    image_sha256 TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    perspective TEXT NOT NULL DEFAULT 'first_person',
    provider TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL DEFAULT '',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    locked BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    user_edited BOOLEAN NOT NULL DEFAULT FALSE,
    captured_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS visual_context_location_idx
    ON public.visual_context (LOWER(worldspace_name), LOWER(location_name), active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_cell_idx
    ON public.visual_context (LOWER(cell_id), active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_subject_idx
    ON public.visual_context (subject_type, subject_key, active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_image_idx
    ON public.visual_context (image_sha256);
