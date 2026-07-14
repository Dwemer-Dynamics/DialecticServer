CREATE OR REPLACE VIEW public.memory_v AS
WITH event_source AS (
    SELECT
        eventlog.rowid,
        eventlog.gamets,
        eventlog.ts,
        eventlog.type,
        eventlog.data,
        eventlog.people,
        eventlog.delivery_state,
        NULLIF(substring(eventlog.data from '"speaker"[[:space:]]*:[[:space:]]*"([^"]+)"'), '') AS json_speaker,
        NULLIF(substring(eventlog.data from '"listener_hint"[[:space:]]*:[[:space:]]*"([^"]+)"'), '') AS json_listener,
        NULLIF(substring(eventlog.data from '"resolved_rechat_target"[[:space:]]*:[[:space:]]*"([^"]+)"'), '') AS json_rechat_target,
        NULLIF(substring(eventlog.data from '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'), '') AS json_origin_line,
        NULLIF(substring(eventlog.data from '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'), '') AS json_text,
        COALESCE(
            NULLIF(substring(eventlog.data from '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'), ''),
            NULLIF(substring(eventlog.data from '"message"[[:space:]]*:[[:space:]]*"([^"]+)"'), ''),
            NULLIF(substring(eventlog.data from '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'), ''),
            eventlog.data
        ) AS decoded_text
    FROM public.eventlog
    WHERE eventlog.data IS NOT NULL
      AND eventlog.data <> ''
      AND (eventlog.delivery_state IS NULL OR eventlog.delivery_state NOT IN ('aborted', 'failed', 'cancelled'))
      AND eventlog.type::text = ANY (ARRAY[
          'inputtext',
          'inputtext_s',


          'narrator_inputtext',
          'chat',
          'backgroundchat',
          'narration',
          'conversation_start',
          'conversation_end',
          'world_context',
          'infoaction',
          'funcret',
          'death',
          'combatend',
          'combatbark',
          'itemfound',
          'itemtransfer'
      ])
      AND (
          eventlog.type::text <> 'world_context'
          OR eventlog.data IS DISTINCT FROM (
              SELECT previous_world.data
              FROM public.eventlog previous_world
              WHERE previous_world.type::text = 'world_context'
                AND (
                    previous_world.gamets < eventlog.gamets
                    OR (previous_world.gamets = eventlog.gamets AND previous_world.rowid < eventlog.rowid)
                )
              ORDER BY previous_world.gamets DESC, previous_world.rowid DESC
              LIMIT 1
          )
      )
)
SELECT subquery.message,
       subquery.uid,
       subquery.gamets,
       subquery.speaker,
       subquery.listener,
       subquery.ts
FROM (
    SELECT memory.message,
           memory.uid,
           memory.gamets,
           '-'::text AS speaker,
           '-'::text AS listener,
           memory.ts
    FROM public.memory
    WHERE memory.message !~~ 'Dear Diary%'::text
      AND memory.message <> ''::text

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

    SELECT CASE
               WHEN event_source.type::text = ANY (ARRAY['inputtext', 'inputtext_s', 'narrator_inputtext'])
                   THEN ('Player: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'world_context'
                   THEN event_source.data
               WHEN event_source.type::text = 'conversation_start'
                   THEN ('Conversation started: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'conversation_end'
                   THEN ('Conversation ended: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'prechat'
                   THEN ('Prechat: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'rechat' AND event_source.json_origin_line IS NOT NULL
                   THEN ('Rechat: '::text || COALESCE(event_source.json_speaker, 'Unknown') || ' to ' || COALESCE(event_source.json_rechat_target, event_source.json_listener, 'Unknown') || ': ' || event_source.json_origin_line)
               WHEN event_source.type::text = 'rechat'
                   THEN ('Rechat: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'narration'
                   THEN ('Narration: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'infoaction'
                   THEN ('Action result: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'funcret'
                   THEN ('Function result: '::text || event_source.decoded_text)
               WHEN event_source.type::text = 'itemtransfer'
                   THEN ('Item transfer: '::text || COALESCE(event_source.json_text, event_source.decoded_text))
               ELSE event_source.decoded_text
           END AS message,
           event_source.rowid::integer AS uid,
           event_source.gamets,
           CASE
               WHEN event_source.type::text = 'world_context' THEN '-'::text
               ELSE COALESCE(
                   event_source.json_speaker,
                   NULLIF(substring(event_source.data from '^([^:]{1,80}):'), ''),
                   NULLIF(split_part(trim(both '|' from COALESCE(event_source.people, '')), '|', 1), ''),
                   '-'::text
               )
           END AS speaker,
           CASE
               WHEN event_source.type::text = 'world_context' THEN '-'::text
               ELSE COALESCE(
                   NULLIF(substring(event_source.data from '\([Tt]alking to ([^)]+)\)'), ''),
                   event_source.json_rechat_target,
                   event_source.json_listener,
                   NULLIF(split_part(trim(both '|' from COALESCE(event_source.people, '')), '|', 2), ''),
                   '-'::text
               )
           END AS listener,
           event_source.ts
    FROM event_source
) subquery
ORDER BY subquery.gamets, subquery.ts;
