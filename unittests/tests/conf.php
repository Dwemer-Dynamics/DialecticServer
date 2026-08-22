<?php

//[Main Configuration]
$GLOBALS["PLAYER_NAME"]="Prisoner"; //Player's current character name.
$GLOBALS["DBDRIVER"]="phpunit"; //Database - Do not change.
$GLOBALS["DIALECTIC_NAME"]="The Narrator"; //NPC name. MUST MATCH their Fallout in-game NPC name!
$GLOBALS["PROMPT_HEAD"]="Let's roleplay in the Universe of Fallout."; //System Prompt. Defines the rules of the roleplay.
$GLOBALS["DIALECTIC_PERS"]="You are The Narrator in a Fallout adventure. You will only talk to #PLAYER_NAME#. "
    . "You refer to yourself as 'The Narrator'. "
    . "Only #PLAYER_NAME# can hear you. "
    . "Your goal is to comment on #PLAYER_NAME#'s playthrough, and occasionally give hints. NO SPOILERS. " 
    . "Talk about quests and last events."; //NPC personality.
$GLOBALS["DYNAMIC_PROFILE"]=true; //Dynamic profile updates during certain ingame events.
$GLOBALS["MINIME_T5"]=true; //Assists smaller weight LLMs with action and memory functions.

//[Advanced Configuration]
$GLOBALS["RECHAT_H"]=2; //Rechat Rounds. Higher values will increase the amount of rounds NPC's will talk amongst themselves.
$GLOBALS["RECHAT_P"]=100; //Rechat Probability.
$GLOBALS["CONTEXT_HISTORY"]="50"; //Amount of context history (dialogue and events) that will be sent to LLM.
$GLOBALS["HTTP_TIMEOUT"]=15; //Timeout for AI requests.
$GLOBALS["NEWQUEUE"]=true; //Leave as is - read only
$GLOBALS["MAX_WORDS_LIMIT"]=0; //Enforce a word limit for AI's responses. 0 = unlimited.
$GLOBALS["NARRATOR_TALKS"]=true; //Enables the Narrator.
$GLOBALS["NARRATOR_WELCOME"]=false; //The Narrator will recap previous events after a save is loaded.
$GLOBALS["LANG_LLM_XTTS"]=false; //XTTS Only! Will offer a language field to LLM, and will try match to XTTSv2 language.
$GLOBALS["DIALECTIC_ANIMATIONS"]=true; //Issues animations to AI driven NPCs.
$GLOBALS["EMOTEMOODS"]="sassy,"
    . "assertive,"
    . "sexy,"
    . "smug,"
    . "kindly,"
    . "lovely,"
    . "seductive,"
    . "sarcastic,"
    . "smirking,"
    . "amused,"
    . "irritated,"
    . "playful,"
    . "neutral,"
    . "teasing,"
    . "desperate,"
    . "scared,"
    . "pleading,"
    . "sad,"
    . "happy,"
    . "angry,"
    . "drunk,"
    . "shy,"
    . "surprised"; //List of moods passed to LLM (comma separated). Triggers animations if enabled.
$GLOBALS["SUMMARY_PROMPT"]=''; //Instructions added when generating summaries for memories and other features.
$GLOBALS["DIARY_PROMPT"]=''; //Instructions added when generating diary entries.

//[AI/LLM Service Selection]
$GLOBALS["CONNECTORS"]=["openrouterjson"]; //AI Service(s).
$GLOBALS["CONNECTORS_DIARY"]='openrouterjson';
$GLOBALS["CORE_CONNECTOR_SUMMARY"]=4;
$GLOBALS["CORE_CONNECTOR_MEDIUMTERM"]=4;

$GLOBALS["DYNAMIC_PROFILE"]=true;

//[AI/LLM Connectors]
//OpenRouter JSON
$GLOBALS["CONNECTOR"]["openrouterjson"]["url"]="https://openrouter.ai/api/v1/chat/completions"; //API endpoint.
$GLOBALS["CONNECTOR"]["openrouterjson"]["model"]="meta-llama/llama-3.1-70b-instruct"; //LLM model.
$GLOBALS["CONNECTOR"]["openrouterjson"]["max_tokens"]='512'; //Maximum tokens to generate.
$GLOBALS["CONNECTOR"]["openrouterjson"]["temperature"]=0.8; //LLM parameter temperature.
$GLOBALS["CONNECTOR"]["openrouterjson"]["presence_penalty"]=0; //LLM parameter presence_penalty.
$GLOBALS["CONNECTOR"]["openrouterjson"]["frequency_penalty"]=0; //LLM parameter frequency_penalty.
$GLOBALS["CONNECTOR"]["openrouterjson"]["repetition_penalty"]=1.1;	//LLM parameter repetition_penalty.
$GLOBALS["CONNECTOR"]["openrouterjson"]["top_p"]=1; //LLM parameter top_p.
$GLOBALS["CONNECTOR"]["openrouterjson"]["top_k"]=40; //LLM parameter top_k.
$GLOBALS["CONNECTOR"]["openrouterjson"]["min_p"]=0; //LLM parameter min_p.
$GLOBALS["CONNECTOR"]["openrouterjson"]["top_a"]=0; //LLM parameter top_a.
$GLOBALS["CONNECTOR"]["openrouterjson"]["ENFORCE_JSON"]=true; //Attempts to enforce JSON. Only valid for some models.
$GLOBALS["CONNECTOR"]["openrouterjson"]["PREFILL_JSON"]=false; //Prefill JSON, Only valid for some models.
$GLOBALS["CONNECTOR"]["openrouterjson"]["MAX_TOKENS_MEMORY"]='1024'; //Maximum tokens to generate when summarizing.
$GLOBALS["CONNECTOR"]["openrouterjson"]["API_KEY"]="openrouterjson_key"; //API key.
$GLOBALS["CONNECTOR"]["openrouterjson"]["xreferer"]="https://dwemerdynamics.com/dialectic"; //Stub needed header.
$GLOBALS["CONNECTOR"]["openrouterjson"]["xtitle"]="DIALECTIC"; //Stub needed header.
$GLOBALS["CONNECTOR"]["openrouterjson"]["json_schema"]=false; //Enable OpenRouter JSON schema.
//OpenAI JSON
$GLOBALS["CONNECTOR"]["openaijson"]["url"]="https://api.openai.com/v1/chat/completions"; //API endpoint.
$GLOBALS["CONNECTOR"]["openaijson"]["model"]='gpt-4o-mini'; //LLM model.
$GLOBALS["CONNECTOR"]["openaijson"]["max_tokens"]='512'; //Maximum tokens to generate.
$GLOBALS["CONNECTOR"]["openaijson"]["temperature"]=1; //LLM parameter temperature.
$GLOBALS["CONNECTOR"]["openaijson"]["presence_penalty"]=1; //LLM parameter presence_penalty.
$GLOBALS["CONNECTOR"]["openaijson"]["frequency_penalty"]=0; //LLM parameter frequency_penalty.
$GLOBALS["CONNECTOR"]["openaijson"]["top_p"]=1; //LLM parameter top_p.
$GLOBALS["CONNECTOR"]["openaijson"]["API_KEY"]=""; //API key.
$GLOBALS["CONNECTOR"]["openaijson"]["MAX_TOKENS_MEMORY"]="1024"; //Maximum tokens to generate when summarizing.
$GLOBALS["CONNECTOR"]["openaijson"]["json_schema"]=false; //Enable OpenAI JSON schema.
//Google OpenAI JSON
$GLOBALS["CONNECTOR"]["google_openaijson"]["url"]="https://generativelanguage.googleapis.com/v1beta/openai/chat/completions"; //API endpoint.
$GLOBALS["CONNECTOR"]["google_openaijson"]["model"]='gemini-1.5-flash'; //LLM model.
$GLOBALS["CONNECTOR"]["google_openaijson"]["max_tokens"]='512'; //Maximum tokens to generate.
$GLOBALS["CONNECTOR"]["google_openaijson"]["temperature"]=1; //LLM parameter temperature.
$GLOBALS["CONNECTOR"]["google_openaijson"]["top_p"]=0.95; //LLM parameter top_p.
$GLOBALS["CONNECTOR"]["google_openaijson"]["API_KEY"]=""; //API key.
$GLOBALS["CONNECTOR"]["google_openaijson"]["MAX_TOKENS_MEMORY"]="800"; //Maximum tokens to generate when summarizing.
$GLOBALS["CONNECTOR"]["google_openaijson"]["json_schema"]=false; //Enable OpenAI JSON schema.
//KoboldCPP JSON
$GLOBALS["CONNECTOR"]["koboldcppjson"]["url"]='http://127.0.0.1:5001';	//KoboldCPP API Endpoint.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["max_tokens"]='512';	//Maximum tokens to generate.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["temperature"]=0.9;	//LLM parameter temperature.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["rep_pen"]=1.12;	//LLM parameter rep_pen.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["top_p"]=0.9;	//LLM parameter top_p.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["min_p"]=0;	//LLM parameter min_p.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["top_k"]=0;	//LLM parameter top_k.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["PREFILL_JSON"]=false; //Prefill JSON, Only valid for some models.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["MAX_TOKENS_MEMORY"]='256';	//Maximum tokens to generate when summarizing.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["newline_as_stopseq"]=false; //A newline in the output that will be considered a stop sequence. Recommended to leave it as default.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["use_default_badwordsids"]=true; //Unban End of Sentence (EOS) tokens. If set to false the LLM will stop generating when it detects an EOS token.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["eos_token"]='<|eot_id|>'; //EOS token LLM uses. Only works if use_default_badwordsids is enabled.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["template"]='chatml'; //Prompt format specified in the HuggingFace model card.
$GLOBALS["CONNECTOR"]["koboldcppjson"]["grammar"]=false; //Enforces use of JSON grammar at the cost of slower generation speed. 
//[Text-to-Speech Service]
$GLOBALS["TTSFUNCTION"]="phpunit";

//[Text-to-Speech Endpoints]
//DIALECTIC XTTS
$GLOBALS["TTS"]["XTTSFASTAPI"]["endpoint"]='http://127.0.0.1:8020'; //API endpoint.
$GLOBALS["TTS"]["XTTSFASTAPI"]["language"]='en'; //Lanuguage.
$GLOBALS["TTS"]["XTTSFASTAPI"]["voiceid"]='TheNarrator'; //Generated voice file name.
//ElevenLabs TTS
$GLOBALS["TTS"]["ELEVEN_LABS"]["voice_id"]="EXAVITQu4vr4xnSDxMaL";	//Voice ID.
$GLOBALS["TTS"]["ELEVEN_LABS"]["optimize_streaming_latency"]="0"; //Latency optimization level. 0 keeps default quality/latency balance.
$GLOBALS["TTS"]["ELEVEN_LABS"]["model_id"]="eleven_monolingual_v1"; //ElevenLabs model to use. Set to eleven_v3 to use V3 enhancers/audio tags.
$GLOBALS["TTS"]["ELEVEN_LABS"]["stability"]="0.75"; //Higher values sound steadier and less varied.
$GLOBALS["TTS"]["ELEVEN_LABS"]["similarity_boost"]="0.75"; //Higher values cling more closely to the selected voice.
$GLOBALS["TTS"]["ELEVEN_LABS"]["style"]=0.0; //Adds extra style exaggeration. Higher values can increase latency.
$GLOBALS["TTS"]["ELEVEN_LABS"]["speed"]=1.0; //Speaking rate. 1.0 is normal speed.
$GLOBALS["TTS"]["ELEVEN_LABS"]["use_speaker_boost"]=true; //Boosts resemblance to the original voice. Ignored by eleven_v3.
$GLOBALS["TTS"]["ELEVEN_LABS"]["apply_text_normalization"]="auto"; //Rewrites numbers, dates, abbreviations, etc. before speech. auto|on|off
$GLOBALS["TTS"]["ELEVEN_LABS"]["apply_language_text_normalization"]=false; //Adds extra language-specific cleanup before synthesis.
$GLOBALS["TTS"]["ELEVEN_LABS"]["v3_audio_tags"]=""; //Optional Eleven v3 prompt tags prefixed to the text, such as [whispers].
$GLOBALS["TTS"]["ELEVEN_LABS"]["API_KEY"]=""; //Optional API key. Prefer using an ElevenLabs API Badge in connectors.
//[Player TTS]
$GLOBALS["TTSFUNCTION_PLAYER"]="none";
$GLOBALS["TTSFUNCTION_PLAYER_VOICE"]="default_male";

//[Speech-to-Text Service]
$GLOBALS["STTFUNCTION"]="whisper";

//[Speech-to-Text Endpoints]
//OpenAI Whisper STT
$GLOBALS["STT"]["WHISPER"]["LANG"]="en"; //Language.
$GLOBALS["STT"]["WHISPER"]["API_KEY"]=""; //API Key.
//Azure STT
$GLOBALS["STT"]["AZURE"]["LANG"]="en-US"; //Language.
$GLOBALS["STT"]["AZURE"]["profanity"]="masked"; //Profanity handling filter.
$GLOBALS["STT"]["AZURE"]["API_KEY"]=""; //API key.
//Local Whisper STT
$GLOBALS["STT"]["LOCALWHISPER"]["URL"]="http://127.0.0.1:9876/api/v0/transcribe"; //API endpoint.
$GLOBALS["STT"]["LOCALWHISPER"]["FORMFIELD"]="audio_file"; //(audio_file,file) Form field name.

//[Memory Configuration]
//Memory Settings
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]=true; //Long term memory embedding.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["TXTAI_URL"]='http://127.0.0.1:8083'; //NOT FUNCTIONAL CURRENTLY. JUST LEAVE AS IS!
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["MEMORY_TIME_DELAY"]=10; //Time in minutes to delay before using a memory in a prompt.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["MEMORY_CONTEXT_SIZE"]=1; //Amount of memory records that will be injected into the prompt.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"]=false; //Combines individual memory logs into larger ones at the cost of tokens.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"]=10; //Time frame used to pack summary data.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["MEMORY_BIAS_A"]=33; //0-100 - Minimal distance to offer memory.
$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["MEMORY_BIAS_B"]=66; //0-100 - Minimal distance to endorse memory.
//Other Options
$GLOBALS["FEATURES"]["MISC"]["ADD_TIME_MARKS"]=false; //Add timestamps to the context logs. Assists with memory recollection.
$GLOBALS["FEATURES"]["MISC"]["TTS_RANDOM_PITCH"]=false; //Adjusting the pitch when generating the voice for this actor will add variation.
$GLOBALS["FEATURES"]["MISC"]["WORLDKNOWLEDGE_INFINIUM"]=false;	//Fallout context information will be added to the prompt. Use for small weight LLMs.
$GLOBALS["FEATURES"]["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"]=false; //Reorders properties in the offered JSON schema.
$GLOBALS["FEATURES"]["MISC"]["LIFE_LINK_PLUGIN"]=false; // WIP. Use life link plugin for dynamic profiles

$GLOBALS["WORLDKNOWLEDGE_INFINIUM"]=true;

global $FUNCTIONS_ARE_ENABLED;
global $TEMPLATE_DIALOG;
global $TEMPLATE_ACTION;
global $MAXIMUM_WORDS;
global $FUNCTION_PARM_INSPECT;
global $COMMAND_PROMPT;
global $COMMAND_PROMPT_FUNCTIONS;
global $COMMAND_PROMPT_ENFORCE_ACTIONS;
global $F_NAMES;
global $F_RETURNMESSAGES;
global $contextData;
global $contextDataHistoric;
global $contextDataWorld;
global $contextDataFull;
global $gameRequest;
global $request;
global $talkedSoFar;
global $enginePath;

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."phpunit.class.php");
$GLOBALS["db"] = new sql();
$GLOBALS["contextData"]=[];
$GLOBALS["contextDataHistoric"]=[];
$GLOBALS["contextDataWorld"]=[];
$GLOBALS["contextDataFull"]=[];
$GLOBALS["request"]="";
$GLOBALS["COMMAND_PROMPT"]="";
$GLOBALS["COMMAND_PROMPT_FUNCTIONS"]="Use # ACTIONS if your character needs to perfom an action.";
$GLOBALS["CACHE_PEOPLE"]="";
$GLOBALS["CACHE_LOCATION"]="";
$GLOBALS["CACHE_PARTY"]="";
$GLOBALS["PATCH_STORE_FUNC_RES"]=null;

?>
