<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");

final class CanonicalRegionTestDbAdapter
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

final class CanonicalRegionResolutionTest extends DatabaseTestCase
{
    private string $connString = "host=localhost dbname=testdb user=dwemer password=dwemer";

    public function setUp(): void
    {
        parent::setUp();
        require("conf.php");
        $GLOBALS["db"] = new CanonicalRegionTestDbAdapter($this->connString);
        $this->resetLocationCaches();
    }

    public function tearDown(): void
    {
        if (isset($GLOBALS["db"])) {
            unset($GLOBALS["db"]);
        }

        $this->resetLocationCaches();
        parent::tearDown();
    }

    private function resetLocationCaches(): void
    {
        unset(
            $GLOBALS["CACHE_LAST_KNOWN_LOCATION"],
            $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"],
            $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"],
            $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"]
        );
    }

    private function insertLocation(string $name, int $formId, string $worldspace): void
    {
        $connection = pg_connect($this->connString);
        $this->assertNotFalse($connection);
        pg_query_params(
            $connection,
            "INSERT INTO locations (name, formid, worldspace) VALUES ($1, $2, $3)",
            [$name, $formId, $worldspace]
        );
        pg_close($connection);
    }

    private function insertWorldContextEvent(string $location, int $localTs = 1, int $gameTs = 100, string $worldspace = '', ?string $contextData = null): void
    {
        $connection = pg_connect($this->connString);
        $this->assertNotFalse($connection);
        $payload = json_encode([
            'type' => 'world_context',
            'location' => $location,
            'worldspace' => $worldspace,
            'game_time' => ['year' => 2281, 'month' => 11, 'day' => 30, 'hour' => 12.0],
        ], JSON_UNESCAPED_SLASHES);
        pg_query_params(
            $connection,
            "INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people, location, party)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)",
            ["0", (string) $gameTs, "world_context", $contextData ?? "world context: {$location}", "dialectic", $localTs, "|The Narrator|", $location, $payload]
        );
        pg_close($connection);
    }

    public function testCanonicalizeRegionNameSupportsFalloutAliases(): void
    {
        $this->assertSame("Mojave Wasteland", canonicalizeRegionName("Mojave"));
        $this->assertSame("New Vegas", canonicalizeRegionName("Freeside"));
        $this->assertSame("Capital Wasteland", canonicalizeRegionName("DC Wasteland"));
    }

    public function testCanonicalRegionResolutionMapsLocationToRegion(): void
    {
        $this->insertLocation("Goodsprings", 0x1000, "Mojave Wasteland");
        $this->insertLocation("Goodsprings General Store", 0x1001, "Goodsprings");
        $this->insertWorldContextEvent("Goodsprings General Store");
        $this->resetLocationCaches();

        $this->assertSame("Goodsprings General Store", DataLastKnownLocationHuman(false, false));
        $this->assertSame("Mojave Wasteland", DataLastKnownCanonicalRegionHuman(false));
    }

    public function testBuildWorldPromptDoesNotEmitHoldTag(): void
    {
        $this->insertLocation("Goodsprings", 0x1003, "Mojave Wasteland");
        $this->insertWorldContextEvent("Goodsprings", 1, 100, "Mojave Wasteland");
        $this->resetLocationCaches();

        $worldPrompt = buildWorldPrompt(100);

        $this->assertStringContainsString("<worldspace>Mojave Wasteland</worldspace>", $worldPrompt);
        $this->assertStringContainsString("<location>Goodsprings</location>", $worldPrompt);
        $this->assertStringNotContainsString("<hold>", $worldPrompt);
    }

    public function testBuildWorldPromptAlwaysEmitsKnownWorldspace(): void
    {
        $this->insertWorldContextEvent("Capital Wasteland", 1, 100, "Capital Wasteland");
        $this->resetLocationCaches();

        $worldPrompt = buildWorldPrompt(100);

        $this->assertStringContainsString("<worldspace>Capital Wasteland</worldspace>", $worldPrompt);
        $this->assertStringContainsString("<location>Capital Wasteland</location>", $worldPrompt);
    }

    public function testBuildWorldPromptUsesResolvedContextLocationWhenPayloadIsGenericWorldspace(): void
    {
        $this->insertWorldContextEvent(
            "Capital Wasteland",
            1,
            100,
            "Capital Wasteland",
            "(Context location: Vault 101, State: outdoors, Worldspace: Capital Wasteland, current weather: Pleasant)"
        );
        $this->resetLocationCaches();

        $worldPrompt = buildWorldPrompt(100);

        $this->assertStringContainsString("<worldspace>Capital Wasteland</worldspace>", $worldPrompt);
        $this->assertStringContainsString("<location>Vault 101</location>", $worldPrompt);
    }
}
