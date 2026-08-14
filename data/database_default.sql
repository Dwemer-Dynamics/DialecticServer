--
-- PostgreSQL database dump
--

-- Dumped from database version 15.12 (Debian 15.12-0+deb12u2)
-- Dumped by pg_dump version 15.12 (Debian 15.12-0+deb12u2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: dwemer
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO dwemer;

--
-- Name: plugins; Type: SCHEMA; Schema: -; Owner: dwemer
--

CREATE SCHEMA IF NOT EXISTS plugins;


ALTER SCHEMA plugins OWNER TO dwemer;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: dwemer
--

COMMENT ON SCHEMA public IS '';


--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner:
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: convert_gamets2days(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2days(gamets bigint) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN floor(gamets * 0.0000001);
            END;
        $$;


ALTER FUNCTION public.convert_gamets2days(gamets bigint) OWNER TO dwemer;

--
-- Name: convert_gamets2gregorian_date(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2gregorian_date(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN to_char(to_timestamp('2281.11.30 19:43:00','YYYY.MM.DD HH24:MI:SS') + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$;


ALTER FUNCTION public.convert_gamets2gregorian_date(gamets bigint) OWNER TO dwemer;

--
-- Name: convert_gamets2hours(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2hours(gamets bigint) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN floor(gamets * 0.0000024);
            END;
        $$;


ALTER FUNCTION public.convert_gamets2hours(gamets bigint) OWNER TO dwemer;

--
-- Name: convert_gamets2fallout_date(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2fallout_date(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN to_char(to_timestamp('2281.11.30 19:43:00','YYYY.MM.DD HH24:MI:SS') + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$;


ALTER FUNCTION public.convert_gamets2fallout_date(gamets bigint) OWNER TO dwemer;

--
-- Name: convert_gamets2fallout_long_date(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2fallout_long_date(gamets bigint) RETURNS text
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
                ts_base := to_timestamp('2281.11.30 19:43:00','YYYY.MM.DD HH24:MI:SS');
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
        $$;


ALTER FUNCTION public.convert_gamets2fallout_long_date(gamets bigint) OWNER TO dwemer;

--
-- Name: convert_gamets2fallout_long_date2(bigint); Type: FUNCTION; Schema: public; Owner: dwemer
--

CREATE FUNCTION public.convert_gamets2fallout_long_date2(gamets bigint) RETURNS text
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
                ts_base := to_timestamp('2281.11.30 19:43:00','YYYY.MM.DD HH24:MI:SS');
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
        $$;


ALTER FUNCTION public.convert_gamets2fallout_long_date2(gamets bigint) OWNER TO dwemer;

--
SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: actions_issued; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.actions_issued (
    action text,
    fullcall text,
    actorname text,
    ts numeric,
    localts numeric,
    gamets numeric,
    original text,
    rowid integer NOT NULL
);


ALTER TABLE public.actions_issued OWNER TO dwemer;

--
-- Name: actions_issued_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.actions_issued_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.actions_issued_rowid_seq OWNER TO dwemer;

--
-- Name: actions_issued_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.actions_issued_rowid_seq OWNED BY public.actions_issued.rowid;


-- Name: audit_memory; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.audit_memory (
    input text,
    keywords text,
    rank_any numeric(20,10),
    rank_all numeric(20,10),
    memory text,
    "time" text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.audit_memory OWNER TO dwemer;

--
-- Name: audit_request_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.audit_request_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.audit_request_rowid_seq OWNER TO dwemer;

--
-- Name: audit_request; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.audit_request (
    request text,
    result text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    rowid bigint DEFAULT nextval('public.audit_request_rowid_seq'::regclass) NOT NULL
);


ALTER TABLE public.audit_request OWNER TO dwemer;

-- Name: core_action; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.core_action (
    id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code_name character varying(128) NOT NULL UNIQUE,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.core_action OWNER TO dwemer;

--
-- Name: core_action_custom; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.core_action_custom (
    id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code_name character varying(128) NOT NULL UNIQUE,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.core_action_custom OWNER TO dwemer;

--
-- Name: combined_core_action; Type: VIEW; Schema: public; Owner: dwemer
--

CREATE VIEW public.combined_core_action AS
 SELECT c.id,
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
SELECT b.id,
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
   FROM (public.core_action b
     LEFT JOIN public.core_action_custom c ON (lower((b.code_name)::text) = lower((c.code_name)::text)))
  WHERE (c.code_name IS NULL);


ALTER TABLE public.combined_core_action OWNER TO dwemer;

--
-- Name: idx_core_action_code_name_lower; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_code_name_lower ON public.core_action USING btree (lower((code_name)::text));

--
-- Name: idx_core_action_action_name_lower; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_action_name_lower ON public.core_action USING btree (lower((action_name)::text));

--
-- Name: idx_core_action_is_activated; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_is_activated ON public.core_action USING btree (is_activated);

--
-- Name: idx_core_action_available_to_npc; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_available_to_npc ON public.core_action USING btree (available_to_npc);

--
-- Name: idx_core_action_available_to_followers; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_available_to_followers ON public.core_action USING btree (available_to_followers);

--
-- Name: idx_core_action_available_to_narrator; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_available_to_narrator ON public.core_action USING btree (available_to_narrator);

--
-- Name: idx_core_action_game_function; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_game_function ON public.core_action USING btree (game_function);

--
-- Name: idx_core_action_custom_code_name_lower; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_code_name_lower ON public.core_action_custom USING btree (lower((code_name)::text));

--
-- Name: idx_core_action_custom_action_name_lower; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_action_name_lower ON public.core_action_custom USING btree (lower((action_name)::text));

--
-- Name: idx_core_action_custom_is_activated; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_is_activated ON public.core_action_custom USING btree (is_activated);

--
-- Name: idx_core_action_custom_available_to_npc; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_available_to_npc ON public.core_action_custom USING btree (available_to_npc);

--
-- Name: idx_core_action_custom_available_to_followers; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_available_to_followers ON public.core_action_custom USING btree (available_to_followers);

--
-- Name: idx_core_action_custom_available_to_narrator; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_available_to_narrator ON public.core_action_custom USING btree (available_to_narrator);

--
-- Name: idx_core_action_custom_game_function; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX idx_core_action_custom_game_function ON public.core_action_custom USING btree (game_function);

--
-- Name: conf_opts; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.conf_opts (
    id text NOT NULL,
    value text
);


ALTER TABLE public.conf_opts OWNER TO dwemer;

ALTER TABLE ONLY public.conf_opts
    ADD CONSTRAINT conf_opts_pkey PRIMARY KEY (id);

--
-- Name: database_versioning; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.database_versioning (
    tablename text NOT NULL,
    version bigint NOT NULL
);


ALTER TABLE public.database_versioning OWNER TO dwemer;

--
-- Name: diarylog; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.diarylog (
    ts text NOT NULL,
    sess character varying(1024),
    topic text,
    content text,
    tags text,
    people text,
    localts bigint NOT NULL,
    location text,
    gamets bigint NOT NULL,
    rowid bigint NOT NULL
);


ALTER TABLE public.diarylog OWNER TO dwemer;

--
-- Name: diarylog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.diarylog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.diarylog_rowid_seq OWNER TO dwemer;

--
-- Name: diarylog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.diarylog_rowid_seq OWNED BY public.diarylog.rowid;


--
-- Name: eventlog; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.eventlog (
    type character varying(128),
    data text,
    sess character varying(1024),
    gamets bigint NOT NULL,
    localts bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text
);


ALTER TABLE public.eventlog OWNER TO dwemer;

--
-- Name: eventlog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.eventlog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.eventlog_rowid_seq OWNER TO dwemer;

--
-- Name: eventlog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY public.eventlog.rowid;


--
-- Name: locations; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.locations (
    name text,
    formid bigint
);


ALTER TABLE public.locations OWNER TO dwemer;

--
-- Name: TABLE locations; Type: COMMENT; Schema: public; Owner: dwemer
--

COMMENT ON TABLE public.locations IS 'locations sent from plugin';


--
-- Name: log; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.log (
    localts bigint NOT NULL,
    prompt text,
    response text,
    url text,
    rowid bigint NOT NULL
);


ALTER TABLE public.log OWNER TO dwemer;

--
-- Name: log_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.log_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.log_rowid_seq OWNER TO dwemer;

--
-- Name: log_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.log_rowid_seq OWNED BY public.log.rowid;


--
-- Name: memory; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.memory (
    speaker text,
    message text,
    session text,
    uid integer NOT NULL,
    listener text,
    localts integer,
    gamets bigint NOT NULL,
    momentum text,
    rowid bigint NOT NULL,
    event character varying(64),
    ts bigint
);


ALTER TABLE public.memory OWNER TO dwemer;

--
-- Name: memory_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.memory_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.memory_rowid_seq OWNER TO dwemer;

--
-- Name: memory_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.memory_rowid_seq OWNED BY public.memory.rowid;


--
-- Name: memory_summary; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.memory_summary (
    gamets_truncated bigint NOT NULL,
    n integer,
    packed_message text,
    summary text,
    classifier text,
    uid integer NOT NULL,
    rowid integer NOT NULL,
    embedding public.vector(384),
    companions text,
    embedding768 public.vector(768),
    tags text,
    scope text,
    native_vec tsvector
);


ALTER TABLE public.memory_summary OWNER TO dwemer;

--
-- Name: memory_summary_tsv_idx; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE INDEX memory_summary_tsv_idx ON public.memory_summary USING gin (native_vec);

--
-- Name: memory_summary_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.memory_summary_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.memory_summary_rowid_seq OWNER TO dwemer;

--
-- Name: memory_summary_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.memory_summary_rowid_seq OWNED BY public.memory_summary.rowid;


--
-- Name: memory_uid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.memory_uid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.memory_uid_seq OWNER TO dwemer;

--
-- Name: memory_uid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.memory_uid_seq OWNED BY public.memory.uid;


--
-- Name: speech; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.speech (
    sess character varying(1024),
    speaker text,
    speech text,
    location text,
    listener text,
    topic text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    companions text,
    audios text,
    utterance_id text
);


ALTER TABLE public.speech OWNER TO dwemer;

--
-- Name: memory_v; Type: VIEW; Schema: public; Owner: dwemer
--

CREATE VIEW public.memory_v AS
 WITH event_source AS (
         SELECT eventlog.rowid,
            eventlog.gamets,
            eventlog.ts,
            eventlog.type,
            eventlog.data,
            eventlog.people,
            eventlog.delivery_state,
            NULLIF("substring"(eventlog.data, '"speaker"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_speaker,
            NULLIF("substring"(eventlog.data, '"listener_hint"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_listener,
            NULLIF("substring"(eventlog.data, '"resolved_rechat_target"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_rechat_target,
            NULLIF("substring"(eventlog.data, '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_origin_line,
            NULLIF("substring"(eventlog.data, '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_text,
            COALESCE(NULLIF("substring"(eventlog.data, '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), NULLIF("substring"(eventlog.data, '"message"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), NULLIF("substring"(eventlog.data, '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), eventlog.data) AS decoded_text
           FROM public.eventlog
          WHERE eventlog.data IS NOT NULL AND eventlog.data <> ''::text AND (eventlog.delivery_state IS NULL OR (eventlog.delivery_state <> ALL (ARRAY['aborted'::text, 'failed'::text, 'cancelled'::text]))) AND eventlog.type::text = ANY (ARRAY['inputtext'::text, 'inputtext_s'::text, 'narrator_inputtext'::text, 'chat'::text, 'prechat'::text, 'rechat'::text, 'narration'::text, 'conversation_start'::text, 'conversation_end'::text, 'world_context'::text, 'infoaction'::text, 'funcret'::text, 'death'::text, 'combatend'::text, 'combatbark'::text, 'itemfound'::text, 'itemtransfer'::text])
        )
 SELECT subquery.message,
    subquery.uid,
    subquery.gamets,
    subquery.speaker,
    subquery.listener,
    subquery.ts
   FROM ( SELECT memory.message,
            memory.uid,
            memory.gamets,
            '-'::text AS speaker,
            '-'::text AS listener,
            memory.ts
           FROM public.memory
          WHERE memory.message !~~ 'Dear Diary%'::text AND memory.message <> ''::text
        UNION ALL
         SELECT (((('(Context Location:'::text || speech.location) || ') '::text) || speech.speaker) || ': '::text) || speech.speech,
            speech.rowid::integer AS uid,
            speech.gamets,
            speech.speaker,
            speech.listener,
            speech.ts
           FROM public.speech
          WHERE speech.speech <> ''::text
        UNION ALL
         SELECT
                CASE
                    WHEN event_source.type::text = ANY (ARRAY['inputtext'::text, 'inputtext_s'::text, 'narrator_inputtext'::text]) THEN 'Player: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'world_context'::text THEN event_source.data
                    WHEN event_source.type::text = 'conversation_start'::text THEN 'Conversation started: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'conversation_end'::text THEN 'Conversation ended: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'prechat'::text THEN 'Prechat: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'rechat'::text AND event_source.json_origin_line IS NOT NULL THEN 'Rechat: '::text || COALESCE(event_source.json_speaker, 'Unknown'::text) || ' to '::text || COALESCE(event_source.json_rechat_target, event_source.json_listener, 'Unknown'::text) || ': '::text || event_source.json_origin_line
                    WHEN event_source.type::text = 'rechat'::text THEN 'Rechat: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'narration'::text THEN 'Narration: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'infoaction'::text THEN 'Action result: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'funcret'::text THEN 'Function result: '::text || event_source.decoded_text
                    WHEN event_source.type::text = 'itemtransfer'::text THEN 'Item transfer: '::text || COALESCE(event_source.json_text, event_source.decoded_text)
                    ELSE event_source.decoded_text
                END AS message,
            event_source.rowid::integer AS uid,
            event_source.gamets,
            COALESCE(event_source.json_speaker, NULLIF("substring"(event_source.data, '^([^:]{1,80}):'::text), ''::text), NULLIF(split_part(TRIM(BOTH '|'::text FROM COALESCE(event_source.people, ''::text)), '|'::text, 1), ''::text), '-'::text) AS speaker,
            COALESCE(NULLIF("substring"(event_source.data, '\([Tt]alking to ([^)]+)\)'::text), ''::text), event_source.json_rechat_target, event_source.json_listener, NULLIF(split_part(TRIM(BOTH '|'::text FROM COALESCE(event_source.people, ''::text)), '|'::text, 2), ''::text), '-'::text) AS listener,
            event_source.ts
           FROM event_source) subquery
  ORDER BY subquery.gamets, subquery.ts;


ALTER TABLE public.memory_v OWNER TO dwemer;

--
-- Name: speech_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.speech_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.speech_rowid_seq OWNER TO dwemer;

--
-- Name: speech_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.speech_rowid_seq OWNED BY public.speech.rowid;


--
-- Name: moods_issued_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.moods_issued_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.moods_issued_rowid_seq OWNER TO dwemer;

--
-- Name: moods_issued; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.moods_issued (
    sess character varying(1024),
    speaker text,
    mood text,
    listener text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint DEFAULT nextval('public.moods_issued_rowid_seq'::regclass) NOT NULL
);


ALTER TABLE public.moods_issued OWNER TO dwemer;

--
-- Name: moods_issued_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.moods_issued_rowid_seq OWNED BY public.moods_issued.rowid;

-- Name: worldknowledge; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.worldknowledge (
    topic character varying NOT NULL,
    aliases text DEFAULT ''::text NOT NULL,
    topic_desc character varying,
    native_vector tsvector,
    knowledge_class text,
    topic_desc_basic text,
    knowledge_class_basic text,
    tags text,
    category text
);


ALTER TABLE public.worldknowledge OWNER TO dwemer;


--
-- Name: worldknowledge_topic_unique_idx; Type: INDEX; Schema: public; Owner: dwemer
--

CREATE UNIQUE INDEX worldknowledge_topic_unique_idx ON public.worldknowledge USING btree (topic);

CREATE UNIQUE INDEX worldknowledge_canonical_topic_unique_idx ON public.worldknowledge USING btree ((lower(btrim(split_part(topic, ','::text, 1)))));

--
-- Name: quests; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.quests (
    ts text NOT NULL,
    sess character varying(1024),
    id_quest character varying(1024) NOT NULL,
    name text,
    editor_id text,
    giver_actor_id text,
    reward text,
    target_id text,
    is_unique boolean,
    mod text,
    stage integer,
    briefing text,
    briefing2 text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    data text,
    status text,
    rowid bigint NOT NULL
);


ALTER TABLE public.quests OWNER TO dwemer;

--
-- Name: quests_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.quests_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.quests_rowid_seq OWNER TO dwemer;

--
-- Name: quests_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.quests_rowid_seq OWNED BY public.quests.rowid;


--
-- Name: responselog; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.responselog (
    localts bigint NOT NULL,
    sent bigint NOT NULL,
    actor text,
    text text,
    action text,
    tag character varying(256),
    rowid bigint NOT NULL
);


ALTER TABLE public.responselog OWNER TO dwemer;

--
-- Name: responselog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.responselog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.responselog_rowid_seq OWNER TO dwemer;

--
-- Name: responselog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.responselog_rowid_seq OWNED BY public.responselog.rowid;


--
-- Name: rolemaster; Type: TABLE; Schema: public; Owner: dwemer
--

CREATE TABLE public.rolemaster (
    localts bigint NOT NULL,
    ttl bigint NOT NULL,
    type character varying(128),
    data text,
    rowid bigint NOT NULL
);


ALTER TABLE public.rolemaster OWNER TO dwemer;

--
-- Name: rolemaster_rowid_seq; Type: SEQUENCE; Schema: public; Owner: dwemer
--

CREATE SEQUENCE public.rolemaster_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.rolemaster_rowid_seq OWNER TO dwemer;

--
-- Name: rolemaster_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: dwemer
--

ALTER SEQUENCE public.rolemaster_rowid_seq OWNED BY public.rolemaster.rowid;


--
-- Name: actions_issued rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.actions_issued ALTER COLUMN rowid SET DEFAULT nextval('public.actions_issued_rowid_seq'::regclass);


--
-- Name: diarylog rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.diarylog ALTER COLUMN rowid SET DEFAULT nextval('public.diarylog_rowid_seq'::regclass);


--
-- Name: eventlog rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.eventlog ALTER COLUMN rowid SET DEFAULT nextval('public.eventlog_rowid_seq'::regclass);


--
-- Name: log rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.log ALTER COLUMN rowid SET DEFAULT nextval('public.log_rowid_seq'::regclass);


--
-- Name: memory uid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.memory ALTER COLUMN uid SET DEFAULT nextval('public.memory_uid_seq'::regclass);


--
-- Name: memory rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.memory ALTER COLUMN rowid SET DEFAULT nextval('public.memory_rowid_seq'::regclass);


--
-- Name: memory_summary rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.memory_summary ALTER COLUMN rowid SET DEFAULT nextval('public.memory_summary_rowid_seq'::regclass);


--
-- Name: quests rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.quests ALTER COLUMN rowid SET DEFAULT nextval('public.quests_rowid_seq'::regclass);


--
-- Name: responselog rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.responselog ALTER COLUMN rowid SET DEFAULT nextval('public.responselog_rowid_seq'::regclass);


--
-- Name: rolemaster rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.rolemaster ALTER COLUMN rowid SET DEFAULT nextval('public.rolemaster_rowid_seq'::regclass);


--
-- Name: speech rowid; Type: DEFAULT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.speech ALTER COLUMN rowid SET DEFAULT nextval('public.speech_rowid_seq'::regclass);


--
-- Data for Name: actions_issued; Type: TABLE DATA; Schema: public; Owner: dwemer
--



-- Data for Name: audit_memory; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: audit_request; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: conf_opts; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: database_versioning; Type: TABLE DATA; Schema: public; Owner: dwemer
--

INSERT INTO public.database_versioning VALUES ('sql_gamets_convert_functions', 20250218001);
INSERT INTO public.database_versioning VALUES ('memory_summary', 20260319001);
INSERT INTO public.database_versioning VALUES ('actions_issued', 20250525001);
INSERT INTO public.database_versioning VALUES ('moods_issued', 20250526001);
INSERT INTO public.database_versioning VALUES ('moods_issued_sequence', 20260626001);
INSERT INTO public.database_versioning VALUES ('core_tts_connector_metadata', 20260626001);
INSERT INTO public.database_versioning VALUES ('core_tts_connector_removed_drivers', 20260712001);
INSERT INTO public.database_versioning VALUES ('worldknowledge', 20250903001);
INSERT INTO public.database_versioning VALUES ('locations', 20250526001);
INSERT INTO public.database_versioning VALUES ('rolemaster', 20250528001);
INSERT INTO public.database_versioning VALUES ('db_maintenance', 20250528002);


--
-- Data for Name: diarylog; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: eventlog; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: locations; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: log; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: memory; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: memory_summary; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
-- Data for Name: moods_issued; Type: TABLE DATA; Schema: public; Owner: dwemer
--



--
