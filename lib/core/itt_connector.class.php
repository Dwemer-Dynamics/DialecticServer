<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'api_badge.class.php');

class ITTConnector
{
    private string $table = 'core_itt_connector';

    private const DRIVER_MAP = [
        'openrouter' => 'openrouter',
        'custom' => 'custom',
        'openai' => 'openai',
        'google_openai' => 'google_openai',
        'llamacpp' => 'llamacpp',
    ];

    private const DISPLAY_NAMES = [
        'openrouter' => 'OpenRouter',
        'custom' => 'Custom OpenAI-Compatible',
        'openai' => 'OpenAI',
        'google_openai' => 'Google OpenAI',
        'llamacpp' => 'llama.cpp',
    ];

    private const API_BADGE_LABELS = [
        'openrouter' => 'OpenRouter',
        'openai' => 'OpenAI',
        'google_openai' => 'Google',
    ];

    public function readAll(): array
    {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT * FROM {$this->table} ORDER BY LOWER(COALESCE(NULLIF(label, ''), driver)), id"
        );
        return is_array($rows) ? $rows : [];
    }

    public function readOne($id): ?array
    {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT * FROM {$this->table} WHERE id=" . intval($id) . ' LIMIT 1'
        );
        return is_array($row) && $row ? $row : null;
    }

    public function getById($id): ?array
    {
        return $this->readOne($id);
    }

    public function create(array $data): int
    {
        $normalized = $this->normalize($data);
        $row = $GLOBALS['db']->fetchOne(
            "INSERT INTO {$this->table} (driver, label, metadata, api_badge_id, url) VALUES (" .
            $GLOBALS['db']->escapeLiteral($normalized['driver']) . ', ' .
            $GLOBALS['db']->escapeLiteral($normalized['label']) . ', ' .
            $GLOBALS['db']->escapeLiteral($normalized['metadata']) . '::jsonb, ' .
            ($normalized['api_badge_id'] > 0 ? $normalized['api_badge_id'] : 'NULL') . ', ' .
            ($normalized['url'] !== '' ? $GLOBALS['db']->escapeLiteral($normalized['url']) : 'NULL') .
            ') RETURNING id'
        );
        return intval($row['id'] ?? 0);
    }

    public function update($id, array $data): bool
    {
        $id = intval($id);
        if ($id < 1 || !$this->readOne($id)) {
            return false;
        }

        $normalized = $this->normalize($data);
        return $GLOBALS['db']->execQuery(
            "UPDATE {$this->table} SET " .
            'driver=' . $GLOBALS['db']->escapeLiteral($normalized['driver']) . ', ' .
            'label=' . $GLOBALS['db']->escapeLiteral($normalized['label']) . ', ' .
            'metadata=' . $GLOBALS['db']->escapeLiteral($normalized['metadata']) . '::jsonb, ' .
            'api_badge_id=' . ($normalized['api_badge_id'] > 0 ? $normalized['api_badge_id'] : 'NULL') . ', ' .
            'url=' . ($normalized['url'] !== '' ? $GLOBALS['db']->escapeLiteral($normalized['url']) : 'NULL') .
            " WHERE id={$id}"
        ) !== false;
    }

    public function delete($id): bool
    {
        return $GLOBALS['db']->delete($this->table, 'id=' . intval($id)) !== false;
    }

    public function clone($id): int
    {
        $row = $this->readOne($id);
        if (!$row) {
            return 0;
        }
        unset($row['id']);
        $row['label'] = $this->uniqueLabel(trim(strval($row['label'] ?? '')) . ' (Copy)');
        return $this->create($row);
    }

    public function getDriverOptions(): array
    {
        $values = $this->loadRawSchema()['ITTFUNCTION']['values'] ?? [];
        if (!is_array($values) || !$values) {
            $values = array_keys(self::DRIVER_MAP);
        }
        return array_values(array_filter(array_unique(array_map([$this, 'normalizeDriverValue'], $values))));
    }

    public function normalizeDriverValue($driver): string
    {
        $driver = strtolower(trim(strval($driver)));
        return isset(self::DRIVER_MAP[$driver]) ? $driver : '';
    }

    public function getProviderKeyFromDriver($driver): string
    {
        $driver = $this->normalizeDriverValue($driver);
        return self::DRIVER_MAP[$driver] ?? '';
    }

    public function getDisplayName($driver): string
    {
        $driver = $this->normalizeDriverValue($driver);
        return self::DISPLAY_NAMES[$driver] ?? 'ITT Connector';
    }

    public function getProviderFieldSchema($driver): array
    {
        $provider = $this->getProviderKeyFromDriver($driver);
        $schema = $provider !== '' ? ($this->loadRawSchema()['ITT'][$provider] ?? []) : [];
        return is_array($schema) ? $schema : [];
    }

    public function getProviderTitle($driver): string
    {
        $schema = $this->getProviderFieldSchema($driver);
        return trim(strval($schema['_title'] ?? '')) ?: $this->getDisplayName($driver);
    }

    public function driverUsesApiBadge($driver): bool
    {
        return isset(self::API_BADGE_LABELS[$this->normalizeDriverValue($driver)]);
    }

    public function getDefaultApiBadgeIdForDriver($driver): int
    {
        $label = self::API_BADGE_LABELS[$this->normalizeDriverValue($driver)] ?? '';
        if ($label === '') {
            return 0;
        }
        $badge = (new ApiBadge())->getByLabel($label);
        return intval($badge['id'] ?? 0);
    }

    public function driverSupportsEditableUrl($driver): bool
    {
        return $this->normalizeDriverValue($driver) !== '';
    }

    public function getDefaultUrlForDriver($driver): string
    {
        $schema = $this->getProviderFieldSchema($driver);
        foreach (['url', 'URL'] as $field) {
            $value = trim(strval($schema[$field]['default'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public function getDefaultUrl(string $driver): string
    {
        return $this->getDefaultUrlForDriver($driver);
    }

    public function getDefaultModel(string $driver): string
    {
        return trim(strval($this->getProviderFieldSchema($driver)['model']['default'] ?? ''));
    }

    public function getDefaultMetadataForDriver($driver): array
    {
        $metadata = [];
        foreach ($this->getProviderFieldSchema($driver) as $field => $definition) {
            if (in_array($field, ['_title', 'API_KEY', 'url', 'URL'], true) || !is_array($definition)) {
                continue;
            }
            if (array_key_exists('default', $definition)) {
                $metadata[$field] = $definition['default'];
            }
        }
        return $metadata;
    }

    public function decodeMetadata($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode(strval($raw ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function buildMetadataFromPostedForm($driver, array $source, array $existing = []): array
    {
        $metadata = $existing;
        foreach ($this->getProviderFieldSchema($driver) as $field => $definition) {
            if (in_array($field, ['_title', 'API_KEY', 'url', 'URL'], true) || !is_array($definition)) {
                continue;
            }
            $key = 'meta__' . $field;
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $raw = is_array($source[$key]) ? '' : trim(strval($source[$key]));
            $type = strval($definition['type'] ?? 'string');
            if (in_array($type, ['integer', 'int'], true)) {
                $metadata[$field] = intval($raw);
            } elseif ($type === 'number') {
                $metadata[$field] = floatval($raw);
            } else {
                $metadata[$field] = $raw;
            }
        }
        return $metadata;
    }

    public function uniqueLabel(string $base, int $excludeId = 0): string
    {
        $base = trim($base) ?: 'ITT Connector';
        $used = [];
        foreach ($this->readAll() as $row) {
            if ($excludeId > 0 && intval($row['id'] ?? 0) === $excludeId) {
                continue;
            }
            $used[strtolower(trim(strval($row['label'] ?? '')))] = true;
        }
        if (!isset($used[strtolower($base)])) {
            return $base;
        }
        for ($index = 2; $index < 5000; $index++) {
            $candidate = $base . ' ' . $index;
            if (!isset($used[strtolower($candidate)])) {
                return $candidate;
            }
        }
        return $base . ' ' . time();
    }

    public function setOldGlobals(array $row): void
    {
        $driver = $this->normalizeDriverValue($row['driver'] ?? '');
        if ($driver === '') {
            return;
        }

        $metadata = array_replace($this->getDefaultMetadataForDriver($driver), $this->decodeMetadata($row['metadata'] ?? '{}'));
        $url = trim(strval($row['url'] ?? '')) ?: $this->getDefaultUrlForDriver($driver);
        if ($url !== '') {
            $metadata['url'] = $url;
            $metadata['URL'] = $url;
        }

        $badgeId = intval($row['api_badge_id'] ?? 0);
        if ($badgeId > 0) {
            $badge = (new ApiBadge())->getById($badgeId);
            $apiKey = trim(strval($badge['api_key'] ?? ''));
            if ($apiKey !== '') {
                $metadata['API_KEY'] = $apiKey;
            }
        }

        $GLOBALS['ITTFUNCTION'] = $driver;
        $GLOBALS['ITT_CONNECTOR'] = $row;
        if (!isset($GLOBALS['ITT']) || !is_array($GLOBALS['ITT'])) {
            $GLOBALS['ITT'] = [];
        }
        $GLOBALS['ITT'][$driver] = $metadata;
    }

    private function normalize(array $data): array
    {
        $driver = $this->normalizeDriverValue($data['driver'] ?? '');
        if ($driver === '') {
            throw new InvalidArgumentException('Unsupported ITT connector driver');
        }

        $metadata = array_replace(
            $this->getDefaultMetadataForDriver($driver),
            $this->decodeMetadata($data['metadata'] ?? [])
        );
        unset($metadata['API_KEY']);
        ksort($metadata);
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $label = trim(strval($data['label'] ?? '')) ?: $this->getDisplayName($driver);
        $url = trim(strval($data['url'] ?? '')) ?: $this->getDefaultUrlForDriver($driver);

        return [
            'driver' => $driver,
            'label' => substr($label, 0, 200),
            'metadata' => $metadataJson !== false ? $metadataJson : '{}',
            'api_badge_id' => $this->driverUsesApiBadge($driver) ? intval($data['api_badge_id'] ?? 0) : 0,
            'url' => $url,
        ];
    }

    private function loadRawSchema(): array
    {
        static $schema = null;
        if (is_array($schema)) {
            return $schema;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf_schema.json';
        $decoded = json_decode(strval(@file_get_contents($path)), true);
        $schema = is_array($decoded) ? $decoded : [];
        return $schema;
    }
}
