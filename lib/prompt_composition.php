<?php

function dialecticPromptCompositionCharacterCount($value): int
{
    if (is_string($value) || is_numeric($value)) {
        $text = strval($value);
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    if (!is_array($value)) {
        return 0;
    }

    $characters = 0;
    foreach ($value as $item) {
        if (is_array($item) && array_key_exists('content', $item)) {
            $characters += dialecticPromptCompositionCharacterCount($item['content']);
            continue;
        }

        $characters += dialecticPromptCompositionCharacterCount($item);
    }

    return $characters;
}

function dialecticPromptCompositionMeasure($value): array
{
    $characters = dialecticPromptCompositionCharacterCount($value);

    return [
        'characters' => $characters,
        'estimated_tokens' => $characters > 0 ? intval(ceil($characters / 4)) : 0,
    ];
}

function dialecticBuildPromptCompositionReport(string $requestType, array $sections, array $messages): array
{
    $measuredSections = [];
    foreach ($sections as $name => $value) {
        $measuredSections[strval($name)] = dialecticPromptCompositionMeasure($value);
    }

    $messageMeasurement = dialecticPromptCompositionMeasure($messages);

    return [
        'request_type' => $requestType,
        'message_count' => count($messages),
        'total_characters' => $messageMeasurement['characters'],
        'estimated_total_tokens' => $messageMeasurement['estimated_tokens'],
        'sections' => $measuredSections,
    ];
}

// Emit a compact section-level report without logging prompt contents.
function dialecticLogPromptComposition(string $requestType, array $sections, array $messages): array
{
    $report = dialecticBuildPromptCompositionReport($requestType, $sections, $messages);
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
        Logger::debug('[PROMPT-COMPOSITION] ' . $json);
    }

    return $report;
}
