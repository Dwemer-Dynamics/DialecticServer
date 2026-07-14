<?php
if (!function_exists('dialecticActionShouldEmitDebugNotification')) {
	function dialecticActionShouldEmitDebugNotification($functionCodeName)
	{
		if (!function_exists('dialecticGetActionCatalogRow')) {
			return false;
		}

		$row = dialecticGetActionCatalogRow($functionCodeName);
		if (!is_array($row)) {
			return false;
		}

		$metadata = $row['metadata'] ?? [];
		if (!is_array($metadata)) {
			return false;
		}

		return !empty($metadata['debug_notification']) || !empty($metadata['top_left_notification']);
	}
}

if (!function_exists('dialecticSanitizeDebugNotificationText')) {
	function dialecticSanitizeDebugNotificationText($text)
	{
		$text = str_replace(["\r", "\n"], ' ', strval($text));
		$text = str_replace('@', ' at ', $text);
		$text = str_replace('|', '/', $text);
		$text = preg_replace('/\s+/', ' ', $text);
		return trim(strval($text));
	}
}

if (!function_exists('dialecticBuildMetadataFollowupRequest')) {
	function dialecticBuildMetadataFollowupRequest($requestText, $promptText)
	{
		$requestText = trim(strval($requestText));
		$promptText = trim(strval($promptText));
		if ($promptText === '') {
			return $requestText;
		}
		if ($requestText === '') {
			return "({$promptText})";
		}

		return "({$promptText}) {$requestText}";
	}
}

if (!function_exists('dialecticShouldSkipFuncretInfoActionLog')) {
	function dialecticShouldSkipFuncretInfoActionLog($functionCodeName)
	{
		$functionCodeName = trim(strval($functionCodeName));
		if ($functionCodeName === '') {
			return true;
		}

		if (function_exists('isNarratorPrivateActionName') && isNarratorPrivateActionName($functionCodeName)) {
			return true;
		}

		return function_exists('dialecticActionCatalogMetadataFlagEnabled')
			&& dialecticActionCatalogMetadataFlagEnabled($functionCodeName, 'suppress_placeholder_infoaction');
	}
}

if (!function_exists('dialecticLogFuncretResultInfoAction')) {
	function dialecticLogFuncretResultInfoAction($functionCodeName, $argName, $argValue, $resultText)
	{
		if (!function_exists('logEvent') || !function_exists('dialecticBuildFuncretResultInfoActionMessage')) {
			return false;
		}

		if (dialecticShouldSkipFuncretInfoActionLog($functionCodeName)) {
			return false;
		}

		$message = dialecticBuildFuncretResultInfoActionMessage($functionCodeName, $argName, $argValue, $resultText);
		if ($message === '') {
			return false;
		}

		$gameRequestCopy = $GLOBALS["gameRequest"] ?? [];
		if (!is_array($gameRequestCopy)) {
			return false;
		}

		$gameRequestCopy[0] = "infoaction";
		$gameRequestCopy[3] = $message;
		logEvent($gameRequestCopy);
		return true;
	}
}

if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . ".last_tool_call_openai.id.txt")) {
	$lastCallId = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . ".last_tool_call_openai.id.txt");
} else {
	$lastCallId = "";
}

$funcretPayload = $gameRequest[3] ?? '';
$decodedFuncretPayload = is_string($funcretPayload) ? json_decode($funcretPayload, true) : null;
if (is_array($decodedFuncretPayload)) {
	$returnFunction = [
		'command',
		trim(strval($decodedFuncretPayload['action'] ?? $decodedFuncretPayload['command'] ?? '')),
		trim(strval($decodedFuncretPayload['target'] ?? $decodedFuncretPayload['arg'] ?? '')),
		trim(strval($decodedFuncretPayload['result'] ?? $decodedFuncretPayload['message'] ?? '')),
	];
} else {
	Logger::warn("Ignoring non-JSON funcret payload");
	return;
}


$functionDisplayName = getFunctionDisplayName($returnFunction[1]);

$functionCodeName = $functionDisplayName;

$functionCodeName = $returnFunction[1];

$useFunctionsAgain = false;
$argName = "target";
$currentFollowupChainDepth = 0;
$followupConfig = function_exists('dialecticActionCatalogGetResolvedFollowupConfig')
	? dialecticActionCatalogGetResolvedFollowupConfig($functionCodeName)
	: [];
$argName = function_exists('dialecticResolveFuncretArgumentName')
	? dialecticResolveFuncretArgumentName($functionCodeName, $followupConfig)
	: trim(strval($followupConfig['arg_name'] ?? 'target'));
if ($argName === '') {
	$argName = 'target';
}

dialecticLogFuncretResultInfoAction($functionCodeName, $argName, $returnFunction[2] ?? '', $returnFunction[3] ?? '');

if (isset($returnFunction[2])) {
	// Patch. 
	$returnFunction[2] = trim($returnFunction[2]);

	error_log("[DIALECTIC] Checking <$functionCodeName> <{$returnFunction[1]}>");

	$followupEnabled = !empty($followupConfig['enabled']);
	$followupPrompt = trim(strval($followupConfig['prompt'] ?? ''));

	if (!$followupEnabled) {
		terminate();

	}

	$useFunctionsAgain = !empty($followupConfig['use_functions_again']);
	$currentFollowupChainDepth = function_exists('dialecticActionCatalogGetLastIssuedActionFollowupChainDepth')
		? intval(dialecticActionCatalogGetLastIssuedActionFollowupChainDepth($functionCodeName))
		: 0;
	$followupChainLimit = function_exists('dialecticActionCatalogGetFollowupChainLimit')
		? intval(dialecticActionCatalogGetFollowupChainLimit())
		: 1;

	if ($useFunctionsAgain && $followupChainLimit > 0 && $currentFollowupChainDepth >= $followupChainLimit) {
		error_log("[DIALECTIC] Follow-up action chaining disabled for {$functionCodeName}: depth {$currentFollowupChainDepth} reached limit {$followupChainLimit}");
		$useFunctionsAgain = false;
	}

	if ($followupPrompt !== '') {
		$request = dialecticBuildMetadataFollowupRequest($request, $followupPrompt);

	} else {
		terminate();
	}
	$functionCalled[] = array(
		'role' => 'assistant',
		'content' => null,
		'tool_calls' => [
			array(
				"id" => $lastCallId,
				"type" => "function",
				"function" => ["name" => $functionDisplayName, "arguments" => "{\"$argName\":\"{$returnFunction[2]}\"}"]
			)
		]
	);

} else
	//$functionCalled[] = array('role' => 'assistant', 'content' => null, 'tool_calls' => [array("id" => $lastCallId, "function"=>["name"=>$functionDisplayName,"arguments" => "{\"$argName\":\"{$returnFunction[2]}\"}"])]);
	$functionCalled[] = array('role' => 'assistant', 'content' => null, 'tool_calls' => [array("id" => $lastCallId, "function" => ["name" => $functionDisplayName, "arguments" => "{\"$argName\":\"\"}"])]); // $returnFunction[2] is not set here

$debugNotificationText = dialecticSanitizeDebugNotificationText($returnFunction[3] ?? '');
if ($debugNotificationText !== '' && dialecticActionShouldEmitDebugNotification($functionCodeName)) {
	$notificationSpeaker = trim(strval($GLOBALS["DIALECTIC_NAME"] ?? ''));
	if ($notificationSpeaker === '') {
		$notificationSpeaker = 'The Narrator';
	}

	dialectic_buffer_command_response_line($notificationSpeaker, "DebugNotification", ["message" => $debugNotificationText]);
}

$returnFunctionArray[] = array('role' => 'tool', 'content' => "{$returnFunction[3]}", 'tool_call_id' => "$lastCallId");

$returnFunctionArray[] = array('role' => $LAST_ROLE, 'content' => $request);


$contextData = array_merge($head, ($contextDataFull), $functionCalled, $returnFunctionArray);

file_put_contents(__DIR__ . "/../log/context_for_{$GLOBALS["DIALECTIC_NAME"]}_after_func.txt", print_r($contextData, true));

if ($useFunctionsAgain) {
	$GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
	$GLOBALS["FUNCTIONS"];
	$GLOBALS["FUNCTIONS_FORCE_CALL"] = "auto";
	$GLOBALS["FOLLOWUP_CHAIN_NEXT_DEPTH"] = $currentFollowupChainDepth + 1;
} else {
	$GLOBALS["FUNCTIONS_ARE_ENABLED"] = false;
	unset($GLOBALS["FOLLOWUP_CHAIN_NEXT_DEPTH"]);
}

?>
