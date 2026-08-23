<?php 

require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib/logger.php");
require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib/settings.php");
require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib/dialectic_runtime.php");

$checkVersion = function($tablename) {
    global $db;
    $query = "
    SELECT version 
    FROM public.database_versioning
    WHERE tablename = '$tablename'
    ";

    $existsColumn=$db->fetchAll($query);

    if (sizeof($existsColumn) == 0 || !$existsColumn[0]["version"] )
        return -1;
    else
        return intval($existsColumn[0]["version"]);
};

$checkTableExists = function($tablename) {
    global $db;
    $query = "
    
        SELECT 1 as exists
        FROM information_schema.tables 
        WHERE table_schema = 'public'
          AND table_name = '$tablename'
    
    ";

    $result = $db->fetchAll($query);

    if (sizeof($result) == 0) {
        return -1;
    }

    return ($result[0]["exists"] == "1")?1:-1;
};

$checkColumnExists = function($tablename, $columnname) {
    global $db;
    $query = "
        SELECT 1 AS exists 
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = '{$tablename}' AND column_name = '{$columnname}'
    ";

    $result = $db->fetchAll($query);

    if (sizeof($result) == 0) {
        return -1;
    }

    return ($result[0]["exists"] == "1") ? 1 : -1;
};

$updateVersion = function($tablename,$version) {
    global $db;
    $db->execQuery("INSERT INTO public.database_versioning SELECT '$tablename',$version where not exists (SELECT 1 from public.database_versioning where tablename='$tablename')");
    $db->execQuery("UPDATE public.database_versioning set version=$version WHERE tablename='$tablename'");
    Logger::info("TABLE $tablename updated to version $version");
};

$refreshDialecticMemoryView = function() use ($db) {
    $sqlFile = __DIR__ . "/../data/memory_v_dialectic.sql";
    if (!file_exists($sqlFile)) {
        Logger::warn("memory_v Dialectic SQL file not found: " . $sqlFile);
        return false;
    }

    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false || strlen($sqlContent) === 0) {
        Logger::warn("memory_v Dialectic SQL file is empty: " . $sqlFile);
        return false;
    }

    $sqlContent = preg_replace('/^\xEF\xBB\xBF/', '', $sqlContent);
    return (bool)$db->execQuery($sqlContent);
};

/////////////////////////

// Ensure base schema and extensions exist for fresh installs
$db->execQuery('CREATE SCHEMA IF NOT EXISTS public');
$db->execQuery('CREATE SCHEMA IF NOT EXISTS plugins');
$db->execQuery("SET search_path TO public");
$db->execQuery('CREATE EXTENSION IF NOT EXISTS vector');
$db->execQuery('CREATE EXTENSION IF NOT EXISTS pg_trgm');

// Ensure database_versioning exists before version checks
try {
    $exists = $db->fetchAll("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='database_versioning'");
    if (!$exists) {
        $db->execQuery(file_get_contents(__DIR__."/../data/database_versioning.sql"));
        $db->execQuery("SET search_path TO public");
    }
} catch (Exception $e) {
    Logger::warn("database_versioning bootstrap: ".$e->getMessage());
}

// Bootstrap critical core tables early to avoid UI queries failing during initial load
try {
    if ($checkTableExists("general_settings") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/general_settings.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_api_badge") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_api_badge.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_stt_connector") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_stt_connector.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_itt_connector") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_itt_connector.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_tts_connector") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_tts_connector.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_llm_connector") == -1) {
        // ensure api_badge for FK first
        if ($checkTableExists("core_api_badge") == -1) {
            $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_api_badge.sql"));
            $db->execQuery("SET search_path TO public");
        }
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_llm_connector.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_profiles") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_profiles.sql"));
    $db->execQuery("SET search_path TO public");
}
    if ($checkTableExists("core_action") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_action.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_npc_master") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_npc_master.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_player") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_player.sql"));
        $db->execQuery("SET search_path TO public");
    }
    if ($checkTableExists("core_narrator") == -1) {
        $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_narrator.sql"));
        $db->execQuery("SET search_path TO public");
    }
} catch (Exception $e) {
    Logger::warn("Bootstrap core tables: " . $e->getMessage());
}

if ($checkVersion("conf_opts") < 20260626001) {
    try {
        if ($checkTableExists("conf_opts") == 1) {
            $constraintExists = $db->fetchAll("
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'conf_opts_pkey'
                  AND conrelid = 'public.conf_opts'::regclass
                LIMIT 1
            ");
            if (sizeof($constraintExists) == 0) {
                $db->execQuery("
                    ALTER TABLE public.conf_opts
                    ADD CONSTRAINT conf_opts_pkey PRIMARY KEY (id)
                ");
            }
        }
        $updateVersion("conf_opts", 20260626001);
        Logger::info("Applied patch conf_opts 20260626001 - primary key on id");
    } catch (Exception $e) {
        Logger::error("Error applying conf_opts primary key migration: " . $e->getMessage());
    }
}

if ($checkVersion("core_action") < 20260426001) {
    Logger::debug("Applying core_action 20260426001 - add parameters/metadata/game function fields");

    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS parameters_json JSONB NOT NULL DEFAULT '{}'::jsonb");
    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS metadata JSONB NOT NULL DEFAULT '{}'::jsonb");
    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS game_function BOOLEAN NOT NULL DEFAULT TRUE");
    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS script_proxy_program JSONB");

    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS parameters_json JSONB NOT NULL DEFAULT '{}'::jsonb");
    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS metadata JSONB NOT NULL DEFAULT '{}'::jsonb");
    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS game_function BOOLEAN NOT NULL DEFAULT TRUE");
    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS script_proxy_program JSONB");

    $db->execQuery("CREATE INDEX IF NOT EXISTS idx_core_action_game_function ON public.core_action (game_function)");
    $db->execQuery("CREATE INDEX IF NOT EXISTS idx_core_action_custom_game_function ON public.core_action_custom (game_function)");

    $db->execQuery("DROP VIEW IF EXISTS public.combined_core_action");
    $db->execQuery("
        CREATE VIEW public.combined_core_action AS
        SELECT
            c.id,
            c.code_name,
            c.action_name,
            c.description,
            c.return_message,
            c.available_to_npc,
            c.available_to_followers,
            c.is_activated,
            c.parameters_json,
            c.metadata,
            c.game_function,
            c.script_proxy_program,
            c.created_at,
            c.updated_at
        FROM public.core_action_custom c
        UNION ALL
        SELECT
            b.id,
            b.code_name,
            b.action_name,
            b.description,
            b.return_message,
            b.available_to_npc,
            b.available_to_followers,
            b.is_activated,
            b.parameters_json,
            b.metadata,
            b.game_function,
            b.script_proxy_program,
            b.created_at,
            b.updated_at
        FROM public.core_action b
        LEFT JOIN public.core_action_custom c ON LOWER(b.code_name) = LOWER(c.code_name)
        WHERE c.code_name IS NULL
    ");

    $updateVersion("core_action", 20260426001);
    Logger::info("Applied patch core_action 20260426001");
}

if ($checkVersion("core_action") < 20260427001) {
    Logger::debug("Applying core_action 20260427001 - add import_version field");

    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS import_version BIGINT NOT NULL DEFAULT 0");
    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS import_version BIGINT NOT NULL DEFAULT 0");

    $db->execQuery("UPDATE public.core_action SET import_version = 0 WHERE import_version IS NULL");
    $db->execQuery("UPDATE public.core_action_custom SET import_version = 0 WHERE import_version IS NULL");

    $db->execQuery("DROP VIEW IF EXISTS public.combined_core_action");
    $db->execQuery("
        CREATE VIEW public.combined_core_action AS
        SELECT
            c.id,
            c.code_name,
            c.action_name,
            c.description,
            c.return_message,
            c.available_to_npc,
            c.available_to_followers,
            c.is_activated,
            c.parameters_json,
            c.metadata,
            c.game_function,
            c.import_version,
            c.script_proxy_program,
            c.created_at,
            c.updated_at
        FROM public.core_action_custom c
        UNION ALL
        SELECT
            b.id,
            b.code_name,
            b.action_name,
            b.description,
            b.return_message,
            b.available_to_npc,
            b.available_to_followers,
            b.is_activated,
            b.parameters_json,
            b.metadata,
            b.game_function,
            b.import_version,
            b.script_proxy_program,
            b.created_at,
            b.updated_at
        FROM public.core_action b
        LEFT JOIN public.core_action_custom c ON LOWER(b.code_name) = LOWER(c.code_name)
        WHERE c.code_name IS NULL
    ");

    $updateVersion("core_action", 20260427001);
    Logger::info("Applied patch core_action 20260427001");
}

if ($checkVersion("core_action") < 20260428001) {
    Logger::debug("Applying core_action 20260428001 - seed baseline actions from repo snapshot when empty");

    $row = $db->fetchOne("SELECT COUNT(*) AS total FROM public.core_action");
    $baseRowCount = intval($row['total'] ?? 0);
    if ($baseRowCount === 0) {
        $seedFile = __DIR__ . "/../data/core_action_seed.sql";
        if (file_exists($seedFile) && trim(strval(file_get_contents($seedFile))) !== '') {
            $db->execQuery(file_get_contents($seedFile));
            Logger::info("Seeded public.core_action from core_action_seed.sql");
        } else {
            Logger::warn("core_action seed file missing or empty; leaving public.core_action unseeded");
        }
    }

    $updateVersion("core_action", 20260428001);
    Logger::info("Applied patch core_action 20260428001");
}

if ($checkVersion("core_action") < 20260429002) {
    Logger::debug("Applying core_action 20260429002 - add available_to_narrator field");

    $db->execQuery("ALTER TABLE public.core_action ADD COLUMN IF NOT EXISTS available_to_narrator BOOLEAN NOT NULL DEFAULT FALSE");
    $db->execQuery("ALTER TABLE public.core_action_custom ADD COLUMN IF NOT EXISTS available_to_narrator BOOLEAN NOT NULL DEFAULT FALSE");

    $db->execQuery("UPDATE public.core_action SET available_to_narrator = FALSE WHERE available_to_narrator IS NULL");
    $db->execQuery("UPDATE public.core_action_custom SET available_to_narrator = FALSE WHERE available_to_narrator IS NULL");

    $db->execQuery("CREATE INDEX IF NOT EXISTS idx_core_action_available_to_narrator ON public.core_action (available_to_narrator)");
    $db->execQuery("CREATE INDEX IF NOT EXISTS idx_core_action_custom_available_to_narrator ON public.core_action_custom (available_to_narrator)");

    $db->execQuery("DROP VIEW IF EXISTS public.combined_core_action");
    $db->execQuery("
        CREATE VIEW public.combined_core_action AS
        SELECT
            c.id,
            c.code_name,
            c.action_name,
            c.description,
            c.return_message,
            c.available_to_npc,
            c.available_to_followers,
            c.available_to_narrator,
            c.is_activated,
            c.parameters_json,
            c.metadata,
            c.game_function,
            c.import_version,
            c.script_proxy_program,
            c.created_at,
            c.updated_at
        FROM public.core_action_custom c
        UNION ALL
        SELECT
            b.id,
            b.code_name,
            b.action_name,
            b.description,
            b.return_message,
            b.available_to_npc,
            b.available_to_followers,
            b.available_to_narrator,
            b.is_activated,
            b.parameters_json,
            b.metadata,
            b.game_function,
            b.import_version,
            b.script_proxy_program,
            b.created_at,
            b.updated_at
        FROM public.core_action b
        LEFT JOIN public.core_action_custom c ON LOWER(b.code_name) = LOWER(c.code_name)
        WHERE c.code_name IS NULL
    ");

    $updateVersion("core_action", 20260429002);
    Logger::info("Applied patch core_action 20260429002");
}

if ($checkVersion("core_action") < 20260430013) {
    Logger::debug("Applying core_action 20260430013 - disable built-in followups for selected actions");

    $disableBuiltInFollowups = function ($tableName, $rewriteCustomRows = false) use ($db) {
        $targetCodes = [
            'Attack',
            'GiveItemTo',
            'MoveTo',
        ];

        $rows = $db->fetchAll("SELECT id, code_name, metadata FROM public.{$tableName}");
        foreach ($rows as $row) {
            $rowId = intval($row['id'] ?? 0);
            $codeName = trim(strval($row['code_name'] ?? ''));
            if ($rowId <= 0 || !in_array($codeName, $targetCodes, true)) {
                continue;
            }

            $metadata = json_decode(strval($row['metadata'] ?? '{}'), true);
            if (!is_array($metadata) || !is_array($metadata['followup'] ?? null)) {
                continue;
            }

            if ($rewriteCustomRows) {
                $customConfig = is_array($metadata['custom_config'] ?? null) ? $metadata['custom_config'] : [];
                if (array_key_exists('followup_enabled', $customConfig)) {
                    continue;
                }
            }

            if (array_key_exists('enabled', $metadata['followup']) && empty($metadata['followup']['enabled'])) {
                continue;
            }

            $metadata['followup']['enabled'] = false;
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($metadataJson) || $metadataJson === '') {
                $metadataJson = '{}';
            }

            $metadataLiteral = $db->escapeLiteral($metadataJson);
            $db->execQuery("
                UPDATE public.{$tableName}
                SET metadata = {$metadataLiteral}::jsonb,
                    updated_at = NOW()
                WHERE id = {$rowId}
            ");
        }
    };

    $disableBuiltInFollowups('core_action', false);
    $disableBuiltInFollowups('core_action_custom', true);

    $updateVersion("core_action", 20260430013);
    Logger::info("Applied patch core_action 20260430013");
}

if ($checkVersion("game_plugins") < 20260427001) {
    Logger::debug("Applying game_plugins 20260427001 - create loaded plugin manifest table");

    $db->execQuery(file_get_contents(__DIR__ . "/../data/add_game_plugins.sql"));
    $db->execQuery("SET search_path TO public");

    $updateVersion("game_plugins", 20260427001);
    Logger::info("Applied patch game_plugins 20260427001");
}

// Narrator is now managed via core_narrator table, not core_npc_master
// Seeding of narrator data happens in the core_narrator migration blocks

$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'eventlog' AND column_name = 'people'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "eventlog" ADD COLUMN "people" text');
    Logger::info("Applied patch eventlog 0.1.2 - added people column");
}

// Add location info to event log

$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'eventlog' AND column_name = 'location'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "eventlog" ADD COLUMN "location" text');
    Logger::info("Applied patch eventlog 0.1.3 - added location column");
}

// Add party info to event log
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'eventlog' AND column_name = 'party'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "eventlog" ADD COLUMN "party" text');
    Logger::info("Applied patch eventlog 0.1.4p1 - added party column");
}

// Add tags to memory summary
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'memory_summary' AND column_name = 'tags'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "memory_summary" ADD COLUMN "tags" text');
    Logger::info("Applied patch memory_summary 0.1.4p2 - added tags column");
}

// Ensure native_vec is created
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'memory_summary' AND column_name = 'native_vec'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "memory_summary" ADD COLUMN "native_vec" TSVECTOR');
    Logger::info("Applied patch memory_summary 0.1.4p3 - added native_vec column");
}
$db->execQuery('CREATE INDEX IF NOT EXISTS memory_summary_tsv_idx ON public.memory_summary USING GIN(native_vec);');

$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'audit_memory' AND column_name = 'keywords'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('
    CREATE TABLE public.audit_memory (
    input text,
    keywords text,
    rank_any numeric(20,10),
    rank_all numeric(20,10),
    memory text,
    "time" text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
)');
    Logger::info("Applied patch audit_memory 0.1.5p1 - created table");
}

// Memory ts
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'memory' AND column_name = 'ts'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
        $db->execQuery('ALTER TABLE "memory" ADD COLUMN "ts" bigint');
        $refreshDialecticMemoryView();

        Logger::info("Applied patch memory 0.1.6p1 - added ts column and refreshed memory_v");
    
}

// Ensure memory_v exists
// Memory ts
$query = "
    SELECT view_definition 
    FROM information_schema.views 
    WHERE table_name = 'memory_v'
";


$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["view_definition"]) {
        $refreshDialecticMemoryView();
    
}

// Recreate vectors summary
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'memory_summary' AND column_name = 'embedding'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE memory_summary add embedding VECTOR(384)');
    
}

// Recreate vectors summary
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'memory_summary' AND column_name = 'embedding768'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE memory_summary add embedding768 VECTOR(768)');
    
}
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'audit_request' AND column_name = 'request'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn[0]["column_name"]) {
    $db->execQuery('
    CREATE TABLE public.audit_request (
        request text,
        result text,
        created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
        rowid bigint NOT NULL
    );
    CREATE SEQUENCE public.audit_request_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
    ALTER TABLE ONLY public.audit_request ALTER COLUMN rowid SET DEFAULT nextval(\'public.audit_request_rowid_seq\'::regclass);
    ALTER TABLE ONLY public.audit_request ADD CONSTRAINT audit_request_primary PRIMARY KEY (rowid);

');
    Logger::info("Applied patch audit_request 0.9.7 - created table");
}


$db->execQuery("
    CREATE TABLE IF NOT EXISTS public.worldknowledge (
        topic character varying NOT NULL,
        aliases text NOT NULL DEFAULT '',
        topic_desc character varying NOT NULL,
        native_vector tsvector,
        knowledge_class text,
        topic_desc_basic text,
        knowledge_class_basic text,
        tags text,
        category text
    )
");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS aliases text NOT NULL DEFAULT ''");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS native_vector tsvector");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS knowledge_class text");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS topic_desc_basic text");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS knowledge_class_basic text");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS tags text");
$db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN IF NOT EXISTS category text");
$db->execQuery("UPDATE public.worldknowledge SET native_vector = setweight(to_tsvector(coalesce(topic, '')),'A')||setweight(to_tsvector(coalesce(topic_desc, '')),'B')");
if ($checkVersion("worldknowledge")<20250902002) {
    $updateVersion("worldknowledge",20250902002);
    Logger::info("Applied patch worldknowledge current schema");
}

//----------------------------------------------------
// SQL convert gamets timestamp to date time formatted
//  sql_gamets_convert_functions 20250218001
//----------------------------------------------------

// Check if functions exist to force patch if they're missing
$checkFunctionExists = function($functionName) {
    global $db;
    $query = "
        SELECT 1 
        FROM information_schema.routines 
        WHERE routine_schema = 'public' 
          AND routine_name = '$functionName'
    ";
    $result = $db->fetchAll($query);
    return (sizeof($result) > 0);
};

$forceRecreate = false;
if (!$checkFunctionExists('convert_gamets2fallout_date') || 
    !$checkFunctionExists('convert_gamets2days') ||
    !$checkFunctionExists('convert_gamets2gregorian_date') ||
    !$checkFunctionExists('convert_gamets2fallout_long_date')) {
    Logger::warn("Some gamets conversion functions are missing. Forcing recreation.");
    $forceRecreate = true;
}

if ($checkVersion("sql_gamets_convert_functions")<20260627002 || $forceRecreate) {
    Logger::debug(" try patch: sql_gamets_convert_functions 20260627002");

    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2days(gamets bigint) CASCADE;");
    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2gregorian_date(gamets bigint) CASCADE;");
    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2fallout_long_date(gamets bigint) CASCADE;");
    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2fallout_long_date2(gamets bigint) CASCADE;");
    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2fallout_date(gamets bigint) CASCADE;");
    $db->execQuery("DROP FUNCTION IF EXISTS public.convert_gamets2hours(gamets bigint) CASCADE;");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2days(gamets bigint) RETURNS bigint
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN floor(gamets * 0.0000001);
            END;
        $$;  ");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2gregorian_date(gamets bigint) RETURNS text
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN to_char(timestamp '2281-10-19 00:00:00' + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$;  ");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2hours(gamets bigint) RETURNS bigint
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN floor(gamets * 0.0000024);
            END;
        $$; ");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2fallout_date(gamets bigint) RETURNS text
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN to_char(timestamp '2281-10-19 00:00:00' + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$; ");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2fallout_long_date(gamets bigint) RETURNS text
            LANGUAGE plpgsql
            AS $$
            DECLARE 
                s_date1 text; 
                s_date2 text; 
                s_date3 text; 
                s_month text;
                s_dayweek text;
                s_dayname text;
                s_longm text;
                f_hours float;
                ts_base timestamp;
                ts2 timestamp;
                s_res text;
            BEGIN
                f_hours := (gamets * 0.0000024);
                ts_base := timestamp '2281-10-19 00:00:00';
                ts2 := ts_base  + f_hours * INTERVAL '1 hour';
                s_month := to_char(ts2, 'MM');
                s_dayweek := to_char(ts2, 'D'); -- D	day of the week, 
                CASE s_dayweek
                    WHEN '2' THEN s_dayname := 'Sunday'; -- sunday
                    WHEN '3' THEN s_dayname := 'Monday';
                    WHEN '4' THEN s_dayname := 'Tuesday';
                    WHEN '5' THEN s_dayname := 'Wednesday';
                    WHEN '6' THEN s_dayname := 'Thursday';
                    WHEN '7' THEN s_dayname := 'Friday';
                    WHEN '1' THEN s_dayname := 'Saturday'; -- saturday
                    ELSE s_dayname := 'unknown day';
                END CASE;
                CASE s_month
                    WHEN '01' THEN s_longm := 'January';
                    WHEN '02' THEN s_longm := 'February';
                    WHEN '03' THEN s_longm := 'March';
                    WHEN '04' THEN s_longm := 'April';
                    WHEN '05' THEN s_longm := 'May';
                    WHEN '06' THEN s_longm := 'June';
                    WHEN '07' THEN s_longm := 'July';
                    WHEN '08' THEN s_longm := 'August';
                    WHEN '09' THEN s_longm := 'September';
                    WHEN '10' THEN s_longm := 'October';
                    WHEN '11' THEN s_longm := 'November';
                    WHEN '12' THEN s_longm := 'December';
                    ELSE s_longm := 'unknown month';
                END CASE;
                s_date1 := to_char(ts2, 'HH12:MI AM');
                s_date2 := to_char(ts2, 'FMDD');
                s_date3 := to_char(ts2, ', FMYYYY');
                s_res := s_dayname || ', ' || s_date1 || ', ' || s_date2 ||  'th of ' || s_longm || s_date3;
                RETURN s_res;
            END;
        $$; ");

    $db->execQuery("
        CREATE OR REPLACE FUNCTION public.convert_gamets2fallout_long_date2(gamets bigint) RETURNS text
            LANGUAGE plpgsql
            AS $$
            DECLARE 
                s_date1 text; 
                s_date2 text; 
                s_month text;
                s_longm text;
                f_hours float;
                ts_base timestamp;
                ts2 timestamp;
                s_res text;
            BEGIN
                f_hours := (gamets * 0.0000024);
                ts_base := timestamp '2281-10-19 00:00:00';
                ts2 := ts_base  + f_hours * INTERVAL '1 hour';
                s_month := to_char(ts2, 'MM');
                CASE s_month
                    WHEN '01' THEN s_longm := 'January';
                    WHEN '02' THEN s_longm := 'February';
                    WHEN '03' THEN s_longm := 'March';
                    WHEN '04' THEN s_longm := 'April';
                    WHEN '05' THEN s_longm := 'May';
                    WHEN '06' THEN s_longm := 'June';
                    WHEN '07' THEN s_longm := 'July';
                    WHEN '08' THEN s_longm := 'August';
                    WHEN '09' THEN s_longm := 'September';
                    WHEN '10' THEN s_longm := 'October';
                    WHEN '11' THEN s_longm := 'November';
                    WHEN '12' THEN s_longm := 'December';
                    ELSE s_longm := 'unknown';
                END CASE;
                s_date1 := to_char(ts2, 'DD');
                s_date2 := to_char(ts2, ' FMYYYY, HH24:MI');
                s_res := s_date1 || 'th of ' || s_longm || s_date2;
                RETURN s_res;
            END;
        $$; ");

    $updateVersion("sql_gamets_convert_functions",20260627002);
    Logger::debug("Applied patch: sql_gamets_convert_functions 20260627002");
}



// fix for memory_summary missing companions
if ($checkVersion("memory_summary")<20250331001) {
    $db->execQuery("UPDATE memory_summary set companions = NULL WHERE companions = '';");
    $updateVersion("memory_summary",20250331001);
    Logger::info("Applied patch memory_summary 20250331001");
}

// add memory_summary scope support (global by default in current system)
if ($checkVersion("memory_summary")<20260319001) {
    $scopeColumn = $db->fetchOne("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'memory_summary' AND column_name = 'scope'
    ");

    if (!isset($scopeColumn["column_name"]) || !$scopeColumn["column_name"]) {
        $db->execQuery('ALTER TABLE "memory_summary" ADD COLUMN "scope" text');
    }

    $updateVersion("memory_summary",20260319001);
    Logger::info("Applied patch memory_summary 20260319001");
}

if ($checkVersion("memory_summary") < 20260730001) {
    Logger::debug("Applying memory_summary 20260730001 - normalize diary memory owners");

    $migrationOk = $db->execQuery("
        UPDATE public.memory_summary
        SET companions = '|' || trim(both '|' from trim(companions)) || '|'
        WHERE classifier = 'diary'
          AND nullif(trim(companions), '') IS NOT NULL
          AND companions NOT LIKE '|%|'
    ") !== false;

    if ($migrationOk) {
        $updateVersion("memory_summary", 20260730001);
        Logger::info("Applied patch memory_summary 20260730001");
    } else {
        Logger::error("Failed to apply patch memory_summary 20260730001");
    }
}

if ($checkVersion("rolemaster")<20250414001) {
    $db->execQuery(file_get_contents(__DIR__."/../data/rolemaster.sql"));
    $updateVersion("rolemaster",20250414001);
    Logger::info("Applied patch rolemaster 20250414001");
}

if ($checkVersion("locations")<20250516001) {
    $db->execQuery(file_get_contents(__DIR__."/../data/add_locations.sql"));
    $updateVersion("locations",20250516001);
    Logger::info("Applied patch locations 20250516001");
}

if ($checkVersion(tablename: "factions")<20260214001) {
    $db->execQuery(file_get_contents(__DIR__."/../data/add_factions.sql"));
    $updateVersion("factions",20260214001);
    Logger::info("Applied patch factions 20260214001");
}

if ($checkVersion("actions_issued")<20250525001) {
    $db->execQuery(file_get_contents(__DIR__."/../data/actions_issued.sql"));
    $updateVersion("actions_issued",20250525001);
    Logger::info("Applied patch actions_issued 20250525001");
}


if ($checkVersion("moods_issued")<20250526001) {
    $db->execQuery(file_get_contents(__DIR__."/../data/table_moods_issued.sql"));
    $updateVersion("moods_issued",20250526001);
    Logger::info("Applied patch moods_issued 20250526001");
}

if ($checkVersion("moods_issued_sequence")<20260626001) {
    $db->execQuery("CREATE SEQUENCE IF NOT EXISTS public.moods_issued_rowid_seq");
    $db->execQuery("SELECT setval('public.moods_issued_rowid_seq', COALESCE((SELECT MAX(rowid) FROM public.moods_issued), 0) + 1, false)");
    $db->execQuery("ALTER SEQUENCE public.moods_issued_rowid_seq OWNED BY public.moods_issued.rowid");
    $db->execQuery("ALTER TABLE public.moods_issued ALTER COLUMN rowid SET DEFAULT nextval('public.moods_issued_rowid_seq'::regclass)");
    $updateVersion("moods_issued_sequence",20260626001);
    Logger::info("Applied patch moods_issued_sequence 20260626001");
}

//----------------------------------------------------

//if ($checkVersion("worldknowledge")<20250903001) { // version 202509... 
    Logger::debug(" try patch: worldknowledge 20250903001");
    
    // Check if vector384 column exists first
    try {
        $columnCheck = $db->fetchAll("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'worldknowledge' 
            AND column_name = 'vector384' 
            AND table_schema = 'public'
        ");
        
        if (empty($columnCheck)) {
            $db->execQuery("CREATE EXTENSION IF NOT EXISTS vector;");
            $db->execQuery("ALTER TABLE public.worldknowledge ADD COLUMN \"vector384\" public.vector(384)");
            Logger::info("Added vector384 column to worldknowledge table");
        } else {
            Logger::info("vector384 column already exists, skipping...");
        }
    } catch (Exception $e) {
        Logger::error("Error with vector384 column: " . $e->getMessage());
        // If it's the "already exists" error, we can safely continue
        if (strpos($e->getMessage(), "already exists") !== false) {
            Logger::info("Column already exists, continuing...");
        } else {
            throw $e; // Re-throw if it's a different error
        }
    }
    
    $updateVersion("worldknowledge",20250903001);
    Logger::info("Applied patch worldknowledge 20250903001");
//}

if ($checkVersion("locations")<20250526001) {
    Logger::debug(" try patch: locations 20250526001");
    $db->execQuery("CREATE EXTENSION IF NOT EXISTS pg_trgm;");
    $updateVersion("locations",20250526001);
    Logger::info("Applied patch locations 20250526001");
}

if ($checkVersion("rolemaster")<20250528001) {
    Logger::debug(" try patch: rolemaster 20250528001");
    $db->execQuery("ALTER TABLE public.responselog ALTER COLUMN \"action\" TYPE text");
    $db->execQuery("ALTER TABLE public.responselog ALTER COLUMN \"actor\" TYPE text");
    $db->execQuery("ALTER TABLE public.responselog ALTER COLUMN \"text\" TYPE text");
    $updateVersion("rolemaster",20250528001);
    Logger::info("Applied patch rolemaster 20250528001");
}



if ($checkVersion("audit_request")<20250616001) {
    Logger::debug(" try patch: audit_request 20250616001");
    $a=$db->execQuery("ALTER TABLE public.audit_request ADD COLUMN IF NOT EXISTS \"url\"  text");
    $a=$a && $db->execQuery("ALTER TABLE public.audit_request ADD COLUMN IF NOT EXISTS \"connector\"  text");
    if ($a) {
        $updateVersion("audit_request",20250616001);
        Logger::info("Applied patch audit_request 20250616001");
    } else {
        Logger::error("Patch audit_request 20250616001 failed!");
    }
}

//----------------------------------------------------
// database maintenance tools
// - autovacuum / table
//----------------------------------------------------

if ($checkVersion("db_maintenance")<20251208001) {
    Logger::debug(" try patch: db_maintenance 20251208001");

    $db->execQuery("
    DO
    $$
        DECLARE
            table_record record;
        BEGIN
            FOR table_record IN
                SELECT quote_ident(pgn.nspname) || '.' || quote_ident(pgc.relname) AS table_name
                FROM pg_catalog.pg_class pgc
                LEFT JOIN pg_catalog.pg_namespace pgn ON pgn.oid = pgc.relnamespace
                WHERE pgc.relkind = 'r'
                  AND pgn.nspname = 'public'
            LOOP
                EXECUTE 'ALTER TABLE ' || table_record.table_name || ' SET (autovacuum_enabled = on, toast.autovacuum_enabled = on)';
            END LOOP;
        END;
    $$;
    ");

    $updateVersion("db_maintenance",20251208001);
    Logger::info("Applied patch db_maintenance 20251208001");
}

//----------------------------------------------------

if ($checkTableExists("core_api_badge") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_api_badge.sql"));
    $db->execQuery("SET search_path TO public");
} else
    Logger::info(__FILE__." core_api_badge exists");

// Add unique constraint on core_api_badge.label to prevent duplicates
if ($checkTableExists("core_api_badge") > 0 && $checkVersion("core_api_badge") < 20251127001) {
    try {
        // Remove duplicates: keep row with highest id and non-empty key per label (case-insensitive)
        $db->execQuery("
            DELETE FROM public.core_api_badge a
            WHERE a.id NOT IN (
                SELECT DISTINCT ON (LOWER(label)) id
                FROM public.core_api_badge
                ORDER BY LOWER(label), CASE WHEN api_key = '' THEN 0 ELSE 1 END DESC, id DESC
            )
        ");
        
        // Normalize label casing to match preset expectations
        $db->execQuery("UPDATE public.core_api_badge SET label = 'OpenRouter' WHERE LOWER(label) = 'openrouter'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'OpenAI' WHERE LOWER(label) = 'openai'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Deepgram' WHERE LOWER(label) = 'deepgram'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Google' WHERE LOWER(label) = 'google'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Azure' WHERE LOWER(label) = 'azure'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'ElevenLabs' WHERE LOWER(label) = 'elevenlabs'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Cartesia' WHERE LOWER(label) = 'cartesia'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Replicate' WHERE LOWER(label) = 'replicate'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Groq' WHERE LOWER(label) = 'groq'");
        $db->execQuery("UPDATE public.core_api_badge SET label = 'Nano-GPT' WHERE LOWER(label) = 'nano-gpt'");
        
        // Add unique constraint once; reinstall/update paths can revisit this patch.
        $db->execQuery("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'core_api_badge_label_unique'
                      AND conrelid = 'public.core_api_badge'::regclass
                ) THEN
                    ALTER TABLE public.core_api_badge
                    ADD CONSTRAINT core_api_badge_label_unique UNIQUE (label);
                END IF;
            END $$;
        ");
        
        // Add case-insensitive index for faster lookups
        $db->execQuery("CREATE INDEX IF NOT EXISTS idx_core_api_badge_label_lower ON public.core_api_badge (LOWER(label))");
        
        $updateVersion("core_api_badge", 20251127001);
        Logger::info("Applied core_api_badge unique constraint 20251127001 (cleaned duplicates, normalized case, added UNIQUE constraint)");
    } catch (Exception $e) {
        Logger::warn("core_api_badge unique constraint update: " . $e->getMessage());
    }
}

if ($checkTableExists("core_llm_connector") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_llm_connector.sql"));
    $db->execQuery("SET search_path TO public");
} else
    Logger::info(__FILE__." core_llm_connector exists");

// Add 'service' column to core_llm_connector if missing
$query = "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'core_llm_connector' AND column_name = 'service'
";

$existsColumn=$db->fetchAll($query);
if (!$existsColumn || !$existsColumn[0]["column_name"]) {
    $db->execQuery('ALTER TABLE "core_llm_connector" ADD COLUMN "service" text');
    Logger::info("Applied patch core_llm_connector service column");
}

if ($checkVersion("core_llm_connector") < 20260423001) {
    Logger::debug("Applying core_llm_connector 20260423001 - Seeding dedicated scene classifier connector");
    try {
        $sceneClassifierLabel = "Gemma 3N E4B";
        $sceneClassifierLabelEscaped = $db->escape($sceneClassifierLabel);
        $existingSceneClassifier = $db->fetchOne(
            "SELECT id FROM public.core_llm_connector WHERE LOWER(COALESCE(label,'')) = LOWER('{$sceneClassifierLabelEscaped}') LIMIT 1"
        );

        if (!$existingSceneClassifier || !isset($existingSceneClassifier["id"])) {
            $openRouterBadge = $db->fetchOne("SELECT id FROM public.core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1");
            $openRouterBadgeId = intval($openRouterBadge["id"] ?? 0);

            $insertPayload = [
                "label" => $sceneClassifierLabel,
                "metadata" => "{}",
                "url" => "https://openrouter.ai/api/v1/chat/completions",
                "model" => "google/gemma-3n-e4b-it",
                "provider" => "openrouter",
                "driver" => "openrouterjson",
                "max_tokens" => 128,
                "enforce_json" => 1,
                "prefill_json" => 0,
                "json_schema" => 1,
                "temperature" => 0.2,
                "service" => "openrouter"
            ];
            if ($openRouterBadgeId > 0) {
                $insertPayload["api_badge_id"] = $openRouterBadgeId;
            }

            $db->insert("core_llm_connector", $insertPayload);
            Logger::info("Inserted dedicated scene classifier connector '{$sceneClassifierLabel}'");
        } else {
            Logger::info("Dedicated scene classifier connector already exists with ID " . intval($existingSceneClassifier["id"]));
        }

        $updateVersion("core_llm_connector", 20260423001);
        Logger::info("Applied patch core_llm_connector 20260423001");
    } catch (Exception $e) {
        Logger::error("Error applying core_llm_connector 20260423001: " . $e->getMessage());
    }
}

if ($checkVersion("core_llm_connector") < 20260423002) {
    Logger::debug("Applying core_llm_connector 20260423002 - Migrating scene classifier default to Gemma 3N E4B");
    try {
        $sceneClassifierLabel = "Gemma 3N E4B";
        $sceneClassifierLabelEscaped = $db->escape($sceneClassifierLabel);

        $sceneClassifierRow = $db->fetchOne(
            "SELECT id FROM public.core_llm_connector
             WHERE LOWER(COALESCE(label,'')) = LOWER('{$sceneClassifierLabelEscaped}')
             ORDER BY id ASC
             LIMIT 1"
        );

        $openRouterBadge = $db->fetchOne("SELECT id FROM public.core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1");
        $openRouterBadgeId = intval($openRouterBadge["id"] ?? 0);

        $sceneClassifierPayload = [
            "label" => $sceneClassifierLabel,
            "metadata" => "{}",
            "url" => "https://openrouter.ai/api/v1/chat/completions",
            "model" => "google/gemma-3n-e4b-it",
            "provider" => "openrouter",
            "driver" => "openrouterjson",
            "max_tokens" => 128,
            "enforce_json" => 1,
            "prefill_json" => 0,
            "json_schema" => 1,
            "temperature" => 0.2,
            "service" => "openrouter"
        ];
        if ($openRouterBadgeId > 0) {
            $sceneClassifierPayload["api_badge_id"] = $openRouterBadgeId;
        }

        if ($sceneClassifierRow && isset($sceneClassifierRow["id"])) {
            $db->updateRow("core_llm_connector", $sceneClassifierPayload, "id=" . intval($sceneClassifierRow["id"]));
            Logger::info("Updated dedicated scene classifier connector ID " . intval($sceneClassifierRow["id"]) . " to Gemma 3N E4B");
        } else {
            $db->insert("core_llm_connector", $sceneClassifierPayload);
            Logger::info("Inserted dedicated scene classifier connector '{$sceneClassifierLabel}'");
        }

        $updateVersion("core_llm_connector", 20260423002);
        Logger::info("Applied patch core_llm_connector 20260423002");
    } catch (Exception $e) {
        Logger::error("Error applying core_llm_connector 20260423002: " . $e->getMessage());
    }
}

if ($checkVersion("core_llm_connector") < 20260423003) {
    Logger::debug("Applying core_llm_connector 20260423003 - Shortening scene classifier connector label");
    try {
        $sceneClassifierLabel = "Gemma 3N E4B";

        $conditions = [];
        $conditions[] = "LOWER(COALESCE(label,'')) = LOWER('" . $db->escape($sceneClassifierLabel) . "')";

        $sceneClassifierRow = $db->fetchOne(
            "SELECT id FROM public.core_llm_connector WHERE " . implode(" OR ", $conditions) . " ORDER BY id ASC LIMIT 1"
        );

        $openRouterBadge = $db->fetchOne("SELECT id FROM public.core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1");
        $openRouterBadgeId = intval($openRouterBadge["id"] ?? 0);

        $sceneClassifierPayload = [
            "label" => $sceneClassifierLabel,
            "metadata" => "{}",
            "url" => "https://openrouter.ai/api/v1/chat/completions",
            "model" => "google/gemma-3n-e4b-it",
            "provider" => "openrouter",
            "driver" => "openrouterjson",
            "max_tokens" => 128,
            "enforce_json" => 1,
            "prefill_json" => 0,
            "json_schema" => 1,
            "temperature" => 0.2,
            "service" => "openrouter"
        ];
        if ($openRouterBadgeId > 0) {
            $sceneClassifierPayload["api_badge_id"] = $openRouterBadgeId;
        }

        if ($sceneClassifierRow && isset($sceneClassifierRow["id"])) {
            $db->updateRow("core_llm_connector", $sceneClassifierPayload, "id=" . intval($sceneClassifierRow["id"]));
            Logger::info("Renamed scene classifier connector ID " . intval($sceneClassifierRow["id"]) . " to '{$sceneClassifierLabel}'");
        } else {
            $db->insert("core_llm_connector", $sceneClassifierPayload);
            Logger::info("Inserted dedicated scene classifier connector '{$sceneClassifierLabel}'");
        }

        $updateVersion("core_llm_connector", 20260423003);
        Logger::info("Applied patch core_llm_connector 20260423003");
    } catch (Exception $e) {
        Logger::error("Error applying core_llm_connector 20260423003: " . $e->getMessage());
    }
}

if ($checkTableExists("core_npc_master_history") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_npc_master_history.sql"));
} else
    Logger::info(__FILE__." core_npc_master_history exists");

$db->execQuery(
    "CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_restore
     ON public.core_npc_master_history (
         npc_id,
         gamets_last_updated DESC NULLS LAST,
         created DESC,
         history_id DESC
     )"
);

if ($checkTableExists("core_stt_connector") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_stt_connector.sql"));
} else
    Logger::info(__FILE__." core_stt_connector exists");


if ($checkTableExists("core_tts_connector") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_tts_connector.sql"));
    $db->execQuery("SET search_path TO public");
} else
    Logger::info(__FILE__." core_tts_connector exists");

if ($checkTableExists("core_llm_connector") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_llm_connector.sql"));
} else
    Logger::info(__FILE__." core_llm_connector exists");

if ($checkTableExists("core_profiles") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_profiles.sql"));
    $db->execQuery("SET search_path TO public");
} else
    Logger::info(__FILE__." core_profiles exists");

if ($checkTableExists("core_npc_master") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_npc_master.sql"));
    $db->execQuery("SET search_path TO public");
} else
    Logger::info(__FILE__." core_npc_master exists");


if (($checkTableExists("core_profiles") > 0) && ($checkVersion("core_profiles") < 20250904005)) {
    // ensure slot column exists for existing installs
    $db->execQuery('ALTER TABLE public.core_profiles ADD COLUMN IF NOT EXISTS "slot" integer');
    // set default profile slot to 1 if missing
    $db->execQuery("UPDATE public.core_profiles SET slot = 1 WHERE id = 1 AND (slot IS NULL OR slot = 0)");
    $updateVersion("core_profiles",20250904005);
    Logger::info("Applied core_profiles 20250904005 (added slot, set default slot=1)");
} else {
    Logger::info(__FILE__." core_profiles up-to-date");
}

// Ensure core_profiles.slot exists even if version was previously bumped
try {
    $colCheck = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='core_profiles' AND column_name='slot'");
    if (!$colCheck || !isset($colCheck[0]["column_name"])) {
        Logger::warn("core_profiles.slot missing; adding column now");
        $db->execQuery('ALTER TABLE public.core_profiles ADD COLUMN "slot" integer');
        $db->execQuery("UPDATE public.core_profiles SET slot = 1 WHERE id = 1 AND (slot IS NULL OR slot = 0)");
        if ($checkVersion("core_profiles") < 20250904006) {
            $updateVersion("core_profiles",20250904006);
        }
        Logger::info("Added core_profiles.slot and set default profile slot=1");
    } else {
        // Column exists; still ensure default profile set to 1
        $db->execQuery("UPDATE public.core_profiles SET slot = 1 WHERE id = 1 AND (slot IS NULL OR slot = 0)");
    }
} catch (Exception $e) {
    Logger::error("Error ensuring core_profiles.slot: ".$e->getMessage());
}

// Enforce uniqueness of core_profiles.slot (1-4), allowing NULLs
try {
    $idx = $db->fetchAll("SELECT indexname FROM pg_indexes WHERE schemaname='public' AND tablename='core_profiles' AND indexname='core_profiles_slot_unique_idx'");
    if (!$idx || !isset($idx[0]["indexname"])) {
        // Clear duplicates: keep the lowest id per slot, set others to NULL
        $db->execQuery("WITH d AS (
            SELECT id, slot, ROW_NUMBER() OVER (PARTITION BY slot ORDER BY id) AS rn
            FROM public.core_profiles WHERE slot IS NOT NULL
        )
        UPDATE public.core_profiles p SET slot = NULL
        FROM d WHERE p.id = d.id AND d.rn > 1");
        // Create unique partial index
    $db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS core_profiles_slot_unique_idx ON public.core_profiles (slot) WHERE slot IS NOT NULL");
    $db->execQuery("SET search_path TO public");
        // Ensure default profile has slot 1
        $db->execQuery("UPDATE public.core_profiles SET slot = 1 WHERE id = 1 AND (slot IS NULL OR slot = 0)");
        if ($checkVersion("core_profiles") < 20250904007) {
            $updateVersion("core_profiles",20250904007);
        }
        Logger::info("Enforced unique slot on core_profiles and set default slot=1");
    }
} catch (Exception $e) {
    Logger::error("Error enforcing unique slot index: ".$e->getMessage());
}

// Add llm_fallback_id column to core_profiles for fallback connector support
if ($checkVersion("core_profiles") < 20251203001) {
    Logger::debug("Applying core_profiles 20251203001 - Adding llm_fallback_id for fallback support");
    try {
        // Add the column if it doesn't exist
        $db->execQuery('ALTER TABLE public.core_profiles ADD COLUMN IF NOT EXISTS llm_fallback_id integer');
        
        // Add foreign key constraint if it doesn't exist
        $fkExists = $db->fetchAll("
            SELECT 1 FROM pg_constraint 
            WHERE conname = 'profiles_llm_fallback_id_fkey'
        ");
        
        if (!$fkExists || !isset($fkExists[0])) {
            $db->execQuery("
                ALTER TABLE public.core_profiles
                ADD CONSTRAINT profiles_llm_fallback_id_fkey 
                FOREIGN KEY (llm_fallback_id) REFERENCES public.core_llm_connector(id)
            ");
            Logger::info("Added foreign key constraint profiles_llm_fallback_id_fkey");
        }
        
        // Add comment
        $db->execQuery("
            COMMENT ON COLUMN public.core_profiles.llm_fallback_id 
            IS 'Fallback LLM connector used when primary connector fails with network error'
        ");
        
        $updateVersion("core_profiles", 20251203001);
        Logger::info("Applied patch core_profiles 20251203001 - Added llm_fallback_id for automatic fallback on network errors");
    } catch (Exception $e) {
        Logger::error("Error adding llm_fallback_id to core_profiles: " . $e->getMessage());
    }
}

if ($checkVersion("core_profiles") < 20260626001) {
    Logger::debug("Applying core_profiles 20260626001 - prune copied global settings from profile metadata");
    try {
        $profileDefaults = [
            'RECHAT_H' => '4',
            'RECHAT_P' => '100',
            'RECHAT_ALLOW_ACTIONS' => false,
            'DYNAMIC_PROFILE_FIELDS' => ['personality', 'speechstyle', 'goals'],
            'RPG_COMMENTS' => ['levelup', 'combat_end', 'lockpick', 'sleep'],
            'RPG_COMMENTS_CHANCE' => 20,
            'COMBAT_BARK_COOLDOWN' => 30,
            'AUTO_DIARY_ENABLED' => false,
            'AUTO_DIARY_WAIT_ENABLED' => true,
            'SALUTATION_AFTER_A_WHILE' => false,
        ];

        $copiedGlobalKeys = [
            'MINIME_T5',
            'BORED_EVENT',
            'DIARY_PROMPT',
            'WORLDKNOWLEDGE_AMOUNT',
            'ALIVE_MESSAGE',
            'LANG_LLM_XTTS',
            'QUEST_COMMENT',
            'DIARY_COOLDOWN',
            'WORLDKNOWLEDGE_INFINIUM',
            'AUTO_DIARY_WAIT',
            'CONTEXT_HISTORY',
            'MAX_WORDS_LIMIT',
            'DIALECTIC_ANIMATIONS',
            'QUEST_COMMENT_CHANCE',
            'CONTEXT_HISTORY_DIARY',
            'BORED_EVENT_SERVERSIDE',
            'ENFORCE_ACTIONS_PROMPT',
            'CONTEXT_HISTORY_DYNAMIC_PROFILE',
        ];

        $rows = $db->fetchAll("SELECT id, metadata FROM public.core_profiles ORDER BY id ASC");
        foreach ($rows as $row) {
            $profileId = intval($row['id'] ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            $metadataRaw = $row['metadata'] ?? '{}';
            $metadata = is_array($metadataRaw) ? $metadataRaw : json_decode(strval($metadataRaw), true);
            if (!is_array($metadata)) {
                $metadata = [];
            }

            if (!array_key_exists('AUTO_DIARY_WAIT_ENABLED', $metadata) && array_key_exists('AUTO_DIARY_WAIT', $metadata)) {
                $metadata['AUTO_DIARY_WAIT_ENABLED'] = $metadata['AUTO_DIARY_WAIT'];
            }

            foreach ($copiedGlobalKeys as $key) {
                unset($metadata[$key]);
            }

            foreach ($profileDefaults as $key => $value) {
                if (!array_key_exists($key, $metadata)) {
                    $metadata[$key] = $value;
                }
            }

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($metadataJson) || $metadataJson === '') {
                $metadataJson = '{}';
            }

            $metadataLiteral = $db->escapeLiteral($metadataJson);
            $db->execQuery("
                UPDATE public.core_profiles
                SET metadata = {$metadataLiteral}::jsonb
                WHERE id = {$profileId}
            ");
        }

        $updateVersion("core_profiles", 20260626001);
        Logger::info("Applied patch core_profiles 20260626001 - pruned copied global profile metadata");
    } catch (Throwable $e) {
        Logger::error("Error pruning copied global profile metadata: " . $e->getMessage());
    }
}

if ($checkVersion("core_profiles") < 20260629002) {
    Logger::debug("Applying core_profiles 20260629002 - remove deprecated profile-level CURRENT_TASK");
    try {
        $db->execQuery("UPDATE public.core_profiles SET metadata = metadata - 'CURRENT_TASK' WHERE metadata ? 'CURRENT_TASK'");

        $updateVersion("core_profiles", 20260629002);
        Logger::info("Applied patch core_profiles 20260629002 - removed deprecated profile-level CURRENT_TASK");
    } catch (Throwable $e) {
        Logger::error("Error removing deprecated profile-level CURRENT_TASK: " . $e->getMessage());
    }
}

// Final repair pass: ensure critical core tables exist even if versions were bumped earlier
try {
    $coreTables = [
        ["name"=>"core_api_badge",   "file"=>__DIR__."/../lib/core/database_schema/core_api_badge.sql"],
        ["name"=>"core_llm_connector","file"=>__DIR__."/../lib/core/database_schema/core_llm_connector.sql"],
        ["name"=>"core_tts_connector","file"=>__DIR__."/../lib/core/database_schema/core_tts_connector.sql"],
        ["name"=>"core_stt_connector","file"=>__DIR__."/../lib/core/database_schema/core_stt_connector.sql"],
        ["name"=>"core_itt_connector","file"=>__DIR__."/../lib/core/database_schema/core_itt_connector.sql"],
        ["name"=>"core_profiles",     "file"=>__DIR__."/../lib/core/database_schema/core_profiles.sql"],
        ["name"=>"core_npc_master",   "file"=>__DIR__."/../lib/core/database_schema/core_npc_master.sql"]
    ];
    foreach ($coreTables as $t) {
        if ($checkTableExists($t["name"]) == -1) {
            Logger::warn("Repair: creating missing table ".$t["name"]);
            $db->execQuery(file_get_contents($t["file"]));
        }
    }
} catch (Exception $e) {
    Logger::error("Final repair pass failed: ".$e->getMessage());
}

// Current Fallout biography template schema and import
//----------------------------------------------------
try {
    $db->execQuery("
        CREATE TABLE IF NOT EXISTS public.bio_templates (
            npc_name character varying(128) NOT NULL PRIMARY KEY,
            worldknowledge_tags text,
            core text,
            npc_static_bio text,
            appearance text,
            personality text,
            relationships text,
            occupation text,
            skills text,
            speechstyle text,
            goals text,
            voiceid text,
            gender text,
            race text,
            refid text
        );
    ");

    $db->execQuery("
        CREATE TABLE IF NOT EXISTS public.bio_templates_custom (
            npc_name character varying(128) NOT NULL PRIMARY KEY,
            worldknowledge_tags text,
            core text,
            npc_static_bio text,
            appearance text,
            personality text,
            relationships text,
            occupation text,
            skills text,
            speechstyle text,
            goals text,
            voiceid text,
            gender text,
            race text,
            refid text
        );
    ");

    $db->execQuery("DROP VIEW IF EXISTS public.combined_bio_templates CASCADE;");
    $db->execQuery("
        CREATE VIEW public.combined_bio_templates AS
        SELECT c.npc_name,
               c.worldknowledge_tags,
               c.core,
               c.npc_static_bio,
               c.appearance,
               c.personality,
               c.relationships,
               c.occupation,
               c.skills,
               c.speechstyle,
               c.goals,
               c.voiceid,
               c.gender,
               c.race,
               c.refid
          FROM public.bio_templates_custom c
        UNION ALL
        SELECT b.npc_name,
               b.worldknowledge_tags,
               b.core,
               b.npc_static_bio,
               b.appearance,
               b.personality,
               b.relationships,
               b.occupation,
               b.skills,
               b.speechstyle,
               b.goals,
               b.voiceid,
               b.gender,
               b.race,
               b.refid
          FROM (public.bio_templates b
                LEFT JOIN public.bio_templates_custom c
                  ON ((b.npc_name)::text = (c.npc_name)::text))
         WHERE c.npc_name IS NULL;
    ");
    Logger::info("Prepared current Fallout biography template schema and combined view.");
} catch (Exception $e) {
    Logger::error("Error preparing current Fallout biography template schema: " . $e->getMessage());
}

if ($checkVersion("fallout_bio_templates_seed") < 20260615007) {
    Logger::debug("Applying fallout_bio_templates_seed 20260615007");
    try {
        $sqlFile = __DIR__ . "/../data/fallout_bio_templates.sql";
        if (file_exists($sqlFile)) {
            $sqlContent = file_get_contents($sqlFile);
            if ($sqlContent !== false && strlen($sqlContent) > 0) {
                $sqlContent = preg_replace('/^\xEF\xBB\xBF/', '', $sqlContent);
                $seedResult = $db->execQuery($sqlContent);
                if ($seedResult) {
                    $updateVersion("fallout_bio_templates_seed", 20260615007);
                    Logger::info("Applied current Fallout bio template seed 20260615007");
                } else {
                    Logger::error("Failed to apply fallout_bio_templates_seed 20260615007 - SQL did not execute cleanly.");
                }
            } else {
                Logger::warn("Fallout bio template seed file is empty: " . $sqlFile);
            }
        } else {
            Logger::warn("Fallout bio template seed file not found: " . $sqlFile);
        }
    } catch (Exception $e) {
        Logger::error("Error applying current Fallout bio templates: " . $e->getMessage());
    }
}

// Remove DB-layer protection for The Narrator to allow deletion via UI
// Version 20250124001
if ($checkVersion("narrator_protection")<20250124001) {
    Logger::debug("Removing narrator delete/rename protection triggers");
    try {
        $db->execQuery("DROP TRIGGER IF EXISTS trg_protect_narrator_delete ON public.core_npc_master");
        $db->execQuery("DROP FUNCTION IF EXISTS public.protect_narrator_delete() CASCADE");
        $db->execQuery("DROP TRIGGER IF EXISTS trg_protect_narrator_rename ON public.core_npc_master");
        $db->execQuery("DROP FUNCTION IF EXISTS public.protect_narrator_rename() CASCADE");
        $updateVersion("narrator_protection",20250124001);
        Logger::info("Removed narrator protection triggers");
    } catch (Exception $e) {
        Logger::error("Error removing narrator protection triggers: " . $e->getMessage());
    }
}

//----------------------------------------------------
// Item descriptions: current tables and combined view
//----------------------------------------------------

try {
    foreach (['descriptions', 'descriptions_custom'] as $tableName) {
        $db->execQuery("
            CREATE TABLE IF NOT EXISTS public.{$tableName} (
                plugin text NOT NULL DEFAULT '',
                baseid character varying(128) NOT NULL,
                name text,
                description text
            );
        ");
        $db->execQuery("ALTER TABLE public.{$tableName} ADD COLUMN IF NOT EXISTS plugin text NOT NULL DEFAULT ''");
        $db->execQuery("ALTER TABLE public.{$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_pkey");
        $db->execQuery("
            UPDATE public.{$tableName}
               SET plugin = split_part(baseid, '|', 1),
                   baseid = split_part(baseid, '|', 2)
             WHERE plugin = ''
               AND position('|' in baseid) > 0
        ");
        $db->execQuery("
            DELETE FROM public.{$tableName} a
             USING public.{$tableName} b
             WHERE a.ctid < b.ctid
               AND a.plugin = b.plugin
               AND a.baseid = b.baseid
        ");
        $db->execQuery("ALTER TABLE public.{$tableName} ADD PRIMARY KEY (plugin, baseid)");
    }

    $db->execQuery("DROP VIEW IF EXISTS public.combined_descriptions CASCADE;");
    $db->execQuery("
        CREATE VIEW public.combined_descriptions AS
        SELECT c.plugin,
               c.baseid,
               c.name,
               c.description
          FROM public.descriptions_custom c
        UNION ALL
        SELECT i.plugin,
               i.baseid,
               i.name,
               i.description
          FROM (public.descriptions i
                LEFT JOIN public.descriptions_custom c
                  ON ((i.plugin)::text = (c.plugin)::text
                 AND (i.baseid)::text = (c.baseid)::text))
         WHERE c.baseid IS NULL;
    ");
    $updateVersion("combined_descriptions", 20260615001);
    Logger::info("Prepared current description schema and combined view 20260615001");
} catch (Exception $e) {
    Logger::error("Error preparing description schema: " . $e->getMessage());
}

if ($checkVersion("descriptions_defaults") < 20260626004) {
    Logger::debug("Applying descriptions_defaults 20260626004 - refresh Fallout item descriptions");

    try {
        $sqlFile = __DIR__ . "/../data/fallout_item_descriptions.sql";
        if (file_exists($sqlFile)) {
            $sqlContent = file_get_contents($sqlFile);
            if ($sqlContent !== false && strlen($sqlContent) > 0) {
                $sqlContent = preg_replace('/^\xEF\xBB\xBF/', '', $sqlContent);
                $db->execQuery("TRUNCATE TABLE public.descriptions");
                $db->execQuery("TRUNCATE TABLE public.descriptions_custom");
                if ($db->execQuery($sqlContent)) {
                    $updateVersion("descriptions_defaults", 20260626004);
                    Logger::info("Refreshed Fallout item descriptions defaults 20260626004");
                } else {
                    Logger::error("Failed to refresh Fallout item descriptions defaults 20260626004");
                }
            } else {
                Logger::warn("Fallout item descriptions seed file is empty: " . $sqlFile);
            }
        } else {
            Logger::warn("Fallout item descriptions seed file not found: " . $sqlFile);
        }
    } catch (Exception $e) {
        Logger::error("Error refreshing Fallout item descriptions defaults 20260626004: " . $e->getMessage());
    }
}

$memoryViewNeedsRefresh = $checkVersion("memory_v_dialectic_events") < 20260713001;
if ($memoryViewNeedsRefresh) {
    try {
        if ($refreshDialecticMemoryView()) {
            $updateVersion("memory_v", 20260713001);
            $updateVersion("memory_v_dialectic_events", 20260713001);
            Logger::info("Updated memory_v for participant attribution and world-context transition filtering 20260713001");
        } else {
            Logger::error("Failed to update memory_v participant attribution patch 20260713001");
        }
    } catch (Exception $e) {
        Logger::error("Error creating memory_v participant attribution patch: " . $e->getMessage());
    }
}


if ($checkTableExists("import_rules") == -1) {
    $db->execQuery(file_get_contents(__DIR__."/../data/import_rules.sql"));
} else
    Logger::info(__FILE__." import_rules exists");

if ($checkVersion("import_rules") < 20260730001) {
    try {
        if ($db->execQuery("ALTER TABLE public.import_rules ADD COLUMN IF NOT EXISTS match_faction text") === false) {
            throw new RuntimeException("Could not add import_rules.match_faction");
        }
        $updateVersion("import_rules", 20260730001);
        Logger::info("Applied patch import_rules 20260730001 - add faction matching");
    } catch (Throwable $e) {
        Logger::error("Failed to apply patch import_rules 20260730001: " . $e->getMessage());
    }
}

// Usage column
$db->execQuery("ALTER TABLE public.audit_request ADD COLUMN IF NOT EXISTS usage jsonb");

// Some imported dump-style SQL files clear search_path; restore it before
// running unqualified late-stage migrations.
$db->execQuery("SET search_path TO public");

$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS worldspace text");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS tags text");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS is_interior int");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS vanilla_location boolean");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS coords POINT ");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS refs text");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS cleared boolean");
$db->execQuery("ALTER TABLE public.locations ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP");
$db->execQuery("DROP VIEW IF EXISTS public.locations_v");

$db->execQuery("ALTER TABLE public.factions ADD COLUMN IF NOT EXISTS vendor_cont TEXT");
$db->execQuery("ALTER TABLE public.factions ADD COLUMN IF NOT EXISTS stock JSONB");
$db->execQuery("ALTER TABLE public.factions ADD COLUMN IF NOT EXISTS caps numeric");
$db->execQuery("ALTER TABLE public.factions ADD COLUMN IF NOT EXISTS player_rank numeric");

$db->execQuery("CREATE INDEX IF NOT EXISTS event_log_type ON public.eventlog USING btree (type)");
$db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_people_trgm
ON public.eventlog
USING gin (people gin_trgm_ops)");

$db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_people_trgm2
ON public.eventlog
USING gin (data gin_trgm_ops)");

$db->execQuery("CREATE INDEX IF NOT EXISTS idx_speech_speaker_trgm
ON public.speech
USING gin (speaker gin_trgm_ops)");

$db->execQuery("CREATE INDEX IF NOT EXISTS idx_speech_listener_trgm
ON public.speech
USING gin (listener gin_trgm_ops)");

$db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_gamets_pos
ON public.eventlog (gamets)
WHERE gamets > 0");

$db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_gamets_ts_pos
ON public.eventlog (gamets DESC, ts DESC)");

$db->execQuery("CREATE INDEX IF NOT EXISTS   idx_speech_gamets_pos
ON public.speech (gamets)
WHERE gamets > 0");



//----------------------------------------------------
// Prompts Table - System for managing default and custom prompts
// Version 20251110001
//----------------------------------------------------

try {
    if ($checkTableExists("prompts") == 1) {
        $db->execQuery("DELETE FROM public.prompts WHERE prompt_key IS NULL OR btrim(prompt_key) = ''");
        $db->execQuery("
            DELETE FROM public.prompts a
            USING public.prompts b
            WHERE a.prompt_key = b.prompt_key
              AND a.ctid < b.ctid
        ");
        $db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS idx_prompts_prompt_key_unique ON public.prompts (prompt_key)");

        $promptCountRow = $db->fetchOne("SELECT COUNT(*) AS count FROM public.prompts");
        $promptCount = intval($promptCountRow["count"] ?? 0);
        if ($promptCount === 0 && $checkVersion("prompts") >= 20251110001) {
            Logger::warn("Prompts table is empty but migration version is marked as applied. Clearing prompts version entry so seed migrations can rerun.");
            $db->execQuery("DELETE FROM public.database_versioning WHERE tablename='prompts'");
        }
    }
} catch (Throwable $e) {
    Logger::warn("Prompts migration self-heal check failed: " . $e->getMessage());
}

if ($checkVersion("prompts")<20251110001) {
    Logger::debug("Applying prompts table 20251110001");
    
    // Create prompts table
    $db->execQuery("
        CREATE TABLE IF NOT EXISTS public.prompts (
            prompt_key character varying(128) NOT NULL PRIMARY KEY,
            default_prompt text NOT NULL,
            custom_prompt text,
            description text,
            created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Seed initial middleterm narrative summarizer system prompt
    $middletermPrompt = $db->escape(
        "You are a long-term narrative continuity summarizer for an improvised Fallout universe chronicle.\n".
        "- Always read ALL provided materials.\n".
        "- Treat any **Previous Context History Summary** as the canonical prior unless anything in the new Context History explicitly supersedes it.\n".
        "- Maintain in-universe tone and correct chronology. Do not invent facts outside the supplied context.\n".
        "- When combining prior and new histories, you may compress the earlier parts of the prior summary.\n".
        "- Maintain roughly 20-25 bullet points total in **Notable Events**. Older portions should be condensed into broader, grouped statements unless they describe major quest milestones, major character life events (e.g., death, intimacy, severe injury, transformation), or other pivotal story turns.\n".
        "- Preserve continuity and references to major quests even when compressing earlier material."
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'middleterm_narrative_summarizer',
            '$middletermPrompt',
            'System prompt for long-term narrative continuity summarization in middleterm memory processing. Used in: service/processors/middleterm/cmd/generate.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // Seed middleterm request/task prompt (uses {DIALECTIC_NAME} placeholder)
    $middletermRequestPrompt = $db->escape(
        "Main character in this logbook is {DIALECTIC_NAME}.\n".
        "Task: Read **Context History** (newest session) and, if present, the **Previous Context History Summary** (prior canon). ".
        "Integrate them to produce an updated broad narrative strokes summary that preserves continuity. Summary sections:\n\n".
        "- **Notable Events in Chronological Order:**\n".
        "  - Provide ~10 bullet points from earliest to latest, reflecting the story so far.\n".
        "  - Prefer facts already established in the previous summary; only revise if the new context clearly changes them.\n\n".
        "- **Current Quest Progression and background:**\n".
        "  - Name questlines, stages/milestones if stated, objectives completed/active, and motivations.\n".
        "When generating entries, ensure that {DIALECTIC_NAME} - the protagonist - is actively present in the scene. ".
        "Any narrative content that occurs before {DIALECTIC_NAME}'s arrival or outside {DIALECTIC_NAME}'s perspective should be omitted, ".
        "reflect only events {DIALECTIC_NAME} directly witness or participate in.\n".
        "If the resulting summary would exceed roughly 25 bullet points, merge or generalise older entries into broader grouped events. ".
        "Always retain explicit entries for major quest milestones, major character life events, or turning points."
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'middleterm_narrative_request',
            '$middletermRequestPrompt',
            'User request/task instructions for middleterm narrative summarization (contains {DIALECTIC_NAME} placeholder). Used in: service/processors/middleterm/cmd/generate.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // Seed character profile generation prompt (uses {DIALECTIC_NAME} and {CHARACTER_SEED} placeholders)
    $profileGenPrompt = $db->escape(
        "The main character in this logbook is {DIALECTIC_NAME}.{CHARACTER_SEED}\n".
        "Read the context history (context_history) and the recent memories (middle_term_memory),\n".
        " paying attention to notable events and the names of relevant characters.\n\n\n".
        "Based on all this information, generate an character sheet for {DIALECTIC_NAME}.\n\n".
        "This profile must be in XML format and have these fields.\n\n".
        "<core>              Text. Core Identity, name, race and gender, and most remarkable job. Should be in the form of a sentence. e.g. 'Rose. NCR female ranger.'\n".
        "<npc_static_bio>    Text. Basic Summary, and bio. Create if not info available in <context_history>\n".
        "<personality>       Text. Personality Traits. How the characters behave. Traumas. Likes.\n".
        "<appearance>        Text. Physical Appearance. Infer from info available in <context_history>\n".
        "<relationships>     Text. relationships with other actors.\n".
        "<occupation>        Text. Main Occupation & Role\n".
        "<skills>            Text. Skills & Abilities\n".
        "<speech_style>      Text. Speech Style\n".
        "<goals>             Text. Long term Goals & Aspirations'\n"
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'character_profile_generation',
            '$profileGenPrompt',
            'Prompt for AI-generated character profile/biography creation (contains {DIALECTIC_NAME} and {CHARACTER_SEED} placeholders). Used in: ui/cmd/action_ai_regen_profile.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // Seed AI vision appearance description prompt (uses {DIALECTIC_NAME} placeholder)
    $visionAppearancePrompt = $db->escape(
        "Describe the character in the picture. Name is {DIALECTIC_NAME} .\n".
        "Do not focus on clothing, focus on physical appearance (face, eyes, hair, figure, waist,legs,breast size, tattoos if any....). Be concise. \n".
        "Start generation with this text:\n".
        "{DIALECTIC_NAME} is "
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'ai_vision_appearance',
            '$visionAppearancePrompt',
            'AI vision prompt for describing character physical appearance from images (contains {DIALECTIC_NAME} placeholder). Used in: ui/cmd/action_ai_update_appearance.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // Seed memory subsystem summary prompt (uses {PLAYER_NAME}, {COMPANIONS_LINE}, {SUMMARY_PROMPT} placeholders)
    $memorySubsystemPrompt = $db->escape(
        "{PLAYER_NAME} is the player.\n".
        "{COMPANIONS_LINE}\n".
        "You must write a memory summary from the narrator's point of view by analyzing the chat history. Focus only on roleplay elements: character behavior, feelings, relationships, decisions, dialogue, and locations relevant to the story. Ignore any references to game engine mechanics, menus, stats, or system messages.\n".
        "Pay close attention to details that could influence a character's behavior or emotions, as well as tag names and locations. Include quotes from character dialogue in the summary if they are relevant to understanding actions, motivations, or relationships\n\n".
        "Here are additional instructions: {SUMMARY_PROMPT}"
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'memory_subsystem_summary',
            '$memorySubsystemPrompt',
            'Prompt for generating memory summaries from chat history (contains {PLAYER_NAME}, {COMPANIONS_LINE}, {SUMMARY_PROMPT} placeholders). Used in: debug/util_memory_subsystem.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // Seed global dynamic prompts (migrated from conf schema)
    
    // SUMMARY_PROMPT - Memory summary instructions  
    $summaryPrompt = $db->escape("Focus on key events, tagging characters, locations, and factions accurately. Ensure memories align and maintain chronological order while foreshadowing future arcs. Prioritize player agency, and use environmental cues to enhance storytelling and continuity.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('summary_prompt', '$summaryPrompt', 'Additional instructions for memory summary generation. Used as {SUMMARY_PROMPT} placeholder in: debug/util_memory_subsystem.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DYNAMIC_PROMPT_PERSONALITY - Uses {DIALECTIC_NAME} placeholder
    $dynPersonality = $db->escape("Based on the dialogue history and recent events, update {DIALECTIC_NAME} personality traits. Maintain all existing relevant personality traits and add new ones based on recent experiences. Focus on behavioral changes, emotional growth/regression, new traits that emerged, and changes in confidence or outlook. Emphasize any past traumas or new traumas caused by the death of companions, allies, or other known characters, and how these events shape the character's behavior and mindset. Return ONLY the updated personality description in 3-5 sentences. Do not include any introductory text, meta-commentary, or phrases like 'Here is the updated personality' or 'The character's personality is'. Start directly with the personality content.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('dynamic_prompt_personality', '$dynPersonality', 'Instructions for updating NPC personality traits based on recent events (contains {DIALECTIC_NAME} placeholder). Used in: lib/dynamic_update_util.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DYNAMIC_PROMPT_OCCUPATION - Uses {DIALECTIC_NAME} placeholder
    $dynOccupation = $db->escape("Based on story progression and events, update {DIALECTIC_NAME} occupation and role. Maintain the current occupation unless significant changes have occurred. Add new responsibilities, changes in social status, and professional affiliations. Focus on job changes, new duties, and evolving professional relationships. Return ONLY the updated occupation description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like 'The character's occupation is' or 'Here is the updated occupation'. Start directly with the occupation content.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('dynamic_prompt_occupation', '$dynOccupation', 'Instructions for updating NPC occupation and role based on story progression (contains {DIALECTIC_NAME} placeholder). Used in: lib/dynamic_update_util.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DYNAMIC_PROMPT_SKILLS - Uses {DIALECTIC_NAME} placeholder
    $dynSkills = $db->escape("Based on experiences and training, update {DIALECTIC_NAME} skills and abilities. Maintain all existing relevant skills and add new ones based on recent experiences. Focus on new skills learned, existing skills improved, any skills that deteriorated, and combat, technical, survival, or social knowledge gained. Return ONLY a bulleted list using * Skill - Description format. Do not include any introductory text, meta-commentary, or phrases like 'Here are the updated skills' or 'The character's skills include'. Start directly with the first bullet point.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('dynamic_prompt_skills', '$dynSkills', 'Instructions for updating NPC skills and abilities based on experiences (contains {DIALECTIC_NAME} placeholder). Used in: lib/dynamic_update_util.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DYNAMIC_PROMPT_SPEECHSTYLE - Uses {DIALECTIC_NAME} placeholder
    $dynSpeech = $db->escape("Based on recent interactions, update how {DIALECTIC_NAME} speaks and communicates. Maintain existing consistent speech patterns and add new ones based on recent interactions. Focus on changes in vocabulary, new mannerisms, accent changes, and confidence level in speech. Return ONLY the updated speech style description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like 'The character speaks' or 'Here is the updated speech style'. Start directly with the speech style content.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('dynamic_prompt_speechstyle', '$dynSpeech', 'Instructions for updating NPC speech patterns and communication style (contains {DIALECTIC_NAME} placeholder). Used in: lib/dynamic_update_util.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DYNAMIC_PROMPT_GOALS - Uses {DIALECTIC_NAME} placeholder
    $dynGoals = $db->escape("Based on story developments and achievements, update the {DIALECTIC_NAME} goals and aspirations. Maintain existing relevant goals, compressing related goals, and add new ones. Remove goals that have been clearly completed or are no longer applicable. Focus on new aspirations that emerged, modified existing goals due to circumstances, and updated long-term objectives. Return ONLY a bulleted list using * Goal description as actionable aspiration format. Do not include any introductory text, meta-commentary, or phrases like 'Here are the updated goals' or 'The character's goals are'. Start directly with the first bullet point (maintain a maximum of 20 goals with reduction priority when required: 1- compress related goals, 2-eliminate 'study' related goals, 3- eliminate older goals).");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('dynamic_prompt_goals', '$dynGoals', 'Instructions for updating NPC goals and aspirations based on story developments (contains {DIALECTIC_NAME} placeholder). Used in: lib/dynamic_update_util.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DIRECTOR_SYSTEM_PROMPT - Game director system prompt
    $directorSystem = $db->escape("You are a game director, and we are roleplaying Fallout in the Mojave Wasteland. You must create an instruction for an actor to generate new content or events in-game.");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('director_system_prompt', '$directorSystem', 'Main system prompt defining the game director role. Used in: service/processors/rolemaster/cmd/instruction.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DIRECTOR_EXAMPLES_PROMPT - Examples of instruction formats
    $directorExamples = $db->escape("# Examples\n\nuser request: actor \"a\" leaves the place \n{\"instructions\":[{\n  \"character\": \"actor a\",\n  \"instruction\": \"actor a should say goodbye to everyone, hinting that they may not return for a long time\",\n  \"action\": \"ExitLocation\",\n  \"target\": \"everyone\",\n  \"scene_note\": \"The mood is somber as actor a prepares to leave. Actor b watches in silence, perhaps with regret or longing.\"\n},\n{\n  \"character\": \"actor b\",\n  \"instruction\": \"actor b should say goodbye to b\",\n  \"action\": \"JustTalk\",\n  \"target\": \"Actor a\",\n  \"scene_note\": \"Is a sad moment, generally speaking.\"\n}\n]\n}\n\n(no user request, randomly generated content)\n{\"instructions\":[\n {\n  \"character\": \"actor a\",\n  \"instruction\": \"actor a should ask actor b for a few coins, claiming they desperately need a drink.\",\n  \"action\": \"Talk\",\n  \"target\": \"actor b\",\n  \"scene_note\": \"actor a looks disheveled but charming, half-joking and half-serious. Actor b is unsure whether to laugh, help, or walk away. Other actors watch this two guys with curiosity\"\n }\n]\n}");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('director_examples_prompt', '$directorExamples', 'Examples of instruction format for game director responses. Used in: service/processors/rolemaster/cmd/instruction.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // DIRECTOR_INSTRUCTION_RULES - Rules for generating instructions (uses {PLAYER_NAME}, {FUNCTION_LIST} placeholders)
    $directorRules = $db->escape("Just provide instructions! You can also provide more than one instruction, but one per actor (keep limit at  2 or 3 max actors)\nIn addition, follow these general scene rules as a game director:\n * Use any actor in NEARBY ACTORS/NPC IN THE SCENE list ({PLAYER_NAME},busy actors and far away actors are EXCLUDED!)\n * Continue the scene as naturally and fully as possible, unless the user explicitly requests a new one. You can specify actions to reinforce the actors' dialogue.\n * If there are more actors in the room, try to involve them in the conversation.\n * When dialogue becomes repetitive, make a plot twist.\n * If a character reuses the same argument too often, nudge the scene towards a new topic.\n * Occasionally introduce subtle foreshadowing or hint at future events, dangers, or quests.\n * Do not resolve everything neatly - keep room for ongoing tension or future continuation.\n * You must always provide dialogue instructions for the character, as every request requires a dialogue response.\n * Here are a list of actions that can be used: \n{FUNCTION_LIST}\n  ** JustTalk \n * Add a Scene Note: A brief description of the topic, mood, or idea introduced by the instruction. Should serve to guide the desired instruction to become reality. Other actors can see this to properly react.\n * If scene is getting boring/repetitive, add a plot twist");
    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('director_instruction_rules', '$directorRules', 'Rules and guidelines for game director when generating instructions (contains {PLAYER_NAME}, {FUNCTION_LIST} placeholders). Used in: service/processors/rolemaster/cmd/instruction.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");
    
    // Seed WorldKnowledge LLM topic extraction prompt
    $worldknowledgeTopicPrompt = $db->escape(
        "You are an expert at extracting important topics from text.\n".
        "Follow these rules strictly:\n\n".
        "1. Extract only ONE most important topic (person, place, item, concept, etc.) from the text\n".
        "2. Ensure the output is in the **singular form** (e.g., stimpaks -> stimpak, settlements -> settlement)\n".
        "3. Return ONLY the word or phrase (no explanations, no extra text)\n".
        "4. If multiple candidates exist, choose the most important one\n".
        "5. Keep the topic in the same language as the input text\n\n".
        "Examples:\n".
        "Input: 'I heard about stimpaks'\n".
        "Output: stimpak\n\n".
        "Input: 'Going to Goodsprings today'\n".
        "Output: Goodsprings\n\n".
        "Input: 'Met with the Kings'\n".
        "Output: King\n\n".
        "Input: 'Used a laser rifle in combat'\n".
        "Output: laser rifle"
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'custom_worldknowledge',
            '$worldknowledgeTopicPrompt',
            'System prompt for WorldKnowledge LLM-based topic extraction from dialogue/text (does not apply to MiniMe T5 version). Used in: lib/worldknowledge_llm_service.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20251110001);
    Logger::info("Applied patch prompts 20251110001 - Added all dynamic prompts and director prompts");
}

//----------------------------------------------------
// RANDOM NARRATION PROMPT
//----------------------------------------------------

if ($checkVersion("prompts")<20251116001) {
    Logger::debug("Applying prompts table 20251116001 - Adding random_narration_prompt");
    
    // Seed random narration prompt
    $randomNarrationPrompt = $db->escape(
        "Describe the current scene visually using ONLY details from the provided context. Focus on the characters present - their appearance, expressions, body language, and what they're wearing. Include environmental details like lighting and atmosphere. Keep it grounded and concise (2-3 sentences). Do not invent new information, advance the plot, or include dialogue."
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'random_narration_prompt',
            '$randomNarrationPrompt',
            'Prompt for random Narrator interjections that add cinematic visual scene descriptions during conversations. Styled as atmospheric, present-tense narration (2-3 sentences). Used when RANDOM_NARATION is enabled in global settings.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20251116001);
    Logger::info("Applied patch prompts 20251116001 - Added random_narration_prompt");
}

//----------------------------------------------------
// HEIGHT DESCRIPTIONS PROMPT
//----------------------------------------------------

if ($checkVersion("prompts")<20251128002) {
    Logger::debug("Applying prompts table 20251128002 - Adding height_descriptions");
    
    // Seed height descriptions as JSON
    $heightDescriptions = $db->escape(json_encode([
        "height_descriptions" => [
            [
                "name" => "VerySmall",
                "min_scale" => 0.0,
                "max_scale" => 0.60,
                "description" => "Very small and tiny in stature"
            ],
            [
                "name" => "Small",
                "min_scale" => 0.60,
                "max_scale" => 0.80,
                "description" => "Smaller than most people"
            ],
            [
                "name" => "ModestStature",
                "min_scale" => 0.80,
                "max_scale" => 0.95,
                "description" => "Slightly below average height"
            ],
            [
                "name" => "Average",
                "min_scale" => 0.95,
                "max_scale" => 1.05,
                "description" => "Typical height"
            ],
            [
                "name" => "Tall",
                "min_scale" => 1.05,
                "max_scale" => 1.20,
                "description" => "Tall, standing a head above most people"
            ],
            [
                "name" => "VeryTall",
                "min_scale" => 1.20,
                "max_scale" => 1.40,
                "description" => "Very tall"
            ],
            [
                "name" => "Giantlike",
                "min_scale" => 1.40,
                "max_scale" => 99.0,
                "description" => "Giant in height and stature"
            ]
        ]
    ]));
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'height_descriptions',
            '$heightDescriptions',
            'JSON configuration for NPC height descriptions based on scale values. Used to generate natural language height descriptions from numeric scale values for NPC context.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20251128002);
    Logger::info("Applied patch prompts 20251128002 - Added height_descriptions");
}

//----------------------------------------------------
// Add narrator_welcome_prompt to prompts table
// Version 20251224001
//----------------------------------------------------

if ($checkVersion("prompts")<20251224001) {
    Logger::debug("Applying prompts table 20251224001 - Adding narrator_welcome_prompt");
    
    $welcomePrompt = $db->escape(
        "Give a brief (2-3 sentence) recap of recent events and adventures. ".
        "Welcome the player back to their journey."
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'narrator_welcome_prompt',
            '$welcomePrompt',
            'Prompt for narrator welcome message when loading a save game. Used in: main.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20251224001);
    Logger::info("Applied patch prompts 20251224001 - Added narrator_welcome_prompt");
}

//----------------------------------------------------
// Add quest_comment_prompt to prompts table
// Version 20251224002
//----------------------------------------------------

if ($checkVersion("prompts")<20251224002) {
    Logger::debug("Applying prompts table 20251224002 - Adding quest_comment_prompt");
    
    $questPrompt = $db->escape(
        "{DIALECTIC_NAME}, what should we do about this new quest?"
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'quest_comment_prompt',
            '$questPrompt',
            'Prompt for narrator/NPC comments on quest objective updates (contains {DIALECTIC_NAME} placeholder). Used in: prompts/prompts.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20251224002);
    Logger::info("Applied patch prompts 20251224002 - Added quest_comment_prompt");
}

//----------------------------------------------------
// CORE_PLAYER DATA MIGRATION
//----------------------------------------------------

if ($checkVersion("core_player")<20241128001) {
    Logger::debug("Applying core_player migration 20241128001 - Migrating player data from conf_opts");
    
    // List of keys to migrate from conf_opts to core_player
    $keysToMigrate = [
        'PLAYER_NAME' => 'player_name',
        'PLAYER_SPEECH_STYLE' => 'speech_style',
        // Fallout stats
        'Locations Discovered', 'Days Passed', 'Hours Slept', 'Hours Waited',
        'Caps Found', 'Most Caps Carried', 'Containers Looted', 'Barters',
        'Skill Increases', 'Skill Books Read', 'Books Read',
        'Food Eaten', 'Chems Taken', 'Stimpaks Taken', 'RadAway Taken', 'Doctors Bags Used',
        'Speech Successes', 'Bribes', 'Intimidations',
        'Locks Picked', 'Lockpicks Broken', 'Terminals Hacked',
        'Quests Completed', 'Main Quests Completed', 'Side Quests Completed',
        'Misc Objectives Completed', 'Questlines Completed', 'Companions Recruited',
        'Factions Discovered'
    ];
    
    foreach ($keysToMigrate as $confKey => $playerKey) {
        // If confKey is numeric (index in array), use it as both source and dest
        if (is_numeric($confKey)) {
            $confKey = $playerKey;
        }
        
        // Check if data exists in conf_opts
        $escapedConfKey = $db->escape($confKey);
        $result = $db->fetchAll("SELECT value FROM public.conf_opts WHERE id = '{$escapedConfKey}' LIMIT 1");
        
        if ($result && isset($result[0]['value'])) {
            $value = $result[0]['value'];
            $escapedPlayerKey = $db->escape($playerKey);
            $escapedValue = $db->escape($value);
            
            // Insert or update in core_player
            $db->execQuery("
                INSERT INTO public.core_player (id, value) 
                VALUES ('{$escapedPlayerKey}', '{$escapedValue}')
                ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
            ");
            
            Logger::debug("Migrated {$confKey} -> core_player.{$playerKey}");
        }
    }
    
    $updateVersion("core_player", 20241128001);
    Logger::info("Applied patch core_player 20241128001 - Migrated player data from conf_opts");
}


//----------------------------------------------------
// PLAYER AUTO DIARY FEATURE - Add auto diary toggles
// Version 20260707001
//----------------------------------------------------

if ($checkVersion("core_player")<20260707001) {
    Logger::debug("Applying core_player migration 20260707001 - Adding player auto diary toggles");

    $db->execQuery("
        INSERT INTO public.core_player (id, value)
        VALUES
            ('auto_diary_enabled', '0'),
            ('auto_diary_wait_enabled', '0')
        ON CONFLICT (id) DO NOTHING
    ");

    $updateVersion("core_player", 20260707001);
    Logger::info("Applied patch core_player 20260707001 - Added player auto diary toggles");
}

//----------------------------------------------------
// CORE_NARRATOR DATA MIGRATION
//----------------------------------------------------

if ($checkVersion("core_narrator")<20250101001) {
    Logger::debug("Applying core_narrator migration 20250101001 - Migrating narrator settings from conf_opts");
    
    // Map conf_opts keys to core_narrator keys
    $keysToMigrate = [
        'NARRATOR_TALKS' => 'enabled',
        'NARRATOR_WELCOME' => 'welcome_enabled',
        'RANDOM_NARATION' => 'random_enabled',
        'RANDOM_NARATION_CHANCE' => 'random_chance',
        'RANDOM_NARRATION_COOLDOWN' => 'random_cooldown',
        'HIDE_NARRATOR_DIALOGUE' => 'hide_from_context',
    ];
    
    foreach ($keysToMigrate as $confKey => $narratorKey) {
        // Check if data exists in conf_opts
        $escapedConfKey = $db->escape($confKey);
        $result = $db->fetchAll("SELECT value FROM public.conf_opts WHERE id = '{$escapedConfKey}' LIMIT 1");
        
        if ($result && isset($result[0]['value'])) {
            $value = $result[0]['value'];
            $escapedNarratorKey = $db->escape($narratorKey);
            $escapedValue = $db->escape($value);
            
            // Insert or update in core_narrator
            $db->execQuery("
                INSERT INTO public.core_narrator (id, value) 
                VALUES ('{$escapedNarratorKey}', '{$escapedValue}')
                ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
            ");
            
            Logger::debug("Migrated {$confKey} -> core_narrator.{$narratorKey}");
        }
    }
    
    // Seed defaults if no values exist (only if table is empty)
    $countResult = $db->fetchAll("SELECT COUNT(*) AS c FROM public.core_narrator");
    $count = $countResult && isset($countResult[0]['c']) ? intval($countResult[0]['c']) : 0;
    
    if ($count === 0) {
        // Seed with defaults from conf.php if available, otherwise use hardcoded defaults
        $defaults = [
            'roleplay_name' => 'The Narrator',
            'enabled' => isset($GLOBALS["NARRATOR_TALKS"]) ? ($GLOBALS["NARRATOR_TALKS"] ? '1' : '0') : '1',
            'welcome_enabled' => isset($GLOBALS["NARRATOR_WELCOME"]) ? ($GLOBALS["NARRATOR_WELCOME"] ? '1' : '0') : '0',
            'random_enabled' => isset($GLOBALS["RANDOM_NARATION"]) ? ($GLOBALS["RANDOM_NARATION"] ? '1' : '0') : '0',
            'random_chance' => isset($GLOBALS["RANDOM_NARATION_CHANCE"]) ? (string)intval($GLOBALS["RANDOM_NARATION_CHANCE"]) : '15',
            'random_cooldown' => isset($GLOBALS["RANDOM_NARRATION_COOLDOWN"]) ? (string)intval($GLOBALS["RANDOM_NARRATION_COOLDOWN"]) : '2',
            'hide_from_context' => isset($GLOBALS["HIDE_NARRATOR_DIALOGUE"]) ? ($GLOBALS["HIDE_NARRATOR_DIALOGUE"] ? '1' : '0') : '0',
        ];
        
        foreach ($defaults as $key => $value) {
            $escapedKey = $db->escape($key);
            $escapedValue = $db->escape($value);
            $db->execQuery("
                INSERT INTO public.core_narrator (id, value) 
                VALUES ('{$escapedKey}', '{$escapedValue}')
                ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
            ");
        }
        
        Logger::debug("Seeded core_narrator with default values");
    }
    
    $updateVersion("core_narrator", 20250101001);
    Logger::info("Applied patch core_narrator 20250101001 - Migrated narrator settings from conf_opts");
}

//----------------------------------------------------
// CORE_NARRATOR CHARACTER DATA MIGRATION FROM CORE_NPC_MASTER
//----------------------------------------------------

if ($checkVersion("core_narrator")<20250101002) {
    Logger::debug("Applying core_narrator migration 20250101002 - Migrating narrator character data from core_npc_master");
    
    // Check if The Narrator exists in core_npc_master
    $narratorNpc = $db->fetchOne("SELECT * FROM public.core_npc_master WHERE npc_name = 'The Narrator' LIMIT 1");
    
    if ($narratorNpc) {
        // Copy character data to core_narrator
        $migrationData = [];
        
        if (isset($narratorNpc['profile_id']) && $narratorNpc['profile_id'] !== null) {
            $migrationData['profile_id'] = (string)intval($narratorNpc['profile_id']);
        }
        
        if (isset($narratorNpc['voiceid']) && $narratorNpc['voiceid'] !== null && $narratorNpc['voiceid'] !== '') {
            $migrationData['voiceid'] = $narratorNpc['voiceid'];
        }
        
        if (isset($narratorNpc['core']) && $narratorNpc['core'] !== null && $narratorNpc['core'] !== '') {
            $migrationData['core'] = $narratorNpc['core'];
        }
        
        if (isset($narratorNpc['npc_static_bio']) && $narratorNpc['npc_static_bio'] !== null && $narratorNpc['npc_static_bio'] !== '') {
            $migrationData['background'] = $narratorNpc['npc_static_bio'];
        }
        
        if (isset($narratorNpc['personality']) && $narratorNpc['personality'] !== null && $narratorNpc['personality'] !== '') {
            $migrationData['personality'] = $narratorNpc['personality'];
        }
        
        if (isset($narratorNpc['speechstyle']) && $narratorNpc['speechstyle'] !== null && $narratorNpc['speechstyle'] !== '') {
            $migrationData['speechstyle'] = $narratorNpc['speechstyle'];
        }
        
        if (isset($narratorNpc['goals']) && $narratorNpc['goals'] !== null && $narratorNpc['goals'] !== '') {
            $migrationData['goals'] = $narratorNpc['goals'];
        }
        
        if (isset($narratorNpc['worldknowledge_tags']) && $narratorNpc['worldknowledge_tags'] !== null && $narratorNpc['worldknowledge_tags'] !== '') {
            $migrationData['worldknowledge'] = $narratorNpc['worldknowledge_tags'];
        }
        
        if (isset($narratorNpc['gender']) && $narratorNpc['gender'] !== null && $narratorNpc['gender'] !== '') {
            $migrationData['gender'] = $narratorNpc['gender'];
        }
        
        if (isset($narratorNpc['prompt_head']) && $narratorNpc['prompt_head'] !== null && $narratorNpc['prompt_head'] !== '') {
            $migrationData['prompt_head'] = $narratorNpc['prompt_head'];
        }
        
        // Insert/update in core_narrator (only if not already set)
        foreach ($migrationData as $key => $value) {
            $existing = $db->fetchOne("SELECT value FROM public.core_narrator WHERE id = '{$db->escape($key)}' LIMIT 1");
            if (!$existing || !isset($existing['value']) || $existing['value'] === '') {
                $escapedKey = $db->escape($key);
                $escapedValue = $db->escape($value);
                $db->execQuery("
                    INSERT INTO public.core_narrator (id, value) 
                    VALUES ('{$escapedKey}', '{$escapedValue}')
                    ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
                ");
                Logger::debug("Migrated narrator.{$key} from core_npc_master");
            }
        }
        
    // Delete The Narrator from core_npc_master
    $db->execQuery("DELETE FROM public.core_npc_master WHERE npc_name = 'The Narrator'");
    Logger::info("Deleted The Narrator from core_npc_master");
    } else {
        // No narrator in core_npc_master - check if we need to seed defaults in core_narrator
        $narratorCount = $db->fetchAll("SELECT COUNT(*) AS c FROM public.core_narrator");
        $count = $narratorCount && isset($narratorCount[0]['c']) ? intval($narratorCount[0]['c']) : 0;
        
        if ($count === 0) {
            // Fresh install - seed narrator character data with defaults
            $defaultProfile = $db->fetchOne("SELECT id FROM public.core_profiles WHERE default_narrator = '1' LIMIT 1");
            $profileId = $defaultProfile && isset($defaultProfile['id']) ? (string)intval($defaultProfile['id']) : '1';
            
            $defaults = [
                'profile_id' => $profileId,
                'roleplay_name' => 'The Narrator',
                'voiceid' => 'TheNarrator',
                'core' => "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the Fallout wasteland. Provide unique insight and descriptions of what is going on in the world.",
                'background' => "A guiding voice that describes the world, events, and transitions. He is not a character, but a voice within the player's mind.",
                'personality' => 'Detached, descriptive, witty, helpful.',
                'speechstyle' => '',
                'goals' => '',
                'worldknowledge' => 'knowall',
                'gender' => 'male',
            ];
            
            foreach ($defaults as $key => $value) {
                $escapedKey = $db->escape($key);
                $escapedValue = $db->escape($value);
                $db->execQuery("
                    INSERT INTO public.core_narrator (id, value) 
                    VALUES ('{$escapedKey}', '{$escapedValue}')
                    ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
                ");
            }
            
            Logger::info("Seeded narrator character data with defaults for fresh install");
        }
    }
    
    $updateVersion("core_narrator", 20250101002);
    Logger::info("Applied patch core_narrator 20250101002 - Migrated narrator character data from core_npc_master and removed from NPC list");
}

//----------------------------------------------------
// NARRATOR DIARY FEATURE - Add diary_enabled toggle
// Version 20260209001
//----------------------------------------------------

if ($checkVersion("core_narrator")<20260209001) {
    Logger::debug("Applying core_narrator migration 20260209001 - Adding diary_enabled toggle");
    
    // Add diary_enabled field (default to disabled)
    $db->execQuery("
        INSERT INTO public.core_narrator (id, value) 
        VALUES ('diary_enabled', '0')
        ON CONFLICT (id) DO NOTHING
    ");
    
    Logger::info("Added diary_enabled to core_narrator (defaults to disabled)");
    
    $updateVersion("core_narrator", 20260209001);
    Logger::info("Applied patch core_narrator 20260209001 - Added diary_enabled toggle");
}

//----------------------------------------------------
// CORE_NARRATOR DEFAULT CHARACTER BACKFILL
// Version 20260417001
//----------------------------------------------------

if ($checkVersion("core_narrator")<20260417001) {
    Logger::debug("Skipping removed core_narrator backfill migration 20260417001");
    $updateVersion("core_narrator", 20260417001);
    Logger::info("Applied patch core_narrator 20260417001 - Backfill removed");
}

//----------------------------------------------------
// NARRATOR AUTO DIARY FEATURE - Add auto_diary_enabled toggle
// Version 20260522001
//----------------------------------------------------

if ($checkVersion("core_narrator")<20260522001) {
    Logger::debug("Applying core_narrator migration 20260522001 - Adding auto_diary_enabled toggle");

    // Add auto_diary_enabled field (default to disabled)
    $db->execQuery("
        INSERT INTO public.core_narrator (id, value)
        VALUES ('auto_diary_enabled', '0')
        ON CONFLICT (id) DO NOTHING
    ");

    Logger::info("Added auto_diary_enabled to core_narrator (defaults to disabled)");

    $updateVersion("core_narrator", 20260522001);
    Logger::info("Applied patch core_narrator 20260522001 - Added auto_diary_enabled toggle");
}

//----------------------------------------------------
// NARRATOR ROLEPLAY NAME - Prompt-facing narrator alias
// Version 20260714001
//----------------------------------------------------

if ($checkVersion("core_narrator")<20260714001) {
    Logger::debug("Applying core_narrator migration 20260714001 - Adding narrator roleplay name");

    $db->execQuery("
        INSERT INTO public.core_narrator (id, value)
        VALUES ('roleplay_name', 'The Narrator')
        ON CONFLICT (id) DO NOTHING
    ");

    $updateVersion("core_narrator", 20260714001);
    Logger::info("Applied patch core_narrator 20260714001 - Added narrator roleplay name");
}

//----------------------------------------------------
// Relationship LLM Prompts
// Version 20260125001
//----------------------------------------------------

if ($checkVersion("prompts")<20260125001) {
    Logger::debug("Applying relationship LLM prompts 20260125001");

    // Relationship Analysis Prompt - For parsing TEXT relationships to JSONB
    $relAnalysisPrompt = $db->escape(
'You are a relationship analyzer for Fallout NPCs. Analyze relationship descriptions and output JSON.

AFFINITY SCALE (-100 to +100, bell curve - extremes are RARE):
+91 to +100: Bonded (soulmates, unbreakable)
+76 to +90: Devoted (deep loyalty/love)
+56 to +75: Fond (genuine affection)
+31 to +55: Friendly (pleasant, helpful)
+6 to +30: Acquaintance (polite nod)
-5 to +5: Neutral (stranger)
-6 to -30: Wary (distrustful)
-31 to -55: Cold (unfriendly)
-56 to -75: Resentful (bitter, grudges)
-76 to -90: Hateful (active malice)
-91 to -100: Hostile (kill on sight)

TYPES: romantic, platonic, familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective, indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious, admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive

INFERENCE RULES:
1. FACTION: NCR -> add "Caesar\'s Legion": -60 enemy. Caesar\'s Legion -> add "NCR": -60 enemy.
2. RACIAL: If NPC shows racial attitudes, add race as target (e.g., "ghoul": -40 contempt)
3. OCCUPATION: Powder Gangers -> "NCR": -40 rival. Brotherhood of Steel -> "NCR": -60 enemy.
4. "{PLAYER_NAME}" = Player character. Store as "Player".

OUTPUT (JSON only):
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}'
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rel_llm_analysis',
            '$relAnalysisPrompt',
            'System prompt for relationship LLM analysis - parses text relationships to JSONB format (contains {PLAYER_NAME} placeholder). Used by the Dialectic relationship manager.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    // Relationship Evaluation Prompt - For evaluating conversations
    $relEvalPrompt = $db->escape(
'You are a behavioral psychologist. Evaluate interactions and provide BRIEF insight.

SPEAKER ATTRIBUTION:
- [PLAYER] and [NPC] tags show who said what
- Only evaluate based on what PLAYER did, not the NPC\'s own words

AFFINITY SCALE (-100 to +100):
- +/-1: Normal chat
- +/-2-3: Notably friendly/rude, small favors
- +/-5-10: Meaningful help, gifts, insults
- +/-15-25: Saving life, violence, betrayal
- +/-50+: Extreme events (killing loved ones, marriage)

MOST INTERACTIONS = 0 or +/-1. Be conservative. Skip trivial exchanges.

REASON FORMAT - Keep it SHORT (under 15 words):
OK: "Teasing triggered defensiveness"
OK: "Genuine interest validates their experience"
OK: "Protective action builds trust"
NOT: Long clinical explanations

TYPE CHANGES (rare - only for defining moments):
- Only change type for: romance confession, betrayal, violence, marriage, family reveal
- Most interactions just adjust affinity, not type

OUTPUT (JSON only):
{"changes": {"Player": {"delta": 1, "reason": "brief insight"}}}

No changes? Return: {"changes": {}}'
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rel_llm_evaluation',
            '$relEvalPrompt',
            'System prompt for relationship evaluation - judges affinity changes from conversations. Used by the Dialectic relationship manager.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    // NPC-to-NPC Evaluation Prompt - For bidirectional NPC conversations
    $relNpc2NpcPrompt = $db->escape(
'You are a behavioral psychologist. Evaluate NPC-to-NPC interaction briefly.

DIRECTION:
- speaker = NPC who SPOKE
- listener = NPC who HEARD
- speaker.delta = speaker\'s feelings toward listener changed?
- listener.delta = listener\'s feelings toward speaker changed?

SCALE: +/-1 typical, +/-2-3 notable, +/-5+ significant. Be conservative.

REASON FORMAT - Under 15 words:
OK: "Dark humor built rapport"
OK: "Bossy tone caused mild resentment"
OK: "Helpful advice appreciated"

OUTPUT - Use exactly "speaker" and "listener":
{"speaker": {"delta": 0, "reason": "brief"}, "listener": {"delta": 1, "reason": "brief"}}

No changes? Return empty objects: {}'
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rel_llm_npc_to_npc',
            '$relNpc2NpcPrompt',
            'System prompt for NPC-to-NPC relationship evaluation - bidirectional in single call. Used by the Dialectic relationship manager.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260125001);
    Logger::info("Applied patch prompts 20260125001 - Added relationship LLM prompts");
}

//----------------------------------------------------
// PLAYER SPEECH STYLE GENERATION PROMPT
//----------------------------------------------------

if ($checkVersion("prompts")<20260327001) {
    Logger::debug("Applying prompts table 20260327001 - Adding player_speech_style_prompt");
    
    $playerSpeechStylePrompt = $db->escape(
        "Generate a practical speech style prompt for {PLAYER_NAME} using recent dialogue and optional guidance. "
        . "Write exactly one paragraph (3-5 sentences) that can be used directly to rewrite player dialogue in roleplay. "
        . "Capture vocabulary, tone, cadence, formality, recurring phrases, and interpersonal style. "
        . "Stay grounded in the dialogue samples and guidance. Do not use bullet points, labels, or headings."
    );
    
    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'player_speech_style_prompt',
            '$playerSpeechStylePrompt',
            'Prompt for generating player speech style from recent player input events and optional user guidance. Supports placeholders: {PLAYER_NAME}, {PLAYER_GUIDANCE}, {CURRENT_SPEECH_STYLE}, {DIALOGUE_SAMPLES}. Used in: ui/cmd/action_player_generate_speech_style.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $updateVersion("prompts", 20260327001);
    Logger::info("Applied patch prompts 20260327001 - Added player_speech_style_prompt");
}

//----------------------------------------------------
// BASE DIALOGUE RESPONSE PROMPTS
//----------------------------------------------------

if ($checkVersion("prompts")<20260412001) {
    Logger::debug("Applying prompts table 20260412001 - Adding dialogue response prompts");

    $dialogueLineResponsePrompt = $db->escape(
        " Write {DIALECTIC_NAME}'s next dialogue line."
        . " Be original, creative, knowledgeable, use your own thoughts. "
        . " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}"
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'dialogue_line_response',
            '$dialogueLineResponsePrompt',
            'Base response instruction used for standard NPC dialogue when inline narration is disabled. Supports placeholders: {DIALECTIC_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260412001);
    Logger::info("Applied patch prompts 20260412001 - Added dialogue response prompts");
}

if ($checkVersion("prompts")<20260422001) {
    Logger::debug("Applying prompts table 20260422001 - Adding mode-specific inline narration prompts");

    $emptyCustomPromptSql = "NULL";

    $dialogueLineInlineResponseNarratorPrompt = $db->escape(
        " Write {DIALECTIC_NAME}'s next prose/narration."
        . " Be original, creative, knowledgeable, use your own thoughts. "
        . " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}"
    );
    $inlineNarrationPromptNarrator = $db->escape(
        "You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles*). Do not wrap the entire reply in asterisks; keep any spoken dialogue outside the asterisks."
    );
    $dialogueLineInlineResponseNpcPrompt = $db->escape(
        " Write {DIALECTIC_NAME}'s next dialogue line."
        . " If needed, you may include one brief third-person narration block in single asterisks before the dialogue."
        . " Keep any spoken dialogue outside the asterisks, and do not wrap the entire reply in asterisks."
        . " Be original, creative, knowledgeable, use your own thoughts."
        . " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}"
    );
    $inlineNarrationPromptNpc = $db->escape(
        "You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles softly*). Keep any spoken dialogue outside the asterisks. Do not wrap the entire reply in asterisks."
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, custom_prompt, description)
        VALUES (
            'dialogue_line_inline_response_narrator',
            '$dialogueLineInlineResponseNarratorPrompt',
            $emptyCustomPromptSql,
            'Base response instruction used when inline narration mode is narrator. Supports placeholders: {DIALECTIC_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            custom_prompt = CASE
                WHEN (public.prompts.custom_prompt IS NULL OR public.prompts.custom_prompt = '')
                    AND EXCLUDED.custom_prompt IS NOT NULL
                THEN EXCLUDED.custom_prompt
                ELSE public.prompts.custom_prompt
            END,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, custom_prompt, description)
        VALUES (
            'inline_narration_prompt_narrator',
            '$inlineNarrationPromptNarrator',
            $emptyCustomPromptSql,
            'Additional narration formatting instruction used when inline narration mode is narrator. Used in: prompts/dialogue_prompt.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            custom_prompt = CASE
                WHEN (public.prompts.custom_prompt IS NULL OR public.prompts.custom_prompt = '')
                    AND EXCLUDED.custom_prompt IS NOT NULL
                THEN EXCLUDED.custom_prompt
                ELSE public.prompts.custom_prompt
            END,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, custom_prompt, description)
        VALUES (
            'dialogue_line_inline_response_npc',
            '$dialogueLineInlineResponseNpcPrompt',
            $emptyCustomPromptSql,
            'Base response instruction used when inline narration mode is npc. Supports placeholders: {DIALECTIC_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            custom_prompt = CASE
                WHEN (public.prompts.custom_prompt IS NULL OR public.prompts.custom_prompt = '')
                    AND EXCLUDED.custom_prompt IS NOT NULL
                THEN EXCLUDED.custom_prompt
                ELSE public.prompts.custom_prompt
            END,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, custom_prompt, description)
        VALUES (
            'inline_narration_prompt_npc',
            '$inlineNarrationPromptNpc',
            $emptyCustomPromptSql,
            'Additional narration formatting instruction used when inline narration mode is npc. Used in: prompts/dialogue_prompt.php'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            custom_prompt = CASE
                WHEN (public.prompts.custom_prompt IS NULL OR public.prompts.custom_prompt = '')
                    AND EXCLUDED.custom_prompt IS NOT NULL
                THEN EXCLUDED.custom_prompt
                ELSE public.prompts.custom_prompt
            END,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260422001);
    Logger::info("Applied patch prompts 20260422001 - Added mode-specific inline narration prompts");
}

//----------------------------------------------------
// emotions expression
//----------------------------------------------------

if ($checkVersion("emotions_expression")<20251130003) {
    Logger::debug(" try patch: emotions_expression 20251130003");
    $b_ok = true;
    try {
        $query = " ALTER TABLE public.speech ADD COLUMN IF NOT EXISTS mood TEXT; ";
        $db->execQuery($query);        
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'speech' table: " . $e->getMessage());
    }
    try {
        $query = " ALTER TABLE public.speech ADD COLUMN IF NOT EXISTS emotion TEXT; ";
        $db->execQuery($query);        
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'speech' table: " . $e->getMessage());
    }
    try {
        $query = " ALTER TABLE public.speech ADD COLUMN IF NOT EXISTS emotion_intensity TEXT; ";
        $db->execQuery($query);        
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'speech' table: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("emotions_expression",20251130003);
        Logger::info("Applied patch emotions_expression 20251130003");
    }
}

if ($checkVersion("emotions_expression")<20251230001) {
    Logger::debug(" try patch: emotions_expression 20251230001");
    $b_ok = true;
    try {
        $query = " ALTER TABLE public.moods_issued ADD COLUMN IF NOT EXISTS emotion TEXT; ";
        $db->execQuery($query);        
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'moods_issued' table: " . $e->getMessage());
    }
    try {
        $query = " ALTER TABLE public.moods_issued ADD COLUMN IF NOT EXISTS emotion_intensity TEXT; ";
        $db->execQuery($query);        
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'moods_issued' table: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("emotions_expression",20251230001);
        Logger::info("Applied patch emotions_expression 20251230001");
    }
}

//----------------------------------------------------

if ($checkVersion("prompts")<20260423001) {
    Logger::debug(" try patch: prompts 20260423001");

    // Fresh installs only seed the consolidated prompt entry.

    $directorSuggestionSystemSingle = $db->escape(
        "You are a game director, and we are roleplaying Fallout in the Mojave Wasteland. You must create an instruction for an actor to generate new content or events in-game.\n\n"
        . "# Examples\n\n"
        . "user request: actor \"a\" leaves the place \n"
        . "{\"instructions\":[{\n"
        . "  \"character\": \"actor a\",\n"
        . "  \"instruction\": \"actor a should say goodbye to everyone, hinting that they may not return for a long time\",\n"
        . "  \"action\": \"ExitLocation\",\n"
        . "  \"target\": \"everyone\",\n"
        . "  \"scene_note\": \"The mood is somber as actor a prepares to leave. Actor b watches in silence, perhaps with regret or longing.\"\n"
        . "},\n"
        . "{\n"
        . "  \"character\": \"actor b\",\n"
        . "  \"instruction\": \"actor b should say goodbye to b\",\n"
        . "  \"action\": \"JustTalk\",\n"
        . "  \"target\": \"Actor a\",\n"
        . "  \"scene_note\": \"\"\n"
        . "}\n"
        . "]\n"
        . "}\n\n"
        . "(no user request, randomly generated content)\n"
        . "{\"instructions\":[\n"
        . " {\n"
        . "  \"character\": \"actor a\",\n"
        . "  \"instruction\": \"actor a should ask actor b for a few coins, claiming they desperately need a drink.\",\n"
        . "  \"action\": \"Talk\",\n"
        . "  \"target\": \"actor b\",\n"
        . "  \"scene_note\": \"actor a looks disheveled but charming, half-joking and half-serious. Actor b is unsure whether to laugh, help, or walk away.\"\n"
        . " }\n"
        . "]\n"
        . "}\n\n"
        . "Just provide instructions! You can also provide more than one instruction, but one per actor (keep limit at 2 or 3 max actors)\n"
        . "In addition, follow these general scene rules as a game director:\n"
        . " * Use any actor in NEARBY ACTORS/NPC IN THE SCENE list ({PLAYER_NAME}, busy actors and far away actors are excluded)\n"
        . " * Continue the scene as naturally and fully as possible, unless the user explicitly requests a new one. You can specify actions to reinforce the actors' dialogue.\n"
        . " * If there are more actors in the room, try to involve them in the conversation.\n"
        . " * When dialogue becomes repetitive, make a plot twist.\n"
        . " * If a character reuses the same argument too often, nudge the scene towards a new topic.\n"
        . " * Occasionally introduce subtle foreshadowing or hint at future events, dangers, or quests.\n"
        . " * Do not resolve everything neatly - keep room for ongoing tension or future continuation.\n"
        . " * You must always provide dialogue instructions for the character, as every request requires a dialogue response.\n"
        . " * Here are a list of actions that can be used:\n{FUNCTION_LIST}\n  ** JustTalk\n"
        . " * Add a Scene Note: A brief description of the topic, mood, or idea introduced by the instruction. Should serve to guide the desired instruction to become reality.\n"
        . " * If scene is getting boring, add a plot twist"
    );

    $db->execQuery("INSERT INTO public.prompts (prompt_key, default_prompt, description) VALUES ('directorSuggestionSystem', '$directorSuggestionSystemSingle', 'Single prompt-manager entry for rolemaster suggestion generation. Includes system framing, examples, and suggestion rules. Supports {PLAYER_NAME} and {FUNCTION_LIST} placeholders. Used in: service/processors/rolemaster/cmd/suggestion.php') ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP");

    $updateVersion("prompts", 20260423001);
    Logger::info("Applied patch prompts 20260423001 - Added directorSuggestionSystem prompt");
}

//----------------------------------------------------

if ($checkVersion("prompts")<20260430017) {
    Logger::debug("Applying prompts table 20260430017 - Adding narrator_bored_prompt");

    $narratorBoredPrompt = $db->escape(
        "({DIALECTIC_NAME} makes one short comment directly to {PLAYER_NAME} about something happening right now in the current scene. Keep it grounded in the present moment, do not ask follow-up questions, and do not continue the conversation.) {TEMPLATE_DIALOG}"
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'narrator_bored_prompt',
            '$narratorBoredPrompt',
            'Prompt for narrator bored events directed at the player (contains {DIALECTIC_NAME}, {PLAYER_NAME}, and {TEMPLATE_DIALOG} placeholders). Used in: main.php narrator bored routing.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260430017);
    Logger::info("Applied patch prompts 20260430017 - Added narrator_bored_prompt");
}

if ($checkVersion("prompts")<20260502004) {
    Logger::debug("Applying prompts table 20260502004 - Adding managed rechat strict/relaxed prompts");

    $rechatResponsePromptRelaxed1 = $db->escape(
        "Dialogue turn for {DIALECTIC_NAME}. Respond naturally to whoever just spoke. Address the previous speaker directly. {TEMPLATE_DIALOG}"
    );
    $rechatResponsePromptRelaxed2 = $db->escape(
        "Dialogue turn for {DIALECTIC_NAME}. Continue the conversation naturally. Address whoever you're actually responding to. {TEMPLATE_DIALOG}"
    );
    $rechatResponsePromptRelaxed3 = $db->escape(
        "Dialogue turn for {DIALECTIC_NAME}. Focus on one actor - respond to whoever just spoke. {TEMPLATE_DIALOG}"
    );
    $rechatResponsePromptStrict = $db->escape(
        "Dialogue turn for {DIALECTIC_NAME}. The previous speaker was {PREVIOUS_SPEAKER}. You must respond directly to {PREVIOUS_SPEAKER}."
    );
    $rechatListenerPromptRelaxed = $db->escape(
        "specify who {DIALECTIC_NAME} is talking to. Address whoever just spoke - can be any person in the conversation."
    );
    $rechatListenerPromptStrict = $db->escape(
        "specify who {DIALECTIC_NAME} is talking to. The listener must be exactly {PREVIOUS_SPEAKER}. Address the person who just spoke."
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rechat_response_prompt_relaxed_1',
            '$rechatResponsePromptRelaxed1',
            'Relaxed rechat cue variant 1. Supports placeholders: {DIALECTIC_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rechat_response_prompt_relaxed_2',
            '$rechatResponsePromptRelaxed2',
            'Relaxed rechat cue variant 2. Supports placeholders: {DIALECTIC_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rechat_response_prompt_relaxed_3',
            '$rechatResponsePromptRelaxed3',
            'Relaxed rechat cue variant 3. Supports placeholders: {DIALECTIC_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    for ($i = 1; $i <= 3; $i++) {
        $db->execQuery("
            INSERT INTO public.prompts (prompt_key, default_prompt, description)
            VALUES (
                'rechat_response_prompt_strict_{$i}',
                '$rechatResponsePromptStrict',
                'Strict rechat cue variant {$i}. Supports placeholders: {DIALECTIC_NAME}, {PREVIOUS_SPEAKER}. Used in: prompts/prompts.php when strict rechat enforcement is enabled.'
            )
            ON CONFLICT (prompt_key) DO UPDATE SET
                default_prompt = EXCLUDED.default_prompt,
                description = EXCLUDED.description,
                updated_at = CURRENT_TIMESTAMP
        ");
    }

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rechat_listener_prompt_relaxed',
            '$rechatListenerPromptRelaxed',
            'Relaxed listener instruction for rechat JSON responses. Supports placeholders: {DIALECTIC_NAME}. Used in: functions/json_response.php.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'rechat_listener_prompt_strict',
            '$rechatListenerPromptStrict',
            'Strict listener instruction for rechat JSON responses. Supports placeholders: {DIALECTIC_NAME}, {PREVIOUS_SPEAKER}. Used in: functions/json_response.php when strict rechat enforcement is enabled.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260502004);
    Logger::info("Applied patch prompts 20260502004 - Added managed rechat strict/relaxed prompts");
}

if ($checkVersion("prompts")<20260611001) {
    Logger::debug("Applying prompts table 20260611001 - Adding player respeech prompts");

    $playerRespeechRewritePrompt = $db->escape(
        "Rewrite dialogue for {PLAYER_NAME}, using this text as source \"{PLAYER_NAME}:{SPEECH}\". Use comments between brackets only as guidance for tone, target, length, and verbosity. If the source includes brief narration or stage business before the spoken line, preserve it as one short third-person narration block in single asterisks before the dialogue. Do not repeat bracketed comments or speaker names in the output."
    );
    $playerRespeechOutputPrompt = $db->escape(
        "Output only the rewritten line. If the source includes brief leading narration, keep at most one short leading narration block in single asterisks before the spoken dialogue. Keep spoken dialogue outside the asterisks. No speaker names. No bracketed comments."
    );
    $playerRespeechRewriteStripPrompt = $db->escape(
        "Rewrite dialogue for {PLAYER_NAME}, using this text as source \"{PLAYER_NAME}:{SPEECH}\". Use comments between brackets only as guidance for tone, target, length, and verbosity. Do not repeat bracketed comments, stage directions, narration, asterisked narration, or speaker names in the output."
    );
    $playerRespeechOutputStripPrompt = $db->escape(
        "Output only the final spoken dialogue line. No narration. No stage directions. No asterisked narration. No speaker names. No bracketed comments."
    );

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'player_respeech_rewrite_prompt',
            '$playerRespeechRewritePrompt',
            'Main player respeech/auto-chat rewrite instruction. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is disabled.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'player_respeech_output_prompt',
            '$playerRespeechOutputPrompt',
            'Player respeech/auto-chat output formatting instruction. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is disabled.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'player_respeech_rewrite_strip_prompt',
            '$playerRespeechRewriteStripPrompt',
            'Main player respeech/auto-chat rewrite instruction for narration-stripping mode. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is enabled.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $db->execQuery("
        INSERT INTO public.prompts (prompt_key, default_prompt, description)
        VALUES (
            'player_respeech_output_strip_prompt',
            '$playerRespeechOutputStripPrompt',
            'Player respeech/auto-chat output formatting instruction for narration-stripping mode. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is enabled.'
        )
        ON CONFLICT (prompt_key) DO UPDATE SET
            default_prompt = EXCLUDED.default_prompt,
            description = EXCLUDED.description,
            updated_at = CURRENT_TIMESTAMP
    ");

    $updateVersion("prompts", 20260611001);
    Logger::info("Applied patch prompts 20260611001 - Added player respeech prompts");
}

//----------------------------------------------------

if ($checkVersion("utterance_delivery") < 20260502001) {
    Logger::debug(" try patch: utterance_delivery 20260502001");
    $b_ok = true;

    try {
        $db->execQuery("ALTER TABLE public.eventlog ADD COLUMN IF NOT EXISTS utterance_id TEXT;");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'eventlog' table (utterance_id): " . $e->getMessage());
    }

    try {
        $db->execQuery("ALTER TABLE public.eventlog ADD COLUMN IF NOT EXISTS delivery_state TEXT;");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'eventlog' table (delivery_state): " . $e->getMessage());
    }

    try {
        $db->execQuery("ALTER TABLE public.speech ADD COLUMN IF NOT EXISTS utterance_id TEXT;");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'speech' table (utterance_id): " . $e->getMessage());
    }

    try {
        $db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_utterance_id ON public.eventlog (utterance_id);");
        $db->execQuery("CREATE INDEX IF NOT EXISTS idx_eventlog_delivery_state ON public.eventlog (delivery_state);");
        $db->execQuery("CREATE INDEX IF NOT EXISTS idx_speech_utterance_id ON public.speech (utterance_id);");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error creating utterance delivery indexes: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("utterance_delivery", 20260502001);
        Logger::info("Applied patch utterance_delivery 20260502001");
    }
}

//----------------------------------------------------

if ($checkVersion("dialectic_chat_event_types") < 20260613001) {
    Logger::debug(" try patch: dialectic_chat_event_types 20260613001");
    $b_ok = true;

    try {
        $db->execQuery("
            UPDATE public.eventlog
               SET type='chat',
                   delivery_state=COALESCE(NULLIF(delivery_state, ''), 'emitted')
             WHERE type='dialectic_ai_response'
        ");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error normalizing Dialectic AI response event types: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("dialectic_chat_event_types", 20260613001);
        Logger::info("Applied patch dialectic_chat_event_types 20260613001");
    }
}

//----------------------------------------------------

if ($checkVersion("general_settings") < 20260502002) {
    Logger::debug("Applying general_settings 20260502002 - create database-backed general settings table");
    $b_ok = true;

    try {
        if ($checkTableExists("general_settings") == -1) {
            $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/general_settings.sql"));
            $db->execQuery("SET search_path TO public");
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error ensuring 'general_settings' table: " . $e->getMessage());
    }

    try {
        $db->execQuery("ALTER TABLE public.general_settings ADD COLUMN IF NOT EXISTS description TEXT DEFAULT '';");
        $db->execQuery("ALTER TABLE public.general_settings ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'general_settings' table: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260502002);
        Logger::info("Applied patch general_settings 20260502002");
    }
}

//----------------------------------------------------

if ($checkVersion("core_stt_connector") < 20260502002) {
    Logger::debug("Applying core_stt_connector 20260502002 - add api badge and URL support");
    $b_ok = true;

    try {
        if ($checkTableExists("core_stt_connector") == -1) {
            $db->execQuery(file_get_contents(__DIR__."/../lib/core/database_schema/core_stt_connector.sql"));
            $db->execQuery("SET search_path TO public");
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error ensuring 'core_stt_connector' table: " . $e->getMessage());
    }

    try {
        $db->execQuery("ALTER TABLE public.core_stt_connector ADD COLUMN IF NOT EXISTS api_badge_id integer;");
        $db->execQuery("ALTER TABLE public.core_stt_connector ADD COLUMN IF NOT EXISTS url text;");
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error altering 'core_stt_connector' table: " . $e->getMessage());
    }

    try {
        $fkExists = $db->fetchAll("
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'stt_connector_api_badge_id_fkey'
            LIMIT 1
        ");
        if (!$fkExists) {
            $db->execQuery("
                ALTER TABLE public.core_stt_connector
                ADD CONSTRAINT stt_connector_api_badge_id_fkey
                FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id)
            ");
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error ensuring 'core_stt_connector' FK: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_stt_connector", 20260502002);
        Logger::info("Applied patch core_stt_connector 20260502002");
    }
}

if ($checkVersion("general_settings") < 20260502003) {
    Logger::debug("Applying general_settings 20260502003 - seed managed global settings");
    $b_ok = true;

    try {
        $seedResult = dialecticSeedMissingManagedGeneralSettings();
        if (!empty($seedResult['missing'])) {
            throw new Exception('Failed writing managed general settings: ' . implode(', ', $seedResult['missing']));
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error seeding global settings: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260502003);
        Logger::info("Applied patch general_settings 20260502003");
    }
}

if ($checkVersion("general_settings") < 20260502004) {
    Logger::debug("Applying general_settings 20260502004 - add strict rechat response setting");
    $b_ok = true;

    try {
        $settingId = 'ENFORCE_STRICT_RECHAT_RESPONSE';
        $definition = dialecticGetSchemaDefinition($settingId);
        $currentValue = $definition['default'] ?? false;
        $description = dialecticGetManagedGeneralSettingDescriptions()[$settingId] ?? dialecticGetSchemaDescription($settingId);

        if (!dialecticSetGeneralSetting($settingId, $currentValue, $description)) {
            throw new Exception("Failed writing general setting '{$settingId}'");
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error adding strict rechat response setting: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260502004);
        Logger::info("Applied patch general_settings 20260502004");
    }
}

//----------------------------------------------------

if ($checkVersion("general_settings") < 20260502005) {
    Logger::debug("Applying general_settings 20260502005 - add prompt context options setting");
    $b_ok = true;

    try {
        $settingId = 'PROMPT_CONTEXT_OPTIONS';
        $definition = dialecticGetSchemaDefinition($settingId);
        $currentValue = dialecticGetDefaultPromptContextOptions();
        $description = dialecticGetManagedGeneralSettingDescriptions()[$settingId] ?? dialecticGetSchemaDescription($settingId);

        if (!dialecticSetGeneralSetting($settingId, $currentValue, $description)) {
            throw new Exception("Failed writing general setting '{$settingId}'");
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error adding prompt context options setting: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260502005);
        Logger::info("Applied patch general_settings 20260502005");
    }
}

//----------------------------------------------------

//----------------------------------------------------

//----------------------------------------------------

if ($checkVersion("general_settings") < 20260511001) {
    Logger::debug("Applying general_settings 20260511001 - ensure rechat mode defaults to random for new installs");
    $b_ok = true;

    try {
        $settingId = 'RECHAT_MODE';
        $existingRow = dialecticGetGeneralSettingRow($settingId);

        if (!$existingRow) {
            $definition = dialecticGetSchemaDefinition($settingId);
            $currentValue = $definition['default'] ?? 'random';

            $description = dialecticGetManagedGeneralSettingDescriptions()[$settingId] ?? dialecticGetSchemaDescription($settingId);
            if (!dialecticSetGeneralSetting($settingId, $currentValue, $description)) {
                throw new Exception("Failed writing general setting '{$settingId}'");
            }
        }
    } catch (Exception $e) {
        $b_ok = false;
        Logger::error("Error ensuring default rechat mode setting: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260511001);
        Logger::info("Applied patch general_settings 20260511001");
    }
}

//----------------------------------------------------

if ($checkVersion("core_action") < 20260610001) {
    Logger::debug("Applying core_action 20260610001 - make MoveTo actor-only");

    $db->execQuery("
        UPDATE public.core_action
           SET description = 'Move to a visible nearby actor or NPC. Use TravelTo for places, buildings, cities, doors, or locations.',
               return_message = '#DIALECTIC_NAME# moves to #TARGET#.',
               parameters_json = '{\"type\":\"object\",\"required\":[\"target\"],\"properties\":{\"target\":{\"enum\":[],\"type\":\"string\",\"description\":\"Visible nearby target NPC, actor, or being. Do not use this for places, buildings, cities, doors, or locations.\"}}}'::jsonb,
               updated_at = NOW()
         WHERE code_name = 'MoveTo'
    ");

    $db->execQuery("
        UPDATE public.core_action_custom
           SET description = 'Move to a visible nearby actor or NPC. Use TravelTo for places, buildings, cities, doors, or locations.',
               return_message = '#DIALECTIC_NAME# moves to #TARGET#.',
               parameters_json = '{\"type\":\"object\",\"required\":[\"target\"],\"properties\":{\"target\":{\"enum\":[],\"type\":\"string\",\"description\":\"Visible nearby target NPC, actor, or being. Do not use this for places, buildings, cities, doors, or locations.\"}}}'::jsonb,
               updated_at = NOW()
         WHERE code_name = 'MoveTo'
           AND (
                description = 'Move to a visible building or visible actor, also used to guide #PLAYER_NAME# to an actor or building.'
                OR return_message = 'Walk to a visible building or visible actor, also used to guide #PLAYER_NAME# to an actor or building.'
           )
    ");

    $updateVersion("core_action", 20260610001);
    Logger::info("Applied patch core_action 20260610001");
}

if ($checkVersion("core_action") < 20260610002) {
    Logger::debug("Applying core_action 20260610002 - clarify TravelTo long-distance targets");

    $db->execQuery("
        UPDATE public.core_action
           SET description = 'Travel long distance to a building, city, door or other location. Also known as lead the way.',
               parameters_json = '{\"type\":\"object\",\"required\":[\"location\"],\"properties\":{\"location\":{\"type\":\"string\",\"description\":\"Building, city, door, or other location to travel to.\"}}}'::jsonb,
               updated_at = NOW()
         WHERE code_name = 'TravelTo'
    ");

    $db->execQuery("
        UPDATE public.core_action_custom
           SET description = 'Travel long distance to a building, city, door or other location. Also known as lead the way.',
               parameters_json = '{\"type\":\"object\",\"required\":[\"location\"],\"properties\":{\"location\":{\"type\":\"string\",\"description\":\"Building, city, door, or other location to travel to.\"}}}'::jsonb,
               updated_at = NOW()
         WHERE code_name = 'TravelTo'
           AND (
                description = 'Only use if #PLAYER_NAME# explicitly suggests it. Guide #PLAYER_NAME# to a town or city. Also known as lead the way.'
                OR description = 'Use it to move to major locations and landmarks and POIs.'
           )
    ");

    $updateVersion("core_action", 20260610002);
    Logger::info("Applied patch core_action 20260610002");
}

if ($checkVersion("core_action") < 20260617001) {
    Logger::debug("Applying core_action 20260617001 - restrict built-in actions to canonical Dialectic FNV set");

    $b_ok = true;
    try {
        $seedPath = realpath(__DIR__ . '/../data/core_action_seed.sql');
        if ($seedPath === false || !is_file($seedPath)) {
            throw new RuntimeException("Missing core_action seed file");
        }

        $seedSql = trim(strval(file_get_contents($seedPath)));
        if ($seedSql === '') {
            throw new RuntimeException("Empty core_action seed file");
        }

        $db->execQuery($seedSql);
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error applying canonical Dialectic FNV action seed: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_action", 20260617001);
        Logger::info("Applied patch core_action 20260617001");
    }
}

if ($checkVersion("core_action") < 20260624003) {
    Logger::debug("Applying core_action 20260624003 - refresh inventory/barter action seed");

    $b_ok = true;
    try {
        $seedPath = realpath(__DIR__ . '/../data/core_action_seed.sql');
        if ($seedPath === false || !is_file($seedPath)) {
            throw new RuntimeException("Missing core_action seed file");
        }

        $seedSql = trim(strval(file_get_contents($seedPath)));
        if ($seedSql === '') {
            throw new RuntimeException("Empty core_action seed file");
        }

        $db->execQuery($seedSql);
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error applying inventory/barter action seed: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_action", 20260624003);
        Logger::info("Applied patch core_action 20260624003");
    }
}

if ($checkVersion("core_profiles") < 20260717001) {
    Logger::debug("Applying core_profiles 20260717001 - restore profile diary generation settings");
    try {
        $diaryDefaults = [
            'DIARY_PROMPT' => "Please write a short summary of #PLAYER_NAME# and #DIALECTIC_NAME#'s recent dialogues and events into #DIALECTIC_NAME#'s diary. WRITE AS IF YOU WERE #DIALECTIC_NAME#. Start the diary entry with the current date and time.",
            'DIARY_COOLDOWN' => 120,
            'CONTEXT_HISTORY_DIARY' => 100,
        ];

        $rows = $db->fetchAll("SELECT id, metadata FROM public.core_profiles ORDER BY id ASC");
        foreach ($rows as $row) {
            $profileId = intval($row['id'] ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            $metadataRaw = $row['metadata'] ?? '{}';
            $metadata = is_array($metadataRaw) ? $metadataRaw : json_decode(strval($metadataRaw), true);
            if (!is_array($metadata)) {
                $metadata = [];
            }
            foreach ($diaryDefaults as $key => $value) {
                if (!array_key_exists($key, $metadata)) {
                    $metadata[$key] = $value;
                }
            }

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $metadataLiteral = $db->escapeLiteral(is_string($metadataJson) ? $metadataJson : '{}');
            $db->execQuery("UPDATE public.core_profiles SET metadata = {$metadataLiteral}::jsonb WHERE id = {$profileId}");
        }

        $updateVersion("core_profiles", 20260717001);
        Logger::info("Applied patch core_profiles 20260717001 - restored profile diary generation settings");
    } catch (Throwable $e) {
        Logger::error("Error restoring profile diary settings: " . $e->getMessage());
    }
}

if ($checkVersion("core_action") < 20260716002) {
    Logger::debug("Applying core_action 20260716002 - add Fallout narrator actions without protected kill targets");

    $b_ok = true;
    try {
        $seedPath = realpath(__DIR__ . '/../data/core_action_seed.sql');
        if ($seedPath === false || !is_file($seedPath)) {
            throw new RuntimeException("Missing core_action seed file");
        }

        $seedSql = trim(strval(file_get_contents($seedPath)));
        if ($seedSql === '') {
            throw new RuntimeException("Empty core_action seed file");
        }

        $db->execQuery($seedSql);
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error applying Fallout narrator action seed: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_action", 20260716002);
        Logger::info("Applied patch core_action 20260716002");
    }
}

if ($checkVersion("core_action") < 20260719001) {
    Logger::debug("Applying core_action 20260719001 - add equipment actions");

    $b_ok = true;
    try {
        $seedPath = realpath(__DIR__ . '/../data/core_action_seed.sql');
        if ($seedPath === false || !is_file($seedPath)) {
            throw new RuntimeException("Missing core_action seed file");
        }

        $seedSql = trim(strval(file_get_contents($seedPath)));
        if ($seedSql === '') {
            throw new RuntimeException("Empty core_action seed file");
        }

        $db->execQuery($seedSql);
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error applying equipment action seed: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_action", 20260719001);
        Logger::info("Applied patch core_action 20260719001");
    }
}

if ($checkVersion("core_tts_connector_metadata") < 20260626001) {
    Logger::debug("Applying core_tts_connector_metadata 20260626001 - remove copied connector metadata references");

    $b_ok = true;
    try {
        $db->execQuery("
            UPDATE public.core_tts_connector
            SET metadata = regexp_replace(
                    replace(metadata::text, '/DialecticServer/ui/xtts_clone.php', 'xtts_clone.php'),
                    'If you want to use [^\".]+ XTTS then click\\[HOST PC IP\\]\\. \\[WSL IP\\] will set it back to point to DIALECTIC XTTS\\.',
                    'If you want to use a host-machine XTTS server then click[HOST PC IP]. [WSL IP] will set it back to point to DIALECTIC XTTS.',
                    'g'
                )::jsonb
            WHERE metadata::text LIKE '%/DialecticServer/ui/xtts_clone.php%'
               OR metadata::text LIKE '%XTTS then click[HOST PC IP]%'
        ");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error removing copied TTS connector metadata references: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_tts_connector_metadata", 20260626001);
        Logger::info("Applied patch core_tts_connector_metadata 20260626001");
    }
}

if ($checkVersion("prompts") < 20260627001) {
    Logger::debug("Applying prompts 20260627001 - rewrite retired HERIKA_NAME placeholders");

    $b_ok = true;
    try {
        $db->execQuery("
            UPDATE public.prompts
               SET default_prompt = replace(replace(default_prompt, '#HERIKA_NAME#', '#DIALECTIC_NAME#'), '{HERIKA_NAME}', '{DIALECTIC_NAME}'),
                   custom_prompt = CASE
                       WHEN custom_prompt IS NULL THEN NULL
                       ELSE replace(replace(custom_prompt, '#HERIKA_NAME#', '#DIALECTIC_NAME#'), '{HERIKA_NAME}', '{DIALECTIC_NAME}')
                   END,
                   updated_at = CURRENT_TIMESTAMP
             WHERE default_prompt LIKE '%HERIKA_NAME%'
                OR custom_prompt LIKE '%HERIKA_NAME%'
        ");

        $db->execQuery("
            UPDATE public.general_settings
               SET value = replace(replace(value, '#HERIKA_NAME#', '#DIALECTIC_NAME#'), '{HERIKA_NAME}', '{DIALECTIC_NAME}')
             WHERE id = 'PROMPT_HEAD'
               AND value LIKE '%HERIKA_NAME%'
        ");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error rewriting retired HERIKA_NAME placeholders: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("prompts", 20260627001);
        Logger::info("Applied patch prompts 20260627001");
    }
}

if ($checkVersion("legacy_translation_tables_cleanup") < 20260628001) {
    Logger::debug("Applying legacy_translation_tables_cleanup 20260628001 - drop unused legacy translation/template tables");

    $b_ok = true;
    try {
        $legacyTables = [
            'npc_templates_trl',
            'npc_templates',
            'books_trl',
            'books',
            'player_bio',
        ];

        foreach ($legacyTables as $legacyTable) {
            $db->execQuery("DROP TABLE IF EXISTS public.\"{$legacyTable}\" CASCADE");
        }

        $trlRows = $db->fetchAll("
            SELECT table_name
              FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_type = 'BASE TABLE'
               AND table_name LIKE '%\\_trl' ESCAPE '\\'
        ");

        foreach ($trlRows as $trlRow) {
            $trlTable = (string)($trlRow['table_name'] ?? '');
            if ($trlTable === '') {
                continue;
            }
            $escapedTable = str_replace('"', '""', $trlTable);
            $db->execQuery("DROP TABLE IF EXISTS public.\"{$escapedTable}\" CASCADE");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error dropping unused legacy translation/template tables: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("legacy_translation_tables_cleanup", 20260628001);
        Logger::info("Applied patch legacy_translation_tables_cleanup 20260628001");
    }
}

if ($checkVersion("legacy_quest_tables_cleanup") < 20260628001) {
    Logger::debug("Applying legacy_quest_tables_cleanup 20260628001 - drop unused legacy quest template tables");

    $b_ok = true;
    try {
        $legacyQuestTables = [
            'quest_item_types',
            'quest_npc_own_templates',
            'quest_npc_templates',
            'quest_outfits',
        ];

        foreach ($legacyQuestTables as $legacyTable) {
            $db->execQuery("DROP TABLE IF EXISTS public.\"{$legacyTable}\" CASCADE");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error dropping unused legacy quest template tables: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("legacy_quest_tables_cleanup", 20260628001);
        Logger::info("Applied patch legacy_quest_tables_cleanup 20260628001");
    }
}

if ($checkVersion("core_tts_connector_omnivoice") < 20260708001) {
    Logger::debug("Applying core_tts_connector_omnivoice 20260708001 - add OmniVoice default connector");

    $b_ok = true;
    try {
        $db->execQuery("
            INSERT INTO public.core_tts_connector (driver, label, metadata, api_badge_id, url, voice_field)
            SELECT
                'omnivoice',
                'OmniVoice Default',
                '{\"language\":\"en\",\"voicelogic\":\"voicetype\",\"fallback_male\":\"maleadult02\",\"fallback_female\":\"femaleadult02\"}'::jsonb,
                NULL,
                'http://127.0.0.1:8021',
                'voiceid'
            WHERE NOT EXISTS (
                SELECT 1
                  FROM public.core_tts_connector
                 WHERE lower(coalesce(label, '')) = 'omnivoice default'
            )
        ");

        $db->execQuery("
            UPDATE public.core_tts_connector
               SET driver = 'omnivoice',
                   url = 'http://127.0.0.1:8021',
                   voice_field = 'voiceid',
                   metadata = COALESCE(metadata, '{}'::jsonb) || '{\"language\":\"en\",\"voicelogic\":\"voicetype\",\"fallback_male\":\"maleadult02\",\"fallback_female\":\"femaleadult02\"}'::jsonb
             WHERE lower(coalesce(label, '')) = 'omnivoice default'
        ");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error adding OmniVoice default TTS connector: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_tts_connector_omnivoice", 20260708001);
        Logger::info("Applied patch core_tts_connector_omnivoice 20260708001");
    }
}

if ($checkVersion("core_tts_connector_removed_drivers") < 20260712001) {
    Logger::debug("Applying core_tts_connector_removed_drivers 20260712001 - remove unsupported TTS drivers");

    $b_ok = true;
    try {
        $removedDrivers = "'melotts','xvasynth','mimic3','azure','openai','koboldcpp','zonos_gradio','deepgram'";

        $db->execQuery("
            UPDATE public.core_profiles
               SET tts_connector_id = (
                   SELECT id
                     FROM public.core_tts_connector
                    WHERE lower(driver) = 'pockettts'
                    ORDER BY id
                    LIMIT 1
               )
             WHERE tts_connector_id IN (
                   SELECT id
                     FROM public.core_tts_connector
                    WHERE lower(driver) IN ({$removedDrivers})
             )
        ");

        $db->execQuery("
            UPDATE public.core_player
               SET value = COALESCE((
                   SELECT id::text
                     FROM public.core_tts_connector
                    WHERE lower(driver) = 'pockettts'
                    ORDER BY id
                    LIMIT 1
               ), '')
             WHERE id = 'tts_connector_id'
               AND value IN (
                   SELECT id::text
                     FROM public.core_tts_connector
                    WHERE lower(driver) IN ({$removedDrivers})
               )
        ");

        $db->execQuery("
            DELETE FROM public.core_tts_connector
             WHERE lower(driver) IN ({$removedDrivers})
        ");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error removing unsupported TTS drivers: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("core_tts_connector_removed_drivers", 20260712001);
        Logger::info("Applied patch core_tts_connector_removed_drivers 20260712001");
    }
}

$promptManagerDefaultsRow = $db->fetchOne("
    SELECT COUNT(*) AS total
      FROM public.prompts
     WHERE prompt_key IN (
        'dialectic_system_prompt',
        'dialectic_response_rules',
        'dialectic_world_prompt',
        'dialectic_scene_prompt',
        'dialectic_memory_prompt'
     )
");
$promptManagerDefaultsMissing = intval($promptManagerDefaultsRow['total'] ?? 0) !== 5;

if ($checkVersion("prompt_manager_defaults") < 20260713002 || $promptManagerDefaultsMissing) {
    Logger::debug("Applying prompt_manager_defaults 20260713002 - seed Dialectic prompt manager defaults");

    $b_ok = true;
    try {
        dialectic_seed_prompt_manager_defaults($db);
        $promptCount = $db->fetchOne("
            SELECT COUNT(*) AS total
              FROM public.prompts
             WHERE prompt_key IN (
                'dialectic_system_prompt',
                'dialectic_response_rules',
                'dialectic_world_prompt',
                'dialectic_scene_prompt',
                'dialectic_memory_prompt'
             )
        ");
        if (intval($promptCount['total'] ?? 0) !== 5) {
            throw new RuntimeException("Dialectic prompt manager defaults were not seeded completely");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error seeding Dialectic prompt manager defaults: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("prompt_manager_defaults", 20260713002);
        Logger::info("Applied patch prompt_manager_defaults 20260713002");
    }
}

$dialecticNpcsViewRow = $db->fetchOne("
    SELECT COUNT(*) AS total
      FROM information_schema.views
     WHERE table_schema = 'public'
       AND table_name = 'dialecticnpcs'
");
$dialecticNpcsViewMissing = intval($dialecticNpcsViewRow['total'] ?? 0) !== 1;

if ($checkVersion("dialecticnpcs_view") < 20260713002 || $dialecticNpcsViewMissing) {
    Logger::debug("Applying dialecticnpcs_view 20260713002 - create canonical NPC compatibility view");

    $b_ok = true;
    try {
        dialectic_ensure_dialecticnpcs_view($db);
        $viewRow = $db->fetchOne("
            SELECT COUNT(*) AS total
              FROM information_schema.views
             WHERE table_schema = 'public'
               AND table_name = 'dialecticnpcs'
        ");
        if (intval($viewRow['total'] ?? 0) !== 1) {
            throw new RuntimeException("public.dialecticnpcs was not created");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error creating public.dialecticnpcs: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("dialecticnpcs_view", 20260713002);
        Logger::info("Applied patch dialecticnpcs_view 20260713002");
    }
}

$profileDefaultsRow = $db->fetchOne("
    SELECT
        COUNT(*) FILTER (WHERE default_npc = '1') AS npc_defaults,
        COUNT(*) FILTER (WHERE default_narrator = '1') AS narrator_defaults
      FROM public.core_profiles
");
$profileDefaultsInvalid = (
    intval($profileDefaultsRow['npc_defaults'] ?? 0) !== 1 ||
    intval($profileDefaultsRow['narrator_defaults'] ?? 0) !== 1
);

if ($checkVersion("profile_defaults") < 20260713002 || $profileDefaultsInvalid) {
    Logger::debug("Applying profile_defaults 20260713002 - normalize default profile ownership");

    $b_ok = true;
    try {
        dialectic_ensure_profile_defaults($db);
        $profileRow = $db->fetchOne("
            SELECT
                COUNT(*) FILTER (WHERE default_npc = '1') AS npc_defaults,
                COUNT(*) FILTER (WHERE default_narrator = '1') AS narrator_defaults
              FROM public.core_profiles
        ");
        if (intval($profileRow['npc_defaults'] ?? 0) !== 1 || intval($profileRow['narrator_defaults'] ?? 0) !== 1) {
            throw new RuntimeException("Default NPC and narrator profiles were not normalized");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error normalizing default profiles: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("profile_defaults", 20260713002);
        Logger::info("Applied patch profile_defaults 20260713002");
    }
}

$playthroughMetadataRow = $db->fetchOne("
    SELECT
        to_regclass('dialectic_meta.playthrough_profiles')::text AS profiles_relation,
        to_regclass('dialectic_meta.settings')::text AS settings_relation,
        (SELECT pg_get_functiondef(p.oid)
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE n.nspname = 'dialectic_meta'
            AND p.proname = 'clone_schema'
          LIMIT 1) AS clone_function_definition,
        (SELECT COUNT(DISTINCT p.proname)
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE n.nspname = 'dialectic_meta'
            AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size')) AS clone_functions
");
$playthroughMetadataIncomplete = (
    empty($playthroughMetadataRow['profiles_relation']) ||
    empty($playthroughMetadataRow['settings_relation']) ||
    intval($playthroughMetadataRow['clone_functions'] ?? 0) !== 3 ||
    stripos((string)($playthroughMetadataRow['clone_function_definition'] ?? ''), 'sync_schema_sequences(dest_schema)') === false
);

if ($checkVersion("playthrough_metadata_schema") < 20260730001 || $playthroughMetadataIncomplete) {
    Logger::debug("Applying playthrough_metadata_schema 20260730001 - refresh clone functions and repair sequences");

    $b_ok = true;
    try {
        $metadataPath = __DIR__ . "/../lib/core/database_schema/playthrough_metadata.sql";
        $cloneFunctionsPath = __DIR__ . "/../lib/schema_clone_function.sql";
        foreach ([$metadataPath, $cloneFunctionsPath] as $sqlPath) {
            $sql = file_get_contents($sqlPath);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("Missing or empty playthrough schema file: {$sqlPath}");
            }
            if (!$db->execQuery($sql)) {
                throw new RuntimeException("Failed to execute playthrough schema file: {$sqlPath}");
            }
        }
        if (!$db->execQuery("SELECT dialectic_meta.sync_schema_sequences('public')")) {
            throw new RuntimeException("Failed to repair public schema sequences");
        }

        $metadataRow = $db->fetchOne("
            SELECT
                to_regclass('dialectic_meta.playthrough_profiles')::text AS profiles_relation,
                to_regclass('dialectic_meta.settings')::text AS settings_relation
        ");
        $functionRow = $db->fetchOne("
            SELECT COUNT(DISTINCT p.proname) AS clone_functions
              FROM pg_proc p
              JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'dialectic_meta'
               AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size')
        ");
        if (
            empty($metadataRow['profiles_relation']) ||
            empty($metadataRow['settings_relation']) ||
            intval($functionRow['clone_functions'] ?? 0) !== 3
        ) {
            throw new RuntimeException("Playthrough metadata schema verification failed");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error creating playthrough metadata schema: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("playthrough_metadata_schema", 20260730001);
        Logger::info("Applied patch playthrough_metadata_schema 20260730001");
    }
}

$relationshipQueueRow = $db->fetchOne("
    SELECT COUNT(*) AS total
      FROM information_schema.tables
     WHERE table_schema = 'public'
       AND table_name IN ('relationship_eval_queue', 'relationship_init_queue')
");
$relationshipQueueColumnRow = $db->fetchOne("
    SELECT COUNT(*) AS total
      FROM information_schema.columns
     WHERE table_schema = 'public'
       AND table_name IN ('relationship_eval_queue', 'relationship_init_queue')
       AND column_name IN ('retry_count', 'last_error')
");
$relationshipQueuesIncomplete = intval($relationshipQueueRow['total'] ?? 0) !== 2
    || intval($relationshipQueueColumnRow['total'] ?? 0) !== 4;

if ($checkVersion("relationship_async_queues") < 20260713002 || $relationshipQueuesIncomplete) {
    Logger::debug("Applying relationship_async_queues 20260713002 - create relationship worker queues");

    $b_ok = true;
    try {
        $queueSchemaPath = __DIR__ . "/../lib/core/database_schema/relationship_async_queues.sql";
        $queueSchema = file_get_contents($queueSchemaPath);
        if ($queueSchema === false || trim($queueSchema) === '') {
            throw new RuntimeException("Missing or empty relationship queue schema: {$queueSchemaPath}");
        }
        if (!$db->execQuery($queueSchema)) {
            throw new RuntimeException("Failed to execute relationship queue schema");
        }

        $queueRow = $db->fetchOne("
            SELECT
                (SELECT COUNT(*)
                   FROM information_schema.tables
                  WHERE table_schema = 'public'
                    AND table_name IN ('relationship_eval_queue', 'relationship_init_queue')) AS table_total,
                (SELECT COUNT(*)
                   FROM information_schema.columns
                  WHERE table_schema = 'public'
                    AND table_name IN ('relationship_eval_queue', 'relationship_init_queue')
                    AND column_name IN ('retry_count', 'last_error')) AS column_total
        ");
        if (intval($queueRow['table_total'] ?? 0) !== 2 || intval($queueRow['column_total'] ?? 0) !== 4) {
            throw new RuntimeException("Relationship queue schema verification failed");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error creating relationship worker queues: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("relationship_async_queues", 20260713002);
        Logger::info("Applied patch relationship_async_queues 20260713002");
    }
}

$currentMissionRow = $db->fetchOne("SELECT to_regclass('public.currentmission')::text AS relation_name");
$currentMissionExists = !empty($currentMissionRow['relation_name']);

if ($checkVersion("legacy_currentmission_cleanup") < 20260713003 || $currentMissionExists) {
    Logger::debug("Applying legacy_currentmission_cleanup 20260713003 - remove unused currentmission compatibility table");

    $b_ok = true;
    try {
        $db->execQuery("DROP TABLE IF EXISTS public.currentmission CASCADE");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error removing legacy currentmission table: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("legacy_currentmission_cleanup", 20260713003);
        Logger::info("Applied patch legacy_currentmission_cleanup 20260713003");
    }
}

//----------------------------------------------------

if ($checkVersion("general_settings") < 20260722001) {
    Logger::debug("Applying general_settings 20260722001 - add forced location World Knowledge setting");

    $b_ok = true;
    try {
        $settingId = 'LOCATION_WORLDKNOWLEDGE';
        $existingRow = dialecticGetGeneralSettingRow($settingId);
        $definition = dialecticGetSchemaDefinition($settingId);
        $description = dialecticGetManagedGeneralSettingDescriptions()[$settingId]
            ?? dialecticGetSchemaDescription($settingId);

        if ($existingRow) {
            $currentValue = $existingRow['value'] ?? ($definition['default'] ?? true);
        } else {
            $legacyValue = dialecticReadLegacyGlobalValue($settingId, '__DIALECTIC_SETTING_MISSING__');
            $currentValue = ($legacyValue === '__DIALECTIC_SETTING_MISSING__')
                ? ($definition['default'] ?? true)
                : $legacyValue;
        }

        if (!dialecticSetGeneralSetting($settingId, $currentValue, $description)) {
            throw new RuntimeException("Failed writing {$settingId}");
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error adding forced location World Knowledge setting: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings", 20260722001);
        Logger::info("Applied patch general_settings 20260722001");
    }
}

//----------------------------------------------------

$managedGeneralSettingIds = array_values(array_unique(dialecticGetManagedGeneralSettingIds()));
$managedGeneralSettingLiterals = implode(',', array_map(
    static fn(string $settingId): string => $db->escapeLiteral($settingId),
    $managedGeneralSettingIds
));
$managedGeneralSettingRow = $db->fetchOne(
    "SELECT COUNT(DISTINCT id) AS total FROM public.general_settings WHERE id IN ({$managedGeneralSettingLiterals})"
);
$managedGeneralSettingsComplete = intval($managedGeneralSettingRow['total'] ?? 0) === count($managedGeneralSettingIds);

if ($checkVersion("general_settings_seed_repair") < 20260713004 || !$managedGeneralSettingsComplete) {
    Logger::debug("Applying general_settings_seed_repair 20260713004 - restore missing managed settings");

    $b_ok = true;
    try {
        $seedResult = dialecticSeedMissingManagedGeneralSettings();
        if (!empty($seedResult['missing'])) {
            throw new RuntimeException('Managed general settings remain missing: ' . implode(', ', $seedResult['missing']));
        }
        Logger::info(
            'Managed general settings repaired: inserted=' . intval($seedResult['inserted'] ?? 0) .
            ', normalized=' . intval($seedResult['normalized'] ?? 0)
        );
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error repairing managed general settings: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("general_settings_seed_repair", 20260713004);
        Logger::info("Applied patch general_settings_seed_repair 20260713004");
    }
}

if ($checkVersion("tts_gender_fallback_defaults") < 20260715001) {
    Logger::debug("Applying tts_gender_fallback_defaults 20260715001 - use Fallout adult voice defaults");

    $b_ok = true;
    try {
        $db->execQuery("
            UPDATE public.core_tts_connector
               SET metadata = jsonb_set(
                   COALESCE(metadata, '{}'::jsonb),
                   '{fallback_male}',
                   '\"maleadult02\"'::jsonb,
                   true
               )
             WHERE lower(trim(COALESCE(metadata->>'fallback_male', ''))) IN ('', 'default_male')
        ");
        $db->execQuery("
            UPDATE public.core_tts_connector
               SET metadata = jsonb_set(
                   COALESCE(metadata, '{}'::jsonb),
                   '{fallback_female}',
                   '\"femaleadult02\"'::jsonb,
                   true
               )
             WHERE lower(trim(COALESCE(metadata->>'fallback_female', ''))) IN ('', 'default_female')
        ");
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error updating TTS gender fallback defaults: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("tts_gender_fallback_defaults", 20260715001);
        Logger::info("Applied patch tts_gender_fallback_defaults 20260715001");
    }
}

$npcJsonObjectState = $db->fetchOne("
    SELECT
        (SELECT COUNT(*) FROM public.core_npc_master
          WHERE (extended_data IS NOT NULL AND jsonb_typeof(extended_data) <> 'object')
             OR (metadata IS NOT NULL AND jsonb_typeof(metadata) <> 'object'))
      + (SELECT COUNT(*) FROM public.core_npc_master_history
          WHERE (extended_data IS NOT NULL AND jsonb_typeof(extended_data) <> 'object')
             OR (metadata IS NOT NULL AND jsonb_typeof(metadata) <> 'object')) AS invalid_rows
");
$npcJsonObjectsInvalid = intval($npcJsonObjectState['invalid_rows'] ?? 0) > 0;

if ($checkVersion("npc_json_object_normalization") < 20260717002 || $npcJsonObjectsInvalid) {
    Logger::debug("Applying npc_json_object_normalization 20260717002 - normalize NPC JSON object fields");

    $b_ok = true;
    try {
        foreach (['core_npc_master', 'core_npc_master_history'] as $table) {
            if (!$db->execQuery("
                UPDATE public.{$table}
                   SET extended_data = '{}'::jsonb
                 WHERE extended_data IS NOT NULL
                   AND jsonb_typeof(extended_data) <> 'object'
            ")) {
                throw new RuntimeException("Failed normalizing {$table}.extended_data");
            }
            if (!$db->execQuery("
                UPDATE public.{$table}
                   SET metadata = '{}'::jsonb
                 WHERE metadata IS NOT NULL
                   AND jsonb_typeof(metadata) <> 'object'
            ")) {
                throw new RuntimeException("Failed normalizing {$table}.metadata");
            }
        }

        $remaining = $db->fetchOne("
            SELECT
                (SELECT COUNT(*) FROM public.core_npc_master
                  WHERE (extended_data IS NOT NULL AND jsonb_typeof(extended_data) <> 'object')
                     OR (metadata IS NOT NULL AND jsonb_typeof(metadata) <> 'object'))
              + (SELECT COUNT(*) FROM public.core_npc_master_history
                  WHERE (extended_data IS NOT NULL AND jsonb_typeof(extended_data) <> 'object')
                     OR (metadata IS NOT NULL AND jsonb_typeof(metadata) <> 'object')) AS invalid_rows
        ");
        if (intval($remaining['invalid_rows'] ?? 0) !== 0) {
            throw new RuntimeException('NPC JSON object verification failed');
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error normalizing NPC JSON object fields: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("npc_json_object_normalization", 20260717002);
        Logger::info("Applied patch npc_json_object_normalization 20260717002");
    }
}

if ($checkVersion("worldknowledge") < 20260720001) {
    Logger::debug("Applying worldknowledge 20260720001 - support unique basic-only Fallout lore");

    $b_ok = true;
    try {
        if (!$db->execQuery("DELETE FROM public.worldknowledge WHERE topic IS NULL OR btrim(topic) = ''")) {
            throw new RuntimeException('Failed removing invalid world knowledge topics');
        }

        if (!$db->execQuery("
            DELETE FROM public.worldknowledge current_row
            USING (
                SELECT ctid
                FROM (
                    SELECT
                        ctid,
                        row_number() OVER (
                            PARTITION BY lower(btrim(topic))
                            ORDER BY
                                CASE WHEN topic_desc IS NOT NULL AND btrim(topic_desc) <> '' THEN 1 ELSE 0 END DESC,
                                CASE WHEN topic_desc_basic IS NOT NULL AND btrim(topic_desc_basic) <> '' THEN 1 ELSE 0 END DESC,
                                CASE WHEN knowledge_class IS NOT NULL AND btrim(knowledge_class) <> '' THEN 1 ELSE 0 END DESC,
                                CASE WHEN knowledge_class_basic IS NOT NULL AND btrim(knowledge_class_basic) <> '' THEN 1 ELSE 0 END DESC,
                                CASE WHEN tags IS NOT NULL AND btrim(tags) <> '' THEN 1 ELSE 0 END DESC,
                                CASE WHEN category IS NOT NULL AND btrim(category) <> '' THEN 1 ELSE 0 END DESC,
                                ctid
                        ) AS row_number
                    FROM public.worldknowledge
                ) ranked
                WHERE row_number > 1
            ) duplicates
            WHERE current_row.ctid = duplicates.ctid
        ")) {
            throw new RuntimeException('Failed deduplicating world knowledge topics');
        }

        if (!$db->execQuery("UPDATE public.worldknowledge SET topic = lower(btrim(topic))")) {
            throw new RuntimeException('Failed normalizing world knowledge topic keys');
        }
        if (!$db->execQuery("ALTER TABLE public.worldknowledge ALTER COLUMN topic_desc DROP NOT NULL")) {
            throw new RuntimeException('Failed making the advanced world knowledge description optional');
        }
        if (!$db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS worldknowledge_topic_unique_idx ON public.worldknowledge (topic)")) {
            throw new RuntimeException('Failed creating the world knowledge topic index');
        }
        if (!$db->execQuery("
            UPDATE public.worldknowledge
               SET native_vector =
                     setweight(to_tsvector(coalesce(topic, '')), 'A')
                  || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                  || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
        ")) {
            throw new RuntimeException('Failed rebuilding world knowledge search vectors');
        }
    } catch (Throwable $e) {
        $b_ok = false;
        Logger::error("Error upgrading world knowledge for basic Fallout lore: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("worldknowledge", 20260720001);
        Logger::info("Applied patch worldknowledge 20260720001");
    }
}

if ($checkVersion("fallout_worldknowledge_seed") < 20260722001) {
    Logger::debug("Applying fallout_worldknowledge_seed 20260722001 - seed Fallout lore aliases");

    $b_ok = true;
    $transactionOpen = false;
    $seedPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_basic.csv';
    try {
        require_once(dirname(__DIR__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'worldknowledge_topic.php');

        if (!is_readable($seedPath)) {
            throw new RuntimeException("World knowledge seed file is not readable: {$seedPath}");
        }

        $handle = fopen($seedPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open world knowledge seed file: {$seedPath}");
        }

        try {
            $expectedHeader = [
                'topic',
                'aliases',
                'topic_desc',
                'knowledge_class',
                'topic_desc_basic',
                'knowledge_class_basic',
                'tags',
                'category',
            ];
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header !== $expectedHeader) {
                throw new RuntimeException('World knowledge seed CSV header does not match the expected contract');
            }

            if (!$db->execQuery('BEGIN')) {
                throw new RuntimeException('Unable to begin world knowledge seed transaction');
            }
            $transactionOpen = true;
            if (!$db->execQuery("
                DELETE FROM public.worldknowledge current_row
                USING (
                    SELECT ctid
                    FROM (
                        SELECT
                            ctid,
                            row_number() OVER (
                                PARTITION BY lower(btrim(split_part(topic, ',', 1)))
                                ORDER BY
                                    CASE WHEN topic_desc IS NOT NULL AND btrim(topic_desc) <> '' THEN 1 ELSE 0 END DESC,
                                    CASE WHEN topic_desc_basic IS NOT NULL AND btrim(topic_desc_basic) <> '' THEN 1 ELSE 0 END DESC,
                                    ctid
                            ) AS row_number
                        FROM public.worldknowledge
                    ) ranked
                    WHERE row_number > 1
                ) duplicates
                WHERE current_row.ctid = duplicates.ctid
            ")) {
                throw new RuntimeException('Failed deduplicating canonical world knowledge topics');
            }
            if (!$db->execQuery("
                CREATE UNIQUE INDEX IF NOT EXISTS worldknowledge_canonical_topic_unique_idx
                    ON public.worldknowledge ((lower(btrim(split_part(topic, ',', 1)))))
            ")) {
                throw new RuntimeException('Failed creating the canonical world knowledge topic index');
            }
            $seenTopics = [];
            $seedRows = 0;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) !== count($expectedHeader)) {
                    throw new RuntimeException('World knowledge seed CSV contains a malformed row');
                }
                $data = array_combine($expectedHeader, $row);
                if (!is_array($data)) {
                    throw new RuntimeException('Unable to map a world knowledge seed row');
                }

                $topicAndAliases = trim(strval($data['topic'] ?? ''));
                if (trim(strval($data['aliases'] ?? '')) !== '') {
                    $topicAndAliases .= ',' . trim(strval($data['aliases']));
                }
                $topic = dialecticWorldKnowledgeNormalizeTopicList($topicAndAliases);
                $canonicalTopic = dialecticWorldKnowledgeCanonicalTopic($topic);
                $basicDescription = trim(strval($data['topic_desc_basic'] ?? ''));
                $category = strtolower(trim(strval($data['category'] ?? '')));
                if ($canonicalTopic === '' || !preg_match('/^[a-z0-9_]+$/', $canonicalTopic)) {
                    throw new RuntimeException("World knowledge seed contains an invalid topic key: {$topic}");
                }
                if (isset($seenTopics[$canonicalTopic])) {
                    throw new RuntimeException("World knowledge seed contains a duplicate canonical topic: {$canonicalTopic}");
                }
                if ($basicDescription === '') {
                    throw new RuntimeException("World knowledge seed is missing a basic description for {$topic}");
                }
                if (!in_array($category, ['location', 'creature', 'faction', 'person', 'event'], true)) {
                    throw new RuntimeException("World knowledge seed contains an invalid category for {$topic}");
                }
                foreach (['topic_desc', 'knowledge_class', 'knowledge_class_basic', 'tags'] as $blankField) {
                    if (trim(strval($data[$blankField] ?? '')) !== '') {
                        throw new RuntimeException("World knowledge seed field {$blankField} must be blank for {$topic}");
                    }
                }

                $seenTopics[$canonicalTopic] = true;
                $seedRows++;
                $topicSql = $db->escape($topic);
                $descriptionSql = $db->escape($basicDescription);
                $categorySql = $db->escape($category);
                if (!$db->execQuery("
                    INSERT INTO public.worldknowledge AS existing (
                        topic,
                        topic_desc,
                        native_vector,
                        knowledge_class,
                        topic_desc_basic,
                        knowledge_class_basic,
                        tags,
                        category
                    ) VALUES (
                        '{$topicSql}',
                        NULL,
                        setweight(to_tsvector('{$topicSql}'), 'A')
                            || setweight(to_tsvector('{$descriptionSql}'), 'C'),
                        NULL,
                        '{$descriptionSql}',
                        NULL,
                        NULL,
                        '{$categorySql}'
                    )
                    ON CONFLICT ((lower(btrim(split_part(topic, ',', 1))))) DO UPDATE
                    SET topic = EXCLUDED.topic,
                        native_vector =
                              setweight(to_tsvector(EXCLUDED.topic), 'A')
                           || setweight(to_tsvector(coalesce(existing.topic_desc, '')), 'B')
                           || setweight(to_tsvector(coalesce(existing.topic_desc_basic, '')), 'C')
                ")) {
                    throw new RuntimeException("Failed seeding world knowledge topic {$topic}");
                }
            }

            if ($seedRows === 0) {
                throw new RuntimeException('World knowledge seed CSV contains no data rows');
            }
            if (!$db->execQuery('COMMIT')) {
                throw new RuntimeException('Unable to commit world knowledge seed transaction');
            }
            $transactionOpen = false;
        } finally {
            fclose($handle);
        }
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        $b_ok = false;
        Logger::error("Error seeding basic Fallout world knowledge: " . $e->getMessage());
    }

    if ($b_ok) {
        $updateVersion("fallout_worldknowledge_seed", 20260722001);
        Logger::info("Applied patch fallout_worldknowledge_seed 20260722001");
    }
}

if ($checkVersion('worldknowledge_parity') < 20260813001) {
    Logger::debug('Applying worldknowledge_parity 20260813001 - add factory catalog metadata and structured audit');
    $migrationOk = false;
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge parity migration');
        }
        $transactionOpen = true;
        $schemaSql = file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core'
            . DIRECTORY_SEPARATOR . 'database_schema' . DIRECTORY_SEPARATOR . 'worldknowledge_parity_v1.sql'
        );
        if ($schemaSql === false || trim($schemaSql) === '') {
            throw new RuntimeException('World Knowledge parity schema is missing');
        }
        if (!$db->execQuery($schemaSql)) {
            throw new RuntimeException('Unable to apply World Knowledge parity schema');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge parity schema');
        }
        $transactionOpen = false;
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        dialecticWorldKnowledgeInstallFactoryCatalog($db, dirname(__DIR__) . DIRECTORY_SEPARATOR);
        $migrationOk = true;
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        Logger::error('Error applying World Knowledge parity schema: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_parity', 20260813001);
        Logger::info('Applied patch worldknowledge_parity 20260813001');
    }
}

if ($checkVersion('worldknowledge_access') < 20260813001) {
    Logger::debug('Applying worldknowledge_access 20260813001 - install tiered catalog and NPC context tags');
    $migrationOk = false;
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge access migration');
        }
        $transactionOpen = true;
        $schemaSql = file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core'
            . DIRECTORY_SEPARATOR . 'database_schema' . DIRECTORY_SEPARATOR . 'worldknowledge_parity_v1.sql'
        );
        if ($schemaSql === false || trim($schemaSql) === '' || !$db->execQuery($schemaSql)) {
            throw new RuntimeException('Unable to apply World Knowledge access schema');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge access schema');
        }
        $transactionOpen = false;
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        dialecticWorldKnowledgeInstallFactoryCatalog($db, dirname(__DIR__) . DIRECTORY_SEPARATOR);
        $migrationOk = true;
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        Logger::error('Error applying World Knowledge access migration: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_access', 20260813001);
        Logger::info('Applied patch worldknowledge_access 20260813001');
    }
}

if ($checkVersion('worldknowledge_canonical_tags') < 20260813003) {
    Logger::debug('Applying worldknowledge_canonical_tags 20260813003 - canonicalize access permissions');
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge canonical tag migration');
        }
        $transactionOpen = true;
        $schemaSql = file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core'
            . DIRECTORY_SEPARATOR . 'database_schema' . DIRECTORY_SEPARATOR . 'worldknowledge_parity_v1.sql'
        );
        if ($schemaSql === false || trim($schemaSql) === '' || !$db->execQuery($schemaSql)) {
            throw new RuntimeException('Unable to apply World Knowledge canonical tag schema');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge canonical tag schema');
        }
        $transactionOpen = false;
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        dialecticWorldKnowledgeInstallFactoryCatalog($db, dirname(__DIR__) . DIRECTORY_SEPARATOR);
        $updateVersion('worldknowledge_canonical_tags', 20260813003);
        Logger::info('Applied patch worldknowledge_canonical_tags 20260813003');
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        Logger::error('Error applying World Knowledge canonical tags: ' . $e->getMessage());
    }
}

if ($checkVersion('worldknowledge_oghma_parity') < 20260814001) {
    Logger::debug('Applying worldknowledge_oghma_parity 20260814001 - install curated Oghma catalog');
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge Oghma parity migration');
        }
        $transactionOpen = true;
        $schemaSql = file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core'
            . DIRECTORY_SEPARATOR . 'database_schema' . DIRECTORY_SEPARATOR . 'worldknowledge_parity_v1.sql'
        );
        if ($schemaSql === false || trim($schemaSql) === '' || !$db->execQuery($schemaSql)) {
            throw new RuntimeException('Unable to apply World Knowledge Oghma parity schema');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge Oghma parity schema');
        }
        $transactionOpen = false;
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        dialecticWorldKnowledgeInstallFactoryCatalog($db, dirname(__DIR__) . DIRECTORY_SEPARATOR);
        $invalidCatalogRows = $db->fetchAll(
            "SELECT catalog.catalog_id, catalog.catalog_version"
            . " FROM public.worldknowledge_catalogs AS catalog"
            . " WHERE NOT catalog.is_active AND (SELECT count(*) FROM public.worldknowledge AS article"
            . " WHERE article.source_kind='factory' AND article.catalog_id=catalog.catalog_id"
            . " AND article.catalog_version=catalog.catalog_version) <> catalog.row_count"
        );
        $removedIncompleteCatalogs = 0;
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin incomplete World Knowledge catalog cleanup');
        }
        $transactionOpen = true;
        foreach ((array)$invalidCatalogRows as $invalidCatalog) {
            $catalogId = strval($invalidCatalog['catalog_id'] ?? '');
            $catalogVersion = strval($invalidCatalog['catalog_version'] ?? '');
            if ($catalogId === '' || $catalogVersion === '') {
                continue;
            }
            if (!$db->execQuery(
                "DELETE FROM public.worldknowledge WHERE source_kind='factory' AND catalog_id="
                . $db->escapeLiteral($catalogId) . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
            ) || !$db->execQuery(
                'DELETE FROM public.worldknowledge_catalogs WHERE NOT is_active AND catalog_id='
                . $db->escapeLiteral($catalogId) . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
            )) {
                throw new RuntimeException("Unable to remove incomplete World Knowledge catalog {$catalogId}/{$catalogVersion}");
            }
            $removedIncompleteCatalogs++;
        }
        $updateVersion('worldknowledge_oghma_parity', 20260814001);
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit incomplete World Knowledge catalog cleanup');
        }
        $transactionOpen = false;
        Logger::info("Applied patch worldknowledge_oghma_parity 20260814001; removed {$removedIncompleteCatalogs} incomplete inactive factory catalogs");
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        Logger::error('Error applying World Knowledge Oghma parity: ' . $e->getMessage());
    }
}

if ($checkVersion('worldknowledge_herika_v1') < 20260814002) {
    Logger::debug('Applying worldknowledge_herika_v1 20260814002 - use the finalized eight-field Herika article contract');
    $migrationOk = false;
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge Herika V1 migration');
        }
        $transactionOpen = true;
        $schemaSql = file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core'
            . DIRECTORY_SEPARATOR . 'database_schema' . DIRECTORY_SEPARATOR . 'worldknowledge_parity_v1.sql'
        );
        if ($schemaSql === false || trim($schemaSql) === '' || !$db->execQuery($schemaSql)) {
            throw new RuntimeException('Unable to apply World Knowledge Herika V1 schema');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge Herika V1 schema');
        }
        $transactionOpen = false;

        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        dialecticWorldKnowledgeInstallFactoryCatalog($db, dirname(__DIR__) . DIRECTORY_SEPARATOR);
        $migrationOk = true;
    } catch (Throwable $e) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        Logger::error('Error applying World Knowledge Herika V1 contract: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_herika_v1', 20260814002);
        Logger::info('Applied patch worldknowledge_herika_v1 20260814002');
    }
}

if ($checkVersion('worldknowledge_npc_common_cleanup') < 20260814004) {
    Logger::debug('Applying worldknowledge_npc_common_cleanup 20260814004 - remove the legacy public marker from NPC tags');
    $migrationOk = false;
    try {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        $updatedNpcTags = dialecticWorldKnowledgeInstallNpcAccessTags(
            $db,
            dirname(__DIR__) . DIRECTORY_SEPARATOR
        );
        $removedCommonTags = 0;
        foreach (['bio_templates', 'bio_templates_custom', 'core_npc_master', 'core_npc_master_history'] as $npcTable) {
            $result = $db->fetchOne(
                "WITH updated AS (UPDATE public.{$npcTable} AS npc SET worldknowledge_tags=COALESCE(("
                . "SELECT string_agg(btrim(entry.tag), ',' ORDER BY entry.ordinality)"
                . " FROM unnest(string_to_array(coalesce(npc.worldknowledge_tags,''), ','))"
                . " WITH ORDINALITY AS entry(tag, ordinality)"
                . " WHERE btrim(entry.tag)<>'' AND lower(btrim(entry.tag))<>'common'"
                . "),'') WHERE 'common'=ANY(regexp_split_to_array(lower(coalesce(npc.worldknowledge_tags,'')),"
                . " '[,|[:space:]]+')) RETURNING 1) SELECT count(*) AS updated FROM updated"
            );
            if (!is_array($result) || !array_key_exists('updated', $result)) {
                throw new RuntimeException("Unable to remove common from {$npcTable} World Knowledge tags");
            }
            $removedCommonTags += intval($result['updated'] ?? 0);
        }
        $migrationOk = true;
    } catch (Throwable $e) {
        Logger::error('Error removing the legacy common marker from NPC tags: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_npc_common_cleanup', 20260814004);
        Logger::info("Applied patch worldknowledge_npc_common_cleanup 20260814004; reprojected {$updatedNpcTags} factory rows and removed common from {$removedCommonTags} NPC tag rows");
    }
}

if ($checkVersion('worldknowledge_npc_class_cleanup') < 20260814005) {
    Logger::debug('Applying worldknowledge_npc_class_cleanup 20260814005 - remove persisted NPC subject classes');
    $migrationOk = false;
    try {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        $updatedNpcTags = dialecticWorldKnowledgeInstallNpcAccessTags(
            $db,
            dirname(__DIR__) . DIRECTORY_SEPARATOR
        );
        $removedSubjectTags = 0;
        foreach (['bio_templates', 'bio_templates_custom', 'core_npc_master', 'core_npc_master_history'] as $npcTable) {
            $normalizedNpcName = "trim(both '_' from regexp_replace(lower(split_part(coalesce(npc.npc_name,''),'__',1)), '[^a-z0-9]+', '_', 'g'))";
            $result = $db->fetchOne(
                "WITH updated AS (UPDATE public.{$npcTable} AS npc SET worldknowledge_tags=COALESCE(("
                . "SELECT string_agg(btrim(entry.tag), ',' ORDER BY entry.ordinality)"
                . " FROM unnest(string_to_array(coalesce(npc.worldknowledge_tags,''), ','))"
                . " WITH ORDINALITY AS entry(tag, ordinality)"
                . " WHERE btrim(entry.tag)<>''"
                . " AND lower(btrim(entry.tag))<>({$normalizedNpcName})"
                . "),'') WHERE ({$normalizedNpcName})<>'' AND ({$normalizedNpcName})=ANY("
                . "regexp_split_to_array(lower(coalesce(npc.worldknowledge_tags,'')), '[,|[:space:]]+'))"
                . " RETURNING 1) SELECT count(*) AS updated FROM updated"
            );
            if (!is_array($result) || !array_key_exists('updated', $result)) {
                throw new RuntimeException("Unable to remove persisted NPC subjects from {$npcTable} World Knowledge tags");
            }
            $removedSubjectTags += intval($result['updated'] ?? 0);
        }
        $migrationOk = true;
    } catch (Throwable $e) {
        Logger::error('Error removing persisted NPC subject classes: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_npc_class_cleanup', 20260814005);
        Logger::info("Applied patch worldknowledge_npc_class_cleanup 20260814005; reprojected {$updatedNpcTags} factory rows and removed subjects from {$removedSubjectTags} NPC tag rows");
    }
}

if ($checkVersion('worldknowledge_catalog_integrity') < 20260814006) {
    Logger::debug('Applying worldknowledge_catalog_integrity 20260814006 - restore and verify the complete active factory catalog');
    $migrationOk = false;
    try {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';
        $catalogResult = dialecticWorldKnowledgeInstallFactoryCatalog(
            $db,
            dirname(__DIR__) . DIRECTORY_SEPARATOR
        );
        $migrationOk = true;
    } catch (Throwable $e) {
        Logger::error('Error synchronizing the complete World Knowledge catalog: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('worldknowledge_catalog_integrity', 20260814006);
        Logger::info(
            'Applied patch worldknowledge_catalog_integrity 20260814006; synchronized '
            . $catalogResult['catalog_id'] . '/' . $catalogResult['catalog_version']
            . ' with ' . $catalogResult['row_count'] . ' factory articles'
        );
    }
}

if ($checkVersion("latest_diary_context") < 20260727001) {
    Logger::debug("Applying latest_diary_context 20260727001 - index latest NPC diary lookups");

    $migrationOk = $db->execQuery(
        "CREATE INDEX IF NOT EXISTS idx_diarylog_people_gamets
         ON public.diarylog (lower(trim(people)), gamets DESC, localts DESC, rowid DESC)"
    ) !== false;

    if ($migrationOk) {
        $updateVersion("latest_diary_context", 20260727001);
        Logger::info("Applied patch latest_diary_context 20260727001");
    } else {
        Logger::error("Failed to apply patch latest_diary_context 20260727001");
    }
}

if ($checkVersion('core_itt_connector') < 20260731001) {
    Logger::debug('Applying core_itt_connector 20260731001 - add PipVision connector storage');
    $migrationOk = $db->execQuery(
        file_get_contents(__DIR__ . '/../lib/core/database_schema/core_itt_connector.sql')
    ) !== false;
    if ($migrationOk) {
        $updateVersion('core_itt_connector', 20260731001);
        Logger::info('Applied patch core_itt_connector 20260731001');
    } else {
        Logger::error('Failed to apply patch core_itt_connector 20260731001');
    }
}

if ($checkVersion('visual_context') < 20260731001) {
    Logger::debug('Applying visual_context 20260731001 - add PipVision persistence');
    $migrationOk = $db->execQuery(
        file_get_contents(__DIR__ . '/../lib/core/database_schema/visual_context.sql')
    ) !== false;
    if ($migrationOk) {
        $updateVersion('visual_context', 20260731001);
        Logger::info('Applied patch visual_context 20260731001');
    } else {
        Logger::error('Failed to apply patch visual_context 20260731001');
    }
}

if ($checkVersion('pipvision_general_settings') < 20260731001) {
    Logger::debug('Applying pipvision_general_settings 20260731001 - seed PipVision defaults');
    $migrationOk = true;
    foreach ([
        'GLOBAL_ITT_CONNECTOR_ID',
        'VISUAL_CONTEXT_SCENE_TTL_MINUTES',
        'VISUAL_CONTEXT_PROMPT_MAX_CHARS',
        'PIPVISION_IMAGE_QUALITY',
        'PIPVISION_REQUEST_TIMEOUT_SECONDS',
    ] as $settingId) {
        try {
            $existing = $db->fetchOne(
                'SELECT id FROM public.general_settings WHERE id=' . $db->escapeLiteral($settingId) . ' LIMIT 1'
            );
            if ($existing) {
                continue;
            }
            $definition = dialecticGetSchemaDefinition($settingId);
            if (!dialecticSetGeneralSetting(
                $settingId,
                $definition['default'] ?? '',
                dialecticGetSchemaDescription($settingId)
            )) {
                throw new RuntimeException("Failed writing {$settingId}");
            }
        } catch (Throwable $e) {
            $migrationOk = false;
            Logger::error('Failed seeding PipVision setting ' . $settingId . ': ' . $e->getMessage());
            break;
        }
    }
    if ($migrationOk) {
        $updateVersion('pipvision_general_settings', 20260731001);
        Logger::info('Applied patch pipvision_general_settings 20260731001');
    }
}

if ($checkVersion('itt_connector_defaults') < 20260731002) {
    Logger::debug('Applying itt_connector_defaults 20260731002 - seed CHIM-compatible ITT defaults');
    $migrationOk = true;
    try {
        require_once(__DIR__ . '/../lib/core/itt_connector.class.php');
        $ittConnector = new ITTConnector();
        $connectors = $ittConnector->readAll();
        $activeId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
        $activeRow = $activeId > 0 ? $ittConnector->getById($activeId) : null;

        if (!$connectors) {
            $activeId = $ittConnector->create([
                'driver' => 'openrouter',
                'label' => 'Global ITT Connector',
                'metadata' => $ittConnector->getDefaultMetadataForDriver('openrouter'),
                'api_badge_id' => $ittConnector->getDefaultApiBadgeIdForDriver('openrouter'),
                'url' => $ittConnector->getDefaultUrlForDriver('openrouter'),
            ]);
            if ($activeId < 1) {
                throw new RuntimeException('Failed creating the default ITT connector');
            }
            $activeRow = $ittConnector->getById($activeId);
        } elseif (!$activeRow) {
            $activeRow = $connectors[0];
            $activeId = intval($activeRow['id'] ?? 0);
        }

        if ($activeId < 1 || !$activeRow || !dialecticSetGeneralSetting(
            'GLOBAL_ITT_CONNECTOR_ID',
            $activeId,
            dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID')
        )) {
            throw new RuntimeException('Failed selecting the default ITT connector');
        }
    } catch (Throwable $e) {
        $migrationOk = false;
        Logger::error('Failed seeding ITT connector defaults: ' . $e->getMessage());
    }

    if ($migrationOk) {
        $updateVersion('itt_connector_defaults', 20260731002);
        Logger::info('Applied patch itt_connector_defaults 20260731002');
    }
}

Logger::info(__FILE__." update file processed. This file has ".__LINE__." lines.");
