<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/settings.php';
require_once __DIR__ . '/../../lib/worldknowledge_forced_context.php';

final class WorldKnowledgeForcedContextTest extends TestCase
{
    public function testLocationSettingIsManagedAndEnabledByDefault(): void
    {
        $this->assertContains('LOCATION_WORLDKNOWLEDGE', dialecticGetManagedGeneralSettingIds());
        $definition = dialecticGetSchemaDefinition('LOCATION_WORLDKNOWLEDGE');
        $this->assertTrue($definition['default'] ?? false);
        $this->assertSame('global', $definition['scope'] ?? null);
        $this->assertSame(
            'World Knowledge',
            dialecticGetOverrideableGeneralSettingCategory('LOCATION_WORLDKNOWLEDGE')
        );
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
        $this->assertStringContainsString(': mojave_wasteland', $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertStringNotContainsString('mojave_wasteland,Mojave', $GLOBALS['WORLDKNOWLEDGE_HINT']);
    }
}
