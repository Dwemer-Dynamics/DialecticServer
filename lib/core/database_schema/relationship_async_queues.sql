CREATE TABLE IF NOT EXISTS public.relationship_eval_queue (
    id SERIAL PRIMARY KEY,
    npc_id INTEGER NOT NULL UNIQUE,
    eval_data JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    retry_count INTEGER DEFAULT 0,
    last_error TEXT
);

ALTER TABLE public.relationship_eval_queue ADD COLUMN IF NOT EXISTS retry_count INTEGER DEFAULT 0;
ALTER TABLE public.relationship_eval_queue ADD COLUMN IF NOT EXISTS last_error TEXT;

CREATE TABLE IF NOT EXISTS public.relationship_init_queue (
    id SERIAL PRIMARY KEY,
    npc_id INTEGER NOT NULL UNIQUE,
    init_data JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    retry_count INTEGER DEFAULT 0,
    last_error TEXT
);

ALTER TABLE public.relationship_init_queue ADD COLUMN IF NOT EXISTS retry_count INTEGER DEFAULT 0;
ALTER TABLE public.relationship_init_queue ADD COLUMN IF NOT EXISTS last_error TEXT;
