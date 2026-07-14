<?php




// IF GLOBAL INPUTCHAT (NO TARGET)
$promptIncludesStartTime = (isset($startTime) && is_numeric($startTime)) ? $startTime : microtime(true);
$gameRequest = (isset($gameRequest) && is_array($gameRequest)) ? $gameRequest : [];
for ($promptIncludeIndex = 0; $promptIncludeIndex <= 4; $promptIncludeIndex++) {
    if (!array_key_exists($promptIncludeIndex, $gameRequest)) {
        $gameRequest[$promptIncludeIndex] = "";
    }
}
$GLOBALS["gameRequest"] = $gameRequest;
$GLOBALS["DIALECTIC_NAME"] = $GLOBALS["DIALECTIC_NAME"] ?? "The Narrator";
$GLOBALS["PLAYER_NAME"] = $GLOBALS["PLAYER_NAME"] ?? "Player";
$GLOBALS["TEMPLATE_DIALOG"] = $GLOBALS["TEMPLATE_DIALOG"] ?? "";
$currentNpcData = (isset($currentNpcData) && is_array($currentNpcData)) ? $currentNpcData : [];

require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));

require_once(__DIR__ . DIRECTORY_SEPARATOR . "prompts/prompts.php");

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));

$PROMPT_HEAD = !empty($GLOBALS["PROMPT_HEAD"]) ? $GLOBALS["PROMPT_HEAD"] : "Let\'s roleplay in the Universe of Fallout. I\'m {$GLOBALS["PLAYER_NAME"]} ";

/* 
 * Info gathering to mangle function definitions. This will enforce some parameters to be fixed-
 */

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));
$FUNCTION_PARM_MOVETO=DataPosibleMoveToTargets();		// Move_To is actor-only; locations should use TravelTo.
if (!isset($FUNCTION_PARM_MOVETO))
	$FUNCTION_PARM_MOVETO=[];
$FUNCTION_PARM_MOVETO[]=$GLOBALS["PLAYER_NAME"];


$FUNCTION_PARM_INSPECT=DataPosibleInspectTargets();	// To avoid moving to non existant target, lets limit available targets to the real ones in function definition
if (!isset($FUNCTION_PARM_INSPECT))
	$FUNCTION_PARM_INSPECT=[];
$FUNCTION_PARM_INSPECT[]=$GLOBALS["PLAYER_NAME"];

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));
require(__DIR__.DIRECTORY_SEPARATOR."prompts".DIRECTORY_SEPARATOR."command_prompt.php");
error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));
require_once(__DIR__.DIRECTORY_SEPARATOR . "functions" . DIRECTORY_SEPARATOR . "functions.php");
error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $promptIncludesStartTime));

/* This will use the extra key from PROMPTS array to do some things 
 (enable/disable, force mod, change token limit oe define a transformer (non IA related) function.
 */

if (isset($PROMPTS[$gameRequest[0]]) && isset($PROMPTS[$gameRequest[0]]["extra"])) {
	if (isset($PROMPTS[$gameRequest[0]]["extra"]["mood"]))
		$GLOBALS["FORCE_MOOD"] = $PROMPTS[$gameRequest[0]]["extra"]["mood"];
	if (isset($PROMPTS[$gameRequest[0]]["extra"]["force_tokens_max"]))
		$GLOBALS["FORCE_MAX_TOKENS"] = $PROMPTS[$gameRequest[0]]["extra"]["force_tokens_max"];
	if (isset($PROMPTS[$gameRequest[0]]["extra"]["transformer"]))
		$GLOBALS["TRANSFORMER_FUNCTION"] = $PROMPTS[$gameRequest[0]]["extra"]["transformer"];
	if (isset($PROMPTS[$gameRequest[0]]["extra"]["dontuse"]))
		if (($PROMPTS[$gameRequest[0]]["extra"]["dontuse"]))
			return "";


}

?>
