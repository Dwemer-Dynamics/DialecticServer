<?php
/**
 * RELATIONSHIP SYSTEM - Context Injection
 *
 * This file is automatically loaded by main.php at line 1792:
 *   requireFilesRecursively(__DIR__."/ext/","context.php");
 *
 * It injects relationship context into the AI prompt.
 *
 * Relationship evaluations are queued after completed responses and handled by
 * the background worker. Prompt construction only reads persisted state.
 *
 * TWO MODES:
 * 1. RELLLM_CONNECTOR set: Token-efficient mode
 *    - Only injects tier labels (Fond, Wary, etc.)
 *    - NO #REL: command instructions (RelationshipLLM handles scoring)
 *
 * 2. RELLLM_CONNECTOR not set: Full mode
 *    - Injects numbers and tiers
 *    - Adds #REL: command instructions for conversation model
 */

$relationshipContextStartTime = $GLOBALS["startTime"] ?? microtime(true);
error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $relationshipContextStartTime));

require_once $GLOBALS["ENGINE_PATH"] . "lib/relationship_runtime.php";

// Master toggle - if disabled, skip everything in this file
if (!dialecticRelationshipSettingEnabled()) {
    return;
}

require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";

if (!function_exists('_relSplitPeopleList')) {
    function _relSplitPeopleList($people): array {
        $raw = trim(strval($people));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[|,]+/', $raw);
        $names = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name !== '' && strcasecmp($name, 'The Narrator') !== 0) {
                $names[strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }
}

// Get the current NPC name
$npcName = $GLOBALS["DIALECTIC_NAME"] ?? null;

Logger::info("[REL-CONTEXT] npcName=" . ($npcName ?? 'NULL') . ", CACHE_PEOPLE=" . substr($GLOBALS["CACHE_PEOPLE"] ?? 'NULL', 0, 100));

if ($npcName && strcasecmp(trim((string)$npcName), 'The Narrator') !== 0) {
    $knownRels = RelationshipManager::getRelationships($npcName);

    // Parse nearby NPCs from CACHE_PEOPLE
    $nearbyNpcs = [];
    if (!empty($GLOBALS["CACHE_PEOPLE"])) {
        // Dialectic usually stores nearby people as |Name|Name|, but older paths may use commas.
        $nearbyNpcs = _relSplitPeopleList($GLOBALS["CACHE_PEOPLE"]);
    }

    // Also include NPCs mentioned in recent dialogue
    // This ensures relationships are shown for NPCs being discussed, not just physically present
    $mentionedNpcs = [];
    if (!empty($GLOBALS["DIALECTIC_CONTEXT"])) {
        $knownNames = array_keys($knownRels);

        // Scan recent context for mentions of known NPCs
        $contextLower = strtolower($GLOBALS["DIALECTIC_CONTEXT"]);
        foreach ($knownNames as $knownNpc) {
            if ($knownNpc === 'Player') continue; // Player always included
            if (stripos($contextLower, strtolower($knownNpc)) !== false) {
                $mentionedNpcs[] = $knownNpc;
            }
        }
    }

    // Merge nearby + mentioned, remove duplicates
    $relevantNpcs = array_values(array_filter(
        array_unique(array_merge($nearbyNpcs, $mentionedNpcs)),
        static function ($name) use ($npcName) {
            $name = trim((string)$name);
            return $name !== ''
                && strcasecmp($name, (string)$npcName) !== 0
                && strcasecmp($name, 'The Narrator') !== 0;
        }
    ));

    // Build the relationship context block
    // This automatically uses tier-only mode if RELLLM_CONNECTOR is set
    $relationshipContext = RelationshipManager::buildContextFromRelationships(
        $npcName,
        $knownRels,
        $relevantNpcs
    );

    Logger::debug("[REL-CONTEXT] buildContext returned " . strlen($relationshipContext) . " chars for " . $npcName);

    // Inject into the character section of the prompt
    // We append to DIALECTIC_PERS which gets included in the <character> block
    if (!empty($relationshipContext)) {
        $GLOBALS["DIALECTIC_PERS"] .= "\n\n" . $relationshipContext;
        Logger::debug("[REL-CONTEXT] Injected " . strlen($relationshipContext) . " chars for {$npcName}");
    } else {
        Logger::warn("[REL-CONTEXT] No context to inject for {$npcName}");
    }

    // Only add #REL: command instructions if NOT using dedicated RelationshipLLM
    // When RELLLM_CONNECTOR is set, the relationship model handles all scoring
    // and the conversation model doesn't need to embed commands
    $useRelLLM = dialecticRelationshipUsesDedicatedConnector();

    if (!$useRelLLM) {
        // Add the relationship system instructions to COMMAND_PROMPT
        // This teaches the AI how to use #REL: and #TYPE: commands
        $relationshipInstructions = RelationshipManager::getSystemPromptAddition();
        if (!empty($GLOBALS["COMMAND_PROMPT"])) {
            $GLOBALS["COMMAND_PROMPT"] .= "\n\n" . $relationshipInstructions;
        }
    }
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $GLOBALS["startTime"]));

?>
