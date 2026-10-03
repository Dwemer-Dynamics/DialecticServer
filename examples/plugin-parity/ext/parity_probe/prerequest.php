<?php

// Observes the Dialectic client's ParityProbe events on the existing event route:
//   funcret      dialectic.action_result.v1 {action, target, result, status, bridge, request_id}
//   pluginevent  dialectic.plugin_event.v1 {bridge, name, data, actor, actor_refid}
if (!function_exists('parityProbeObserveEvent')) {
    function parityProbeObserveEvent($request): ?array
    {
        $data = is_array($request) ? ($request[3] ?? null) : null;
        if (!is_string($data) || strlen($data) > 8192) {
            return null;
        }
        $payload = json_decode($data, true);
        if (!is_array($payload)) {
            return null;
        }
        $schema = strval($payload['schema'] ?? '');
        if (($request[0] ?? '') === 'funcret' && $schema === 'dialectic.action_result.v1'
            && ($payload['action'] ?? '') === 'ExtCmdParityProbe_Ping') {
            return [
                'kind' => 'completion',
                'argument' => trim(strval($payload['target'] ?? '')),
                'result' => trim(strval($payload['status'] ?? '') . ' ' . strval($payload['result'] ?? '')),
            ];
        }
        if (($request[0] ?? '') === 'pluginevent' && $schema === 'dialectic.plugin_event.v1'
            && strcasecmp(strval($payload['bridge'] ?? ''), 'ParityProbe') === 0) {
            return [
                'kind' => 'event',
                'argument' => trim(strval($payload['name'] ?? '')),
                'result' => is_scalar($payload['data'] ?? null) ? strval($payload['data']) : '',
            ];
        }
        return null;
    }
}

$parityProbeEvent = parityProbeObserveEvent($gameRequest ?? null);
if ($parityProbeEvent !== null) {
    $GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe:' . $parityProbeEvent['kind'] . ':' . $parityProbeEvent['argument'];
    error_log('[parity_probe] ' . $parityProbeEvent['kind'] . ': ' . substr($parityProbeEvent['result'], 0, 200));
}
