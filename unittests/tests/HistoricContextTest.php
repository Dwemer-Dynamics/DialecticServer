<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."data_functions.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."chat_helper_functions.php");

final class HistoricContextTestDbAdapter
{
    private string $connString;

    public function __construct(string $connString)
    {
        $this->connString = $connString;
    }

    private function connect()
    {
        $connection = pg_connect($this->connString);
        if ($connection === false) {
            throw new RuntimeException("Failed to connect to test database.");
        }

        return $connection;
    }

    public function fetchAll(string $query): array
    {
        $connection = $this->connect();
        $result = pg_query($connection, $query);
        if ($result === false) {
            pg_close($connection);
            return [];
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }

        pg_close($connection);
        return $rows;
    }

    public function escape(string $value): string
    {
        $connection = $this->connect();
        $escaped = pg_escape_string($connection, $value);
        pg_close($connection);
        return $escaped;
    }
}

final class HistoricContextTest extends DatabaseTestCase
{
    private string $connString = "host=localhost dbname=testdb user=dwemer password=dwemer";

    public function setUp(): void
    {
        parent::setUp();
        require("conf.php");
        $GLOBALS["db"] = new HistoricContextTestDbAdapter($this->connString);
        $GLOBALS["PLAYER_NAME"] = "Prisoner";
        $GLOBALS["DIALECTIC_NAME"] = "Veronica";
        $GLOBALS["gameRequest"] = ["chat", "200", "200", ""];
    }

    public function tearDown(): void
    {
        unset(
            $GLOBALS["db"],
            $GLOBALS["gameRequest"],
            $GLOBALS["PLAYER_NAME"],
            $GLOBALS["DIALECTIC_NAME"]
        );

        parent::tearDown();
    }

    private function insertEvent(
        string $type,
        string $data,
        string $people,
        int $ts,
        int $gameTs,
        int $localTs,
        ?string $deliveryState = null
    ): void {
        $connection = pg_connect($this->connString);
        $this->assertNotFalse($connection);
        pg_query_params(
            $connection,
            "INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people, location, party, delivery_state)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)",
            [
                (string)$ts,
                (string)$gameTs,
                $type,
                $data,
                "pending",
                $localTs,
                $people,
                "",
                "[]",
                $deliveryState,
            ]
        );
        pg_close($connection);
    }

    public function testBuildHistoricContextIncludesRestrainedAudienceRows(): void
    {
        $this->insertEvent(
            "inputtext",
            "Prisoner: Keep your voice down.",
            "|Veronica (restrained)|Prisoner|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("Prisoner: Keep your voice down.", $contents);
    }

    public function testBuildHistoricContextKeepsInjectedEventForOffSceneRecipient(): void
    {
        $injectedEvent = "(Veronica told Prisoner that the Courier will ask for the sealed letter.)";
        $this->insertEvent(
            "inputtext",
            $injectedEvent,
            "|Veronica|Prisoner|",
            90,
            90,
            90
        );
        for ($index = 0; $index < 8; $index++) {
            $this->insertEvent(
                "inputtext",
                "Chet: Unrelated event {$index}.",
                "|Chet|",
                100 + $index,
                100 + $index,
                100 + $index
            );
        }

        $recipientContext = buildHistoricContext("Prisoner", -5);
        $recipientContents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $recipientContext);
        $otherContext = buildHistoricContext("Doc Mitchell", -5);
        $otherContents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $otherContext);

        $this->assertContains($injectedEvent, $recipientContents);
        $this->assertNotContains($injectedEvent, $otherContents);
    }

    public function testBuildHistoricContextIncludesNarratorRowsForSharedAudience(): void
    {
        $this->insertEvent(
            "chat",
            "The Narrator: A cold wind sweeps through the inn.",
            "|Veronica|Prisoner|The Narrator|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("The Narrator: A cold wind sweeps through the inn.", $contents);
    }

    public function testBuildHistoricContextStillExcludesNonNarratorRowsForFarAwayAudience(): void
    {
        $this->insertEvent(
            "chat",
            "Chet: Everything's for sale, my friend.",
            "|Veronica (far away)|Prisoner|Chet|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Chet: Everything's for sale, my friend.", $contents);
    }

    public function testBuildHistoricContextIncludesEmittedChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Veronica: The perimeter is secure, Courier. (talking to Prisoner)",
            "|Veronica|Prisoner|",
            100,
            100,
            100,
            "emitted"
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("Veronica: The perimeter is secure, Courier. (talking to Prisoner)", $contents);
    }

    public function testBuildHistoricContextExcludesPendingChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Veronica: I have not actually said this yet. (talking to Prisoner)",
            "|Veronica|Prisoner|",
            100,
            100,
            100,
            "pending"
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Veronica: I have not actually said this yet. (talking to Prisoner)", $contents);
    }

    public function testBuildHistoricContextExcludesAbortedChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Veronica: This line was canceled. (talking to Prisoner)",
            "|Veronica|Prisoner|",
            100,
            100,
            100,
            "aborted"
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Veronica: This line was canceled. (talking to Prisoner)", $contents);
    }

    public function testBuildHistoricContextExcludesNearbyActorSnapshots(): void
    {
        $this->insertEvent(
            "nearby_actors",
            "nearby actors: |Veronica|Doc Mitchell|Chet|Graussy|",
            "|Veronica|Doc Mitchell|Chet|Graussy|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Veronica", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("nearby actors: |Veronica|Doc Mitchell|Chet|Graussy|", $contents);
    }

    public function testNormalizeActorNameForComparisonStripsRestrainedSuffix(): void
    {
        $this->assertSame("veronica", normalizeActorNameForComparison("Veronica (restrained)"));
    }
}
