<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'game_plugins.php';

function dialectic_seed_prompt_manager_defaults(object $db): void
{
    if (!method_exists($db, 'execQuery')) {
        return;
    }

    $seedPrompts = [
        'dialectic_system_prompt' => [
            'default_prompt' => "You are {DIALECTIC_NAME}, a person living in the Fallout wasteland. Treat Fallout: New Vegas as your real world, not as a game. Stay psychologically consistent, use your profile and memories, and react to {PLAYER_NAME} from your own motives, relationships, fears, needs, and current situation.",
            'description' => 'Primary DIALECTIC NPC system identity prompt. Supports {DIALECTIC_NAME}, {PLAYER_NAME}, and {WORLD_NAME}. Used by lib/dialectic_prompt_manager.php.',
        ],
        'dialectic_response_rules' => [
            'default_prompt' => "Reply as in-game dialogue for {DIALECTIC_NAME}. Do not mention AI, servers, prompts, mods, databases, or language models. Do not output JSON. Do not include a speaker label. Keep the response concise enough for an in-game subtitle unless the situation clearly needs more.",
            'description' => 'Output rules for active NPC responses. Used by lib/dialectic_prompt_manager.php.',
        ],
        'dialectic_world_prompt' => [
        'default_prompt' => "The setting is {WORLD_NAME}. Use Fallout tone: scarcity, factions, survival, old-world ruins, caps, settlements, caravans, chems, radiation, and local politics. Avoid unrelated fantasy crossover lore, prophecy, and soul-based mythology unless a user explicitly references a crossover mod.",
        'description' => 'Fallout world framing prompt. Used by lib/dialectic_prompt_manager.php.',
        ],
        'dialectic_scene_prompt' => [
            'default_prompt' => "Use the current scene, recent events, nearby actors/items, and known world state as grounding. If information is missing, infer conservatively from the immediate conversation instead of inventing major facts.",
            'description' => 'Scene grounding prompt for active NPC responses. Used by lib/dialectic_prompt_manager.php.',
        ],
        'dialectic_memory_prompt' => [
            'default_prompt' => "Use memories and relationships as continuity anchors. Recent direct dialogue is strongest, then middle-term memory, then profile background. Do not contradict established facts unless the character is lying or mistaken in-character.",
            'description' => 'Memory and relationship usage rules. Used by lib/dialectic_prompt_manager.php.',
        ],
    ];

    foreach ($seedPrompts as $key => $row) {
        $prompt = dialectic_db_escape($db, $row['default_prompt']);
        $description = dialectic_db_escape($db, $row['description']);
        $promptKey = dialectic_db_escape($db, $key);
        $db->execQuery("
            INSERT INTO public.prompts (prompt_key, default_prompt, description)
            VALUES ('{$promptKey}', '{$prompt}', '{$description}')
            ON CONFLICT (prompt_key) DO UPDATE SET
                default_prompt = EXCLUDED.default_prompt,
                description = EXCLUDED.description,
                updated_at = CURRENT_TIMESTAMP
        ");
    }
}

function dialectic_ensure_dialecticnpcs_view(object $db): void
{
    static $done = false;
    if ($done || !method_exists($db, 'execQuery')) {
        return;
    }
    $done = true;

    $db->execQuery("
        CREATE OR REPLACE VIEW public.dialecticnpcs AS
        SELECT
            id,
            npc_name,
            npc_favorite,
            lock_profile,
            prompt_head,
            npc_static_bio,
            worldknowledge_tags,
            emote_moods,
            refid,
            base,
            gender,
            race,
            voiceid,
            core,
            profile_id,
            dynamic_profile,
            personality,
            speechstyle,
            occupation,
            appearance,
            skills,
            goals,
            metadata,
            extended_data,
            gamets_last_updated,
            tags,
            md5
        FROM public.core_npc_master
        WHERE npc_name <> 'The Narrator'
    ");
}

function dialectic_ensure_profile_defaults(object $db): void
{
    static $done = false;
    if ($done || !method_exists($db, 'execQuery') || !method_exists($db, 'fetchOne')) {
        return;
    }
    $done = true;

    $profileRow = $db->fetchOne("SELECT id FROM public.core_profiles ORDER BY id ASC LIMIT 1");
    if (!is_array($profileRow) || empty($profileRow['id'])) {
        return;
    }

    $firstProfileId = intval($profileRow['id']);

    $defaultNpcRow = $db->fetchOne("SELECT id FROM public.core_profiles WHERE default_npc = '1' ORDER BY id ASC LIMIT 1");
    if (!is_array($defaultNpcRow) || empty($defaultNpcRow['id'])) {
        $db->execQuery("UPDATE public.core_profiles SET default_npc = '1' WHERE id = {$firstProfileId}");
        $defaultNpcId = $firstProfileId;
    } else {
        $defaultNpcId = intval($defaultNpcRow['id']);
    }

    $defaultNarratorRow = $db->fetchOne("SELECT id FROM public.core_profiles WHERE default_narrator = '1' ORDER BY id ASC LIMIT 1");
    if (!is_array($defaultNarratorRow) || empty($defaultNarratorRow['id'])) {
        $db->execQuery("UPDATE public.core_profiles SET default_narrator = '1' WHERE id = {$firstProfileId}");
    }

    $db->execQuery("
        WITH npc_default AS (
            SELECT id FROM public.core_profiles WHERE default_npc = '1' ORDER BY id ASC LIMIT 1
        )
        UPDATE public.core_profiles p
        SET default_npc = CASE WHEN p.id = (SELECT id FROM npc_default) THEN '1' ELSE '0' END
        WHERE p.default_npc = '1'
           OR p.id = (SELECT id FROM npc_default)
    ");

    $db->execQuery("
        WITH narrator_default AS (
            SELECT id FROM public.core_profiles WHERE default_narrator = '1' ORDER BY id ASC LIMIT 1
        )
        UPDATE public.core_profiles p
        SET default_narrator = CASE WHEN p.id = (SELECT id FROM narrator_default) THEN '1' ELSE '0' END
        WHERE p.default_narrator = '1'
           OR p.id = (SELECT id FROM narrator_default)
    ");

    $db->execQuery("
        UPDATE public.core_npc_master
        SET profile_id = {$defaultNpcId}
        WHERE npc_name <> 'The Narrator'
          AND (
              profile_id IS NULL
              OR NOT EXISTS (
                  SELECT 1 FROM public.core_profiles p WHERE p.id = public.core_npc_master.profile_id
              )
          )
    ");
}

function dialectic_db_escape(object $db, string $value): string
{
    if (method_exists($db, 'escape')) {
        return $db->escape($value);
    }

    return str_replace("'", "''", $value);
}

function dialectic_parse_payload_fields(string $payload): array
{
    $fields = [];
    $trimmed = trim($payload);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $fields[$key] = $value;
                }
            }

            foreach ([
                'target' => ['name' => ['target', 'npc'], 'refid' => ['target_refid', 'npc_id']],
                'speaker' => ['name' => ['speaker', 'actor_name'], 'refid' => ['speaker_refid', 'actor_refid']],
                'player_actor' => ['name' => ['player']],
                'player' => ['name' => ['player']],
            ] as $objectKey => $mapping) {
                if (!isset($decoded[$objectKey]) || !is_array($decoded[$objectKey])) {
                    continue;
                }

                foreach ($mapping as $sourceKey => $aliases) {
                    if (!isset($decoded[$objectKey][$sourceKey]) || !is_scalar($decoded[$objectKey][$sourceKey])) {
                        continue;
                    }

                    foreach ($aliases as $alias) {
                        if (!isset($fields[$alias]) || trim(strval($fields[$alias])) === '') {
                            $fields[$alias] = $decoded[$objectKey][$sourceKey];
                        }
                    }
                }
            }

            if (isset($decoded['audience_snapshot']) && is_array($decoded['audience_snapshot'])) {
                $encodedAudience = json_encode($decoded['audience_snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encodedAudience)) {
                    $fields['audience_snapshot'] = $encodedAudience;
                }
            }

            return $fields;
        }
    }

    return [];
}

function dialectic_first_payload_value(array $fields, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($fields[$key]) && trim(strval($fields[$key])) !== '') {
            return trim(strval($fields[$key]));
        }
    }

    return '';
}

function dialectic_extract_npc_refid(string $payload, array $fields = []): string
{
    if (empty($fields)) {
        $fields = dialectic_parse_payload_fields($payload);
    }

    return dialectic_first_payload_value($fields, [
        'npc_id',
        'npc_refid',
        'refid',
        'ref_id',
        'reference_id',
        'actor_refid',
        'target_refid',
    ]);
}

function dialectic_extract_player_text(string $payload): string
{
    $fields = dialectic_parse_payload_fields($payload);
    $text = dialectic_first_payload_value($fields, [
        'text',
        'message',
        'input',
        'utterance',
        'player_text',
        'speech',
    ]);
    if ($text !== '') {
        return $text;
    }

    return '';
}

function dialectic_extract_npc_profile_fields(string $payload): array
{
    $fields = dialectic_parse_payload_fields($payload);

    $aliases = [
        'refid' => ['npc_id', 'npc_refid', 'refid', 'ref_id', 'reference_id', 'actor_refid', 'target_refid'],
        'baseid' => ['baseid', 'base_id', 'actor_baseid', 'formid', 'form_id'],
        'gender' => ['gender', 'sex'],
        'race' => ['race', 'species'],
        'strength' => ['strength'],
        'perception' => ['perception'],
        'endurance' => ['endurance'],
        'charisma' => ['charisma'],
        'intelligence' => ['intelligence'],
        'agility' => ['agility'],
        'luck' => ['luck'],
        'barter' => ['barter'],
        'energy_weapons' => ['energy_weapons', 'energyweapons'],
        'explosives' => ['explosives'],
        'guns' => ['guns'],
        'lockpick' => ['lockpick'],
        'medicine' => ['medicine'],
        'melee_weapons' => ['melee_weapons', 'meleeweapons'],
        'repair' => ['repair'],
        'science' => ['science'],
        'sneak' => ['sneak'],
        'speech' => ['speech'],
        'survival' => ['survival'],
        'unarmed' => ['unarmed'],
        'head' => ['head'],
        'upper_body' => ['upper_body', 'upperbody'],
        'left_hand' => ['left_hand', 'lefthand'],
        'right_hand' => ['right_hand', 'righthand'],
        'hair' => ['hair'],
        'weapon' => ['weapon'],
        'upper_body_addon' => ['upper_body_addon', 'upperbodyaddon'],
        'lower_body_addon' => ['lower_body_addon', 'lowerbodyaddon'],
        'level' => ['level'],
        'health' => ['health'],
        'health_max' => ['health_max', 'healthmax', 'max_health'],
        'action_points' => ['action_points', 'actionpoints', 'ap'],
        'action_points_max' => ['action_points_max', 'actionpointsmax', 'ap_max', 'max_ap'],
        'scale' => ['scale'],
        'xp' => ['xp', 'experience'],
        'karma' => ['karma'],
        'class' => ['class', 'npc_class', 'actor_class'],
        'faction' => ['faction', 'factions'],
        'reputation' => ['reputation'],
        'location' => ['location', 'cell', 'worldspace'],
        'voice' => ['voice', 'voiceid', 'voice_id'],
        'voice_formid' => ['voice_formid', 'voice_form_id'],
        'voice_name' => ['voice_name', 'voicename'],
        'confidence' => ['confidence'],
        'aggression' => ['aggression'],
        'assistance' => ['assistance'],
        'morality' => ['morality'],
    ];

    $data = [];
    foreach ($aliases as $target => $keys) {
        $value = dialectic_first_payload_value($fields, $keys);
        if ($value !== '') {
            $data[$target] = $value;
        }
    }

    return $data;
}

function dialectic_parse_actor_pair_list(string $value): array
{
    $items = [];
    foreach (preg_split('/[#|,]/', $value) as $entry) {
        $entry = trim(strval($entry));
        if ($entry === '') {
            continue;
        }

        [$formid, $rank] = array_pad(explode(':', $entry, 2), 2, '');
        $formid = trim($formid);
        if ($formid === '') {
            continue;
        }

        $items[] = [
            'formid' => $formid,
            'rank' => trim($rank) !== '' ? intval($rank) : 0,
        ];
    }

    return $items;
}

function dialectic_default_npc_profile_id(object $db): ?int
{
    if (!method_exists($db, 'fetchOne')) {
        return null;
    }

    $row = $db->fetchOne("SELECT id FROM public.core_profiles WHERE default_npc='1' ORDER BY id ASC LIMIT 1");
    if (!is_array($row) || empty($row['id'])) {
        $row = $db->fetchOne("SELECT id FROM public.core_profiles ORDER BY id ASC LIMIT 1");
    }

    return (is_array($row) && !empty($row['id'])) ? intval($row['id']) : null;
}

function dialectic_npc_name_to_codename(string $npcName): string
{
    $codename = strtolower(trim($npcName));
    $codename = strtr($codename, [" " => "_", "'" => "+"]);
    $codename = preg_replace('/[^\w+-]/u', '', $codename);
    return is_string($codename) ? $codename : '';
}

function dialectic_normalize_formid(string $formid): string
{
    $formid = strtolower(trim($formid));
    $formid = preg_replace('/^0x/i', '', $formid);
    $formid = preg_replace('/[^0-9a-fx]+/i', '', $formid);
    return is_string($formid) ? $formid : '';
}

function dialectic_resolve_actor_form_identity(object $db, string $formid): ?array
{
    $stable = dialecticParseStableFormReference($formid);
    if (is_array($stable)) {
        return [
            'plugin' => $stable['plugin_name'],
            'local_formid' => $stable['local_formid'],
        ];
    }

    $runtimeFormid = dialecticNormalizeRuntimeFormId($formid);
    if ($runtimeFormid === '' || !method_exists($db, 'fetchOne')) {
        return null;
    }
    $prefix = str_starts_with($runtimeFormid, 'FE') ? substr($runtimeFormid, 0, 5) : substr($runtimeFormid, 0, 2);
    $escapedPrefix = dialectic_db_escape($db, $prefix);
    $plugin = $db->fetchOne("
        SELECT plugin_name
        FROM public.game_plugins
        WHERE upper(formid_prefix) = upper('{$escapedPrefix}')
        LIMIT 1
    ");
    if (!is_array($plugin) || empty($plugin['plugin_name'])) {
        return null;
    }

    return [
        'plugin' => strval($plugin['plugin_name']),
        'local_formid' => dialecticExtractLocalFormIdFromRuntimeFormId($runtimeFormid),
    ];
}

function dialectic_fetch_bio_template(object $db, string $npcName, string $refid = '', string $baseid = ''): ?array
{
    if (!method_exists($db, 'fetchOne')) {
        return null;
    }

    $fields = "
        npc_name,
        core,
        npc_static_bio,
        worldknowledge_tags,
        personality,
        relationships,
        occupation,
        appearance,
        skills,
        speechstyle,
        goals,
        voiceid,
        gender,
        race,
        refid
    ";

    $fetchMappedTemplate = static function (array $identity, bool $reference) use ($db): ?array {
        $plugin = dialectic_db_escape($db, strval($identity['plugin'] ?? ''));
        $localFormid = dialectic_db_escape($db, strtoupper(strval($identity['local_formid'] ?? '')));
        if ($plugin === '' || $localFormid === '') {
            return null;
        }
        $pluginColumn = $reference ? 'reference_plugin' : 'base_plugin';
        $formidColumn = $reference ? 'reference_local_formid' : 'base_local_formid';
        $row = $db->fetchOne("
            SELECT t.*, TRUE AS is_nonverbal_creature
            FROM public.bio_template_actor_map m
            JOIN public.combined_bio_templates t ON lower(t.npc_name) = lower(m.template_name)
            WHERE lower(m.{$pluginColumn}) = lower('{$plugin}')
              AND upper(m.{$formidColumn}) = upper('{$localFormid}')
            ORDER BY m.id ASC
            LIMIT 1
        ");
        return (is_array($row) && !empty($row['npc_name'])) ? $row : null;
    };

    $referenceIdentity = dialectic_resolve_actor_form_identity($db, $refid);
    if (is_array($referenceIdentity)) {
        $row = $fetchMappedTemplate($referenceIdentity, true);
        if (is_array($row)) {
            return $row;
        }
    }

    $baseIdentity = dialectic_resolve_actor_form_identity($db, $baseid);
    if (is_array($baseIdentity)) {
        $row = $fetchMappedTemplate($baseIdentity, false);
        if (is_array($row)) {
            return $row;
        }
    }

    $normalizedRefid = dialectic_normalize_formid($refid);
    if ($normalizedRefid !== '') {
        $escapedRefid = dialectic_db_escape($db, $normalizedRefid);
        $row = $db->fetchOne("
            SELECT {$fields}, FALSE AS is_nonverbal_creature
            FROM public.combined_bio_templates
            WHERE regexp_replace(lower(COALESCE(refid, '')), '[^0-9a-fx]+', '', 'g') = '{$escapedRefid}'
            LIMIT 1
        ");
        if (is_array($row) && !empty($row['npc_name'])) {
            return $row;
        }
    }

    $codename = dialectic_npc_name_to_codename($npcName);
    if ($codename === '') {
        return null;
    }

    $escapedCodename = dialectic_db_escape($db, $codename);
    $escapedName = dialectic_db_escape($db, $npcName);

    $row = $db->fetchOne("
        SELECT t.*, TRUE AS is_nonverbal_creature
        FROM public.combined_bio_templates t
        JOIN (
            SELECT min(template_name) AS template_name
            FROM public.bio_template_actor_map
            WHERE lower(COALESCE(exact_name, '')) = lower('{$escapedName}')
            HAVING count(DISTINCT lower(template_name)) = 1
        ) m ON lower(m.template_name) = lower(t.npc_name)
        LIMIT 1
    ");
    if (is_array($row) && !empty($row['npc_name'])) {
        return $row;
    }

    $row = $db->fetchOne("
        SELECT {$fields}, FALSE AS is_nonverbal_creature
        FROM public.combined_bio_templates
        WHERE lower(npc_name) = lower('{$escapedCodename}')
           OR lower(npc_name) = lower('{$escapedName}')
        ORDER BY CASE WHEN lower(npc_name) = lower('{$escapedCodename}') THEN 0 ELSE 1 END
        LIMIT 1
    ");
    if (is_array($row) && !empty($row['npc_name'])) {
        return $row;
    }

    $row = $db->fetchOne("
        SELECT t.*, FALSE AS is_nonverbal_creature
        FROM public.combined_bio_templates t
        WHERE lower(t.npc_name) LIKE lower('{$escapedCodename}') || '\\_%'
          AND NOT EXISTS (
              SELECT 1 FROM public.bio_template_actor_map m WHERE lower(m.template_name) = lower(t.npc_name)
          )
        ORDER BY length(t.npc_name) ASC, t.npc_name ASC
        LIMIT 1
    ");

    return (is_array($row) && !empty($row['npc_name'])) ? $row : null;
}

function dialectic_sql_nullable_string(object $db, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return "NULL";
    }
    return "'" . dialectic_db_escape($db, $value) . "'";
}

function dialectic_is_temporary_silent_voice(string $voiceId, string $voiceName = ''): bool
{
    $voiceKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId . ' ' . $voiceName));
    if ($voiceKey === '') {
        return false;
    }

    return str_contains($voiceKey, 'nodialogue') ||
        str_contains($voiceKey, 'donotrecord') ||
        str_contains($voiceKey, 'nvdlec01femaleunquenodialogue') ||
        str_contains($voiceKey, 'nvdlc01femaleunquenodialogue');
}

function dialectic_ensure_npc(object $db, string $npcName, string $refid = '', array $profileFields = []): void
{
    $npcName = trim($npcName);
    if ($npcName === '' || $npcName === 'The Narrator') {
        return;
    }

    $escapedName = dialectic_db_escape($db, $npcName);
    if ($refid === '' && !empty($profileFields['refid'])) {
        $refid = strval($profileFields['refid']);
    }

    $escapedRefid = dialectic_db_escape($db, $refid);
    $gender = trim(strval($profileFields['gender'] ?? ''));
    $race = trim(strval($profileFields['race'] ?? ''));
    $voice = trim(strval($profileFields['voice'] ?? ''));
    $voiceFormId = trim(strval($profileFields['voice_formid'] ?? ''));
    $voiceName = trim(strval($profileFields['voice_name'] ?? ''));
    $actorProfileVoice = $voice;
    if (dialectic_is_temporary_silent_voice($voice, $voiceName)) {
        $voice = '';
        $voiceFormId = '';
        $voiceName = '';
    }
    $profileId = dialectic_default_npc_profile_id($db);
    $baseid = trim(strval($profileFields['baseid'] ?? ''));
    $bioTemplate = dialectic_fetch_bio_template($db, $npcName, $refid, $baseid);

    $templateValue = static function (string $key) use ($bioTemplate): string {
        return is_array($bioTemplate) ? trim(strval($bioTemplate[$key] ?? '')) : '';
    };

    $core = $templateValue('core');
    $npcStaticBio = $templateValue('npc_static_bio');
    $worldknowledgeTags = $templateValue('worldknowledge_tags');
    $personality = $templateValue('personality');
    $relationships = $templateValue('relationships');
    $occupation = $templateValue('occupation');
    $appearance = $templateValue('appearance');
    $skills = $templateValue('skills');
    $speechstyle = $templateValue('speechstyle');
    $goals = $templateValue('goals');

    if ($personality === '') {
        $personality = 'A Fallout: New Vegas wasteland resident. Replace this generated DIALECTIC seed profile with richer character data when available.';
    }
    if ($speechstyle === '') {
        $speechstyle = 'Speaks plainly and reacts to the Courier and the current situation.';
    }

    if ($gender === '') {
        $gender = $templateValue('gender');
    }
    if ($race === '') {
        $race = $templateValue('race');
    }
    $isMappedCreature = is_array($bioTemplate) && !empty($bioTemplate['is_nonverbal_creature']);
    if ($isMappedCreature) {
        $voice = $templateValue('voiceid');
        $voiceFormId = '';
        $voiceName = '';
    } elseif ($voice === '') {
        $voice = $templateValue('voiceid');
    }

    $extended = [];
    foreach ($profileFields as $key => $value) {
        if ($value !== '' && !in_array($key, ['refid', 'gender', 'race', 'voice', 'voice_formid', 'voice_name'], true)) {
            if (is_array($value)) {
                $extended[$key] = $value;
            } elseif ($key === 'factions' || $key === 'faction') {
                $parsedFactions = dialectic_parse_actor_pair_list(strval($value));
                $extended['factions'] = !empty($parsedFactions) ? $parsedFactions : $value;
            } else {
                $extended[$key] = $value;
            }
        }
    }
    if ($voice !== '' || $voiceFormId !== '' || $voiceName !== '') {
        $extended['voice_metadata'] = [
            'voiceid' => $voice,
            'voice_formid' => $voiceFormId,
            'voice_name' => $voiceName,
            'source' => $isMappedCreature ? 'creature_bio_template' : 'actor_profile',
            'updated_at' => time(),
        ];
    }

    $metadataPayload = [
        'source' => 'dialectic',
        'game' => 'fnv',
        'last_seen_by' => 'main.php',
    ];
    if (is_array($bioTemplate) && !empty($bioTemplate['npc_name'])) {
        $metadataPayload['bio_template'] = $bioTemplate['npc_name'];
    }

    $metadata = dialectic_db_escape($db, json_encode($metadataPayload, JSON_UNESCAPED_SLASHES));
    $extendedJson = dialectic_db_escape($db, json_encode($extended, JSON_UNESCAPED_SLASHES));

    $db->execQuery("
        INSERT INTO public.core_npc_master (
            npc_name,
            npc_favorite,
            lock_profile,
            core,
            npc_static_bio,
            worldknowledge_tags,
            personality,
            relationships,
            occupation,
            appearance,
            skills,
            speechstyle,
            goals,
            gender,
            race,
            voiceid,
            refid,
            base,
            profile_id,
            metadata,
            extended_data,
            gamets_last_updated,
            md5
        ) VALUES (
            '{$escapedName}',
            0,
            0,
            " . dialectic_sql_nullable_string($db, $core) . ",
            " . dialectic_sql_nullable_string($db, $npcStaticBio) . ",
            " . dialectic_sql_nullable_string($db, $worldknowledgeTags) . ",
            " . dialectic_sql_nullable_string($db, $personality) . ",
            " . dialectic_sql_nullable_string($db, $relationships) . ",
            " . dialectic_sql_nullable_string($db, $occupation) . ",
            " . dialectic_sql_nullable_string($db, $appearance) . ",
            " . dialectic_sql_nullable_string($db, $skills) . ",
            " . dialectic_sql_nullable_string($db, $speechstyle) . ",
            " . dialectic_sql_nullable_string($db, $goals) . ",
            " . dialectic_sql_nullable_string($db, $gender) . ",
            " . dialectic_sql_nullable_string($db, $race) . ",
            " . dialectic_sql_nullable_string($db, $voice) . ",
            " . ($refid !== '' ? "'{$escapedRefid}'" : "NULL") . ",
            " . dialectic_sql_nullable_string($db, $baseid) . ",
            " . ($profileId !== null ? intval($profileId) : "NULL") . ",
            '{$metadata}'::jsonb,
            '{$extendedJson}'::jsonb,
            0,
            md5('{$escapedName}')
        )
        ON CONFLICT (npc_name) DO UPDATE SET
            core = COALESCE(NULLIF(public.core_npc_master.core, ''), EXCLUDED.core),
            npc_static_bio = COALESCE(NULLIF(public.core_npc_master.npc_static_bio, ''), EXCLUDED.npc_static_bio),
            worldknowledge_tags = COALESCE(NULLIF(public.core_npc_master.worldknowledge_tags, ''), EXCLUDED.worldknowledge_tags),
            personality = CASE
                WHEN public.core_npc_master.personality IN (
                    'A Fallout: New Vegas wasteland resident. Replace this generated Dialectic seed profile with richer character data when available.',
                    'A Fallout: New Vegas wasteland resident. Replace this generated DIALECTIC seed profile with richer character data when available.'
                )
                    THEN EXCLUDED.personality
                ELSE COALESCE(NULLIF(public.core_npc_master.personality, ''), EXCLUDED.personality)
            END,
            relationships = COALESCE(NULLIF(public.core_npc_master.relationships, ''), EXCLUDED.relationships),
            occupation = COALESCE(NULLIF(public.core_npc_master.occupation, ''), EXCLUDED.occupation),
            appearance = COALESCE(NULLIF(public.core_npc_master.appearance, ''), EXCLUDED.appearance),
            skills = COALESCE(NULLIF(public.core_npc_master.skills, ''), EXCLUDED.skills),
            speechstyle = CASE
                WHEN public.core_npc_master.speechstyle = 'Speaks plainly and reacts to the Courier and the current situation.'
                    THEN EXCLUDED.speechstyle
                ELSE COALESCE(NULLIF(public.core_npc_master.speechstyle, ''), EXCLUDED.speechstyle)
            END,
            goals = COALESCE(NULLIF(public.core_npc_master.goals, ''), EXCLUDED.goals),
            gender = COALESCE(NULLIF(EXCLUDED.gender, ''), public.core_npc_master.gender),
            race = COALESCE(NULLIF(EXCLUDED.race, ''), public.core_npc_master.race),
            voiceid = CASE
                WHEN public.core_npc_master.lock_profile = 1 THEN public.core_npc_master.voiceid
                WHEN " . ($isMappedCreature ? "TRUE" : "FALSE") . "
                 AND (
                    NULLIF(public.core_npc_master.voiceid, '') IS NULL
                    OR lower(public.core_npc_master.voiceid) = lower(" . dialectic_sql_nullable_string($db, $actorProfileVoice) . ")
                 ) THEN EXCLUDED.voiceid
                ELSE COALESCE(NULLIF(public.core_npc_master.voiceid, ''), NULLIF(EXCLUDED.voiceid, ''))
            END,
            refid = COALESCE(NULLIF(EXCLUDED.refid, ''), public.core_npc_master.refid),
            base = COALESCE(NULLIF(EXCLUDED.base, ''), public.core_npc_master.base),
            profile_id = COALESCE(public.core_npc_master.profile_id, EXCLUDED.profile_id),
            metadata = COALESCE(public.core_npc_master.metadata, '{}'::jsonb) || EXCLUDED.metadata,
            extended_data = COALESCE(public.core_npc_master.extended_data, '{}'::jsonb) || EXCLUDED.extended_data,
            gamets_last_updated = EXCLUDED.gamets_last_updated
    ");
}

?>
