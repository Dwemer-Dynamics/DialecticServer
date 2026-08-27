<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
    . 'lib' . DIRECTORY_SEPARATOR . 'compact_context_history.php');

final class CompactNpcContextHistoryTest extends TestCase
{
    public function testCompactPromptInfoDefaultsOffAndPreservesOriginalPrompt(): void
    {
        require_once __DIR__ . '/../../lib/prompt_composition.php';
        $schema = json_decode(file_get_contents(__DIR__ . '/../../conf/conf_schema.json'), true);
        $this->assertFalse($schema['PROMPT_HEAD_MARKDOWN_ENABLED']['default']);
        $xml = " <character>\r\n<personality>Direct</personality>\r\n</character>\r\n";
        $this->assertSame($xml, dialecticFormatPromptHeadSection($xml, false));
    }

    public function testCompactPromptInfoKeepsHierarchyFieldsAndHyphenLists(): void
    {
        require_once __DIR__ . '/../../lib/prompt_composition.php';
        $xml = "<world>\n<worldspace>Mojave Wasteland</worldspace>\n<location>Goodsprings</location>\n</world>\n"
            . "<character>\n<personality>Direct</personality>\n<equipment>\n#Current Equipment\n"
            . "• Rifle\n</equipment>\n</character>\n"
            . "<nearby_actors>\n#NEARBY ACTORS/NPC IN THE SCENE\n##Boone (00096BCE)\n</nearby_actors>\n"
            . "<available_actions_list>\nAVAILABLE ACTION: Follow\n</available_actions_list>\n"
            . "<general_instructions>\nUse <inventory> for reference.\n</general_instructions>\n"
            . "<fallout_context>\n<knowledge>\n<oghma contract=\"oghma-parity-v1\" status=\"grounded\">\n"
            . "<article topic=\"quarry_junction\" source=\"location\" access=\"basic\">\n"
            . "<content>Deathclaws live here.</content>\n</article>\n</oghma>\n</knowledge>\n</fallout_context>";
        $formatted = dialecticFormatPromptHeadSection($xml, true);
        foreach ([
            '# World', '- Worldspace: Mojave Wasteland', '- Location: Goodsprings',
            '# Character', '## Personality', '## Equipment', '- Rifle',
            '# Nearby Actors', '- Boone (00096BCE)', '# Available Actions',
            '- AVAILABLE ACTION: Follow', 'Use `Inventory` for reference.',
            '# Fallout Context', '## Knowledge', '### Oghma', '- Contract: oghma-parity-v1',
            '- Status: grounded', '#### Article', '- Topic: quarry_junction',
            '- Source: location', '- Access: basic', '##### Content', 'Deathclaws live here.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $formatted);
        }
        $this->assertStringNotContainsString('<', $formatted);
        $this->assertStringNotContainsString('Current Equipment', $formatted);
        $head = [['role' => 'system', 'content' => $formatted]];
        $history = "# Courier: Hello.\n# Boone: Hello.";
        $this->assertSame(
            dialecticAppendCompactHistoryToPrompt($head, $history),
            dialecticAppendCompactHistoryToPrompt($head, $history, false)
        );
        $this->assertSame(
            rtrim($formatted) . "\n\n# Conversation History\n\n- Courier: Hello.\n- Boone: Hello.",
            dialecticAppendCompactHistoryToPrompt($head, $history, true)[0]['content']
        );
        $this->assertSame($head, dialecticAppendCompactHistoryToPrompt($head, '', true));
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'],
            $GLOBALS['DIALECTIC_NAME']
        );
    }

    public function testSettingIsEnabledByDefaultAndCanBeDisabled(): void
    {
        $GLOBALS['DIALECTIC_NAME'] = 'Sunny Smiles';

        $this->assertTrue(dialecticCompactNpcContextHistoryEnabled());
        $this->assertTrue(dialecticShouldCompactNpcContextHistory());

        $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] = false;

        $this->assertFalse(dialecticCompactNpcContextHistoryEnabled());
        $this->assertFalse(dialecticShouldCompactNpcContextHistory());
    }

    public function testNarratorRemainsExcluded(): void
    {
        $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] = true;

        $this->assertFalse(dialecticShouldCompactNpcContextHistory('The Narrator'));
        $this->assertTrue(dialecticShouldCompactNpcContextHistory('Sunny Smiles'));
    }

    public function testFormatsFalloutConversationAsOnePlaintextBlock(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => '{"speaker":"Sunny Smiles","listener":"Courier","action":"Talk","text":"Careful around the wells."}',
            ],
            [
                'role' => 'user',
                'content' => '*(Context location: Goodsprings background chat) Trudy: Drinks are on the house.*',
            ],
            [
                'role' => 'user',
                'content' => 'Courier: What is out there? (Speaking privately to Sunny Smiles)',
            ],
            [
                'role' => 'user',
                'content' => 'LOCATION CHANGE to Goodsprings Saloon, worldspace: Mojave Wasteland, timeline mark: 0 hours ago',
            ],
        ];

        $formatted = dialecticFormatCompactNpcContextHistory($history, 'Sunny Smiles');

        $this->assertSame(
            implode("\n", [
                '# Sunny Smiles, speaking to Courier: Careful around the wells.',
                '# Background dialogue at Goodsprings: Trudy: Drinks are on the house.',
                '# Courier, speaking to Sunny Smiles: What is out there?',
                '# The current scene is at Goodsprings Saloon in Mojave Wasteland.',
            ]),
            $formatted
        );
        $this->assertStringNotContainsString('{"speaker"', $formatted);
    }

    public function testKeepsHistoricActionsInPlaintext(): void
    {
        $history = [[
            'role' => 'assistant',
            'content' => '{"speaker":"Boone","listener":"Courier","text":"Lead the way.","action":"Follow","target":"Courier"}',
        ]];

        $this->assertSame(
            '# Boone, speaking to Courier: Lead the way. [Action: Follow, targeting Courier]',
            dialecticFormatCompactNpcContextHistory($history, 'Boone')
        );
    }

    public function testConvertsToolHistoryToPlaintext(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Courier: Wait here. (Talking to Boone)'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'function' => ['name' => 'StopFollowing'],
                ]],
            ],
            ['role' => 'tool', 'content' => 'StopFollowing completed.'],
        ];

        $this->assertSame(
            implode("\n", [
                '# Courier, speaking to Boone: Wait here.',
                '# Requested action: StopFollowing.',
                '# Tool result: StopFollowing completed.',
            ]),
            dialecticFormatCompactNpcContextHistory($history, 'Boone')
        );
    }

    public function testAppendsHistoryInsideTheSystemPromptWithoutAddingMessages(): void
    {
        $head = [[
            'role' => 'system',
            'content' => '<actors_nearby>Sunny Smiles</actors_nearby>',
        ]];

        $result = dialecticAppendCompactHistoryToPrompt(
            $head,
            "# Courier: Hello.\n# Sunny Smiles: Howdy."
        );

        $this->assertCount(1, $result);
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame(
            "<actors_nearby>Sunny Smiles</actors_nearby>\n\n"
                . "# Courier: Hello.\n# Sunny Smiles: Howdy.",
            $result[0]['content']
        );
    }
}
