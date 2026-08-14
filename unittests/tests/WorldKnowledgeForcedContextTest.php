<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/settings.php';
require_once __DIR__ . '/../../lib/worldknowledge_forced_context.php';

final class WorldKnowledgeForcedContextTest extends TestCase
{
    public function testForcedContextAndFallbackSettingsAreManagedAndEnabledByDefault(): void
    {
        foreach (['LOCATION_WORLDKNOWLEDGE', 'RACE_WORLDKNOWLEDGE', 'FACTION_WORLDKNOWLEDGE', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK'] as $setting) {
            $this->assertContains($setting, dialecticGetManagedGeneralSettingIds());
            $definition = dialecticGetSchemaDefinition($setting);
            $this->assertTrue($definition['default'] ?? false);
            $this->assertSame('global', $definition['scope'] ?? null);
            $this->assertSame('World Knowledge', dialecticGetOverrideableGeneralSettingCategory($setting));
        }
    }

    public function testInteriorLocationAndWorldspaceProduceSeparateSignals(): void
    {
        $this->assertSame(
            [
                'location' => ['goodsprings general store', 'goodsprings'],
                'worldspace' => ['mojave wasteland'],
            ],
            dialecticWorldKnowledgeBuildLocationSignalGroups(
                'Goodsprings General Store',
                'Mojave Wasteland',
                [['name' => 'Goodsprings General Store', 'worldspace' => 'Mojave Wasteland']]
            )
        );
    }

    public function testDlcWorldspacesRetainLocalAndParentRegionTags(): void
    {
        $this->assertSame(['big_mt', 'mojave'], dialecticWorldKnowledgeNormalizeRegionTags('Big MT'));
        $this->assertSame(
            ['point_lookout', 'capital_wasteland'],
            dialecticWorldKnowledgeNormalizeRegionTags('Point Lookout')
        );
        $this->assertSame(
            ['mothership_zeta', 'capital_wasteland'],
            dialecticWorldKnowledgeNormalizeRegionTags('Mothership Zeta')
        );
        $this->assertSame('mojave', dialecticWorldKnowledgeNormalizeRegionTag('Zion Canyon'));
    }

    public function testAdvancedAndBasicKnowledgePermissionsArePreserved(): void
    {
        $row = [
            'topic_desc' => 'Restricted advanced lore.',
            'knowledge_class' => 'scholar,!raider',
            'topic_desc_basic' => 'Common basic lore.',
            'knowledge_class_basic' => '',
        ];

        $this->assertSame(
            ['level' => 'advanced', 'description' => 'Restricted advanced lore.'],
            dialecticWorldKnowledgeResolveKnowledgePayload($row, ['scholar'])
        );
        $this->assertSame(
            ['level' => 'basic', 'description' => 'Common basic lore.'],
            dialecticWorldKnowledgeResolveKnowledgePayload($row, ['scholar', 'raider'])
        );
    }

    public function testParityAccessDecisionSupportsAdvancedBasicDeniedAndKnowall(): void
    {
        $row = [
            'topic' => 'enclave_archive,Enclave Archive',
            'source_kind' => 'factory',
            'topic_desc' => 'Restricted advanced lore.',
            'knowledge_class' => 'enclave,!raider',
            'topic_desc_basic' => 'Common basic lore.',
            'knowledge_class_basic' => 'common,!raider',
        ];

        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['enclave'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($row, ['common'])['level']);
        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($row, ['wastelander'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['knowall', 'raider'])['level']);
    }

    public function testAccessRulesGrantOnAnyFlatClass(): void
    {
        $row = [
            'topic' => 'alien',
            'topic_desc' => 'Confirmed technical details.',
            'knowledge_class' => 'scientist,xenotechnology,lone_wanderer',
            'topic_desc_basic' => 'Some wastelanders tell stories about strange lights.',
            'knowledge_class_basic' => 'capital_wasteland',
        ];

        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($row, ['common'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($row, ['region:capital_wasteland'])['level']);
        $advanced = dialecticWorldKnowledgeAccessDecision($row, ['role:scientist']);
        $this->assertSame('advanced', $advanced['level']);
        $this->assertSame(['scientist'], $advanced['matched']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['person:lone_wanderer'])['level']);
    }

    public function testLegacyNamespacesNormalizeWithoutCollidingWithExactPeople(): void
    {
        $this->assertSame('ncr', dialecticWorldKnowledgeNormalizeAccessTag('faction:ncr'));
        $this->assertSame('raider', dialecticWorldKnowledgeNormalizeAccessTag('raiders'));
        $this->assertSame('zion', dialecticWorldKnowledgeNormalizeAccessTag('community:zion_canyon'));
        $this->assertSame('traveler', dialecticWorldKnowledgeNormalizeAccessTag('role:courier'));
        $this->assertSame('courier', dialecticWorldKnowledgeNormalizeAccessTag('person:courier'));
        $this->assertSame('the_vault_dweller', dialecticWorldKnowledgeNormalizeAccessTag('person:vault_dweller'));
        $this->assertSame('vault_dweller', dialecticWorldKnowledgeNormalizeAccessTag('role:vault_dweller'));
        $this->assertSame(
            'scientist,xenotechnology,lone_wanderer',
            dialecticWorldKnowledgeNormalizeAccessRule('role:scientist,domain:xenotechnology,person:lone_wanderer')
        );
        $courierArticle = [
            'topic' => 'courier',
            'topic_desc' => 'Personal history of the Courier.',
            'knowledge_class' => 'courier',
            'topic_desc_basic' => '',
            'knowledge_class_basic' => 'courier',
        ];
        $this->assertSame(
            'denied',
            dialecticWorldKnowledgeAccessDecision($courierArticle, ['role:courier'])['level']
        );
        $this->assertSame(
            'advanced',
            dialecticWorldKnowledgeAccessDecision($courierArticle, ['person:courier'])['level']
        );
    }

    public function testNegativeRulesDenyBeforePositiveClasses(): void
    {
        $denied = dialecticWorldKnowledgeClassDecision('common,!raider', ['common', 'raider']);
        $this->assertFalse($denied['allowed']);
        $this->assertSame('negative_class', $denied['reason']);
        $this->assertFalse(dialecticWorldKnowledgeClassDecision('!raider', ['common'])['allowed']);
        $this->assertTrue(dialecticWorldKnowledgeClassDecision('', ['common'])['allowed']);
    }

    public function testPersonAndRegionalRulesDoNotLeakAcrossTheTtwMap(): void
    {
        $amata = [
            'topic' => 'amata',
            'topic_desc' => 'Private details known to close associates.',
            'knowledge_class' => 'amata,lone_wanderer,alphonse_almodovar,james',
            'topic_desc_basic' => 'Amata is the Overseer\'s daughter in Vault 101.',
            'knowledge_class_basic' => 'vault_101,amata,lone_wanderer',
        ];
        $goodsprings = [
            'topic' => 'goodsprings',
            'topic_desc' => 'Detailed local history.',
            'knowledge_class' => 'goodsprings,historian',
            'topic_desc_basic' => 'Goodsprings is a small Mojave settlement.',
            'knowledge_class_basic' => 'mojave,traveler',
        ];

        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($amata, ['common', 'region:capital_wasteland'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($amata, ['community:vault_101'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($amata, ['person:amata'])['level']);
        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($goodsprings, ['common', 'region:capital_wasteland'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($goodsprings, ['common', 'region:mojave'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($goodsprings, ['role:historian', 'region:mojave'])['level']);
    }

    public function testRestrictedFactoryArticleUsesFlatClassesAndKnowallOverride(): void
    {
        $row = [
            'topic' => 'restricted_enclave_archive',
            'source_kind' => 'factory',
            'catalog_id' => 'fallout-3-new-vegas-ttw-official',
            'topic_desc' => 'Restricted operational details known only to the archive custodian and authorized Enclave researchers.',
            'knowledge_class' => 'archive_custodian,enclave,researcher,prewar_archives',
            'topic_desc_basic' => '',
            'knowledge_class_basic' => 'archive_custodian',
        ];

        foreach ([
            ['common'],
            ['common', 'region:capital_wasteland'],
        ] as $unauthorizedTags) {
            $decision = dialecticWorldKnowledgeAccessDecision($row, $unauthorizedTags);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame('', $decision['description']);
        }

        $this->assertSame(
            'advanced',
            dialecticWorldKnowledgeAccessDecision($row, ['person:archive_custodian'])['level']
        );
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['faction:enclave'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
    }

    public function testFactoryTemplateTagsAugmentExistingProfilesWithoutReplacingUserTags(): void
    {
        $previousDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = new class {
            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                return ['worldknowledge_tags' => 'community:goodsprings,region:mojave,role:doctor'];
            }
        };
        $GLOBALS['WORLDKNOWLEDGE'] = 'domain:medicine';
        $GLOBALS['DIALECTIC_NAME'] = 'Doc Mitchell';
        $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] = ['npc_codename' => 'doc_mitchell'];

        try {
            $tags = dialecticWorldKnowledgeKnowledgeTags();
            $this->assertContains('medicine', $tags);
            $this->assertContains('goodsprings', $tags);
            $this->assertContains('mojave', $tags);
            $this->assertContains('doc_mitchell', $tags);
        } finally {
            $GLOBALS['db'] = $previousDb;
        }
    }

    public function testChronologyAllowsOnlyArticlesValidForTheCurrentEra(): void
    {
        $row = ['valid_from_year' => '2281', 'valid_to_year' => '2282'];

        $this->assertTrue(dialecticWorldKnowledgeChronologyAllows($row, null));
        $this->assertFalse(dialecticWorldKnowledgeChronologyAllows($row, 2277));
        $this->assertTrue(dialecticWorldKnowledgeChronologyAllows($row, 2281));
        $this->assertFalse(dialecticWorldKnowledgeChronologyAllows($row, 2283));
    }

    public function testInjectedAliasesDeduplicateNormalMatching(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        dialecticWorldKnowledgeMarkTopicInjected('new_california_republic,NCR');

        $this->assertTrue(dialecticWorldKnowledgeTopicWasInjected('New California Republic'));
        $this->assertTrue(dialecticWorldKnowledgeTopicWasInjected('NCR'));
        $this->assertFalse(dialecticWorldKnowledgeTopicWasInjected('Caesars Legion'));
    }

    public function testForcedPromptUsesCanonicalTopicInsteadOfAliasList(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        $added = dialecticWorldKnowledgeAppendForcedRows(
            [[
                'topic' => 'mojave_wasteland,Mojave',
                'topic_desc' => '',
                'knowledge_class' => '',
                'topic_desc_basic' => 'The Mojave Wasteland surrounds New Vegas.',
                'knowledge_class_basic' => '',
            ]],
            [],
            'worldspace',
            1
        );

        $this->assertSame(1, $added);
        $this->assertStringContainsString('<article topic="mojave_wasteland" source="worldspace" access="basic">', $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertStringNotContainsString('mojave_wasteland,Mojave', $GLOBALS['WORLDKNOWLEDGE_HINT']);
    }

    public function testRaceAndFactionSignalsComeFromActiveNpcData(): void
    {
        $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] = [
            'race' => 'Ghoul Race',
            'extended_data' => json_encode([
                'factions' => [
                    ['name' => 'Followers of the Apocalypse', 'formid' => '00123456', 'rank' => 0],
                    ['name' => 'Former Raiders', 'formid' => '00654321', 'rank' => -1],
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        $signals = dialecticWorldKnowledgeCurrentNpcSignals(null);

        $this->assertSame(['ghoul race', 'ghoul'], $signals['race']);
        $this->assertSame(['followers of the apocalypse'], $signals['faction']);
    }

    public function testDeniedForcedArticleIsAuditedButNotInjected(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] = [];

        $added = dialecticWorldKnowledgeAppendForcedRows([[
            'topic' => 'enclave_secrets',
            'topic_desc' => 'Restricted Enclave data.',
            'knowledge_class' => 'enclave',
            'topic_desc_basic' => '',
            'knowledge_class_basic' => 'enclave',
        ]], ['wastelander'], 'faction', 1);

        $this->assertSame(0, $added);
        $this->assertSame('', $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertSame('denied', $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'][0]['level'] ?? null);
    }

    public function testDeniedConversationArticleRendersWithoutProtectedText(): void
    {
        $row = [
            'topic' => 'enclave_secrets,Enclave Secrets',
            'category' => 'history',
            'catalog_id' => 'fallout-test',
            'catalog_version' => 'v1',
        ];
        $decision = [
            'topic' => 'enclave_secrets',
            'level' => 'denied',
            'reason' => 'missing_required_class',
            'description' => '',
        ];

        $xml = dialecticWorldKnowledgeRenderArticleXml($row, $decision, 'conversation');

        $this->assertStringContainsString('topic="enclave_secrets" source="conversation" access="denied"', $xml);
        $this->assertStringContainsString('reason="missing_required_class"', $xml);
        $this->assertStringNotContainsString('Restricted Enclave data', $xml);
        $this->assertStringEndsWith('</article>', $xml);
    }

    public function testStructuredAuditIncludesContextTagsAndMatchedClassEvidence(): void
    {
        $db = new class {
            public string $query = '';

            public function escapeLiteral(string $value): string
            {
                return "'" . str_replace("'", "''", $value) . "'";
            }

            public function execQuery(string $query): bool
            {
                $this->query = $query;
                return true;
            }
        };

        dialecticWorldKnowledgeRecordAudit($db, [
            'status' => 'grounded',
            'context_tags' => ['common', 'mojave', 'scientist'],
            'access_decisions' => [[
                'topic' => 'big_mt',
                'level' => 'advanced',
                'matched' => ['scientist'],
            ]],
        ]);

        $this->assertStringContainsString('tag_decisions,context_tags,fallback', $db->query);
        $this->assertStringContainsString('"mojave"', $db->query);
        $this->assertStringContainsString('"matched":["scientist"]', $db->query);
        $this->assertStringNotContainsString('matched_clause', $db->query);
    }

    public function testFrozenDeterministicRetrievalCases(): void
    {
        $retriever = new DialecticWorldKnowledgeRetriever([
            ['topic' => 'new_california_republic,NCR', 'canonical_topic' => 'new_california_republic', 'category' => 'faction', 'tags' => 'representative democracy,california republic army'],
            ['topic' => 'caesars_legion,Caesar Legion', 'canonical_topic' => 'caesars_legion', 'category' => 'faction', 'retrieval_phrases' => 'slave army,roman war culture', 'tags' => 'military hierarchy,enslaved labor'],
            ['topic' => 'brotherhood_of_steel,Brotherhood', 'canonical_topic' => 'brotherhood_of_steel', 'category' => 'faction', 'retrieval_phrases' => 'technology hoarding', 'tags' => 'power armor,technology control'],
            ['topic' => 'enclave', 'canonical_topic' => 'enclave', 'category' => 'faction', 'tags' => 'power armor,pre war government'],
        ]);

        $cases = [
            ['Tell me about the NCR.', ['new_california_republic']],
            ['The NCR controls the checkpoint.', ['new_california_republic']],
            ['Explain the newcaliforniarepublic.', ['new_california_republic']],
            ['Transcript may have said new californa republic.', ['new_california_republic']],
            ['Tell me about the slave army and roman war culture.', ['caesars_legion']],
            ['Followers: I followed the road west.', []],
            ['I found power armor on the road.', []],
            ['Tell me about representative democracy.', []],
            ['Tell me about power armor and technology hoarding.', ['brotherhood_of_steel']],
            ['Compare NCR and Caesar Legion.', ['new_california_republic', 'caesars_legion']],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, $retriever->extract($input, [], 3)['topics'], $input);
        }
    }

    public function testWorldKnowledgeOverridesUseGlobalThenProfileThenNpcPrecedence(): void
    {
        $GLOBALS['RACE_WORLDKNOWLEDGE'] = true;
        dialecticApplyOverrideValueToGlobals('RACE_WORLDKNOWLEDGE', 'false');
        $this->assertFalse($GLOBALS['RACE_WORLDKNOWLEDGE']);

        dialecticApplyOverrideValueToGlobals('RACE_WORLDKNOWLEDGE', 'true');
        $this->assertTrue($GLOBALS['RACE_WORLDKNOWLEDGE']);
    }
}
