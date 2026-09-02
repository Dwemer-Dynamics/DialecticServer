<?php

/**
 * Shared "Voice Filter" preset control used by the NPC, Narrator, and Player editors.
 *
 * The preset catalog is owned by the server (lib/core/tts_filter_presets.php) and is read through
 * dialecticTtsFilterPresetOptions(true). Nothing here defines FFmpeg values or preset labels, so the
 * three editors always agree with the engine. When the catalog cannot be loaded the control renders
 * read-only and callers must leave the stored value untouched (see dialecticVoiceFilterCatalogAvailable).
 */

if (!function_exists('dialecticVoiceFilterPresetChoices')) {
    /**
     * @return array<int, array{value:string,label:string,description:string}> Empty when the catalog is unavailable.
     */
    function dialecticVoiceFilterPresetChoices(): array
    {
        static $choices = null;
        if (is_array($choices)) {
            return $choices;
        }

        if (!function_exists('dialecticTtsFilterPresetOptions')) {
            $catalogFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR
                . 'core' . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';
            if (is_file($catalogFile)) {
                require_once($catalogFile);
            }
        }
        if (!function_exists('dialecticTtsFilterPresetOptions')) {
            return $choices = [];
        }

        try {
            $catalog = dialecticTtsFilterPresetOptions(true);
        } catch (Throwable $error) {
            return $choices = [];
        }
        if (!is_array($catalog)) {
            return $choices = [];
        }

        // Accept the shapes a server-owned catalog realistically returns so the UI never restates the
        // preset list: value=>label, value=>[label, description], or a list of rows.
        $normalized = [];
        foreach ($catalog as $key => $entry) {
            $value = is_string($key) ? $key : '';
            $label = '';
            $description = '';

            if (is_array($entry)) {
                foreach (['value', 'id', 'key', 'preset'] as $valueKey) {
                    if (isset($entry[$valueKey]) && is_scalar($entry[$valueKey])) {
                        $value = trim((string)$entry[$valueKey]);
                        break;
                    }
                }
                foreach (['label', 'name', 'title'] as $labelKey) {
                    if (isset($entry[$labelKey]) && is_scalar($entry[$labelKey])) {
                        $label = trim((string)$entry[$labelKey]);
                        break;
                    }
                }
                foreach (['description', 'hint', 'summary'] as $descriptionKey) {
                    if (isset($entry[$descriptionKey]) && is_scalar($entry[$descriptionKey])) {
                        $description = trim((string)$entry[$descriptionKey]);
                        break;
                    }
                }
            } elseif (is_scalar($entry)) {
                if (is_string($key)) {
                    $label = trim((string)$entry);
                } else {
                    $value = trim((string)$entry);
                }
            }

            if ($label === '' && $value !== '') {
                $label = ucwords(str_replace(['_', '-'], ' ', $value));
            }
            if ($label === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'label' => $label,
                'description' => $description,
            ];
        }

        return $choices = $normalized;
    }
}

if (!function_exists('dialecticVoiceFilterCatalogAvailable')) {
    function dialecticVoiceFilterCatalogAvailable(): bool
    {
        return count(dialecticVoiceFilterPresetChoices()) > 0;
    }
}

if (!function_exists('dialecticVoiceFilterNoneValue')) {
    /** The catalog entry meaning "no filter". Falls back to the first entry. */
    function dialecticVoiceFilterNoneValue(): string
    {
        $choices = dialecticVoiceFilterPresetChoices();
        foreach ($choices as $choice) {
            if ($choice['value'] === '' || strtolower($choice['value']) === 'none') {
                return $choice['value'];
            }
        }
        return isset($choices[0]) ? $choices[0]['value'] : '';
    }
}

if (!function_exists('dialecticVoiceFilterNormalizePreset')) {
    /** Returns the catalog value matching $value, or the "none" value when it is unknown. */
    function dialecticVoiceFilterNormalizePreset($value): string
    {
        $candidate = is_scalar($value) ? trim((string)$value) : '';
        foreach (dialecticVoiceFilterPresetChoices() as $choice) {
            if ($choice['value'] === $candidate) {
                return $choice['value'];
            }
        }
        return dialecticVoiceFilterNoneValue();
    }
}

if (!function_exists('dialecticVoiceFilterIsNone')) {
    function dialecticVoiceFilterIsNone($value): bool
    {
        $candidate = is_scalar($value) ? trim((string)$value) : '';
        return $candidate === '' || $candidate === dialecticVoiceFilterNoneValue();
    }
}

if (!function_exists('dialecticVoiceFilterPreviewUrl')) {
    function dialecticVoiceFilterPreviewUrl(): string
    {
        $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
        $uiPosition = strpos($scriptPath, '/ui/');
        $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
        if ($webRoot === '/') {
            $webRoot = '';
        }
        return rtrim($webRoot, '/') . '/ui/api/voice_filter_preview.php';
    }
}

if (!function_exists('dialecticVoiceFilterAssetTags')) {
    /** Stylesheet + preview client tags. Safe to emit more than once per page. */
    function dialecticVoiceFilterAssetTags(string $webRoot): string
    {
        $uiDir = dirname(__DIR__, 2);
        $cssFile = $uiDir . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'voice-filter.css';
        $jsFile = $uiDir . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'voice_filter_preview.js';
        $cssVersion = @filemtime($cssFile) ?: '1';
        $jsVersion = @filemtime($jsFile) ?: '1';
        $base = rtrim($webRoot, '/');

        return '<link rel="stylesheet" href="' . htmlspecialchars($base . '/ui/css/voice-filter.css?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
            . '<script src="' . htmlspecialchars($base . '/ui/js/voice_filter_preview.js?v=' . $jsVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }
}

if (!function_exists('dialecticMergePostedVoiceFilterIntoMetadata')) {
    /**
     * Fold the posted Voice Filter selection into the NPC metadata JSON without disturbing any other
     * key. tts_filter_preset is the only key this control owns; picking the "none" preset removes it.
     *
     * @param array      $post   Request payload, mutated in place ($_POST from the NPC editor).
     * @param object     $npc    NpcMaster instance, used only to read metadata that was not posted.
     * @param int        $npcId  NPC id, or 0 when creating.
     */
    function dialecticMergePostedVoiceFilterIntoMetadata(array &$post, $npc, int $npcId): void
    {
        if (!array_key_exists('tts_filter_preset', $post)) {
            return;
        }
        $postedPreset = $post['tts_filter_preset'];
        unset($post['tts_filter_preset']);
        if (!dialecticVoiceFilterCatalogAvailable()) {
            return; // Catalog unavailable: never rewrite stored metadata from a control we could not render.
        }
        $preset = dialecticVoiceFilterNormalizePreset($postedPreset);

        $rawMetadata = null;
        if (array_key_exists('metadata', $post)) {
            $rawMetadata = $post['metadata'];
        } elseif ($npcId > 0 && is_object($npc) && method_exists($npc, 'getById')) {
            try {
                $existing = $npc->getById($npcId);
                $rawMetadata = is_array($existing) ? ($existing['metadata'] ?? null) : null;
            } catch (Throwable $error) {
                return;
            }
        }

        $metadata = [];
        if (is_array($rawMetadata)) {
            $metadata = $rawMetadata;
        } elseif (is_string($rawMetadata) && trim($rawMetadata) !== '') {
            $decoded = json_decode($rawMetadata, true);
            if (!is_array($decoded)) {
                return; // Malformed metadata: leave it exactly as posted rather than replacing it with a stub.
            }
            $metadata = $decoded;
        }

        if (dialecticVoiceFilterIsNone($preset)) {
            if (!array_key_exists('tts_filter_preset', $metadata)) {
                return;
            }
            unset($metadata['tts_filter_preset']);
        } else {
            $metadata['tts_filter_preset'] = $preset;
        }

        if (count($metadata) === 0) {
            $post['metadata'] = '{}';
            return;
        }
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $post['metadata'] = $encoded;
        }
    }
}

if (!function_exists('dialecticRenderVoiceFilterField')) {
    /**
     * Render the Voice Filter select plus its compact sample button.
     *
     * @param array $options {
     *   @var string $id                Element id for the select (also seeds the status id).
     *   @var string $name              POST field name. Defaults to tts_filter_preset.
     *   @var string $value             Currently stored preset value.
     *   @var string $hint              Help text rendered under the control.
     *   @var string $hint_tag          small|span - matches the surrounding editor's markup.
     *   @var bool   $wrap_form_item    Wrap in <div class="form-item"> (NPC editor grid).
     *   @var string $scope             npc|narrator|player - tells the preview endpoint what to render.
     *   @var string $voice_source      CSS selector for the unsaved voice id input.
     *   @var string $profile_source    CSS selector for the unsaved profile select (npc/narrator).
     *   @var string $connector_source  CSS selector for the unsaved TTS connector select (player).
     *   @var string $npc_id            Optional NPC id passed through to the preview endpoint.
     * }
     */
    function dialecticRenderVoiceFilterField(array $options): string
    {
        $id = trim(strval($options['id'] ?? 'tts_filter_preset'));
        if ($id === '') {
            $id = 'tts_filter_preset';
        }
        $name = trim(strval($options['name'] ?? 'tts_filter_preset'));
        if ($name === '') {
            $name = 'tts_filter_preset';
        }
        $hint = strval($options['hint'] ?? 'Fixed FFmpeg voice filter applied after TTS generation.');
        $hintTag = (strval($options['hint_tag'] ?? 'span') === 'small') ? 'small' : 'span';
        $wrapFormItem = !empty($options['wrap_form_item']);
        $choices = dialecticVoiceFilterPresetChoices();
        $selected = dialecticVoiceFilterNormalizePreset($options['value'] ?? '');
        $statusId = $id . '_status';

        $escape = static function ($text): string {
            return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
        };

        $html = $wrapFormItem ? '<div class="form-item">' : '';
        $html .= '<div class="voice-filter-field" data-voice-filter'
            . ' data-voice-filter-url="' . $escape(dialecticVoiceFilterPreviewUrl()) . '"'
            . ' data-voice-filter-scope="' . $escape($options['scope'] ?? '') . '"'
            . ' data-voice-filter-voice-source="' . $escape($options['voice_source'] ?? '') . '"'
            . ' data-voice-filter-profile-source="' . $escape($options['profile_source'] ?? '') . '"'
            . ' data-voice-filter-connector-source="' . $escape($options['connector_source'] ?? '') . '"'
            . ' data-voice-filter-npc-id="' . $escape($options['npc_id'] ?? '') . '">';
        $html .= '<label for="' . $escape($id) . '">Voice Filter</label>';
        $html .= '<div class="voice-filter-row">';

        if (count($choices) === 0) {
            // Catalog unavailable: keep the stored value intact instead of silently clearing it on save.
            $html .= '<select id="' . $escape($id) . '" class="voice-filter-select" disabled'
                . ' aria-describedby="' . $escape($statusId) . '"><option>Unavailable</option></select>';
            $html .= '<input type="hidden" name="' . $escape($name) . '" value="' . $escape($options['value'] ?? '') . '">';
        } else {
            $html .= '<select id="' . $escape($id) . '" name="' . $escape($name) . '"'
                . ' class="voice-filter-select" data-voice-filter-select'
                . ' aria-describedby="' . $escape($statusId) . '">';
            foreach ($choices as $choice) {
                $html .= '<option value="' . $escape($choice['value']) . '"'
                    . ($choice['value'] === $selected ? ' selected' : '')
                    . ($choice['description'] !== '' ? ' title="' . $escape($choice['description']) . '"' : '')
                    . '>' . $escape($choice['label']) . '</option>';
            }
            $html .= '</select>';
        }

        $html .= '<button type="button" class="voice-filter-play" data-voice-filter-play'
            . ' aria-describedby="' . $escape($statusId) . '"'
            . ' title="Play a short sample with the selected voice filter">'
            . '<span class="voice-filter-play-icon" aria-hidden="true"></span>'
            . '<span class="voice-filter-sr-only">Play voice filter sample</span>'
            . '</button>';
        $html .= '</div>';
        $html .= '<p class="voice-filter-status" id="' . $escape($statusId) . '" role="status" aria-live="polite"'
            . ' data-voice-filter-status>'
            . (count($choices) === 0 ? 'Voice filter presets are unavailable on this server.' : '')
            . '</p>';
        $html .= '<audio class="voice-filter-audio" data-voice-filter-audio controls preload="none" hidden'
            . ' aria-label="Voice filter sample"></audio>';
        $html .= '</div>';
        $html .= '<' . $hintTag . ' class="hint">' . $escape($hint) . '</' . $hintTag . '>';
        $html .= $wrapFormItem ? '</div>' : '';

        return $html;
    }
}
