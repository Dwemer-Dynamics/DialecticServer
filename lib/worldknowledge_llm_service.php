<?php

/**
 * LLM-based topic extraction for Japanese language support
 * This replaces the T5-based minimeTopic function for multilingual support
 */

function LLMTopic($text, $language = 'ja') {
    $startTime = microtime(true);
    
    // Check if we have a valid WorldKnowledge connector configured
    if (!isset($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"]) || empty($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"])) {
        error_log("[WORLDKNOWLEDGE LLM] CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM not configured");
        return false;
    }
    
    // Check cache first
    $cacheKey = md5("topic_extraction_" . $text);
    if (isset($GLOBALS["MINIME_TOPIC_CACHE"][$cacheKey])) {
        error_log("[WORLDKNOWLEDGE LLM] Using cached topic for: " . substr($text, 0, 50));
        return $GLOBALS["MINIME_TOPIC_CACHE"][$cacheKey];
    }
    
    try {
        // Get the WorldKnowledge-specific connector
        $connector = new LLMConnector();
        $connectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"]);
        
        if (!$connectorData) {
            error_log("[WORLDKNOWLEDGE LLM] Failed to load connector ID: " . $GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"]);
            return false;
        }
        
        $connectionHandler = $connector->getConnector($connectorData);
        
        // Load prompt from the prompt manager database entry.
        $systemPrompt = null;

        try {
            global $db;
            $promptData = $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'custom_worldknowledge'");
            if ($promptData) {
                $systemPrompt = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
                error_log("[WORLDKNOWLEDGE LLM] Loaded prompt from database (custom: " . (!empty($promptData['custom_prompt']) ? 'yes' : 'no') . ")");
            }
        } catch (Exception $e) {
            error_log("[WORLDKNOWLEDGE LLM] Failed to load prompt from database: " . $e->getMessage());
        }
        
        // Final fallback to default prompt if nothing was loaded (matches database default)
        if (empty($systemPrompt)) {
            $systemPrompt = <<<PROMPT
You are an expert at extracting important topics from text.
Follow these rules strictly:

1. Extract only ONE most important topic (person, place, item, concept, etc.) from the text
2. Ensure the output is in the **singular form** (e.g., stimpaks -> stimpak, settlements -> settlement)
3. Return ONLY the word or phrase (no explanations, no extra text)
4. If multiple candidates exist, choose the most important one
5. Keep the topic in the same language as the input text

Examples:
Input: 'I heard about the NCR'
Output: NCR

Input: 'Going to Freeside today'
Output: Freeside

Input: 'Met with the Followers of the Apocalypse'
Output: Followers of the Apocalypse

Input: 'Used chems in combat'
Output: chem
PROMPT;
            error_log("[WORLDKNOWLEDGE LLM] Using hardcoded fallback prompt (database unavailable)");
        }
        
        $userPrompt = "Text: " . $text;
        
        // Prepare context for the connector
        $contextData = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];
        
        // Custom parameters for fast, simple extraction
        $customParms = [
            'max_tokens' => 50,  // Short response
            'temperature' => 0.3, // Low temperature for consistency
            'stream' => false     // No streaming needed
        ];
        
        // Call the LLM using the fast method
        $response = callLLMFast($contextData, $customParms);
        
        if (!$response) {
            error_log("[WORLDKNOWLEDGE LLM] Failed to get response from LLM");
            return false;
        }
        
        // Extract the topic from response
        $topic = trim($response);
        
        // Clean up the response (remove quotes, extra whitespace, etc.)
        $topic = preg_replace('/^["\']|["\']$/', '', $topic);
        $topic = preg_replace('/\s+/', ' ', $topic);
        $topic = trim($topic);
        
        // Validate the topic (should be a simple word or phrase)
        if (empty($topic) || strlen($topic) > 100) {
            error_log("[WORLDKNOWLEDGE LLM] Invalid topic extracted: " . $topic);
            return false;
        }
        
        $elapsedTime = microtime(true) - $startTime;
        
        $result = json_encode([
            'generated_tags' => $topic,
            'elapsed_time' => $elapsedTime
        ]);
        
        // Cache the result
        $GLOBALS["MINIME_TOPIC_CACHE"][$cacheKey] = $result;
        
        error_log("[WORLDKNOWLEDGE LLM] Extracted topic: '$topic' in {$elapsedTime}s from: " . substr($text, 0, 50));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[WORLDKNOWLEDGE LLM] Exception during topic extraction: " . $e->getMessage());
        return false;
    }
}

/**
 * Fast LLM call for simple tasks like topic extraction
 * Uses the WorldKnowledge-specific connector with minimal overhead
 */
function callLLMFast($contextData, $customParms = []) {
    if (!isset($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"]) || empty($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"])) {
        error_log("[WORLDKNOWLEDGE LLM] CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM not configured in callLLMFast");
        return false;
    }
    
    $connector = new LLMConnector();
    $connectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM"]);
    
    if (!$connectorData) {
        error_log("[WORLDKNOWLEDGE LLM] Failed to load connector in callLLMFast");
        return false;
    }
    
    // Build the request
    $url = $connectorData["url"];
    $model = $connectorData["model"];
    $apiKeyId = $connectorData["api_badge_id"];
    
    // Get API key
    $apiBadge = new ApiBadge();
    $apiKeyData = $apiBadge->getById($apiKeyId);
    $apiKey = $apiKeyData["api_key"];
    
    // Prepare request data
    $data = [
        'model' => $model,
        'messages' => $contextData,
        'max_tokens' => $customParms['max_tokens'] ?? 50,
        'temperature' => $customParms['temperature'] ?? 0.3,
        'stream' => false
    ];
    
    // Prepare headers
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: https://dwemerdynamics.com/',
        'X-Title: Dwemer Dynamics - WorldKnowledge Topic Extraction'
    ];
    
    // Add provider-specific headers if needed
    if (isset($connectorData["provider"]) && !empty($connectorData["provider"])) {
        $headers[] = 'X-Provider: ' . $connectorData["provider"];
    }
    
    // Make the request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $timeoutMs = max(250, min(3000, intval($GLOBALS['WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS'] ?? 1500)));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("[WORLDKNOWLEDGE LLM] HTTP error $httpCode: $response");
        return false;
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        error_log("[WORLDKNOWLEDGE LLM] Invalid response format: " . substr($response, 0, 200));
        return false;
    }
    
    return $responseData['choices'][0]['message']['content'];
}

?>
