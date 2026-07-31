<?php

function dialecticRolemasterBoredActorKey(string $actorName): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($actorName, 'UTF-8')
        : strtolower($actorName);
}

// Normalize the plugin's spatial actor snapshot into a canonical lookup map.
function dialecticRolemasterBoredActorMap(array $actors, string $playerName = '', string $seedActor = ''): array
{
    $actorMap = [];
    foreach ($actors as $actor) {
        $actorName = is_array($actor) ? ($actor['name'] ?? '') : $actor;
        $actorName = trim((string)$actorName);
        if ($actorName === '' || ($playerName !== '' && strcasecmp($actorName, $playerName) === 0)) {
            continue;
        }
        $actorMap[dialecticRolemasterBoredActorKey($actorName)] = $actorName;
    }

    $seedActor = trim($seedActor);
    if ($seedActor !== '' && ($playerName === '' || strcasecmp($seedActor, $playerName) !== 0)) {
        $actorMap[dialecticRolemasterBoredActorKey($seedActor)] = $seedActor;
    }

    return $actorMap;
}

function dialecticRolemasterBoredCanonicalActor(string $actorName, array $actorMap): ?string
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return null;
    }

    return $actorMap[dialecticRolemasterBoredActorKey($actorName)] ?? null;
}

function dialecticRolemasterFilterBoredInstructions(array $instructions, array $actorMap, string $seedActor): array
{
    $valid = [];
    $seedInstruction = null;

    foreach ($instructions as $instruction) {
        if (!is_array($instruction)) {
            continue;
        }

        $canonicalActor = dialecticRolemasterBoredCanonicalActor(
            (string)($instruction['character'] ?? ''),
            $actorMap
        );
        if ($canonicalActor === null) {
            continue;
        }

        $instruction['character'] = $canonicalActor;
        if ($seedActor !== '' && strcasecmp($canonicalActor, $seedActor) === 0) {
            $seedInstruction = $instruction;
        } else {
            $valid[] = $instruction;
        }
    }

    if ($seedActor !== '' && $seedInstruction === null) {
        return [];
    }

    if ($seedInstruction !== null) {
        array_unshift($valid, $seedInstruction);
    }

    return $valid;
}

function dialecticRolemasterBoredListenerRequirement(string $target, array $actorMap): string
{
    $canonicalTarget = dialecticRolemasterBoredCanonicalActor($target, $actorMap);
    return $canonicalTarget === null ? '' : " The dialogue listener must be {$canonicalTarget}.";
}
