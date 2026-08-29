<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'eventlog_helper.php';

final class RelationshipTimelineRowsTest extends TestCase
{
    private function snapshot(array $relationships): array
    {
        return $relationships;
    }

    public function testAdjacentSnapshotsReportTargetLevelChanges(): void
    {
        $changes = dialecticDiffRelationshipSnapshots(
            $this->snapshot([
                'Player' => ['aff' => 10, 'type' => 'platonic'],
                'Boone' => ['aff' => 33, 'type' => 'professional'],
                'Cass' => ['aff' => 5, 'type' => 'neutral'],
            ]),
            $this->snapshot([
                'Player' => ['aff' => 35, 'type' => 'romantic'],
                'Cass' => ['aff' => 5, 'type' => 'rival'],
                'Rex' => ['aff' => 0, 'type' => 'neutral'],
            ])
        );

        $kinds = [];
        foreach ($changes as $change) {
            $kinds[$change['target']] = $change['kind'];
        }

        $this->assertSame([
            'Boone' => 'removed',
            'Cass' => 'type',
            'Player' => 'affinity_type',
            'Rex' => 'added',
        ], $kinds);
    }

    public function testIdenticalSnapshotsProduceNoRows(): void
    {
        $state = $this->snapshot(['Player' => ['aff' => 12, 'type' => 'platonic']]);

        $this->assertSame([], dialecticDiffRelationshipSnapshots($state, $state));
    }

    public function testAffinityChangeReportsBeforeAndAfterTiers(): void
    {
        $changes = dialecticDiffRelationshipSnapshots(
            ['Player' => ['aff' => 10, 'type' => 'platonic']],
            ['Player' => ['aff' => 35, 'type' => 'platonic']]
        );

        $this->assertCount(1, $changes);
        $this->assertSame('affinity', $changes[0]['kind']);
        $this->assertSame('Acquaintance', $changes[0]['before_tier']);
        $this->assertSame('Friendly', $changes[0]['after_tier']);
        $this->assertSame(
            'Player +10 -> +35 (Acquaintance -> Friendly)',
            dialecticDescribeRelationshipChange($changes[0])
        );
    }

    public function testSummaryStaysCompactAndDetailKeepsEveryChange(): void
    {
        $changes = dialecticDiffRelationshipSnapshots(
            [],
            [
                'Arcade' => ['aff' => 5, 'type' => 'neutral'],
                'Boone' => ['aff' => 10, 'type' => 'professional'],
                'Cass' => ['aff' => 15, 'type' => 'platonic'],
                'Rex' => ['aff' => 20, 'type' => 'platonic'],
            ]
        );

        $text = dialecticBuildRelationshipTimelineText('Veronica', $changes, [
            'when_fallout' => 'at Mon 19 Oct 2287 14:02',
            'source_label' => 'Relationship evaluation',
            'visible_limit' => 3,
        ]);

        $this->assertSame(1, $text['hidden']);
        $this->assertStringStartsWith('Veronica: Arcade added at', $text['summary']);
        $this->assertStringEndsWith('(+1 more)', $text['summary']);
        $this->assertStringNotContainsString('Rex', $text['summary']);
        $this->assertStringContainsString('Source: Relationship evaluation.', $text['detail']);
        $this->assertStringContainsString('Veronica to Rex: relationship added', $text['detail']);
    }

    public function testHistorySourceMarkerIsStrippedWithoutReshapingJson(): void
    {
        $this->assertSame(
            '{"relationships":{},"list":[1,2]}',
            dialecticStripHistorySourceMarker('{"relationships":{},"list":[1,2],"_dialectic_history_source":"infosave"}')
        );
        $this->assertSame(
            '{"relationships":{}}',
            dialecticStripHistorySourceMarker('{"relationships":{}}')
        );
        $this->assertSame(['a' => 1], dialecticStripHistorySourceMarker(['a' => 1, '_dialectic_history_source' => 'x']));
        $this->assertSame('not json', dialecticStripHistorySourceMarker('not json'));
        $this->assertSame(
            ['extended_data' => '{"a":1}'],
            dialecticStripHistorySourceMarkerFromRow(['extended_data' => '{"a":1,"_dialectic_history_source":"x"}'])
        );
    }

    public function testVirtualRowsOnlyJoinThePageWindowThatContainsThem(): void
    {
        $eventRows = [
            ['localts' => 500, 'rowid' => 9, 'gamets' => 0, 'ts' => 0, 'type' => 'chat'],
            ['localts' => 300, 'rowid' => 7, 'gamets' => 0, 'ts' => 0, 'type' => 'chat'],
            ['localts' => 100, 'rowid' => 3, 'gamets' => 0, 'ts' => 0, 'type' => 'chat'],
        ];
        $relationshipRows = [
            ['localts' => 600, 'rowid' => 0, 'gamets' => 0, 'ts' => 0, 'type' => 'relationship'],
            ['localts' => 400, 'rowid' => 0, 'gamets' => 0, 'ts' => 0, 'type' => 'relationship'],
            ['localts' => 50, 'rowid' => 0, 'gamets' => 0, 'ts' => 0, 'type' => 'relationship'],
        ];

        $timestamps = static function (array $rows): array {
            return array_map(static function (array $row): int {
                return (int)$row['localts'];
            }, $rows);
        };

        // Middle page: only the change inside the page window is shown.
        $this->assertSame(
            [500, 400, 300, 100],
            $timestamps(dialecticMergeRelationshipTimelineRows($eventRows, $relationshipRows, false, false))
        );
        // First page also picks up anything newer than the newest physical row.
        $this->assertSame(
            [600, 500, 400, 300, 100],
            $timestamps(dialecticMergeRelationshipTimelineRows($eventRows, $relationshipRows, true, false))
        );
        // Last page also picks up anything older than the oldest physical row.
        $this->assertSame(
            [500, 400, 300, 100, 50],
            $timestamps(dialecticMergeRelationshipTimelineRows($eventRows, $relationshipRows, false, true))
        );
        // Nothing is lost across the whole run.
        $this->assertSame(
            [600, 500, 400, 300, 100, 50],
            $timestamps(dialecticMergeRelationshipTimelineRows($eventRows, $relationshipRows, true, true))
        );
    }

    public function testEmptyPagesOnlyAbsorbVirtualRowsWhenTheyAreTheWholeRun(): void
    {
        $relationshipRows = [
            ['localts' => 600, 'rowid' => 0, 'gamets' => 0, 'ts' => 0, 'type' => 'relationship'],
        ];

        $this->assertSame($relationshipRows, dialecticMergeRelationshipTimelineRows([], $relationshipRows, true, true));
        $this->assertSame([], dialecticMergeRelationshipTimelineRows([], $relationshipRows, false, false));
    }

    public function testRelationshipRowsFollowTheEventLogTypeFilters(): void
    {
        $this->assertTrue(dialecticRelationshipTimelineIsVisible('', []));
        $this->assertTrue(dialecticRelationshipTimelineIsVisible('relationship', []));
        $this->assertFalse(dialecticRelationshipTimelineIsVisible('chat', []));
        $this->assertFalse(dialecticRelationshipTimelineIsVisible('', ['relationship']));
    }

    public function testTooltipExposesDetailToHoverAndKeyboardFocus(): void
    {
        $html = dialecticRelationshipTimelineTooltipHtml([
            'history_id' => 42,
            'data' => 'Veronica: Player +10 -> +35',
            'detail' => "Veronica to Player: affinity +10 -> +35.",
        ], 'eventlog-rel');

        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('aria-describedby="eventlog-rel-42"', $html);
        $this->assertStringContainsString('id="eventlog-rel-42"', $html);
        $this->assertStringContainsString('role="tooltip"', $html);
        $this->assertStringContainsString('Veronica: Player +10 -&gt; +35', $html);
    }
}
