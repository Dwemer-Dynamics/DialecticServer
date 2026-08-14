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
        $this->assertSame(['region:big_mt', 'region:mojave'], dialecticWorldKnowledgeNormalizeRegionTags('Big MT'));
        $this->assertSame(
            ['region:point_lookout', 'region:capital_wasteland'],
            dialecticWorldKnowledgeNormalizeRegionTags('Point Lookout')
        );
        $this->assertSame(
            ['region:mothership_zeta', 'region:capital_wasteland'],
            dialecticWorldKnowledgeNormalizeRegionTags('Mothership Zeta')
        );
        $this->assertSame('region:mojave', dialecticWorldKnowledgeNormalizeRegionTag('Zion Canyon'));
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
        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($row, ['knowall', 'raider'])['level']);

        unset($row['source_kind']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
    }

    public function testAccessRulesRequireEveryTagWithinAClause(): void
    {
        $row = [
            'topic' => 'alien',
            'topic_desc' => 'Confirmed technical details.',
            'knowledge_class' => 'role:scientist&domain:xenotechnology|person:lone_wanderer',
            'topic_desc_basic' => 'Some wastelanders tell stories about strange lights.',
            'knowledge_class_basic' => 'common&region:capital_wasteland',
        ];

        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($row, ['common'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($row, ['common', 'region:capital_wasteland'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($row, ['role:scientist', 'common', 'region:capital_wasteland'])['level']);
        $advanced = dialecticWorldKnowledgeAccessDecision($row, ['role:scientist', 'domain:xenotechnology']);
        $this->assertSame('advanced', $advanced['level']);
        $this->assertSame(['role:scientist', 'domain:xenotechnology'], $advanced['matched_clause']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($row, ['person:lone_wanderer'])['level']);
    }

    public function testMalformedNonemptyRuleFailsClosedWithoutBreakingNegativeOnlyRules(): void
    {
        $invalid = dialecticWorldKnowledgeClassDecision('&&', ['common']);
        $this->assertFalse($invalid['allowed']);
        $this->assertFalse($invalid['denied']);
        $this->assertSame('invalid_rule', $invalid['reason']);

        $negativeOnly = dialecticWorldKnowledgeClassDecision('!raider', ['common']);
        $this->assertTrue($negativeOnly['allowed']);
        $this->assertSame('negative_only', $negativeOnly['reason']);
        $this->assertTrue(dialecticWorldKnowledgeClassDecision('', ['common'])['allowed']);
    }

    public function testPersonAndRegionalRulesDoNotLeakAcrossTheTtwMap(): void
    {
        $amata = [
            'topic' => 'amata',
            'topic_desc' => 'Private details known to close associates.',
            'knowledge_class' => 'person:amata|person:lone_wanderer|person:alphonse_almodovar|person:james',
            'topic_desc_basic' => 'Amata is the Overseer\'s daughter in Vault 101.',
            'knowledge_class_basic' => 'community:vault_101|person:amata|person:lone_wanderer',
        ];
        $goodsprings = [
            'topic' => 'goodsprings',
            'topic_desc' => 'Detailed local history.',
            'knowledge_class' => 'community:goodsprings|role:historian&region:mojave',
            'topic_desc_basic' => 'Goodsprings is a small Mojave settlement.',
            'knowledge_class_basic' => 'common&region:mojave|role:caravaner',
        ];

        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($amata, ['common', 'region:capital_wasteland'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($amata, ['community:vault_101'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($amata, ['person:amata'])['level']);
        $this->assertSame('denied', dialecticWorldKnowledgeAccessDecision($goodsprings, ['common', 'region:capital_wasteland'])['level']);
        $this->assertSame('basic', dialecticWorldKnowledgeAccessDecision($goodsprings, ['common', 'region:mojave'])['level']);
        $this->assertSame('advanced', dialecticWorldKnowledgeAccessDecision($goodsprings, ['role:historian', 'region:mojave'])['level']);
    }

    public function testFactorySecretCannotLeakThroughCommonRegionOrKnowall(): void
    {
        $row = [
            'topic' => 'restricted_enclave_archive',
            'source_kind' => 'factory',
            'catalog_id' => 'fallout-3-new-vegas-ttw-official',
            'topic_desc' => 'Restricted operational details known only to the archive custodian and authorized Enclave researchers.',
            'knowledge_class' => 'person:archive_custodian|faction:enclave&role:researcher&domain:prewar_archives',
            'topic_desc_basic' => '',
            'knowledge_class_basic' => 'person:archive_custodian',
        ];

        foreach ([
            ['common'],
            ['common', 'region:capital_wasteland'],
            ['knowall'],
            ['faction:enclave', 'role:researcher'],
        ] as $unauthorizedTags) {
            $decision = dialecticWorldKnowledgeAccessDecision($row, $unauthorizedTags);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame('', $decision['description']);
        }

        $this->assertSame(
            'advanced',
            dialecticWorldKnowledgeAccessDecision($row, ['person:archive_custodian'])['level']
        );
        $this->assertSame(
            'advanced',
            dialecticWorldKnowledgeAccessDecision(
                $row,
                ['faction:enclave', 'role:researcher', 'domain:prewar_archives']
            )['level']
        );
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
            $this->assertContains('domain:medicine', $tags);
            $this->assertContains('community:goodsprings', $tags);
            $this->assertContains('region:mojave', $tags);
            $this->assertContains('person:doc_mitchell', $tags);
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
        $this->assertStringContainsString('<article topic="mojave_wasteland" level="basic"', $GLOBALS['WORLDKNOWLEDGE_HINT']);
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

        $this->assertStringContainsString('topic="enclave_secrets" level="denied"', $xml);
        $this->assertStringContainsString('reason="missing_required_class"', $xml);
        $this->assertStringNotContainsString('Restricted Enclave data', $xml);
        $this->assertStringEndsWith(' />', $xml);
    }

    public function testStructuredAuditIncludesContextTagsAndMatchedClauseEvidence(): void
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
            'context_tags' => ['common', 'region:mojave', 'role:scientist'],
            'access_decisions' => [[
                'topic' => 'big_mt',
                'level' => 'advanced',
                'matched_clause' => ['role:scientist', 'domain:robotics'],
                'rule_version' => 2,
            ]],
        ]);

        $this->assertStringContainsString('tag_decisions,context_tags,fallback', $db->query);
        $this->assertStringContainsString('"region:mojave"', $db->query);
        $this->assertStringContainsString('"matched_clause":["role:scientist","domain:robotics"]', $db->query);
        $this->assertStringContainsString('"rule_version":2', $db->query);
    }

    public function testFrozenDeterministicRetrievalCases(): void
    {
        $retriever = new DialecticWorldKnowledgeRetriever([
            ['topic' => 'new_california_republic,NCR', 'canonical_topic' => 'new_california_republic', 'category' => 'faction', 'tags' => 'representative democracy,california republic army'],
            ['topic' => 'caesars_legion,Caesar Legion', 'canonical_topic' => 'caesars_legion', 'category' => 'faction', 'tags' => 'slave army,roman war culture'],
            ['topic' => 'brotherhood_of_steel,Brotherhood', 'canonical_topic' => 'brotherhood_of_steel', 'category' => 'faction', 'tags' => 'power armor,technology hoarding'],
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
