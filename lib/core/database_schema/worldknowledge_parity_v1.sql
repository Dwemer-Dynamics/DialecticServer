CREATE TABLE IF NOT EXISTS public.worldknowledge_catalogs (
    catalog_id text NOT NULL,
    catalog_version text NOT NULL,
    display_name text NOT NULL,
    checksum_sha256 character varying(64) NOT NULL,
    row_count integer NOT NULL,
    manifest jsonb NOT NULL DEFAULT '{}'::jsonb,
    is_active boolean NOT NULL DEFAULT false,
    installed_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at timestamp without time zone,
    CONSTRAINT worldknowledge_catalogs_pkey PRIMARY KEY (catalog_id, catalog_version),
    CONSTRAINT worldknowledge_catalogs_checksum_format CHECK (checksum_sha256 ~ '^[a-f0-9]{64}$'),
    CONSTRAINT worldknowledge_catalogs_row_count CHECK (row_count >= 0)
);

ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS entry_id bigserial;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS canonical_topic text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS source_kind text NOT NULL DEFAULT 'custom';
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS catalog_id text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS catalog_version text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS content_hash character varying(64);
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS source_url text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS source_revision text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS setting text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS region text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS valid_from_year integer;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS valid_to_year integer;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS editorial_note text;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS retrieval_phrases text NOT NULL DEFAULT '';
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS metadata jsonb NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS created_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE public.worldknowledge
SET canonical_topic = lower(btrim(split_part(topic, ',', 1)))
WHERE canonical_topic IS NULL OR btrim(canonical_topic) = '';

ALTER TABLE public.worldknowledge ALTER COLUMN canonical_topic SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.worldknowledge'::regclass
          AND contype = 'p'
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_pkey PRIMARY KEY (entry_id);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'worldknowledge_ownership_check'
          AND conrelid = 'public.worldknowledge'::regclass
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_ownership_check CHECK (
                (source_kind = 'factory' AND catalog_id IS NOT NULL AND catalog_version IS NOT NULL)
                OR (source_kind = 'custom' AND catalog_id IS NULL AND catalog_version IS NULL)
            );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'worldknowledge_content_hash_check'
          AND conrelid = 'public.worldknowledge'::regclass
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_content_hash_check CHECK (
                content_hash IS NULL OR content_hash ~ '^[a-f0-9]{64}$'
            );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'worldknowledge_chronology_check'
          AND conrelid = 'public.worldknowledge'::regclass
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_chronology_check CHECK (
                valid_from_year IS NULL OR valid_to_year IS NULL OR valid_from_year <= valid_to_year
            );
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'worldknowledge_source_kind_check'
          AND conrelid = 'public.worldknowledge'::regclass
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_source_kind_check
            CHECK (source_kind IN ('factory', 'custom'));
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'worldknowledge_catalog_foreign_key'
          AND conrelid = 'public.worldknowledge'::regclass
    ) THEN
        ALTER TABLE public.worldknowledge
            ADD CONSTRAINT worldknowledge_catalog_foreign_key
            FOREIGN KEY (catalog_id, catalog_version)
            REFERENCES public.worldknowledge_catalogs(catalog_id, catalog_version)
            ON DELETE RESTRICT;
    END IF;
END
$$;

DROP INDEX IF EXISTS public.worldknowledge_topic_unique_idx;
DROP INDEX IF EXISTS public.worldknowledge_canonical_topic_unique_idx;

CREATE UNIQUE INDEX IF NOT EXISTS worldknowledge_custom_topic_unique_idx
    ON public.worldknowledge (canonical_topic)
    WHERE source_kind = 'custom' AND is_active;

CREATE UNIQUE INDEX IF NOT EXISTS worldknowledge_factory_topic_unique_idx
    ON public.worldknowledge (catalog_id, catalog_version, canonical_topic)
    WHERE source_kind = 'factory';

CREATE UNIQUE INDEX IF NOT EXISTS worldknowledge_one_active_catalog_idx
    ON public.worldknowledge_catalogs ((is_active))
    WHERE is_active;

CREATE INDEX IF NOT EXISTS worldknowledge_effective_lookup_idx
    ON public.worldknowledge (canonical_topic, source_kind, is_active);

CREATE INDEX IF NOT EXISTS worldknowledge_catalog_lookup_idx
    ON public.worldknowledge (catalog_id, catalog_version)
    WHERE source_kind = 'factory';

UPDATE public.worldknowledge
SET native_vector =
      setweight(to_tsvector('simple', coalesce(topic, '')), 'A')
   || setweight(to_tsvector('simple', coalesce(topic_desc, '')), 'B')
   || setweight(to_tsvector('simple', coalesce(topic_desc_basic, '')), 'C')
   || setweight(to_tsvector('simple', coalesce(retrieval_phrases, '')), 'A')
   || setweight(to_tsvector('simple', coalesce(tags, '')), 'B')
   || setweight(to_tsvector('simple', coalesce(category, '')), 'C');

DROP VIEW IF EXISTS public.worldknowledge_effective;
CREATE VIEW public.worldknowledge_effective AS
WITH candidates AS (
    SELECT wk.*,
           row_number() OVER (
               PARTITION BY wk.canonical_topic
               ORDER BY CASE WHEN wk.source_kind = 'custom' THEN 0 ELSE 1 END,
                        wk.updated_at DESC,
                        wk.entry_id DESC
           ) AS effective_rank
    FROM public.worldknowledge wk
    LEFT JOIN public.worldknowledge_catalogs catalog
      ON catalog.catalog_id = wk.catalog_id
     AND catalog.catalog_version = wk.catalog_version
    WHERE wk.is_active
      AND (
          wk.source_kind = 'custom'
          OR (wk.source_kind = 'factory' AND catalog.is_active)
      )
)
SELECT entry_id, topic, canonical_topic, topic_desc, native_vector,
       knowledge_class, topic_desc_basic, knowledge_class_basic, retrieval_phrases,
       tags, category, source_kind, catalog_id, catalog_version,
       content_hash, source_url, source_revision, setting, region,
       valid_from_year, valid_to_year, editorial_note, metadata,
       is_active, created_at, updated_at
FROM candidates
WHERE effective_rank = 1;

CREATE TABLE IF NOT EXISTS public.worldknowledge_audit (
    audit_id bigserial PRIMARY KEY,
    created_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    algorithm_version text NOT NULL,
    status text NOT NULL,
    request_type text NOT NULL,
    npc_name text,
    input_text text NOT NULL,
    normalized_input text NOT NULL,
    catalog_id text,
    catalog_version text,
    grounded_matches jsonb NOT NULL DEFAULT '[]'::jsonb,
    rejected_candidates jsonb NOT NULL DEFAULT '[]'::jsonb,
    tag_decisions jsonb NOT NULL DEFAULT '[]'::jsonb,
    context_tags jsonb NOT NULL DEFAULT '[]'::jsonb,
    fallback jsonb NOT NULL DEFAULT '{}'::jsonb,
    forced_signals jsonb NOT NULL DEFAULT '[]'::jsonb,
    access_decisions jsonb NOT NULL DEFAULT '[]'::jsonb,
    selected_articles jsonb NOT NULL DEFAULT '[]'::jsonb,
    settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    catalog_checksum character varying(64),
    prompt_hash character varying(64),
    retrieval_elapsed_ms numeric(12,3) NOT NULL DEFAULT 0,
    elapsed_ms numeric(12,3) NOT NULL DEFAULT 0,
    CONSTRAINT worldknowledge_audit_status_check CHECK (
        status IN ('grounded', 'no_match', 'fallback_succeeded', 'fallback_unresolved', 'fallback_failed',
                   'fallback_disabled', 'fallback_unconfigured', 'disabled', 'ineligible', 'unavailable',
                   'not_run', 'legacy')
    )
);

ALTER TABLE public.worldknowledge_audit
    ADD COLUMN IF NOT EXISTS retrieval_elapsed_ms numeric(12,3) NOT NULL DEFAULT 0;

ALTER TABLE public.worldknowledge_audit
    ADD COLUMN IF NOT EXISTS context_tags jsonb NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE public.worldknowledge_audit
    ADD COLUMN IF NOT EXISTS settings jsonb NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE public.worldknowledge_audit
    ADD COLUMN IF NOT EXISTS catalog_checksum character varying(64);

ALTER TABLE public.worldknowledge_audit
    ADD COLUMN IF NOT EXISTS prompt_hash character varying(64);

ALTER TABLE public.worldknowledge_audit DROP CONSTRAINT IF EXISTS worldknowledge_audit_status_check;
UPDATE public.worldknowledge_audit SET status = 'grounded' WHERE status = 'denied';
ALTER TABLE public.worldknowledge_audit
    ADD CONSTRAINT worldknowledge_audit_status_check CHECK (
        status IN ('grounded', 'no_match', 'fallback_succeeded', 'fallback_unresolved', 'fallback_failed',
                   'fallback_disabled', 'fallback_unconfigured', 'disabled', 'ineligible', 'unavailable',
                   'not_run', 'legacy')
    );

CREATE INDEX IF NOT EXISTS worldknowledge_audit_created_idx
    ON public.worldknowledge_audit (created_at DESC);

CREATE INDEX IF NOT EXISTS worldknowledge_audit_status_idx
    ON public.worldknowledge_audit (status, created_at DESC);
