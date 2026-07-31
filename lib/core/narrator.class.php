<?php

class Narrator
{
    public const CANONICAL_NAME = 'The Narrator';
    public const DEFAULT_ROLEPLAY_NAME = self::CANONICAL_NAME;

    private $table = "core_narrator";
    private $db;

    public function __construct()
    {
        if (!isset($GLOBALS["db"])) {
            throw new \Exception("Database connection not initialized. Please ensure \$GLOBALS['db'] is set before instantiating Narrator class.");
        }
        $this->db = $GLOBALS["db"];
    }

    /**
     * Get a single narrator setting value
     * @param string $key The setting key
     * @return string|null The value, or null if not found
     */
    public function get(string $key): ?string
    {
        $escaped = $this->escape($key);
        $query = "SELECT value FROM {$this->table} WHERE id = '{$escaped}' LIMIT 1";
        $result = $this->db->fetchOne($query);
        
        if ($result && isset($result['value'])) {
            return $result['value'];
        }
        
        return null;
    }

    /**
     * Set/update a single narrator setting value
     * @param string $key The setting key
     * @param string $value The value to set
     * @return bool Success status
     */
    public function set(string $key, string $value): bool
    {
        $escaped_key = $this->escape($key);
        $escaped_value = $this->escape($value);
        
        // Check if key exists
        $exists = $this->get($key);
        
        if ($exists !== null) {
            // Update existing
            $query = "UPDATE {$this->table} SET value = '{$escaped_value}' WHERE id = '{$escaped_key}'";
        } else {
            // Insert new
            $query = "INSERT INTO {$this->table} (id, value) VALUES ('{$escaped_key}', '{$escaped_value}')";
        }
        
        $result = $this->db->query($query);
        return $result !== false;
    }

    /**
     * Get all narrator settings as associative array
     * @return array Associative array of key => value
     */
    public function getAll(): array
    {
        $query = "SELECT id, value FROM {$this->table}";
        $results = $this->db->fetchAll($query);
        
        $data = [];
        if (is_array($results)) {
            foreach ($results as $row) {
                if (isset($row['id']) && isset($row['value'])) {
                    $data[$row['id']] = $row['value'];
                }
            }
        }
        
        return $data;
    }

    /**
     * Set multiple narrator settings at once
     * @param array $data Associative array of key => value pairs
     * @return bool Success status
     */
    public function setMultiple(array $data): bool
    {
        $success = true;
        foreach ($data as $key => $value) {
            if (!$this->set($key, $value)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Delete a narrator setting
     * @param string $key The setting key to delete
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        $escaped = $this->escape($key);
        $query = "DELETE FROM {$this->table} WHERE id = '{$escaped}'";
        $result = $this->db->query($query);
        return $result !== false;
    }

    /**
     * Check if a key exists
     * @param string $key The setting key
     * @return bool True if exists, false otherwise
     */
    public function exists(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Escape string for SQL
     * @param string $value The value to escape
     * @return string Escaped value
     */
    private function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    /**
     * Get a value and parse it as boolean
     * @param string $key The setting key
     * @param bool $default Default value if not found
     * @return bool The boolean value
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get a value and parse it as integer
     * @param string $key The setting key
     * @param int $default Default value if not found
     * @return int The integer value
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return intval($value);
    }

    /**
     * Normalize and validate the prompt-facing narrator name.
     */
    public static function normalizeRoleplayName($value): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string)$value));
        if ($name === '') {
            return self::DEFAULT_ROLEPLAY_NAME;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length > 64) {
            throw new \InvalidArgumentException('Narrator roleplay name must be 64 characters or fewer.');
        }

        if (preg_match("/^[\\p{L}\\p{M}\\p{N} .'\\x{2019}\\-]+$/u", $name) !== 1) {
            throw new \InvalidArgumentException('Narrator roleplay name may only contain letters, numbers, spaces, apostrophes, periods, and hyphens.');
        }

        if (in_array(strtolower($name), ['player', 'everyone'], true)) {
            throw new \InvalidArgumentException("Narrator roleplay name cannot be '{$name}'.");
        }

        return $name;
    }

    public function getRoleplayName(): string
    {
        try {
            return self::normalizeRoleplayName($this->get('roleplay_name'));
        } catch (\InvalidArgumentException $e) {
            return self::DEFAULT_ROLEPLAY_NAME;
        }
    }

    /**
     * Load all narrator settings into GLOBALS with proper type conversion
     */
    public function loadIntoGlobals(): void
    {
        $allSettings = $this->getAll();

        if (!isset($allSettings['inline_narration_mode'])) {
            $currentGlobalMode = strtolower(trim((string)($GLOBALS['INLINE_NARRATION_MODE'] ?? '')));
            $inlineNarrationMode = in_array($currentGlobalMode, ['disabled', 'narrator', 'npc', 'text_only'], true)
                ? $currentGlobalMode
                : 'disabled';

            if ($this->set('inline_narration_mode', $inlineNarrationMode)) {
                $allSettings['inline_narration_mode'] = $inlineNarrationMode;
            }
        }

        if (!isset($allSettings['remove_asterisks_from_npc_output'])) {
            $serialized = !array_key_exists('REMOVE_ASTERISKS_FROM_NPC_OUTPUT', $GLOBALS) || !empty($GLOBALS['REMOVE_ASTERISKS_FROM_NPC_OUTPUT']) ? '1' : '0';
            if ($this->set('remove_asterisks_from_npc_output', $serialized)) {
                $allSettings['remove_asterisks_from_npc_output'] = $serialized;
            }
        }

        if (!isset($allSettings['remove_asterisks_from_player_input'])) {
            $serialized = !array_key_exists('REMOVE_ASTERISKS_FROM_PLAYER_INPUT', $GLOBALS) || !empty($GLOBALS['REMOVE_ASTERISKS_FROM_PLAYER_INPUT']) ? '1' : '0';
            if ($this->set('remove_asterisks_from_player_input', $serialized)) {
                $allSettings['remove_asterisks_from_player_input'] = $serialized;
            }
        }

        if (!isset($allSettings['remove_player_autochat_asterisks'])) {
            $serialized = !array_key_exists('REMOVE_PLAYER_AUTOCHAT_ASTERISKS', $GLOBALS) || !empty($GLOBALS['REMOVE_PLAYER_AUTOCHAT_ASTERISKS']) ? '1' : '0';
            if ($this->set('remove_player_autochat_asterisks', $serialized)) {
                $allSettings['remove_player_autochat_asterisks'] = $serialized;
            }
        }
        
        // Map database keys to GLOBALS keys with type conversion
        $keyMapping = [
            'roleplay_name' => ['NARRATOR_ROLEPLAY_NAME', 'string', self::DEFAULT_ROLEPLAY_NAME],
            'enabled' => ['NARRATOR_TALKS', 'bool', true],
            'welcome_enabled' => ['NARRATOR_WELCOME', 'bool', false],
            'random_enabled' => ['RANDOM_NARATION', 'bool', false],
            'random_chance' => ['RANDOM_NARATION_CHANCE', 'int', 15],
            'random_cooldown' => ['RANDOM_NARRATION_COOLDOWN', 'int', 2],
            'bored_enabled' => ['ALLOW_NARRATOR_BORED_EVENTS', 'bool', false],
            'bored_chance' => ['ALLOW_NARRATOR_BORED_EVENTS_CHANCE', 'int', 25],
            'quest_comment_cooldown' => ['QUEST_COMMENT_COOLDOWN', 'int', 3],
            'hide_from_context' => ['HIDE_NARRATOR_DIALOGUE', 'bool', false],
            'dynamic_profile' => ['DYNAMIC_PROFILE', 'bool', false],
            'inline_narration_mode' => ['INLINE_NARRATION_MODE', 'string', isset($GLOBALS['INLINE_NARRATION_MODE']) ? $GLOBALS['INLINE_NARRATION_MODE'] : 'disabled'],
            'remove_player_autochat_asterisks' => [
                'REMOVE_PLAYER_AUTOCHAT_ASTERISKS',
                'bool',
                isset($GLOBALS['REMOVE_PLAYER_AUTOCHAT_ASTERISKS']) ? (bool)$GLOBALS['REMOVE_PLAYER_AUTOCHAT_ASTERISKS'] : true,
            ],
            'preserve_asterisks_in_context' => ['PRESERVE_ASTERISKS_IN_CONTEXT', 'bool', false],
            'remove_asterisks_from_player_input' => [
                'REMOVE_ASTERISKS_FROM_PLAYER_INPUT',
                'bool',
                isset($GLOBALS['REMOVE_ASTERISKS_FROM_PLAYER_INPUT']) ? (bool)$GLOBALS['REMOVE_ASTERISKS_FROM_PLAYER_INPUT'] : true,
            ],
            'remove_asterisks_from_npc_output' => [
                'REMOVE_ASTERISKS_FROM_NPC_OUTPUT',
                'bool',
                isset($GLOBALS['REMOVE_ASTERISKS_FROM_NPC_OUTPUT']) ? (bool)$GLOBALS['REMOVE_ASTERISKS_FROM_NPC_OUTPUT'] : true,
            ],
            'diary_enabled' => ['NARRATOR_DIARY_ENABLED', 'bool', false],
            'auto_diary_enabled' => ['NARRATOR_AUTO_DIARY_ENABLED', 'bool', false],
            'only_diary_access' => ['NARRATOR_ONLY_DIARY_ACCESS', 'bool', false],
            'connector_id' => ['NARRATOR_CONNECTOR_ID', 'int', null],
            'diary_connector_id' => ['NARRATOR_DIARY_CONNECTOR_ID', 'int', null],
        ];
        
        foreach ($keyMapping as $dbKey => $config) {
            list($globalKey, $type, $default) = $config;
            
            if (isset($allSettings[$dbKey])) {
                if ($type === 'bool') {
                    $GLOBALS[$globalKey] = filter_var($allSettings[$dbKey], FILTER_VALIDATE_BOOLEAN);
                } elseif ($type === 'int') {
                    $GLOBALS[$globalKey] = intval($allSettings[$dbKey]);
                } else {
                    $GLOBALS[$globalKey] = $allSettings[$dbKey];
                }
            } elseif (!isset($GLOBALS[$globalKey])) {
                // Only set default if GLOBALS doesn't already have a value
                $GLOBALS[$globalKey] = $default;
            }
        }

        $inlineNarrationMode = strtolower(trim((string)($GLOBALS['INLINE_NARRATION_MODE'] ?? 'disabled')));
        if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
            $inlineNarrationMode = 'disabled';
        }
        $GLOBALS['INLINE_NARRATION_MODE'] = $inlineNarrationMode;
        
        // NOTE: Character data (DIALECTIC_NAME, DIALECTIC_PERS, PROMPT_HEAD, etc.) is NOT loaded here.
        // loadCharacterIntoGlobals() should only be called when The Narrator is confirmed
        // as the active speaker (in main.php profile loading or book override sections).
        // Loading it here would overwrite global PROMPT_HEAD before profile selection.
    }
    
    /**
     * Load narrator character data into GLOBALS (DIALECTIC_* variables)
     */
    public function loadCharacterIntoGlobals(): void
    {
        $allSettings = $this->getAll();
        
        // Routing always uses the canonical name; prompts may use the roleplay alias.
        $GLOBALS['DIALECTIC_NAME'] = self::CANONICAL_NAME;
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = $this->getRoleplayName();
        $GLOBALS['DIALECTIC_ROLEPLAY_NAME'] = $GLOBALS['NARRATOR_ROLEPLAY_NAME'];
        $promptName = $GLOBALS['NARRATOR_ROLEPLAY_NAME'];
        
        // Map character fields to GLOBALS
        // Set DIALECTIC_PERS from core field (like NPCs do)
        if (isset($allSettings['core']) && $allSettings['core'] !== null && $allSettings['core'] !== '') {
            $GLOBALS['DIALECTIC_PERS'] = "Roleplay as {$promptName}.\n" . dialecticRenderNarratorRoleplayText($allSettings['core']);
        } else {
            $GLOBALS['DIALECTIC_PERS'] = "Roleplay as {$promptName}";
        }
        
        if (isset($allSettings['background'])) {
            $GLOBALS['DIALECTIC_BACKGROUND'] = dialecticRenderNarratorRoleplayText($allSettings['background']);
        }
        
        if (isset($allSettings['personality'])) {
            $GLOBALS['DIALECTIC_PERSONALITY'] = dialecticRenderNarratorRoleplayText($allSettings['personality']);
        }
        
        if (isset($allSettings['speechstyle'])) {
            $GLOBALS['DIALECTIC_SPEECHSTYLE'] = dialecticRenderNarratorRoleplayText($allSettings['speechstyle']);
        }
        
        if (isset($allSettings['goals'])) {
            $GLOBALS['DIALECTIC_GOALS'] = dialecticRenderNarratorRoleplayText($allSettings['goals']);
        }
        
        if (isset($allSettings['worldknowledge'])) {
            $GLOBALS['WORLDKNOWLEDGE'] = $allSettings['worldknowledge'];
        }

        // Override PROMPT_HEAD if narrator has a custom prompt_head (like NPCs do)
        if (isset($allSettings['prompt_head']) && $allSettings['prompt_head'] !== null && $allSettings['prompt_head'] !== '') {
            $GLOBALS['PROMPT_HEAD'] = dialecticRenderNarratorRoleplayText($allSettings['prompt_head']);
        }

        if (isset($allSettings['voiceid']) && $allSettings['voiceid']) {
            $GLOBALS['PATCH_OVERRIDE_VOICE']          = $allSettings['voiceid'];

            $GLOBALS['TTS']['XTTSFASTAPI']['voiceid']  = $allSettings['voiceid'];
            $GLOBALS['TTS']['CHATTERBOX']['voiceid']   = $allSettings['voiceid'];
            $GLOBALS['TTS']['POCKETTTS']['voiceid']    = $allSettings['voiceid'];
            $GLOBALS['TTS']['OMNIVOICE']['voiceid']    = $allSettings['voiceid'];
            $GLOBALS['TTS']['PIPERTTS']['voiceid']     = $allSettings['voiceid'];
            $GLOBALS['TTS']['ELEVEN_LABS']['voice_id'] = $allSettings['voiceid'];
            $GLOBALS['TTS']['KOKORO']['voiceid']       = $allSettings['voiceid'];
            $GLOBALS['TTS']['CARTESIA']['voiceid']     = $allSettings['voiceid'];
            $GLOBALS['TTS']['INWORLD']['voiceid']      = $allSettings['voiceid'];

        } else {
            unset($GLOBALS['PATCH_OVERRIDE_VOICE']);
        }
    }
    
    /**
     * Get the profile_id for the narrator
     * @return int|null The profile ID, or null if not set
     */
    public function getProfileId(): ?int
    {
        $value = $this->get('profile_id');
        if ($value === null) {
            return null;
        }
        return intval($value);
    }
    
    /**
     * Get all narrator data as an array compatible with NpcMaster::getByName format
     * This allows existing code to work with minimal changes
     * @return array Narrator data in NPC format
     */
    public function getNarratorData(): array
    {
        $allSettings = $this->getAll();
        
        return [
            'id' => 1, // Narrator always has ID 1 conceptually
            'npc_name' => self::CANONICAL_NAME,
            'roleplay_name' => $this->getRoleplayName(),
            'profile_id' => $this->getProfileId(),
            'voiceid' => $allSettings['voiceid'] ?? 'TheNarrator',
            'core' => $allSettings['core'] ?? '',
            'npc_static_bio' => $allSettings['background'] ?? '',
            'personality' => $allSettings['personality'] ?? '',
            'speechstyle' => $allSettings['speechstyle'] ?? '',
            'goals' => $allSettings['goals'] ?? '',
            'worldknowledge_tags' => $allSettings['worldknowledge'] ?? 'knowall',
            'gender' => $allSettings['gender'] ?? 'male',
            'prompt_head' => $allSettings['prompt_head'] ?? '',
            'lock_profile' => 1, // Narrator is always locked
            'npc_favorite' => 1, // Narrator is always favorited
            'md5' => md5(self::CANONICAL_NAME),
            'dynamic_profile' => $this->getBool('dynamic_profile', false) ? 1 : 0,
        ];
    }
    
    /**
     * Get dynamic profile fields array
     * @return array Array of field names to update dynamically
     */
    public function getDynamicProfileFields(): array
    {
        $value = $this->get('dynamic_profile_fields');
        if ($value === null || $value === '') {
            return [];
        }
        
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        
        return $decoded;
    }
    
    /**
     * Set dynamic profile fields array
     * @param array $fields Array of field names (personality, speechstyle, goals)
     * @return bool Success status
     */
    public function setDynamicProfileFields(array $fields): bool
    {
        $validFields = ['personality', 'speechstyle', 'goals'];
        $filtered = array_intersect($fields, $validFields);
        $json = json_encode(array_values($filtered));
        return $this->set('dynamic_profile_fields', $json);
    }
    
}

if (!function_exists('dialecticGetNarratorRoleplayName')) {
    function dialecticGetNarratorRoleplayName(): string
    {
        try {
            return Narrator::normalizeRoleplayName($GLOBALS['NARRATOR_ROLEPLAY_NAME'] ?? Narrator::DEFAULT_ROLEPLAY_NAME);
        } catch (\InvalidArgumentException $e) {
            return Narrator::DEFAULT_ROLEPLAY_NAME;
        }
    }
}

if (!function_exists('dialecticGetNarratorDisplayNameHeaderValue')) {
    function dialecticGetNarratorDisplayNameHeaderValue(): string
    {
        return base64_encode(dialecticGetNarratorRoleplayName());
    }
}

if (!function_exists('dialecticBuildNarratorContextLine')) {
    function dialecticBuildNarratorContextLine($text): string
    {
        return dialecticGetNarratorRoleplayName() . ': ' . ltrim((string)$text);
    }
}

if (!function_exists('dialecticGetPromptCharacterName')) {
    function dialecticGetPromptCharacterName(): string
    {
        $canonicalName = trim((string)($GLOBALS['DIALECTIC_NAME'] ?? ''));
        if ($canonicalName !== '' && strcasecmp($canonicalName, Narrator::CANONICAL_NAME) !== 0) {
            return $canonicalName;
        }

        return dialecticGetNarratorRoleplayName();
    }
}

if (!function_exists('dialecticRenderNarratorRoleplayText')) {
    function dialecticRenderNarratorRoleplayText($text): string
    {
        $text = (string)$text;
        $roleplayName = dialecticGetNarratorRoleplayName();
        if (strcasecmp($roleplayName, Narrator::CANONICAL_NAME) === 0) {
            return $text;
        }

        return str_ireplace(Narrator::CANONICAL_NAME, $roleplayName, $text);
    }
}

if (!function_exists('dialecticRenderNarratorContextText')) {
    function dialecticRenderNarratorContextText($text): string
    {
        return dialecticRenderNarratorRoleplayText($text);
    }
}

if (!function_exists('dialecticApplyNarratorRoleplayNameToContext')) {
    function dialecticApplyNarratorRoleplayNameToContext(array $messages): array
    {
        foreach ($messages as &$message) {
            if (is_array($message) && array_key_exists('content', $message) && is_string($message['content'])) {
                $message['content'] = dialecticRenderNarratorContextText($message['content']);
            }
        }
        unset($message);

        return $messages;
    }
}

if (!function_exists('dialecticNormalizeNarratorRoleplayActorName')) {
    function dialecticNormalizeNarratorRoleplayActorName($name): string
    {
        $name = trim((string)$name);
        $roleplayName = dialecticGetNarratorRoleplayName();
        if ($name !== '' && strcasecmp($name, $roleplayName) === 0) {
            return Narrator::CANONICAL_NAME;
        }

        return $name;
    }
}

