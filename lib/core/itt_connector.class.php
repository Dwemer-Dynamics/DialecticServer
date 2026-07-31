<?php

class ITTConnector
{
    private string $table = 'core_itt_connector';

    private const DRIVERS = [
        'openrouter' => 'OpenRouter',
        'openai' => 'OpenAI',
        'google_openai' => 'Google OpenAI',
        'custom' => 'Custom OpenAI-Compatible',
        'llamacpp' => 'llama.cpp',
    ];

    public function readAll(): array
    {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT * FROM {$this->table} ORDER BY LOWER(COALESCE(NULLIF(label, ''), driver)), id"
        );
        return is_array($rows) ? $rows : [];
    }

    public function getById($id): ?array
    {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT * FROM {$this->table} WHERE id=" . intval($id) . ' LIMIT 1'
        );
        return is_array($row) && $row ? $row : null;
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
        if ($id < 1 || !$this->getById($id)) {
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

    public function getDriverOptions(): array
    {
        return self::DRIVERS;
    }

    public function getDefaultUrl(string $driver): string
    {
        return match ($driver) {
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'google_openai' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            'llamacpp' => 'http://127.0.0.1:8080/v1/chat/completions',
            default => '',
        };
    }

    public function getDefaultModel(string $driver): string
    {
        return match ($driver) {
            'openrouter' => 'google/gemini-2.5-flash',
            'openai' => 'gpt-4.1-mini',
            'google_openai' => 'gemini-2.5-flash',
            default => '',
        };
    }

    public function setOldGlobals(array $row): void
    {
        $GLOBALS['ITTFUNCTION'] = strtolower(trim(strval($row['driver'] ?? '')));
        $GLOBALS['ITT_CONNECTOR'] = $row;
    }

    private function normalize(array $data): array
    {
        $driver = strtolower(trim(strval($data['driver'] ?? '')));
        if (!isset(self::DRIVERS[$driver])) {
            throw new InvalidArgumentException('Unsupported PipVision connector driver');
        }

        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = json_decode(strval($metadata), true);
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $label = trim(strval($data['label'] ?? ''));
        if ($label === '') {
            $label = self::DRIVERS[$driver];
        }

        $url = trim(strval($data['url'] ?? ''));
        if ($url === '') {
            $url = $this->getDefaultUrl($driver);
        }

        return [
            'driver' => $driver,
            'label' => substr($label, 0, 200),
            'metadata' => $metadataJson !== false ? $metadataJson : '{}',
            'api_badge_id' => intval($data['api_badge_id'] ?? 0),
            'url' => $url,
        ];
    }
}
