<?php

// Override some descriptions when in instruction mode

// We must use internal named keys here.

$GLOBALS["F_DESCRIPTIONS_NEW"]["TravelTo"]="Travel long distance to a building, city, door or other location. Also known as lead the way.";
$GLOBALS["F_NAMES_NEW"]["TravelTo"] = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
    ? dialecticNormalizeActionCatalogDisplayActionName("TravelTo")
    : "TravelTo";

foreach ($GLOBALS["FUNCTIONS"] as $n=>$f) {
    $internalCode=getFunctionCodeNameByDisplayName($f["name"]);
    if (isset($GLOBALS["F_DESCRIPTIONS_NEW"][$internalCode]))
        $GLOBALS["FUNCTIONS"][$n]["description"]=$GLOBALS["F_DESCRIPTIONS_NEW"][$internalCode];

    if (isset($GLOBALS["F_NAMES_NEW"][$internalCode]))
        $GLOBALS["FUNCTIONS"][$n]["name"]=$GLOBALS["F_NAMES_NEW"][$internalCode];


}

foreach ($GLOBALS["F_DESCRIPTIONS_NEW"] as $k=>$v)
    $GLOBALS["F_DESCRIPTIONS"][$k]=$v;

foreach ($GLOBALS["F_NAMES_NEW"] as $k=>$v) 
    $GLOBALS["F_NAMES"][$k]=$v;


$GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";

?>
