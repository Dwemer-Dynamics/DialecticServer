<?php
/**
 * DIALECTIC Relationship Manager
 *
 * Handles NPC relationship tracking, context injection, and AI output parsing.
 * Relationship state is stored in core_npc_master extended_data and injected into dialogue context.
 *
 * Usage:
 *   - prerequest: RelationshipManager::buildContext($npcName, $nearbyNpcs)
 *   - postrequest: RelationshipManager::parseChanges($aiResponse, $npcName)
 *   - queries: RelationshipManager::getRelationship($npcName, $targetName)
 */

class RelationshipManager {

    // Valid relationship types (the "flavor" of the relationship)
    // Classic types
    const TYPES_CLASSIC = ['romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral'];
    // Extended types for richer relationships
    const TYPES_EXTENDED = [
        'nemesis',      // Personal vendetta, obsessive hatred
        'estranged',    // Broken familial/platonic bond
        'transactional',// Business relationship, mercenary
        'protective',   // Guardian, mentor
        'indebted',     // Owes a debt of gratitude/obligation
        'fanatical',    // Blind loyalty (cultist, housecarl)
        'mentor',       // Teacher relationship
        'student',      // Learning from target
        'servant',      // Serves the target
        'client',       // Target is a customer
        'patron',       // Target supports/sponsors
        'crush',        // Unrequited romantic interest
        'ex',           // Former romantic partner
        'betrayed',     // Was betrayed by target
        'suspicious',   // Doesn't trust target's motives
        'admirer',      // Looks up to target
        'jealous',      // Envies the target
        'fearful',      // Afraid of target
        'obsessed',     // Unhealthy fixation
        'awed',         // Intimidated by power/status
        'contempt',     // Looks down on target
        'pitying',      // Feels sorry for target
        'grateful',     // Thankful for past help
        'curious',      // Wants to know more
        'dismissive',   // Considers target beneath notice
    ];
    // All valid types combined
    const TYPES = [
        'romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral',
        'nemesis', 'estranged', 'transactional', 'protective', 'indebted', 'fanatical',
        'mentor', 'student', 'servant', 'client', 'patron', 'crush', 'ex',
        'betrayed', 'suspicious', 'admirer', 'jealous', 'fearful', 'obsessed',
        'awed', 'contempt', 'pitying', 'grateful', 'curious', 'dismissive'
    ];
    const TYPE_ALIASES = [
        'romance' => 'romantic',
        'marriage' => 'romantic',
        'married' => 'romantic',
        'lover' => 'romantic',
        'lovers' => 'romantic',
        'betrayal' => 'betrayed',
        'enemies' => 'enemy',
    ];

    // Tier boundaries - 11 tiers with BELL CURVE distribution
    // Extremes are HARD to reach (10 pts), center tiers are WIDE (25 pts)
    // Score => Label (checked from high to low)
    const TIERS = [
        91  => 'Bonded',       // +91 to +100: Unbreakable connection (10 pts)
        76  => 'Devoted',      // +76 to +90: Deep loyalty/love (15 pts)
        56  => 'Fond',         // +56 to +75: Genuine affection (20 pts)
        31  => 'Friendly',     // +31 to +55: Pleasant, helpful (25 pts)
        6   => 'Acquaintance', // +6 to +30: Recognize, polite nod (25 pts)
        -5  => 'Neutral',      // -5 to +5: Stranger/indifferent (11 pts)
        -30 => 'Wary',         // -30 to -6: Distrustful, suspicious (25 pts)
        -55 => 'Cold',         // -55 to -31: Unfriendly, dismissive (25 pts)
        -75 => 'Resentful',    // -75 to -56: Bitter, holds grudges (20 pts)
        -90 => 'Hateful',      // -90 to -76: Active malice (15 pts)
        -100 => 'Hostile'      // -100 to -91: Kill on sight (10 pts)
    ];

    // Compact ASCII-safe icons for UI display. Keep these ASCII to avoid mojibake in mixed Windows/WSL deployments.
    const TYPE_EMOJI = [
        'romantic'     => '<3',
        'platonic'     => '++',
        'familial'     => 'fam',
        'professional' => 'job',
        'rival'        => 'vs',
        'enemy'        => '!!',
        'neutral'      => '-',
        'nemesis'      => 'xx',
        'estranged'    => '</3',
        'transactional'=> '$',
        'protective'   => 'sh',
        'indebted'     => 'debt',
        'fanatical'    => 'fire',
        'mentor'       => 'book',
        'student'      => 'grad',
        'servant'      => 'svc',
        'client'       => 'cart',
        'patron'       => 'crown',
        'crush'        => '<3?',
        'ex'           => 'ex',
        'betrayed'     => 'knife',
        'suspicious'   => 'eye',
        'admirer'      => 'star',
        'jealous'      => 'envy',
        'fearful'      => 'fear',
        'obsessed'     => 'obs',
        'awed'         => 'awe',
        'contempt'     => 'ugh',
        'pitying'      => 'pity',
        'grateful'     => 'ty',
        'curious'      => '?',
        'dismissive'   => 'meh'
    ];

    const TIER_EMOJI = [
        'Bonded'       => 'S++',
        'Devoted'      => 'S+',
        'Fond'         => 'A',
        'Friendly'     => 'B',
        'Acquaintance' => 'C',
        'Neutral'      => '-',
        'Wary'         => 'D',
        'Cold'         => 'E',
        'Resentful'    => 'F',
        'Hateful'      => 'F-',
        'Hostile'      => 'X'
    ];

    // Extra icon pool for custom relationship types.
    const EMOJI_POOL = [
        '<3', '</3', '++', '--', 'vs', '!!', '?', '*', '$', 'sh', 'eye', 'book', 'star', 'fire',
        'job', 'fam', 'debt', 'awe', 'fear', 'ty', 'meh', 'ex', 'svc', 'cart', 'crown'
    ];

    public static function shouldRunAutomaticEvaluation($chance = null, $roll = null) {
        if ($chance === null) {
            $chance = $GLOBALS['RELATIONSHIP_UPDATE_CHANCE'] ?? 50;
        }

        $chance = is_numeric($chance) ? max(0, min(100, (int)$chance)) : 50;
        if ($chance === 0) {
            return false;
        }
        if ($chance === 100) {
            return true;
        }

        $roll = $roll === null ? random_int(1, 100) : max(1, min(100, (int)$roll));
        return $roll <= $chance;
    }

    /**
     * Relationship storage uses "Player" as the canonical player target.
     * Prompts may expose the detected Fallout character name, but persisting
     * that display name separately would split one relationship into two.
     */
    public static function normalizeTargetName($targetName) {
        $target = trim((string)$targetName);
        if ($target === '') {
            return '';
        }

        $aliases = [
            'player',
            'the player',
            'player character',
            'the player character',
            'courier',
            'the courier',
            '#player_name#',
            '{player_name}',
        ];
        $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        if ($playerName !== '') {
            $aliases[] = strtolower($playerName);
        }

        return in_array(strtolower($target), $aliases, true) ? 'Player' : $target;
    }

    /**
     * Canonicalize a model-supplied type without allowing the model to invent one.
     */
    public static function canonicalizeRelationshipType($type, $allowedCustomTypes = []) {
        if (!is_string($type)) {
            return null;
        }

        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return null;
        }

        $normalized = self::TYPE_ALIASES[$normalized] ?? $normalized;
        if (in_array($normalized, self::TYPES, true)) {
            return $normalized;
        }

        foreach ((array)$allowedCustomTypes as $customType) {
            $customType = strtolower(trim((string)$customType));
            if ($customType === '' || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $customType)) {
                continue;
            }
            $customType = self::TYPE_ALIASES[$customType] ?? $customType;
            if ($normalized === $customType) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Return only custom types already created and stored by the player.
     */
    public static function getCustomRelationshipTypes($relationships) {
        if (!is_array($relationships)) {
            return [];
        }

        $customTypes = [];
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $type = strtolower(trim((string)($relationship['type'] ?? '')));
            if ($type === '' || isset(self::TYPE_ALIASES[$type]) || in_array($type, self::TYPES, true)) {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $type)) {
                $customTypes[$type] = true;
            }
        }

        return array_keys($customTypes);
    }

    public static function normalizeRelationshipMap($relationships) {
        if (!is_array($relationships)) {
            return [];
        }

        $normalized = [];
        foreach ($relationships as $target => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $canonicalTarget = self::normalizeTargetName($target);
            if ($canonicalTarget === '' || strcasecmp($canonicalTarget, 'The Narrator') === 0) {
                continue;
            }

            $relationship['aff'] = max(-100, min(100, (int)($relationship['aff'] ?? 0)));
            $storedType = strtolower(trim((string)($relationship['type'] ?? 'neutral'))) ?: 'neutral';
            $relationship['type'] = self::TYPE_ALIASES[$storedType] ?? $storedType;
            foreach (['relation', 'note', 'best', 'worst'] as $field) {
                if (array_key_exists($field, $relationship)) {
                    $relationship[$field] = trim((string)$relationship[$field]);
                    if ($relationship[$field] === '') {
                        unset($relationship[$field]);
                    }
                }
            }

            if (!isset($normalized[$canonicalTarget])
                || self::relationshipDataWeight($relationship) > self::relationshipDataWeight($normalized[$canonicalTarget])) {
                $normalized[$canonicalTarget] = $relationship;
            }
        }

        return $normalized;
    }

    private static function relationshipDataWeight($relationship) {
        if (!is_array($relationship)) {
            return 0;
        }

        $weight = abs((int)($relationship['aff'] ?? 0));
        if (($relationship['type'] ?? 'neutral') !== 'neutral') {
            $weight += 5;
        }
        foreach (['relation', 'note', 'best', 'worst'] as $field) {
            if (!empty($relationship[$field])) {
                $weight += 2;
            }
        }
        return $weight;
    }
    /**
     * Get tier label from affinity score
     * PHP calculates this - AI never decides the label
     *
     * 11 Tiers with BELL CURVE distribution:
     * Extremes are hard to reach (10 pts), center tiers are wide (25 pts)
     *
     * +91 to +100: Bonded       (10 pts) - unbreakable
     * +76 to +90:  Devoted      (15 pts) - deep loyalty
     * +56 to +75:  Fond         (20 pts) - genuine affection
     * +31 to +55:  Friendly     (25 pts) - pleasant, helpful
     * +6 to +30:   Acquaintance (25 pts) - polite nod
     * -5 to +5:    Neutral      (11 pts) - stranger/indifferent
     * -30 to -6:   Wary         (25 pts) - distrustful
     * -55 to -31:  Cold         (25 pts) - unfriendly
     * -75 to -56:  Resentful    (20 pts) - bitter
     * -90 to -76:  Hateful      (15 pts) - active malice
     * -100 to -91: Hostile      (10 pts) - kill on sight
     */
    public static function getTierLabel($score) {
        if ($score >= 91) return "Bonded";
        if ($score >= 76) return "Devoted";
        if ($score >= 56) return "Fond";
        if ($score >= 31) return "Friendly";
        if ($score >= 6) return "Acquaintance";
        if ($score >= -5) return "Neutral";
        if ($score >= -30) return "Wary";
        if ($score >= -55) return "Cold";
        if ($score >= -75) return "Resentful";
        if ($score >= -90) return "Hateful";
        return "Hostile";
    }

    /**
     * Get tier reference prompt from database (custom or default)
     * This is injected into NPC context to help the conversation model
     * understand how to adjust behavior based on relationship tiers.
     */
    public static function getTierReferencePrompt() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // Default fallback if database unavailable
        $default = "[TIER REFERENCE - Adjust behavior toward NPCs based on tier]\n" .
            "HOSTILE: Wants them dead, attack on sight\n" .
            "HATEFUL: Despises, refuses cooperation, threatens\n" .
            "RESENTFUL: Deep grudge, bitter, may sabotage\n" .
            "COLD: Dismissive, unhelpful, curt\n" .
            "WARY: Suspicious, guarded, reluctant\n" .
            "NEUTRAL: Polite stranger, no special treatment\n" .
            "ACQUAINTANCE: Recognizes, mildly helpful\n" .
            "FRIENDLY: Pleasant, helpful, enjoys company\n" .
            "FOND: Warm, protective, prioritizes them\n" .
            "DEVOTED: Deep loyalty, would sacrifice\n" .
            "BONDED: Absolute trust, would die for them";

        try {
            // Query the prompts table for custom or default tier reference
            $db = $GLOBALS["db"] ?? null;
            $row = $db && is_object($db) && method_exists($db, 'fetchOne') ? $db->fetchOne(
                "SELECT COALESCE(custom_prompt, default_prompt) as prompt " .
                "FROM prompts WHERE prompt_key = 'rel_tier_reference'"
            ) : null;
            if ($row && !empty($row['prompt'])) {
                $cached = $row['prompt'];
                return $cached;
            }
        } catch (Throwable $e) {
            error_log("[REL] Failed to load tier reference prompt: " . $e->getMessage());
        }

        $cached = $default;
        return $cached;
    }

    /**
     * Get emoji for tier
     */
    public static function getTierEmoji($tier) {
        return self::TIER_EMOJI[$tier] ?? '?';
    }

    /**
     * Get emoji for type
     */
    public static function getTypeEmoji($type) {
        return self::TYPE_EMOJI[strtolower($type)] ?? '?';
    }

    /**
     * Get NPC's relationship data from extended_data
     */
    public static function getRelationships($npcName) {
        require_once __DIR__ . "/core/npc_master.class.php";
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);

        if (!$npcData) {
            $npcData = $npcMaster->getByName(ucfirst(strtolower($npcName)));
        }

        if (!$npcData) {
            return [];
        }

        $extended = json_decode($npcData['extended_data'] ?? '{}', true) ?: [];
        return self::normalizeRelationshipMap($extended['relationships'] ?? []);
    }

    /**
     * Get specific relationship between NPC and target
     *
     * @return array ['aff' => int, 'type' => string, 'tier' => string]
     */
    public static function getRelationship($npcName, $targetName) {
        $rels = self::getRelationships($npcName);
        $targetName = self::normalizeTargetName($targetName);

        if (isset($rels[$targetName])) {
            $rel = $rels[$targetName];
            $rel['tier'] = self::getTierLabel($rel['aff'] ?? 0);
            return $rel;
        }

        // Default: neutral stranger
        return [
            'aff' => 0,
            'type' => 'neutral',
            'tier' => 'Neutral'
        ];
    }

    /**
     * Get relationship with Player specifically
     * Convenience method for common use case
     */
    public static function getPlayerRelationship($npcName) {
        return self::getRelationship($npcName, 'Player');
    }

    /**
     * Build relationship context block for AI injection
     * Only includes Player + nearby NPCs to save tokens
     *
     * When RELLLM_CONNECTOR is set:
     *   - Shows only tier labels (Fond, Wary) - token efficient
     *   - Conversation model doesn't need to know exact numbers
     *
     * When RELLLM_CONNECTOR is not set:
     *   - Shows numbers too for the #REL: command system
     *
     * @param string $npcName The speaking NPC
     * @param array $nearbyNpcs Names of NPCs in the scene
     * @return string Context block to inject
     */
    public static function buildContext($npcName, $nearbyNpcs = []) {
        return self::buildContextFromRelationships(
            $npcName,
            self::getRelationships($npcName),
            $nearbyNpcs
        );
    }

    public static function buildContextFromRelationships(
        $npcName,
        array $rels,
        array $nearbyNpcs = [],
        $tierOnly = null
    ) {
        if ($tierOnly === null) {
            $tierOnly = !empty($GLOBALS['RELLLM_CONNECTOR']) && intval($GLOBALS['RELLLM_CONNECTOR']) > 0;
        }
        $tierOnly = (bool)$tierOnly;

        $lines = [];

        // Add behavioral instructions so the conversation model knows how to use these tiers
        if ($tierOnly) {
            // Get tier reference prompt from database (custom or default)
            $tierPrompt = self::getTierReferencePrompt();
            $tierLines = explode("\n", $tierPrompt);
            foreach ($tierLines as $tierLine) {
                $lines[] = trim($tierLine);
            }
            $lines[] = "";
            $lines[] = self::buildRelationshipHeading($npcName);
        } else {
            $lines[] = self::buildRelationshipHeading($npcName);
        }

        // Always include Player - use actual player name for context display
        $playerRel = $rels['Player'] ?? ['aff' => 0, 'type' => 'neutral'];
        $playerAff = $playerRel['aff'] ?? 0;
        $playerType = ucfirst($playerRel['type'] ?? 'neutral');
        $playerTier = self::getTierLabel($playerAff);
        $playerRelation = $playerRel['relation'] ?? '';
        $playerNote = $playerRel['note'] ?? '';
        $playerBest = $playerRel['best'] ?? '';
        $playerWorst = $playerRel['worst'] ?? '';

        // Get actual player name for display (falls back to "Player" if not set)
        $playerDisplayName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        if (empty($playerDisplayName) || $playerDisplayName === 'the Player') {
            $playerDisplayName = 'Player';
        }

        // Build type/relation string: "Familial/son" or just "Familial"
        $typeStr = $playerType;
        if (!empty($playerRelation)) {
            $typeStr .= "/" . $playerRelation;
        }

        if ($tierOnly) {
            // Token-efficient: tier, type/relation, and events
            $playerLine = sprintf("%s: %s (%s)", $playerDisplayName, $playerTier, $typeStr);
            $playerLine .= self::formatEventNotes($playerWorst, $playerBest, $playerNote);
            $lines[] = $playerLine;
        } else {
            // Include numbers for #REL: command system
            $playerLine = sprintf("%s: %+d (%s, %s)", $playerDisplayName, $playerAff, $playerTier, $typeStr);
            $playerLine .= self::formatEventNotes($playerWorst, $playerBest, $playerNote);
            $lines[] = $playerLine;
        }

        // Add nearby NPCs only
        foreach ($nearbyNpcs as $target) {
            $target = trim($target);
            if (empty($target) || strtolower($target) === 'player') continue;

            if (isset($rels[$target])) {
                $r = $rels[$target];
                $aff = $r['aff'] ?? 0;
                $type = ucfirst($r['type'] ?? 'neutral');
                $tier = self::getTierLabel($aff);
                $relation = $r['relation'] ?? '';
                $note = $r['note'] ?? '';
                $best = $r['best'] ?? '';
                $worst = $r['worst'] ?? '';

                // Build type/relation string
                $typeStr = $type;
                if (!empty($relation)) {
                    $typeStr .= "/" . $relation;
                }

                if ($tierOnly) {
                    $line = sprintf("%s: %s (%s)", $target, $tier, $typeStr);
                } else {
                    $line = sprintf("%s: %+d (%s, %s)", $target, $aff, $tier, $typeStr);
                }

                $line .= self::formatEventNotes($worst, $best, $note);
                $lines[] = $line;
            }
            // If no existing relationship, don't include - they're strangers
        }

        return implode("\n", $lines);
    }

    private static function buildRelationshipHeading($npcName) {
        $npcName = trim((string)$npcName);
        if ($npcName === '') {
            return "[RELATIONSHIPS]";
        }

        $suffix = preg_match('/s$/i', $npcName) ? "'" : "'s";
        return "[" . $npcName . $suffix . " RELATIONSHIPS]";
    }

    /**
     * Format event notes for context injection
     * Output: " - worst; best | note" (only includes non-empty fields)
     *
     * Examples:
     *   - killed brother; saved life | gave wine
     *   - killed brother | gave wine
     *   - saved life | gave wine
     *   - gave wine
     *   - killed brother; saved life
     */
    private static function formatEventNotes($worst, $best, $note) {
        $parts = [];

        // Major events (worst and best) come first, separated by semicolon
        $majorEvents = [];
        if (!empty($worst)) {
            $majorEvents[] = $worst;
        }
        if (!empty($best)) {
            $majorEvents[] = $best;
        }

        if (!empty($majorEvents)) {
            $parts[] = implode('; ', $majorEvents);
        }

        // Minor recent interaction comes after pipe separator
        if (!empty($note)) {
            $parts[] = $note;
        }

        if (empty($parts)) {
            return '';
        }

        // Format: " - major_events | minor_note" or " - major_events" or " - minor_note"
        if (count($parts) === 2) {
            return " - " . $parts[0] . " | " . $parts[1];
        } else {
            return " - " . $parts[0];
        }
    }

    /**
     * Parse AI output for relationship changes and apply them
     *
     * @param string $aiResponse Raw AI response
     * @param string $npcName The speaking NPC
     * @return string Cleaned response with commands stripped
     */
    public static function extractChangeCommands($aiResponse, array $relationships = []) {
        $commands = [
            'affinity' => [],
            'types' => [],
        ];

        if (preg_match_all('/#REL:([^=]+)=([+-]?\d+)#/', (string)$aiResponse, $matches)) {
            foreach ($matches[1] as $i => $target) {
                $commands['affinity'][] = [
                    'target' => trim($target),
                    'delta' => (int)$matches[2][$i],
                ];
            }
        }

        $allowedCustomTypes = self::getCustomRelationshipTypes($relationships);
        if (preg_match_all('/#TYPE:([^=]+)=([a-zA-Z][a-zA-Z0-9_-]{0,49})#/', (string)$aiResponse, $matches)) {
            foreach ($matches[1] as $i => $target) {
                $rawType = trim($matches[2][$i]);
                $newType = self::canonicalizeRelationshipType($rawType, $allowedCustomTypes);
                if ($newType === null) {
                    error_log("[REL] Rejected invented relationship type '$rawType'");
                    continue;
                }
                $commands['types'][] = [
                    'target' => trim($target),
                    'type' => $newType,
                ];
            }
        }

        return $commands;
    }

    public static function applyChangeCommands(array $relationships, array $commands) {
        $changed = false;

        foreach (($commands['affinity'] ?? []) as $change) {
            $target = trim((string)($change['target'] ?? ''));
            if ($target === '') {
                continue;
            }
            if (!isset($relationships[$target])) {
                $relationships[$target] = ['aff' => 0, 'type' => 'neutral'];
            }
            $oldAff = intval($relationships[$target]['aff'] ?? 0);
            $newAff = max(-100, min(100, $oldAff + intval($change['delta'] ?? 0)));
            if ($newAff !== $oldAff) {
                $relationships[$target]['aff'] = $newAff;
                $changed = true;
            }
        }

        foreach (($commands['types'] ?? []) as $change) {
            $target = trim((string)($change['target'] ?? ''));
            $newType = self::canonicalizeRelationshipType(
                $change['type'] ?? null,
                self::getCustomRelationshipTypes($relationships)
            );
            if ($target === '' || $newType === null) {
                continue;
            }
            if (!isset($relationships[$target])) {
                $relationships[$target] = ['aff' => 0, 'type' => 'neutral'];
            }
            $oldType = strtolower((string)($relationships[$target]['type'] ?? 'neutral'));
            if ($newType !== $oldType) {
                $relationships[$target]['type'] = $newType;
                $changed = true;
            }
        }

        return [
            'relationships' => $relationships,
            'changed' => $changed,
        ];
    }

    public static function parseChanges($aiResponse, $npcName) {
        require_once __DIR__ . "/core/npc_master.class.php";
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);

        if (!$npcData) {
            $npcData = $npcMaster->getByName(ucfirst(strtolower($npcName)));
        }

        if (!$npcData) {
            // Can't update relationships for unknown NPC
            return preg_replace('/#(REL|TYPE):[^#]+#/', '', $aiResponse);
        }

        $extended = json_decode($npcData['extended_data'] ?? '{}', true) ?: [];
        if (!empty($extended['relationships_locked'])) {
            return preg_replace('/#(REL|TYPE):[^#]+#/', '', $aiResponse);
        }
        $rels = self::normalizeRelationshipMap($extended['relationships'] ?? []);
        $commands = self::extractChangeCommands($aiResponse, $rels);
        $result = self::applyChangeCommands($rels, $commands);
        $rels = $result['relationships'];
        $changed = $result['changed'];

        // Save if changed
        if ($changed) {
            $extended['relationships'] = $rels;
            $saved = $npcMaster->updateByArray([
                'id' => $npcData['id'],
                'extended_data' => json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);
            if ($saved) {
                dialecticRelationshipTimelineStamp($npcData['id'], 'relationship_inline');
            }
        }

        // Strip commands before TTS
        return preg_replace('/#(REL|TYPE):[^#]+#/', '', $aiResponse);
    }

    /**
     * Set relationship directly (for initialization or admin)
     */
    public static function setRelationship($npcName, $targetName, $affinity, $type = null) {
        $targetName = self::normalizeTargetName($targetName);
        require_once __DIR__ . "/core/npc_master.class.php";
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);

        if (!$npcData) {
            $npcData = $npcMaster->getByName(ucfirst(strtolower($npcName)));
        }

        if (!$npcData) {
            error_log("[REL] Cannot set relationship - NPC not found: $npcName");
            return false;
        }

        $extended = json_decode($npcData['extended_data'] ?? '{}', true) ?: [];
        $rels = self::normalizeRelationshipMap($extended['relationships'] ?? []);

        // Initialize or update
        if (!isset($rels[$targetName])) {
            $rels[$targetName] = ['aff' => 0, 'type' => 'neutral'];
        }

        $rels[$targetName]['aff'] = max(-100, min(100, (int)$affinity));

        // Direct/admin writes may create custom types; known aliases remain canonical.
        if ($type !== null && is_string($type) && strlen($type) > 0 && strlen($type) <= 50) {
            $normalizedType = strtolower(trim($type));
            $rels[$targetName]['type'] = self::TYPE_ALIASES[$normalizedType] ?? $normalizedType;
        }

        $extended['relationships'] = $rels;
        $saved = $npcMaster->updateByArray([
            'id' => $npcData['id'],
            'extended_data' => json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);

        if (!$saved) {
            return false;
        }
        dialecticRelationshipTimelineStamp($npcData['id'], 'relationship_admin');

        error_log("[REL] Set $npcName -> $targetName: " . $rels[$targetName]['aff'] .
                  " (" . $rels[$targetName]['type'] . ")");

        return true;
    }

    /**
     * Adjust relationship by delta (convenience method)
     */
    public static function adjustRelationship($npcName, $targetName, $delta) {
        $current = self::getRelationship($npcName, $targetName);
        $newAff = $current['aff'] + $delta;
        return self::setRelationship($npcName, $targetName, $newAff, $current['type']);
    }

    /**
     * Get the system prompt addition for relationship tracking
     */
    public static function getSystemPromptAddition() {
        $prompt = <<<'PROMPT'
[SYSTEM: RELATIONSHIPS]
You have relationships with others defined by a Score (-100 to +100) and a Type.
Levels: Hostile(-100) < Resentful < Cold < Wary < Neutral(0) < Warm < Fond < Attached < Devoted(+100).

COMMANDS (use when feelings change - scale to the significance of the action):
- To adjust affinity: #REL:Name=+/-Amount#
- To change relationship type: #TYPE:Name=NewType# where NewType is EXACTLY one of the types below. Never invent a type.

Types: {RELATIONSHIP_TYPES}

SCALE YOUR RESPONSE TO THE ACTION:
- Minor: +/-5 to 10 (small kindness, rude comment)
- Moderate: +/-15 to 25 (meaningful gift, insult, helping in danger)
- Major: +/-30 to 50 (saving life, betrayal, violence against you)
- Extreme: +/-60 to 80 (killing loved one, life-changing sacrifice)

EXAMPLES:
Small kindness: "Thank you for the flowers." #REL:Player=+5#
Saved your life: "You... you saved me! I won't forget this." #REL:Player=+40#
Attacked you: "You dare strike me?!" #REL:Player=-35# #TYPE:Player=Rival#
Killed your friend: "MURDERER! I will NEVER forgive you!" #REL:Player=-70#
PROMPT;

        return str_replace('{RELATIONSHIP_TYPES}', implode(', ', self::TYPES), $prompt);
    }

    /**
     * Build relationship context for the Rolemaster/Director
     * Called when rolemaster activates to provide relationship awareness
     *
     * Returns prose descriptions of how characters feel about each other -
     * no scores, just narrative the director can use to guide the scene.
     *
     * @param array $npcsInScene Names of NPCs currently in scene
     * @param array $mentionedNpcs Names of NPCs mentioned in dialogue (optional)
     * @return string Prose context block for director
     */
    public static function buildDirectorContext($npcsInScene = [], $mentionedNpcs = []) {
        // Combine and dedupe NPC lists
        $allNpcs = array_unique(array_merge($npcsInScene, $mentionedNpcs));
        $allNpcs = array_filter($allNpcs, function($n) {
            $n = trim($n);
            return !empty($n) && strtolower($n) !== 'player';
        });

        if (empty($allNpcs)) {
            return "";
        }

        $descriptions = [];

        // Clean NPC names (remove status tags)
        $cleanNpcs = [];
        foreach ($allNpcs as $npc) {
            $clean = trim(preg_replace('/\s*\([^)]+\)/', '', $npc));
            if (!empty($clean)) {
                $cleanNpcs[] = $clean;
            }
        }

        // For each NPC, describe their feelings
        foreach ($cleanNpcs as $npc) {
            $rels = self::getRelationships($npc);
            if (empty($rels)) continue;

            $npcDescriptions = [];

            // How this NPC feels about the Player
            if (isset($rels['Player'])) {
                $desc = self::describeFeeling($npc, 'Player', $rels['Player']);
                if ($desc) $npcDescriptions[] = $desc;
            }

            // How this NPC feels about other NPCs in scene
            foreach ($cleanNpcs as $otherNpc) {
                if ($otherNpc === $npc) continue;
                if (isset($rels[$otherNpc])) {
                    $desc = self::describeFeeling($npc, $otherNpc, $rels[$otherNpc]);
                    if ($desc) $npcDescriptions[] = $desc;
                }
            }

            // Subject/topic affinities (anything that's not an NPC name or Player)
            $knownNames = array_map('strtolower', $cleanNpcs);
            $knownNames[] = 'player';

            foreach ($rels as $target => $r) {
                if (in_array(strtolower($target), $knownNames)) continue;
                // This is a subject/topic affinity
                $desc = self::describeSubjectFeeling($npc, $target, $r);
                if ($desc) $npcDescriptions[] = $desc;
            }

            if (!empty($npcDescriptions)) {
                $descriptions = array_merge($descriptions, $npcDescriptions);
            }
        }

        if (empty($descriptions)) {
            return "";
        }

        $lines = [];
        $lines[] = "[HOW CHARACTERS FEEL]";
        $lines = array_merge($lines, $descriptions);
        $lines[] = "";
        $lines[] = "Director: Ensure your instructions respect these feelings. Don't direct characters to act against their strong emotions.";

        return implode("\n", $lines);
    }

    /**
     * Describe how one character feels about another in prose
     */
    private static function describeFeeling($npc, $target, $relData) {
        $aff = $relData['aff'] ?? 0;
        $type = $relData['type'] ?? 'neutral';
        $worst = $relData['worst'] ?? '';
        $best = $relData['best'] ?? '';

        // Skip truly neutral relationships
        if ($aff >= -5 && $aff <= 5 && $type === 'neutral') {
            return null;
        }

        // Build the feeling description based on affinity AND type
        $feeling = self::getEmotionalDescription($aff, $type);

        $desc = "- {$npc} {$feeling} {$target}";

        // Add the WHY - the significant events that shaped this feeling
        $reasons = [];
        if (!empty($worst)) $reasons[] = $worst;
        if (!empty($best)) $reasons[] = $best;

        if (!empty($reasons)) {
            $desc .= " because " . implode(" and ", $reasons);
        }

        return $desc;
    }

    /**
     * Get emotional description based on affinity and relationship type
     */
    private static function getEmotionalDescription($aff, $type) {
        // High positive affinity
        if ($aff >= 76) {
            switch ($type) {
                case 'romantic': return "is deeply in love with";
                case 'familial': return "has an unbreakable family bond with";
                case 'nemesis':
                case 'enemy': return "is completely obsessed with - can't stop thinking about";
                case 'rival': return "has immense respect for as a worthy opponent -";
                case 'fearful': return "is utterly dependent on despite fearing";
                default: return "is utterly devoted to";
            }
        }
        // Moderate-high positive
        elseif ($aff >= 56) {
            switch ($type) {
                case 'romantic': return "has strong romantic feelings for";
                case 'familial': return "deeply loves and protects";
                case 'enemy':
                case 'nemesis': return "has a complex obsession with";
                case 'rival': return "genuinely respects and enjoys competing with";
                default: return "is genuinely fond of";
            }
        }
        // Positive
        elseif ($aff >= 31) {
            switch ($type) {
                case 'romantic': return "is attracted to";
                case 'rival': return "enjoys friendly competition with";
                default: return "is friendly toward";
            }
        }
        // Slight positive
        elseif ($aff >= 6) {
            return "is slightly positive toward";
        }
        // Neutral
        elseif ($aff >= -5) {
            return "is indifferent to";
        }
        // Slight negative
        elseif ($aff >= -30) {
            switch ($type) {
                case 'suspicious': return "doesn't trust";
                case 'fearful': return "is nervous around";
                default: return "is wary of";
            }
        }
        // Moderate negative
        elseif ($aff >= -55) {
            switch ($type) {
                case 'contempt': return "looks down on";
                case 'jealous': return "is bitterly jealous of";
                default: return "is cold and unfriendly toward";
            }
        }
        // Strong negative
        elseif ($aff >= -75) {
            switch ($type) {
                case 'betrayed': return "feels deeply betrayed by";
                default: return "resents and holds grudges against";
            }
        }
        // Very strong negative
        elseif ($aff >= -90) {
            return "actively hates";
        }
        // Extreme negative
        else {
            return "is hostile toward and would attack";
        }
    }

    /**
     * Describe how a character feels about a subject/topic
     */
    private static function describeSubjectFeeling($npc, $subject, $relData) {
        $aff = $relData['aff'] ?? 0;

        // Skip neutral
        if ($aff >= -5 && $aff <= 5) {
            return null;
        }

        if ($aff >= 60) {
            $feeling = "strongly values and supports";
        } elseif ($aff >= 30) {
            $feeling = "favors";
        } elseif ($aff >= 6) {
            $feeling = "is somewhat positive toward";
        } elseif ($aff >= -30) {
            $feeling = "dislikes";
        } elseif ($aff >= -60) {
            $feeling = "strongly opposes";
        } else {
            $feeling = "despises and would act against";
        }

        return "- {$npc} {$feeling} \"{$subject}\"";
    }

}
