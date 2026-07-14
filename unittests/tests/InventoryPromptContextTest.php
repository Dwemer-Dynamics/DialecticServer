<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php';

final class InventoryPromptContextTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ITEM_BLACKLIST'] = '';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ITEM_BLACKLIST']);
    }

    public function testInventoryMarkdownIncludesNormalizedIdsAndFalloutMetadata(): void
    {
        $described = [];
        $lines = dialecticFormatInventoryPromptLines([
            [
                'name' => '9mm Pistol',
                'baseid' => 'e3778',
                'count' => 1,
                'equipped' => true,
                'condition' => 0.83,
                'ammo' => '9mm Round',
                'mods' => ['Extended Mags'],
            ],
        ], static fn(string $name, ?string $baseid): ?string => 'A compact semi-automatic handgun.', $described);

        $this->assertSame(
            '- `0x000E3778:9mm Pistol` (1) [equipped; condition 83%; ammo 9mm Round; mods Extended Mags] - A compact semi-automatic handgun.',
            $lines[0]
        );
        $this->assertSame(['0x000E3778'], $described);
    }

    public function testInventoryContextUsesDocumentedBaseIdFormat(): void
    {
        $described = [];
        $context = dialecticBuildInventoryPromptContext([
            ['name' => 'Leather Armor', 'baseid' => '20420', 'count' => 1],
        ], null, $described);

        $this->assertSame(
            "<inventory>\n# INVENTORY\nFormat: BaseID:ItemName (quantity)\n\n- `0x00020420:Leather Armor` (1)\n</inventory>",
            $context
        );
    }

    public function testInventoryEscapesPromptSyntaxAndSupportsDescriptionFilter(): void
    {
        $described = [];
        $lines = dialecticFormatInventoryPromptLines([
            ['name' => 'Odd `<Relic> & Thing', 'count' => 2],
            ['name' => '9mm Round', 'baseid' => '8ed03', 'count' => 20],
        ], static fn(string $name, ?string $baseid): ?string => null, $described, false);

        $this->assertSame('- Odd &#96;&lt;Relic&gt; &amp; Thing (2)', $lines[0]);
        $this->assertSame('- `0x0008ED03:9mm Round` (20)', $lines[1]);

        $described = [];
        $this->assertSame([], dialecticFormatInventoryPromptLines([
            ['name' => '9mm Round', 'baseid' => '8ed03', 'count' => 20],
        ], static fn(string $name, ?string $baseid): ?string => null, $described, true));
    }

    public function testInventoryDescriptionIsDeduplicatedAgainstEquipmentId(): void
    {
        $descriptionCalls = 0;
        $described = ['00020420'];
        $lines = dialecticFormatInventoryPromptLines([
            ['name' => 'Leather Armor', 'baseid' => '0x00020420', 'count' => 1],
        ], static function (string $name, ?string $baseid) use (&$descriptionCalls): ?string {
            $descriptionCalls++;
            return 'Should not be repeated.';
        }, $described);

        $this->assertSame(0, $descriptionCalls);
        $this->assertSame('- `0x00020420:Leather Armor` (1)', $lines[0]);
    }
}
