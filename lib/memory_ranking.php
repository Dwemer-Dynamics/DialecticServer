<?php

function dialecticMemoryCandidateNumber(array $candidate, string $field, float $default): float
{
    $value = $candidate[$field] ?? null;

    return is_numeric($value) ? (float)$value : $default;
}

function dialecticMemoryCandidateMixedDistance(array $candidate): float
{
    if (isset($candidate['mixed_distance']) && is_numeric($candidate['mixed_distance'])) {
        return (float)$candidate['mixed_distance'];
    }

    $distance = dialecticMemoryCandidateNumber($candidate, 'distance', INF);
    $keywordRank = dialecticMemoryCandidateNumber($candidate, 'rank_fts', 0.0);

    return $distance - $keywordRank;
}

// Selects the strongest semantic and keyword match with deterministic recency tie-breakers.
function dialecticSelectBestHybridMemoryCandidate(array $candidates): ?array
{
    $best = null;
    $bestMixedDistance = INF;
    $bestDistance = INF;
    $bestGamets = -INF;
    $bestRowId = -INF;

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $mixedDistance = dialecticMemoryCandidateMixedDistance($candidate);
        $distance = dialecticMemoryCandidateNumber($candidate, 'distance', INF);
        $gamets = dialecticMemoryCandidateNumber($candidate, 'gamets_truncated', -INF);
        $rowId = dialecticMemoryCandidateNumber($candidate, 'rowid', -INF);

        $isBetter = $mixedDistance < $bestMixedDistance
            || ($mixedDistance === $bestMixedDistance && $distance < $bestDistance)
            || ($mixedDistance === $bestMixedDistance && $distance === $bestDistance && $gamets > $bestGamets)
            || (
                $mixedDistance === $bestMixedDistance
                && $distance === $bestDistance
                && $gamets === $bestGamets
                && $rowId > $bestRowId
            );

        if (!$isBetter) {
            continue;
        }

        $best = $candidate;
        $bestMixedDistance = $mixedDistance;
        $bestDistance = $distance;
        $bestGamets = $gamets;
        $bestRowId = $rowId;
    }

    if ($best === null) {
        return null;
    }

    $best['mixed_distance'] = $bestMixedDistance;
    $best['distance'] = $bestDistance;
    $best['rank_any'] = 1.4 - $bestMixedDistance;
    $best['rank_all'] = 1.4 - $bestDistance;

    return $best;
}
