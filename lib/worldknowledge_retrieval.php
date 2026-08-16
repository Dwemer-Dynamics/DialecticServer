<?php

declare(strict_types=1);

/** Deterministically grounds Fallout dialogue in the installed World Knowledge catalog. */
final class DialecticWorldKnowledgeRetriever
{
    public const VERSION = 'oghma-parity-v1';

    private ?array $preparedIndex = null;

    /** @param list<array<string,mixed>> $catalog */
    public function __construct(array $catalog = [])
    {
        if ($catalog !== []) {
            $this->preparedIndex = $this->buildIndex($catalog);
        }
    }

    public static function isEligibleRequest(string $requestType): bool
    {
        return in_array(strtolower(trim($requestType)), [
            'inputtext',
            'inputtext_s',
            'ginputtext',
            'ginputtext_s',
            'rechat',
            'continue',
            'instruction',
            'suggestion',
        ], true);
    }

    public static function isExplicitKnowledgeRequest(string $text): bool
    {
        $normalized = self::normalize($text);
        if (preg_match('/\b(?:do not|dont|never mind|forget)\b.{0,30}\b(?:explain|describe|discuss|tell|teach)\b/u', $normalized)) {
            return false;
        }

        return preg_match(
            '/\b(?:(?:tell|teach|explain|describe|discuss)\s+(?:me\s+|us\s+)?(?:about|of)|'
             . 'what\s+(?:do\s+you\s+know|have\s+you\s+heard)\s+about|'
             . '(?:do|did)\s+you\s+know\s+(?:anything\s+)?about|'
             . '(?:compare|contrast)\s+(?:the\s+)?|'
             . '(?:history|lore|background|story|details|information|facts)\s+(?:about|on|of))\b/u',
            $normalized
        ) === 1;
    }

    /** Limit history carry-over to short, explicitly referential follow-up lines. */
    public static function shouldUsePreviousExchange(string $text): bool
    {
        $normalized = self::normalize($text);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 240) {
            return false;
        }
        if (preg_match('/^(?:ok(?:ay)?|thanks?|thank you|sure|right|fine|good|got it|i see|never mind|nevermind|forget it|lets go|let us go)$/u', $normalized) === 1) {
            return false;
        }
        if (preg_match('/\b(?:tell me more|go on|what else|anything else|what happened next|why is that|how so)\b/u', $normalized) === 1) {
            return true;
        }
        $reference = preg_match('/\b(?:it|its|they|them|their|theirs|he|him|his|she|her|hers|this|that|these|those|there|former|latter)\b/u', $normalized) === 1;
        $cue = preg_match('/\b(?:who|what|where|when|why|how|which|leader|leaders|founder|founders|origin|origins|history|story|purpose|member|members|enemy|enemies|ally|allies|located|happened|mean|means|more|else|dangerous|safe|powerful|important)\b/u', $normalized) === 1;
        return $reference && $cue;
    }

    /**
     * @param list<array<string,mixed>> $catalog
     * @return array{version:string,topics:list<string>,matches:list<array<string,mixed>>,rejected:list<array<string,mixed>>,tag_decisions:list<array<string,mixed>>,fallback_eligible:bool,elapsed_ms:float}
     */
    public function extract(string $text, array $catalog = [], int $limit = 1): array
    {
        $started = hrtime(true);
        $limit = max(1, min(3, $limit));
        $text = trim($text);
        $explicitRequest = self::isExplicitKnowledgeRequest($text);
        if ($text === '' || ($catalog === [] && $this->preparedIndex === null)) {
            return $this->result([], [], [], [], $explicitRequest, $started);
        }

        $index = $this->indexFor($catalog);
        $normalized = self::normalize($text);
        $speakerLabel = $this->speakerLabel($text);
        $speakerLabelEnd = $speakerLabel === '' ? 0 : strlen($speakerLabel);
        $candidates = [];
        $rejected = [];

        foreach ($index['entries'] as $entry) {
            $pattern = '/(?<![a-z0-9])' . preg_quote($entry['phrase'], '/') . '(?![a-z0-9])/u';
            if (preg_match_all($pattern, $normalized, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $start = intval($match[1]);
                if ($speakerLabelEnd > 0 && $start < $speakerLabelEnd) {
                    $rejected[] = $this->rejection($entry, 'speaker_label', $start);
                    continue;
                }
                $source = $entry['canonical'] ? 'exact_canonical' : 'exact_alias';
                $score = $entry['canonical'] ? 0.96 : 0.92;
                $candidates[] = $this->candidate($entry, $source, $start, $start + strlen(strval($match[0])), $score);
            }
        }

        $windows = $this->tokenWindows($normalized, intval($index['maximum_phrase_tokens']));
        foreach ($windows as $window) {
            $owners = $index['by_compact'][$window['compact']] ?? [];
            $topics = array_values(array_unique(array_column($owners, 'topic')));
            if ($owners === [] || count($topics) !== 1) {
                if (count($topics) > 1) {
                    $rejected[] = [
                        'phrase' => $window['phrase'],
                        'reason' => 'ambiguous_compact',
                        'topics' => $topics,
                        'start' => $window['start'],
                    ];
                }
                continue;
            }
            usort($owners, static fn(array $left, array $right): int => intval($right['canonical']) <=> intval($left['canonical']));
            $entry = $owners[0];
            if ($speakerLabelEnd > 0 && $window['start'] < $speakerLabelEnd) {
                continue;
            }
            $candidates[] = $this->candidate(
                $entry,
                $entry['canonical'] ? 'compact_canonical' : 'compact_alias',
                $window['start'],
                $window['end'],
                $entry['canonical'] ? 0.91 : 0.87,
                $window['phrase']
            );
        }

        $candidates = $this->collapseCandidates($candidates);
        if ($this->hasTranscriptCue($text) || $explicitRequest) {
            foreach ($this->phoneticCandidates($windows, $index) as $candidate) {
                if ($speakerLabelEnd > 0 && $candidate['start'] < $speakerLabelEnd) {
                    $rejected[] = $this->rejection($candidate, 'speaker_label', $candidate['start']);
                    continue;
                }
                if ($this->overlapsAny($candidates, $candidate)) {
                    continue;
                }
                $candidates[] = $candidate;
            }
        }

        $byTopic = [];
        foreach ($candidates as $candidate) {
            $topicKey = self::normalize(strval($candidate['topic']));
            $mentionCount = $this->mentionCount($normalized, strval($candidate['entity_phrase']));
            $candidate['mention_count'] = $mentionCount;
            $candidate['context_score'] = floatval($candidate['score'])
                + min(0.24, max(0, $mentionCount - 1) * 0.12)
                + ($explicitRequest ? 0.08 : 0.0);

            if ($this->isRiskySingleWord($candidate) && $mentionCount <= 1 && !$explicitRequest) {
                $rejected[] = $this->rejection($candidate, 'ambiguous_single_word', intval($candidate['start']));
                continue;
            }
            if ($candidate['context_score'] < 0.78) {
                $rejected[] = $this->rejection($candidate, 'below_threshold', intval($candidate['start']), $candidate['context_score']);
                continue;
            }
            if (!isset($byTopic[$topicKey]) || $candidate['context_score'] > $byTopic[$topicKey]['context_score']) {
                $byTopic[$topicKey] = $candidate;
            }
        }

        $selected = $this->applyRelationalTagSupport($normalized, $index, array_values($byTopic));
        usort($selected, static function (array $left, array $right): int {
            $mentions = intval($right['mention_count']) <=> intval($left['mention_count']);
            if ($mentions !== 0) {
                return $mentions;
            }
            $position = intval($left['start']) <=> intval($right['start']);
            return $position !== 0 ? $position : floatval($right['context_score']) <=> floatval($left['context_score']);
        });
        $selected = array_slice($selected, 0, $limit);

        return $this->result(
            $selected,
            $rejected,
            [],
            array_values(array_column($selected, 'topic')),
            $selected === [] && $explicitRequest,
            $started
        );
    }

    /** Resolve connector suggestions only when each suggestion identifies one catalog entity. */
    public function resolveSuggestions(array $suggestions, array $catalog = [], int $limit = 1): array
    {
        $index = $this->indexFor($catalog);
        $resolved = [];
        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion) || trim($suggestion) === '') {
                continue;
            }
            $phrase = self::normalize($suggestion);
            $owners = array_values(array_unique($index['phrase_owners'][$phrase] ?? []));
            if (count($owners) === 1) {
                $topic = $owners[0];
            } else {
                $compactOwners = $index['by_compact'][self::compact($suggestion)] ?? [];
                $topics = array_values(array_unique(array_column($compactOwners, 'topic')));
                if (count($topics) !== 1) {
                    continue;
                }
                $topic = $topics[0];
            }
            if (!in_array($topic, $resolved, true)) {
                $resolved[] = $topic;
            }
            if (count($resolved) >= max(1, min(3, $limit))) {
                break;
            }
        }
        return $resolved;
    }

    public static function normalize(string $value): string
    {
        $value = strtr($value, [
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{00A0}" => ' ',
        ]);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public static function compact(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', self::normalize($value)) ?? '';
    }

    /** @param list<array<string,mixed>> $catalog */
    private function buildIndex(array $catalog): array
    {
        $phrases = [];
        $tags = [];
        foreach ($catalog as $row) {
            if (array_key_exists('is_active', $row) && !$this->boolValue($row['is_active'])) {
                continue;
            }
            $topicParts = $this->values(strval($row['topic'] ?? $row['title'] ?? ''));
            $topic = trim(strval($row['canonical_topic'] ?? ($topicParts[0] ?? '')));
            if ($topic === '') {
                continue;
            }
            $aliases = array_slice($topicParts, 1);
            $aliases = array_merge($aliases, $this->values(strval($row['aliases'] ?? '')));
            $values = [[$topic, true]];
            foreach ($aliases as $alias) {
                $values[] = [$alias, false];
            }
            foreach ($values as [$value, $canonical]) {
                $phrase = self::normalize(strval($value));
                if ($phrase === '') {
                    continue;
                }
                $phrases[$phrase]['owners'][$topic] = true;
                if ($canonical) {
                    $phrases[$phrase]['canonical_owners'][$topic] = true;
                }
                if (!$canonical && preg_match('/^[A-Z0-9][A-Z0-9.-]{1,7}$/', trim(strval($value)))) {
                    $phrases[$phrase]['acronym_owners'][$topic] = true;
                }
                $phrases[$phrase]['categories'][$topic] = strtolower(trim(strval($row['category'] ?? '')));
            }
            foreach ($this->values(strval($row['tags'] ?? '')) as $tag) {
                $phrase = self::normalize($tag);
                if ($phrase !== '') {
                    $tags[$phrase]['owners'][$topic] = true;
                }
            }
        }

        $entries = [];
        $byCompact = [];
        $phoneticByShape = [];
        $phraseOwners = [];
        $maximumPhraseTokens = 1;
        foreach ($phrases as $phrase => $owners) {
            $ownerTopics = array_keys($owners['owners'] ?? []);
            $phraseOwners[$phrase] = $ownerTopics;
            $canonicalOwners = array_keys($owners['canonical_owners'] ?? []);
            if (count($canonicalOwners) === 1) {
                $topic = $canonicalOwners[0];
            } elseif (count($ownerTopics) === 1) {
                $topic = $ownerTopics[0];
            } else {
                continue;
            }
            $entry = [
                'topic' => $topic,
                'phrase' => $phrase,
                'compact' => self::compact($phrase),
                'phonetic' => $this->phoneticKey($phrase),
                'canonical' => in_array($topic, $canonicalOwners, true),
                'acronym' => in_array($topic, array_keys($owners['acronym_owners'] ?? []), true),
                'category' => strval($owners['categories'][$topic] ?? ''),
                'token_count' => count(preg_split('/\s+/u', $phrase) ?: []),
            ];
            $maximumPhraseTokens = max($maximumPhraseTokens, intval($entry['token_count']));
            $entries[] = $entry;
            $byCompact[$entry['compact']][] = $entry;
            $phoneticByShape[$entry['token_count']][strlen($entry['compact'])][] = $entry;
        }

        $relationalTagEntries = [];
        foreach ($tags as $phrase => $owners) {
            $tokenCount = count(preg_split('/\s+/u', $phrase) ?: []);
            if ($tokenCount < 2 || isset($phrases[$phrase])) {
                continue;
            }
            $relationalTagEntries[$phrase] = [
                'phrase' => $phrase,
                'owners' => array_keys($owners['owners'] ?? []),
            ];
        }

        return [
            'entries' => $entries,
            'by_compact' => $byCompact,
            'phonetic_by_shape' => $phoneticByShape,
            'phrase_owners' => $phraseOwners,
            'relational_tag_entries' => $relationalTagEntries,
            'maximum_phrase_tokens' => min(9, $maximumPhraseTokens),
        ];
    }

    private function indexFor(array $catalog): array
    {
        if ($catalog !== []) {
            return $this->buildIndex($catalog);
        }
        return $this->preparedIndex ?? $this->buildIndex([]);
    }

    /** Let ordinary tags strengthen an identified topic without selecting one. */
    private function applyRelationalTagSupport(string $text, array $index, array $entities): array
    {
        if ($entities === [] || ($index['relational_tag_entries'] ?? []) === []) {
            return $entities;
        }
        $normalized = ' ' . self::normalize($text) . ' ';
        foreach ($entities as &$entity) {
            $topic = strval($entity['topic'] ?? '');
            $matched = [];
            foreach ($index['relational_tag_entries'] as $phrase => $entry) {
                if (str_contains($normalized, ' ' . $phrase . ' ')
                    && in_array($topic, $entry['owners'] ?? [], true)) {
                    $matched[] = $phrase;
                }
            }
            if ($matched !== []) {
                $entity['relational_tag_phrases'] = array_values(array_unique($matched));
                $bonus = min(0.08, count($entity['relational_tag_phrases']) * 0.04);
                $entity['score'] = floatval($entity['score'] ?? 0.0) + $bonus;
                $entity['context_score'] = floatval($entity['context_score'] ?? 0.0) + $bonus;
            }
        }
        unset($entity);
        return $entities;
    }

    private function phoneticCandidates(array $windows, array $index): array
    {
        $candidates = [];
        foreach ($windows as $window) {
            $windowCompact = strval($window['compact']);
            $length = strlen($windowCompact);
            if ($length < 5) {
                continue;
            }
            $candidateEntries = [];
            for ($candidateLength = max(1, $length - 2); $candidateLength <= $length + 2; $candidateLength++) {
                array_push(
                    $candidateEntries,
                    ...($index['phonetic_by_shape'][$window['token_count']][$candidateLength] ?? [])
                );
            }
            foreach ($candidateEntries as $entry) {
                $entryCompact = strval($entry['compact']);
                if (abs($length - strlen($entryCompact)) > 2 || $windowCompact === $entryCompact) {
                    continue;
                }
                $distance = $this->transpositionDistance($windowCompact, $entryCompact);
                $maximumDistance = $length >= 10 ? 2 : 1;
                $phonetic = $this->phoneticKey($windowCompact) === strval($entry['phonetic']);
                if ($distance > $maximumDistance || (!$phonetic && $distance > 1)) {
                    continue;
                }
                $score = 0.84 - ($distance * 0.04) + ($entry['canonical'] ? 0.03 : 0.0);
                $candidate = $this->candidate($entry, $entry['canonical'] ? 'phonetic_canonical' : 'phonetic_alias', $window['start'], $window['end'], $score, $window['phrase']);
                $candidate['literal_distance'] = $distance;
                $candidates[] = $candidate;
            }
        }
        usort($candidates, static fn(array $left, array $right): int => intval($left['literal_distance']) <=> intval($right['literal_distance'])
            ?: floatval($right['score']) <=> floatval($left['score']));
        return $this->collapseCandidates($candidates);
    }

    private function tokenWindows(string $text, int $maximumTokens): array
    {
        preg_match_all('/[a-z0-9]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0] ?? [];
        $windows = [];
        for ($start = 0, $count = count($tokens); $start < $count; $start++) {
            for ($length = 1; $length <= $maximumTokens && $start + $length <= $count; $length++) {
                $slice = array_slice($tokens, $start, $length);
                $phrase = implode(' ', array_column($slice, 0));
                $last = $slice[$length - 1];
                $windows[] = [
                    'phrase' => $phrase,
                    'compact' => self::compact($phrase),
                    'start' => intval($slice[0][1]),
                    'end' => intval($last[1]) + strlen(strval($last[0])),
                    'token_count' => $length,
                ];
            }
        }
        return $windows;
    }

    private function collapseCandidates(array $candidates): array
    {
        $collapsed = [];
        foreach ($candidates as $candidate) {
            $key = self::normalize(strval($candidate['topic'])) . ':' . intval($candidate['start']);
            if (!isset($collapsed[$key]) || floatval($candidate['score']) > floatval($collapsed[$key]['score'])) {
                $collapsed[$key] = $candidate;
            }
        }
        return array_values($collapsed);
    }

    private function candidate(array $entry, string $source, int $start, int $end, float $score, ?string $phrase = null): array
    {
        return [
            'topic' => strval($entry['topic']),
            'phrase' => $phrase ?? strval($entry['phrase']),
            'entity_phrase' => strval($entry['phrase']),
            'source' => $source,
            'category' => strval($entry['category'] ?? ''),
            'acronym' => boolval($entry['acronym'] ?? false),
            'start' => $start,
            'end' => $end,
            'score' => $score,
        ];
    }

    private function rejection(array $candidate, string $reason, int $start, ?float $score = null): array
    {
        $result = [
            'topic' => strval($candidate['topic'] ?? ''),
            'phrase' => strval($candidate['phrase'] ?? ''),
            'reason' => $reason,
            'start' => $start,
        ];
        if ($score !== null) {
            $result['score'] = $score;
        }
        return $result;
    }

    private function result(array $matches, array $rejected, array $tagDecisions, array $topics, bool $fallbackEligible, int $started): array
    {
        return [
            'version' => self::VERSION,
            'topics' => array_values($topics),
            'matches' => array_values($matches),
            'rejected' => array_values($rejected),
            'tag_decisions' => array_values($tagDecisions),
            'fallback_eligible' => $fallbackEligible,
            'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        ];
    }

    private function values(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $value) ?: []), static fn(string $item): bool => $item !== ''));
    }

    private function speakerLabel(string $text): string
    {
        if (!preg_match('/^\s*([^:\r\n]{1,60}):\s+/u', $text, $match)) {
            return '';
        }
        $label = self::normalize(strval($match[1]));
        $tokens = preg_split('/\s+/u', $label) ?: [];
        if ($label === '' || count($tokens) > 4 || preg_match('/^(?:i|we|they|he|she|you|it|there)\b/u', $label)) {
            return '';
        }
        return $label;
    }

    private function hasTranscriptCue(string $text): bool
    {
        return preg_match(
            '/\b(?:speech\s+to\s+text|voice\s+recognition|transcript|transcription|transcribed|'
            . 'bad\s+recording|through\s+the\s+static|may\s+have\s+said|might\s+have\s+said|sounded\s+like)\b/u',
            self::normalize($text)
        ) === 1;
    }

    private function isRiskySingleWord(array $candidate): bool
    {
        $phrase = strval($candidate['entity_phrase'] ?? '');
        if (str_contains($phrase, ' ') || boolval($candidate['acronym'] ?? false)) {
            return false;
        }
        return strlen($phrase) < 5 || in_array($phrase, [
            'boomers', 'fiends', 'followers', 'house', 'kings', 'legion', 'strip', 'vault',
            'powder', 'rangers', 'family', 'republic', 'capital', 'courier', 'doctor',
        ], true);
    }

    private function mentionCount(string $text, string $phrase): int
    {
        return preg_match_all('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/u', $text) ?: 1;
    }

    private function overlapsAny(array $candidates, array $candidate): bool
    {
        foreach ($candidates as $existing) {
            if (intval($candidate['start']) < intval($existing['end']) && intval($existing['start']) < intval($candidate['end'])) {
                return true;
            }
        }
        return false;
    }

    private function phoneticKey(string $value): string
    {
        $value = self::compact($value);
        $value = strtr($value, ['ph' => 'f', 'ck' => 'k', 'qu' => 'kw', 'th' => 't', 'ee' => 'i', 'ea' => 'i', 'y' => 'i']);
        return preg_replace('/(.)\1+/', '$1', $value) ?? $value;
    }

    private function transpositionDistance(string $left, string $right): int
    {
        $distance = levenshtein($left, $right);
        if ($distance !== 2 || strlen($left) !== strlen($right)) {
            return $distance;
        }
        $mismatches = [];
        for ($index = 0; $index < strlen($left); $index++) {
            if ($left[$index] !== $right[$index]) {
                $mismatches[] = $index;
            }
        }
        if (count($mismatches) === 2
            && $mismatches[1] === $mismatches[0] + 1
            && $left[$mismatches[0]] === $right[$mismatches[1]]
            && $left[$mismatches[1]] === $right[$mismatches[0]]) {
            return 1;
        }
        return $distance;
    }

    private function boolValue(mixed $value): bool
    {
        return !in_array($value, [false, 0, '0', 'false', 'off', 'no', null], true);
    }
}
