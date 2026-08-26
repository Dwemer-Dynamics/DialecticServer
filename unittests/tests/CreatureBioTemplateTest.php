<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dialectic_runtime.php';

final class CreatureBioTemplateTest extends DatabaseTestCase
{
    private function db(): sql
    {
        if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
            $GLOBALS['db'] = new sql();
        }
        return $GLOBALS['db'];
    }

    public function testRepresentativeStableIdentitiesResolveWithoutCreaturePrefixFallback(): void
    {
        $cases = [
            ['Dog', 'FalloutNV.esm|0014F425', 'creature_dog'],
            ['Brahmin', 'FalloutNV.esm|000A4E5D', 'creature_brahmin'],
            ['Bighorner', 'FalloutNV.esm|00108020', 'creature_bighorner'],
            ['Gecko', 'FalloutNV.esm|0010CD73', 'creature_gecko'],
            ['Deathclaw', 'FalloutNV.esm|0001CF72', 'creature_deathclaw'],
            ['Deathclaw Alpha', 'FalloutNV.esm|000E584F', 'creature_deathclaw_alpha'],
            ['Deathclaw Mother', 'FalloutNV.esm|000E5851', 'creature_deathclaw_mother'],
            ['Cazador', 'FalloutNV.esm|000E584D', 'creature_cazador'],
            ['Radscorpion', 'FalloutNV.esm|0001CF9D', 'creature_radscorpion'],
            ['Mirelurk', 'FalloutNV.esm|0001CF83', 'creature_mirelurk'],
            ['Yao Guai', 'HonestHearts.esm|00009F5B', 'creature_yao_guai'],
            ['Feral Ghoul', 'FalloutNV.esm|0001CF7A', 'creature_feral_ghoul'],
            ['Tunneler', 'LonesomeRoad.esm|00004C4A', 'creature_tunneler'],
            ['Rawr', 'LonesomeRoad.esm|00007D22', 'creature_rawr'],
        ];

        foreach ($cases as [$name, $baseid, $expected]) {
            $template = dialectic_fetch_bio_template($this->db(), $name, '', $baseid);
            self::assertSame($expected, $template['npc_name'] ?? null, $name);
            self::assertTrue(boolval($template['is_nonverbal_creature'] ?? false), $name);
        }
    }

    public function testExistingNamedTalkingAndRobotProfilesDoNotResolveAsCreatureTemplates(): void
    {
        $dogmeat = dialectic_fetch_bio_template($this->db(), 'Dogmeat');
        self::assertSame('dogmeat', strtolower(strval($dogmeat['npc_name'] ?? '')));

        foreach ([
            ['Super Mutant', 'FalloutNV.esm|0001D3BE'],
            ['Protectron', 'FalloutNV.esm|0001CF8B'],
        ] as [$name, $baseid]) {
            $template = dialectic_fetch_bio_template($this->db(), $name, '', $baseid);
            self::assertFalse(str_starts_with(strtolower(strval($template['npc_name'] ?? '')), 'creature_'));
        }
    }

    public function testCustomTemplateAndExistingNpcFieldsKeepPrecedence(): void
    {
        $db = $this->db();
        $db->execQuery("
            INSERT INTO public.bio_templates_custom (npc_name, npc_static_bio, personality, speechstyle, voiceid)
            VALUES ('creature_gecko', 'Custom gecko biography.', 'Custom gecko personality.', 'Answers direct questions briefly.', 'femaleadult12')
            ON CONFLICT (npc_name) DO UPDATE SET
                npc_static_bio=EXCLUDED.npc_static_bio,
                personality=EXCLUDED.personality,
                speechstyle=EXCLUDED.speechstyle,
                voiceid=EXCLUDED.voiceid
        ");
        $template = dialectic_fetch_bio_template($db, 'Gecko', '', 'FalloutNV.esm|0010CD73');
        self::assertSame('Custom gecko biography.', $template['npc_static_bio'] ?? null);
        self::assertSame('femaleadult12', $template['voiceid'] ?? null);

        dialectic_ensure_npc($db, 'Runtime Deathclaw', '00123456', [
            'baseid' => 'FalloutNV.esm|0001CF72',
            'voice' => 'CreatureDeathclaw',
        ]);
        $created = $db->fetchOne("SELECT * FROM public.core_npc_master WHERE npc_name='Runtime Deathclaw'");
        self::assertSame('maleold02', $created['voiceid'] ?? null);
        self::assertSame('FalloutNV.esm|0001CF72', $created['base'] ?? null);

        $db->execQuery("
            UPDATE public.core_npc_master
            SET voiceid='manual_test_voice', npc_static_bio='Manual biography.', lock_profile=1
            WHERE npc_name='Runtime Deathclaw'
        ");
        dialectic_ensure_npc($db, 'Runtime Deathclaw', '00123456', [
            'baseid' => 'FalloutNV.esm|0001CF72',
            'voice' => 'CreatureDeathclaw',
        ]);
        $preserved = $db->fetchOne("SELECT voiceid, npc_static_bio, lock_profile FROM public.core_npc_master WHERE npc_name='Runtime Deathclaw'");
        self::assertSame('manual_test_voice', $preserved['voiceid'] ?? null);
        self::assertSame('Manual biography.', $preserved['npc_static_bio'] ?? null);
        self::assertSame('1', strval($preserved['lock_profile'] ?? ''));
    }

    public function testCreatureSeedIsAdditiveIdempotentAndTransactional(): void
    {
        $connection = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer');
        self::assertNotFalse($connection);
        $seedPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'fallout_creature_bio_templates.sql';
        $seed = file_get_contents($seedPath);
        self::assertNotFalse($seed);

        $snapshot = static function ($connection): array {
            $result = pg_query($connection, "
                SELECT
                    count(*) FILTER (WHERE npc_name NOT LIKE 'creature\_%') AS human_count,
                    md5(string_agg(npc_name || COALESCE(core, ''), '|' ORDER BY npc_name)
                        FILTER (WHERE npc_name NOT LIKE 'creature\_%')) AS human_hash,
                    count(*) FILTER (WHERE npc_name LIKE 'creature\_%') AS creature_count,
                    (SELECT count(*) FROM public.bio_template_actor_map) AS mapping_count
                FROM public.bio_templates
            ");
            return pg_fetch_assoc($result);
        };

        $before = $snapshot($connection);
        self::assertNotFalse(pg_query($connection, $seed));
        self::assertNotFalse(pg_query($connection, $seed));
        $after = $snapshot($connection);
        self::assertSame($before, $after);
        self::assertSame('108', $after['creature_count']);
        self::assertSame('793', $after['mapping_count']);

        $dogBefore = pg_fetch_result(
            pg_query($connection, "SELECT core FROM public.bio_templates WHERE npc_name='creature_dog'"),
            0,
            0
        );
        $failingSeed = str_replace(
            'COMMIT;',
            "UPDATE public.bio_templates SET core='rollback marker' WHERE npc_name='creature_dog';\nSELECT 1/0;\nCOMMIT;",
            $seed
        );
        self::assertFalse(@pg_query($connection, $failingSeed));
        pg_query($connection, 'ROLLBACK');
        $dogAfter = pg_fetch_result(
            pg_query($connection, "SELECT core FROM public.bio_templates WHERE npc_name='creature_dog'"),
            0,
            0
        );
        self::assertSame($dogBefore, $dogAfter);
        pg_close($connection);
    }
}
