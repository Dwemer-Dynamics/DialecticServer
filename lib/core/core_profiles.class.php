<?php

class CoreProfile
{
    /** Default used when a profile has no Bored Event chance. */
    const BORED_EVENT_DEFAULT = 30;

    private $table     = "core_profiles";
    private $lastError = '';

    /** Clamp a stored profile value to 0-100, falling back to the fixed default. */
    public static function normalizeBoredEventChance($raw): int
    {
        if (is_bool($raw) || !is_numeric($raw)) {
            return self::BORED_EVENT_DEFAULT;
        }

        return max(0, min(100, intval($raw)));
    }

    public static function defaultMetadata(): array
    {
        return [
            'RECHAT_H' => '4',
            'RECHAT_P' => '100',
            'RECHAT_ALLOW_ACTIONS' => true,
            'DYNAMIC_PROFILE_FIELDS' => [
                'personality',
                'speechstyle',
                'goals',
            ],
            'RPG_COMMENTS' => ['levelup', 'combat_end', 'lockpick', 'sleep', 'location_changed', 'quest_updated'],
            'RPG_COMMENTS_CHANCE' => 20,
            'LLM_FALLBACK_ENABLED' => true,
            'COMBAT_BARK_COOLDOWN' => 30,
            'BORED_EVENT' => self::BORED_EVENT_DEFAULT,
            'DIARY_PROMPT' => "Please write a short summary of #PLAYER_NAME# and #DIALECTIC_NAME#'s recent dialogues and events into #DIALECTIC_NAME#'s diary. WRITE AS IF YOU WERE #DIALECTIC_NAME#. Start the diary entry with the current date and time.",
            'DIARY_COOLDOWN' => 120,
            'CONTEXT_HISTORY_DIARY' => 100,
            'AUTO_DIARY_ENABLED' => false,
            'AUTO_DIARY_WAIT_ENABLED' => true,
            'LATEST_DIARY_CONTEXT_ENABLED' => false,
            'SALUTATION_AFTER_A_WHILE' => false,
        ];
    }

    public function create($data)
    {
        $fields = [
            "label",
            "default_npc",
            "default_narrator",
            "tts_connector_id",
            "diary_connector_id",
            "llm_primary_id",
            "llm_secondary_id",
            "llm_tertiary_id",
            "llm_quaternary_id",
            "llm_formatter_id",
            "llm_fallback_id",
            "metadata",
            "slot",
            "prompt",
        ];

        // Seed defaults into metadata if not provided
        if (! isset($data['metadata']) || $data['metadata'] === '' || $data['metadata'] === null) {
            $data['metadata'] = json_encode(self::defaultMetadata(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        foreach ($data as $k => $v) {
            // Preserve explicit 0/false values; only treat empty-string/null as unset.
            if ($v === '' || $v === null) {
                $data[$k] = null;
            }
        }

        // Validate slot (NULL or 1..4 and unique)
        if (array_key_exists('slot', $data)) {
            $slotRaw = $data['slot'];
            $slotVal = ($slotRaw === null || $slotRaw === '') ? null : intval($slotRaw);
            if (! is_null($slotVal) && ($slotVal < 1 || $slotVal > 4)) {
                $this->lastError = 'Slot must be 1-4 or empty';
                return false;
            }
            if (! is_null($slotVal)) {
                $exists = $GLOBALS["db"]->fetchOne("SELECT id FROM core_profiles WHERE slot=" . $slotVal . " LIMIT 1");
                if (is_array($exists) && isset($exists['id'])) {
                    $this->lastError = 'That slot is already assigned to another profile';
                    return false;
                }
            }
            $data['slot'] = $slotVal;
        }

        $filtered = array_intersect_key($data, array_flip($fields));
        $createdId = $GLOBALS["db"]->insertReturningId($this->table, $filtered);
        $this->repairDefaultFlags($createdId ?: null, $filtered);
        return $createdId;
    }

    public function readAll()
    {
        $query = "SELECT * FROM {$this->table} ORDER BY id ASC";
        return $GLOBALS["db"]->fetchAll($query);
    }

    public function getDefaultNpc()
    {
        $query = "SELECT * FROM {$this->table} where default_npc='1' ORDER BY id ASC";
        return $GLOBALS["db"]->fetchOne($query);
    }

    public function getDefaultNarrator()
    {
        $query = "SELECT * FROM {$this->table} where default_narrator='1' ORDER BY id ASC";
        return $GLOBALS["db"]->fetchOne($query);
    }

    public function readOne($id)
    {
        $id    = intval($id);
        $query = "SELECT * FROM {$this->table} WHERE id = {$id} LIMIT 1";
        return $GLOBALS["db"]->fetchOne($query);
    }

    public function getBySlot($slot)
    {
        $slot  = intval($slot);
        $query = "SELECT * FROM {$this->table} WHERE slot = {$slot} LIMIT 1";
        return $GLOBALS["db"]->fetchOne($query);
    }

    public function getById($id)
    {
        return $this->readOne($id);
    }

    public function update($id, $data)
    {
        $id    = intval($id);
        $where = "id = {$id}";

        $fields = [
            "label",
            "default_npc",
            "default_narrator",
            "tts_connector_id",   // fk to table  core_tts_connector
            "diary_connector_id", // fk to table  core_diary_connector
            "llm_primary_id",     // fk to table  core_llm_connector
            "llm_secondary_id",   // fk to table  core_llm_connector
            "llm_tertiary_id",    // fk to table  core_llm_connector
            "llm_quaternary_id",  // fk to table  core_llm_connector
            "llm_formatter_id",   // fk to table  core_llm_connector
            "llm_fallback_id",    // fk to table  core_llm_connector
            "metadata",
            "slot",
            "prompt",
        ];

        foreach ($data as $k => $v) {
            // Preserve explicit 0/false values; only treat empty-string/null as unset.
            if ($v === '' || $v === null) {
                $data[$k] = null;
            }
        }

        // Validate slot (NULL or 1..4 and unique for other profiles)
        if (array_key_exists('slot', $data)) {
            $slotRaw = $data['slot'];
            $slotVal = ($slotRaw === null || $slotRaw === '') ? null : intval($slotRaw);
            if (! is_null($slotVal) && ($slotVal < 1 || $slotVal > 4)) {
                $this->lastError = 'Slot must be 1-4 or empty';
                return false;
            }
            if (! is_null($slotVal)) {
                $exists = $GLOBALS["db"]->fetchOne("SELECT id FROM core_profiles WHERE slot=" . $slotVal . " AND id<>" . $id . " LIMIT 1");
                if (is_array($exists) && isset($exists['id'])) {
                    $this->lastError = 'That slot is already assigned to another profile';
                    return false;
                }
            }
            $data['slot'] = $slotVal;
        }

        $filtered = array_intersect_key($data, array_flip($fields));
        $updated = $GLOBALS["db"]->updateRow($this->table, $filtered, $where);
        if ($updated !== false) {
            $this->repairDefaultFlags($id, $filtered);
        }
        return $updated;
    }

    public function delete($id)
    {
        $id = intval($id);

        $allProfiles = $this->readAll();
        if (count($allProfiles) <= 1) {
            $this->lastError = 'Cannot delete the last remaining profile';
            return false;
        }

        $profile = $this->readOne($id);
        if (!$profile) {
            $this->lastError = 'Profile not found';
            return false;
        }

        if ($profile['default_npc'] == '1') {
            $this->lastError = 'Cannot delete the default NPC profile. Set another profile as default first.';
            return false;
        }

        if ($profile['default_narrator'] == '1') {
            $this->lastError = 'Cannot delete the default Narrator profile. Set another profile as default first.';
            return false;
        }

        return $GLOBALS["db"]->delete($this->table, "id = {$id}");
    }

    public function isDefaultNpc($id): bool
    {
        $id = intval($id);
        $profile = $this->readOne($id);
        return $profile && $profile['default_npc'] == '1';
    }

    public function isDefaultNarrator($id): bool
    {
        $id = intval($id);
        $profile = $this->readOne($id);
        return $profile && $profile['default_narrator'] == '1';
    }

    public function promoteToDefaultNpc($id)
    {
        $id = intval($id);
        $GLOBALS["db"]->query("UPDATE {$this->table} SET default_npc = '0' WHERE default_npc = '1'");
        return $GLOBALS["db"]->query("UPDATE {$this->table} SET default_npc = '1' WHERE id = {$id}");
    }

    public function promoteToDefaultNarrator($id)
    {
        $id = intval($id);
        $GLOBALS["db"]->query("UPDATE {$this->table} SET default_narrator = '0' WHERE default_narrator = '1'");
        return $GLOBALS["db"]->query("UPDATE {$this->table} SET default_narrator = '1' WHERE id = {$id}");
    }

    public function getProfileCount(): int
    {
        $row = $GLOBALS["db"]->fetchOne("SELECT COUNT(*) AS c FROM {$this->table}");
        return $row ? (int)$row['c'] : 0;
    }

    public function truncate($restart = false, $cascade = false)
    {
        return $GLOBALS["db"]->truncate($this->table, $restart, $cascade);
    }

    public function getLastError()
    {
        if (! empty($this->lastError)) {
            return $this->lastError;
        }

        return $GLOBALS["db"]->GetLastError();
    }

    private function repairDefaultFlags(?int $changedId = null, array $changedData = []): void
    {
        if ($changedId !== null && $changedId > 0) {
            if (($changedData['default_npc'] ?? null) == '1') {
                $GLOBALS["db"]->query("UPDATE {$this->table} SET default_npc = '0' WHERE id <> {$changedId}");
            }
            if (($changedData['default_narrator'] ?? null) == '1') {
                $GLOBALS["db"]->query("UPDATE {$this->table} SET default_narrator = '0' WHERE id <> {$changedId}");
            }
        }

        $first = $GLOBALS["db"]->fetchOne("SELECT id FROM {$this->table} ORDER BY id ASC LIMIT 1");
        if (!is_array($first) || empty($first['id'])) {
            return;
        }

        $firstId = intval($first['id']);
        $npcDefault = $GLOBALS["db"]->fetchOne("SELECT id FROM {$this->table} WHERE default_npc = '1' ORDER BY id ASC LIMIT 1");
        if (!is_array($npcDefault) || empty($npcDefault['id'])) {
            $GLOBALS["db"]->query("UPDATE {$this->table} SET default_npc = '1' WHERE id = {$firstId}");
        }

        $narratorDefault = $GLOBALS["db"]->fetchOne("SELECT id FROM {$this->table} WHERE default_narrator = '1' ORDER BY id ASC LIMIT 1");
        if (!is_array($narratorDefault) || empty($narratorDefault['id'])) {
            $GLOBALS["db"]->query("UPDATE {$this->table} SET default_narrator = '1' WHERE id = {$firstId}");
        }
    }

    public function getAllFk($field)
    {
        // Map foreign key fields to their respective tables
        $fkMap = [
            "tts_connector_id"   => "core_tts_connector",
            "diary_connector_id" => "core_llm_connector",
            "llm_primary_id"     => "core_llm_connector",
            "llm_secondary_id"   => "core_llm_connector",
            "llm_tertiary_id"    => "core_llm_connector",
            "llm_quaternary_id"  => "core_llm_connector",
            "llm_formatter_id"   => "core_llm_connector",
            "llm_fallback_id"    => "core_llm_connector",
        ];

        if (! array_key_exists($field, $fkMap)) {
            return []; // Unknown field
        }

        $table = $fkMap[$field];
        // For LLM-backed fields, prefer connector label; fallback to model if label is empty
        if ($table === 'core_llm_connector') {
            $query = "SELECT id, COALESCE(NULLIF(label,''), model) AS label FROM {$table} ORDER BY id ASC";
        } else {
            $query = "SELECT id, label FROM {$table} ORDER BY id ASC";
        }
        return $GLOBALS["db"]->fetchAll($query);
    }

    public function setOldGlobals($currentProfileData)
    {

        // Load TTS connector configuration
        $ttsConMgr = new TTSConnector();
        $ttsConnectorId = intval($currentProfileData['tts_connector_id'] ?? 0);
        $ttsCon = $ttsConnectorId > 0 ? $ttsConMgr->getById($ttsConnectorId) : null;
        if (!$ttsCon) {
            $ttsCon = ['driver' => 'none', 'metadata' => '{}'];
        }
        $ttsConMgr->setOldGlobals($ttsCon);

        // Decode and apply profile metadata
        $metadata = json_decode($currentProfileData['metadata'] ?? '{}', true);
        $narratorManagedKeys = [
            'REMOVE_ASTERISKS_FROM_PLAYER_INPUT',
            'REMOVE_ASTERISKS_FROM_NPC_OUTPUT',
            'INLINE_NARRATION_MODE',
            'REMOVE_PLAYER_AUTOCHAT_ASTERISKS',
            'PRESERVE_ASTERISKS_IN_CONTEXT'
        ];
        if (is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                if (in_array(strtoupper((string)$key), $narratorManagedKeys, true)) {
                    continue;
                }
                // Use isset-style check instead of empty() to properly handle boolean false values
                // empty(false) returns true, which would skip applying false values from profile
                if ($value !== null && $value !== '') {
                    dialecticApplyOverrideValueToGlobals(strval($key), $value);
                    error_log("[CORE] PROFILE  GLOBALS[$key] = " . print_r($value, true));
                }
            }
        }
        // Replace the removed global setting with the selected profile value before NPC overrides.
        $GLOBALS["BORED_EVENT"] = self::normalizeBoredEventChance(
            is_array($metadata) ? ($metadata['BORED_EVENT'] ?? null) : null
        );

        $GLOBALS["ENFORCE_ACTIONS_PROMPT"] = false;
        if (isset($currentProfileData["prompt"])) {
            $GLOBALS["PROFILE_PROMPT"] = $currentProfileData["prompt"];
        }

    }

    public function clone ($id)
    {
        $id       = intval($id);
        $original = $this->readOne($id);

        if (! $original) {
            return false; // Original record not found
        }

        unset($original['id']); // Remove the ID to create a new record
                                // Clear fields that must be unique or should not be duplicated verbatim
                                // Slot must be unique 1-4; clear to avoid conflict
        $original['slot'] = null;
        // Avoid inadvertently setting new defaults
        $original['default_npc']      = 0;
        $original['default_narrator'] = 0;
        // Modify label to indicate it's a clone and keep it concise
        $original['label'] = (isset($original['label']) && $original['label'] !== ''
                ? ($original['label'] . ' (Copy)')
                : ('Profile ' . $id . ' (Copy)'));

        return $this->create($original);
    }

    public function getMetadata($mda): array
    {
        return json_decode($mda['metadata'] ?? '{}', true) ?: [];
    }

    public function setMetadata($mda, array $data)
    {

        $mda['metadata'] = json_encode($data);
        return $mda;
    }

}
