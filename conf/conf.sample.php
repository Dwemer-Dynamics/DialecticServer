<?php

//[Main Configuration]
$DIALECTIC_GAME_ID="fnv";
$DIALECTIC_WORLD_NAME="Mojave Wasteland";
$DIALECTIC_DB_HOST="127.0.0.1";
$DIALECTIC_DB_PORT="5432";
$DIALECTIC_DB_NAME="dialectic";
$DIALECTIC_DB_USER="dwemer";
$DIALECTIC_DB_PASSWORD="dwemer";
$DIALECTIC_DB_CONNECT_TIMEOUT="90";

$PLAYER_NAME="Courier"; //Player's current character name.
$DBDRIVER="postgresql"; //Database - Do not change.
$DIALECTIC_NAME="The Narrator"; // Current speaking character name used by prompts and profile resolution.
$LOCK_PROFILE=false; //NPC profile lock.
$AUTO_LOCK_PROFILE=true; //When enabled, saving an NPC profile in DIALECTIC NPC page automatically locks it.
$AUTOFILL_CUSTOM_PROFILES=true; //When enabled, custom NPCs with blank profile fields can be auto-filled later using AI profile generation.
$AUTOFILL_CUSTOM_PROFILES_TRIGGER=40; //Number of usable AI profile events required before blank custom NPCs are auto-filled. Range: 10-100.
$PROMPT_HEAD="You are #DIALECTIC_NAME#, a character in the Fallout: New Vegas world. This is not a simulation or a game; this is your reality. You will embody this persona with absolute conviction, prioritizing narrative authenticity and psychological consistency.

The director provides scene prompts and narrative catalysts. Integrate these prompts seamlessly as the next logical event in the story. Treat them as established fact and build upon them with your character's authentic reaction.

Your primary driver is to be a compelling, psychologically consistent, and authentically reactive character. Your loyalty is to the truth of the story. Identify potential narrative directions based on your knowledge of narrative tropes, goals, and relationships, selecting the most appropriate one for the current scene. Original thinking is encouraged."; //System Prompt. Defines the rules of the roleplay.
$DIALECTIC_PERS="You are The Narrator in a Fallout: New Vegas adventure. You will only talk to #PLAYER_NAME#. "
    . "You refer to yourself as 'The Narrator'. "
    . "Only #PLAYER_NAME# can hear you. "
    . "Your goal is to comment on #PLAYER_NAME#'s playthrough, and occasionally give hints. NO SPOILERS. " 
    . "Talk about quests and last events. "
    . "When #PLAYER_NAME# speaks to you directly, answer them as a private voice in their mind using plain spoken dialogue rather than third-person scene narration."; //NPC personality.
$DIALECTIC_PERSONALITY="Detached, observant, witty, and helpful. Acts as a private guide to #PLAYER_NAME#, offering spoiler-free insight and commentary without turning direct conversation into narrated prose."; //NPC personality traits.
$DIALECTIC_SPEECHSTYLE="Speaks clearly and directly with concise, evocative phrasing and occasional dry wit. When addressing #PLAYER_NAME# directly, respond in plain spoken dialogue and avoid stage directions, scene description, or text in asterisks."; //NPC speech style.
$DIARY_COOLDOWN=120; //Cooldown period in seconds between diary entries to prevent spam. If a diary hotkey is pressed within this time period, the request will be ignored.
$DYNAMIC_PROFILE=false; //Dynamic profile updates using a timer system.
// NOTE: AUTO_DIARY and AUTO_DIARY_WAIT have been moved to profile-level settings. Configure them in your profile settings UI instead of here.
$POWER_AWARENESS_ENABLED=false; //Enable Power Awareness system. NPCs will be aware of relative power levels and react appropriately to threats.
$MINIME_T5=false; //Assists smaller weight LLMs with action and memory functions.
$WORLDKNOWLEDGE="knowall"; //Assists smaller weight LLMs with action and memory functions.
$WORLDKNOWLEDGE_AMOUNT=1; //Number of WorldKnowledge keywords to extract from each response. More keyword extraction will mean longer response times.
$PLAYER_RESPEECH=true; //Use default diary connector AI to rewrite player speech. Currently only triggers when starting speech with **.
$PLAYER_SPEECH_STYLE=""; //Instructions for how the player character speaks and communicates. Used as context when rewriting player dialogue.
$PROMPT_TIMESTAMP=false; //Add rough timestamp subdividers to event context (e.g., 'Moments Ago', 'A while ago') to help the LLM understand temporal relationships.
$use_emotions_expression = false; //Add emotions support. Changes the affect context/json object offered to LLM must be false by default.

//[Advanced Configuration]
$RECHAT_H=2; //Rechat Rounds. Higher values will increase the amount of rounds NPC's will talk amongst themselves.
$RECHAT_P=50; //Rechat Probability.
$BORED_EVENT=30; //Bored Event Probability. Chance of an NPC starting a random conversation after a set period of time.
$CONTEXT_HISTORY="50"; //Amount of context history (dialogue and events) that will be sent to LLM.
$CONTEXT_HISTORY_DIARY="100"; //Amount of context history specifically for diary entries. Set to 0 to use regular CONTEXT_HISTORY value.
$CONTEXT_HISTORY_DYNAMIC_PROFILE="50"; //Amount of context history specifically for dynamic profile updates. Set to 0 to use regular CONTEXT_HISTORY value.
$CLEAN_CONTEXT_FOCUS_CHAT_HISTORY=25; //Amount of context history specifically for clean context focus chat. Set to 0 to use regular CONTEXT_HISTORY value.
$HTTP_TIMEOUT=15; //Timeout for AI requests.
$ALIVE_MESSAGE=false; //Leave as is - read only
$MAX_WORDS_LIMIT=0; //Enforce a word limit for AI's responses. 0 = unlimited.
$QUEST_COMMENT = false;
$QUEST_COMMENT_CHANCE= "10%";
$DIALECTIC_ANIMATIONS=true; //Issues animations to AI driven NPCs.
$EMOTEMOODS="sassy,"
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

$INLINE_NARRATION_MODE="disabled"; // disabled|narrator|npc|text_only
$REMOVE_PLAYER_AUTOCHAT_ASTERISKS=true; // keep AUTOCHAT / PLAYER_RESPEECH rewritten player text spoken-only by stripping leading narration / *asterisks*
$REMOVE_ASTERISKS_FROM_PLAYER_INPUT=true; // filters *asterisked* player input from player TTS only
$REMOVE_ASTERISKS_FROM_NPC_OUTPUT=true;
$ENFORCE_ACTIONS_PROMPT=false;
$SUMMARY_PROMPT= 'Focus on key events, tagging characters, locations, and factions accurately. Ensure memories align and maintain chronological order while foreshadowing future arcs. Prioritize player agency, and use environmental cues to enhance storytelling and continuity.'; 

$DYNAMIC_PROFILE_FIELDS = ["personality", "speechstyle", "goals"];

$DYNAMIC_PROMPT_PERSONALITY = "Based on the dialogue history and recent events, update #DIALECTIC_NAME# personality traits. "
    . "Maintain all existing relevant personality traits and add new ones based on recent experiences. "
    . "Focus on behavioral changes, emotional growth/regression, new traits that emerged, and changes in confidence or outlook. "
    . "Emphasize any past traumas or new traumas caused by the death of companions, allies, or other known characters, and how these events shape the character's behavior and mindset. "
    . "Return ONLY the updated personality description in 3-5 sentences. Do not include any introductory text, meta-commentary, or phrases like 'Here is the updated personality' or 'The character's personality is'. "
    . "Start directly with the personality content.";

$DYNAMIC_PROMPT_OCCUPATION = "Based on story progression and events, update #DIALECTIC_NAME# occupation and role. "
    . "Maintain the current occupation unless significant changes have occurred. Add new responsibilities, changes in social status, and professional affiliations. "
    . "Focus on job changes, new duties, and evolving professional relationships. "
    . "Return ONLY the updated occupation description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like 'The character's occupation is' or 'Here is the updated occupation'. "
    . "Start directly with the occupation content.";

$DYNAMIC_PROMPT_SKILLS = "Based on experiences and training, update #DIALECTIC_NAME# skills and abilities. "
    . "Maintain all existing relevant skills and add new ones based on recent experiences. "
    . "Focus on new skills learned, existing skills improved, any skills that deteriorated, and combat, technical, survival, or social knowledge gained. "
    . "Return ONLY a bulleted list using * Skill - Description format. Do not include any introductory text, meta-commentary, or phrases like 'Here are the updated skills' or 'The character's skills include'. "
    . "Start directly with the first bullet point.";

$DYNAMIC_PROMPT_SPEECHSTYLE = "Based on recent interactions, update how #DIALECTIC_NAME# speaks and communicates. "
    . "Maintain existing consistent speech patterns and add new ones based on recent interactions. "
    . "Focus on changes in vocabulary, new mannerisms, accent changes, and confidence level in speech. "
    . "Return ONLY the updated speech style description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like 'The character speaks' or 'Here is the updated speech style'. "
    . "Start directly with the speech style content.";

$DYNAMIC_PROMPT_GOALS = "Based on story developments and achievements, update the #DIALECTIC_NAME# goals and aspirations. "
    . "Maintain existing relevant goals, compressing related goals, and add new ones. Remove goals that have been clearly "
    . "completed or are no longer applicable. Focus on new aspirations that emerged, modified existing goals due to "
    . "circumstances, and updated long-term objectives. Return ONLY a bulleted list using * Goal description as actionable "
    . "aspiration format. Do not include any introductory text, meta-commentary, or phrases like 'Here are the updated goals' "
    . "or 'The character's goals are'. Start directly with the first bullet point (maintain a maximum of 20 goals with "
    . "reduction priority when required: 1- compress related goals, 2-eliminate 'study' related goals, 3- eliminate older goals).";
$DIARY_PROMPT = "Please write a short summary of #PLAYER_NAME# and #DIALECTIC_NAME#'s recent dialogues and events into #DIALECTIC_NAME#'s diary. WRITE AS IF YOU WERE #DIALECTIC_NAME#. Start the diary entry with the current date and time.";

$RPG_COMMENTS=["levelup","combat_end","lockpick","sleep","location_changed","quest_updated","keepmechecked"]; //AI Service(s).
$RPG_COMMENTS_CHANCE=20; //Chance (0-100) for enabled RPG comments to trigger.
$LOCATION_BLACKLIST="The Strip, Lucky 38"; //Comma-separated list of location names to exclude from Points of Interest context.
$ITEM_BLACKLIST=""; //Comma-separated list of item/armor names to exclude from dynamic context.
$SHORTER_NEARBY_ITEM_LIST=false; //Group duplicate nearby ground items into one counted entry and show a single representative RefID in item descriptions.
$EVENT_TYPE_FILTER=""; //Comma-separated list of event types to exclude from context generation.
$GROUND_ITEMS_DESCRIPTIONS_ONLY=false; //Only show nearby ground items that have descriptions in the database.
$INVENTORY_ITEMS_DESCRIPTIONS_ONLY=false; //Only show inventory items that have descriptions in the database.
$HIDE_AMBIENT_COMBAT=false; //Hide ambient NPC-to-NPC combat deaths from context.

//[AI/LLM Service Selection]
$CONNECTORS=["openrouterjson","openaijson","koboldcppjson"]; //AI Service(s).
$CONNECTORS_DIARY="openrouterjson"; //Creates diary entries and memories.

// Core LLM connector defaults (IDs from core_llm_connector table)
$CORE_CONNECTOR_DIRECTOR=1;
$CORE_CONNECTOR_PLAYER=2;
$CORE_CONNECTOR_SUMMARY=4;
$CORE_CONNECTOR_MEDIUMTERM=4;
$CORE_CONNECTOR_SCENECLASSIFIER=7; // Gemma 3N E4B
$SCENE_CLASSIFIER_ENABLED=true; // Enable post-request scene tone/genre classification.
$CORE_CONNECTOR_PROFILES=1;
$RELLLM_CONNECTOR=5; // Relationship Management default (Mistral Small 3.2 24B)

;
//[AI/LLM Connectors]
//OpenRouter JSON
$CONNECTOR["openrouterjson"]["url"]="https://openrouter.ai/api/v1/chat/completions"; //API endpoint.
$CONNECTOR["openrouterjson"]["model"]="z-ai/glm-4.7"; //LLM model.
$CONNECTOR["openrouterjson"]["reasoning_model"]=true; //This is a reasoning model, could output CoT.
$CONNECTOR["openrouterjson"]["fallback_models"]=""; //comma separated models.
$CONNECTOR["openrouterjson"]["PROVIDER"]=""; //use only this list of providers from OpenRouter
$CONNECTOR["openrouterjson"]["providers_sort"]="default"; //Prioritize providers on selected attribute.
$CONNECTOR["openrouterjson"]["providers_to_ignore"]=""; //list of providers to ignore
$CONNECTOR["openrouterjson"]["provider_quantizations"]=""; //use only providers that have the quant. level
$CONNECTOR["openrouterjson"]["provider_max_price_input"]=0.0; //use only providers that have lower input price
$CONNECTOR["openrouterjson"]["provider_max_price_output"]=0.0; //use only providers that have lower output price
$CONNECTOR["openrouterjson"]["max_tokens"]='1024'; //Maximum tokens to generate.
$CONNECTOR["openrouterjson"]["temperature"]=0.6; //LLM parameter temperature.
$CONNECTOR["openrouterjson"]["presence_penalty"]=0; //LLM parameter presence_penalty.
$CONNECTOR["openrouterjson"]["frequency_penalty"]=0; //LLM parameter frequency_penalty.
$CONNECTOR["openrouterjson"]["repetition_penalty"]=1;	//LLM parameter repetition_penalty.
$CONNECTOR["openrouterjson"]["top_p"]=1; //LLM parameter top_p.
$CONNECTOR["openrouterjson"]["min_p"]=0; //LLM parameter min_p.
$CONNECTOR["openrouterjson"]["top_k"]=0; //LLM parameter top_k.
$CONNECTOR["openrouterjson"]["top_a"]=0; //LLM parameter top_a.
$CONNECTOR["openrouterjson"]["ENFORCE_JSON"]=true; //Attempts to enforce JSON. Only valid for some models.
$CONNECTOR["openrouterjson"]["PREFILL_JSON"]=false; //Prefill JSON, Only valid for some models.
$CONNECTOR["openrouterjson"]["MAX_TOKENS_MEMORY"]='1024'; //Maximum tokens to generate when summarizing.
$CONNECTOR["openrouterjson"]["API_KEY"]=""; //API key.
$CONNECTOR["openrouterjson"]["xreferer"]="https://dwemerdynamics.com/dialectic"; //Stub needed header.
$CONNECTOR["openrouterjson"]["xtitle"]="DIALECTIC"; //Stub needed header.
$CONNECTOR["openrouterjson"]["json_schema"]=true; //Enable OpenRouter JSON schema.
// Utility buttons for autofilling parameters
$CONNECTOR["openrouterjson"]["get_parms1"] = false; // Utility button for low randomness parameters
$CONNECTOR["openrouterjson"]["get_parms5"] = false; // Utility button for medium randomness parameters  
$CONNECTOR["openrouterjson"]["get_parms9"] = false; // Utility button for high randomness parameters
//OpenAI JSON
$CONNECTOR["openaijson"]["url"]="https://api.openai.com/v1/chat/completions"; //API endpoint.
$CONNECTOR["openaijson"]["model"]='gpt-4o-mini'; //LLM model.
$CONNECTOR["openaijson"]["reasoning_model"]=false; //This is a reasoning model, could output CoT.
$CONNECTOR["openaijson"]["max_tokens"]='512'; //Maximum tokens to generate.
$CONNECTOR["openaijson"]["temperature"]=0.6; //LLM parameter temperature.
$CONNECTOR["openaijson"]["presence_penalty"]=0; //LLM parameter presence_penalty.
$CONNECTOR["openaijson"]["frequency_penalty"]=0; //LLM parameter frequency_penalty.
$CONNECTOR["openaijson"]["top_p"]=1; //LLM parameter top_p.
$CONNECTOR["openaijson"]["API_KEY"]=""; //API key.
$CONNECTOR["openaijson"]["MAX_TOKENS_MEMORY"]="1024"; //Maximum tokens to generate when summarizing.
$CONNECTOR["openaijson"]["json_schema"]=false; //Enable OpenAI JSON schema.
//Player2 JSON
$CONNECTOR["player2json"]["url"]="http://localhost:4315/v1/chat/completions"; //API endpoint.
//Google OpenAI JSON
$CONNECTOR["google_openaijson"]["url"]="https://generativelanguage.googleapis.com/v1beta/openai/chat/completions"; //API endpoint.
$CONNECTOR["google_openaijson"]["model"]='gemini-1.5-flash'; //LLM model.
$CONNECTOR["google_openaijson"]["max_tokens"]='1024'; //Maximum tokens to generate.
$CONNECTOR["google_openaijson"]["temperature"]=0.75; //LLM parameter temperature.
$CONNECTOR["google_openaijson"]["top_p"]=0.95; //LLM parameter top_p.
$CONNECTOR["google_openaijson"]["API_KEY"]=""; //API key.
$CONNECTOR["google_openaijson"]["MAX_TOKENS_MEMORY"]="800"; //Maximum tokens to generate when summarizing.
$CONNECTOR["google_openaijson"]["json_schema"]=false; //Enable OpenAI JSON schema.
//KoboldCPP JSON
$CONNECTOR["koboldcppjson"]["url"]='http://127.0.0.1:5001';	//KoboldCPP API Endpoint.
$CONNECTOR["koboldcppjson"]["max_tokens"]='512';	//Maximum tokens to generate.
$CONNECTOR["koboldcppjson"]["temperature"]=0.9;	//LLM parameter temperature.
$CONNECTOR["koboldcppjson"]["rep_pen"]=1.12;	//LLM parameter rep_pen.
$CONNECTOR["koboldcppjson"]["top_p"]=0.9;	//LLM parameter top_p.
$CONNECTOR["koboldcppjson"]["min_p"]=0;	//LLM parameter min_p.
$CONNECTOR["koboldcppjson"]["top_k"]=0;	//LLM parameter top_k.
$CONNECTOR["koboldcppjson"]["PREFILL_JSON"]=false; //Prefill JSON, Only valid for some models.
$CONNECTOR["koboldcppjson"]["MAX_TOKENS_MEMORY"]='256';	//Maximum tokens to generate when summarizing.
$CONNECTOR["koboldcppjson"]["newline_as_stopseq"]=false; //A newline in the output that will be considered a stop sequence. Recommended to leave it as default.
$CONNECTOR["koboldcppjson"]["use_default_badwordsids"]=true; //Unban End of Sentence (EOS) tokens. If set to false the LLM will stop generating when it detects an EOS token.
$CONNECTOR["koboldcppjson"]["eos_token"]='<|eot_id|>'; //EOS token LLM uses. Only works if use_default_badwordsids is enabled.
$CONNECTOR["koboldcppjson"]["template"]='chatml'; //Prompt format specified in the HuggingFace model card.
$CONNECTOR["koboldcppjson"]["grammar"]=false; //Enforces use of JSON grammar at the cost of slower generation speed. 
//[Text-to-Speech Service]
$TTSFUNCTION="pockettts";

//[Text-to-Speech Endpoints]
//DIALECTIC XTTS
$TTS["XTTSFASTAPI"]["endpoint"]='http://127.0.0.1:8020'; //API endpoint.
$TTS["XTTSFASTAPI"]["language"]='en'; //Lanuguage.
$TTS["XTTSFASTAPI"]["voiceid"]='TheNarrator'; //Generated voice file name.
$TTS["XTTSFASTAPI"]["voicelogic"]='voicetype';
$TTS["XTTSFASTAPI"]["PARALINGUISTIC_TAGS_ENABLED"]=false; //Enable paralinguistic tags like [laugh], [sigh] for expressive TTS output.
$TTS["XTTSFASTAPI"]["PARALINGUISTIC_TAGS_PROMPT"]=''; //Prompt snippet for instructing LLM to use paralinguistic tags.
$TTS["XTTSFASTAPI"]["PARALINGUISTIC_TAGS_LIST"]='[clear throat],[sigh],[shush],[cough],[groan],[sniff],[gasp],[chuckle],[laugh]'; //Comma-separated list of supported tags.
//Chatterbox
$TTS["CHATTERBOX"]["endpoint"]='http://127.0.0.1:8023'; //API endpoint.
$TTS["CHATTERBOX"]["language"]='en'; //Language.
$TTS["CHATTERBOX"]["voiceid"]='TheNarrator'; //Generated voice file name.
$TTS["CHATTERBOX"]["voicelogic"]='voicetype';
$TTS["CHATTERBOX"]["PARALINGUISTIC_TAGS_ENABLED"]=false; //Enable paralinguistic tags like [laugh], [sigh] for expressive TTS output.
$TTS["CHATTERBOX"]["PARALINGUISTIC_TAGS_PROMPT"]=''; //Prompt snippet for instructing LLM to use paralinguistic tags.
$TTS["CHATTERBOX"]["PARALINGUISTIC_TAGS_LIST"]='[clear throat],[sigh],[shush],[cough],[groan],[sniff],[gasp],[chuckle],[laugh]'; //Comma-separated list of supported tags.
//OmniVoice
$TTS["OMNIVOICE"]["endpoint"]='http://127.0.0.1:8021'; //API endpoint.
$TTS["OMNIVOICE"]["language"]='en'; //Active OmniVoice language profile.
$TTS["OMNIVOICE"]["voiceid"]='TheNarrator'; //Generated voice file name.
$TTS["OMNIVOICE"]["voicelogic"]='voicetype';
//PocketTTS
$TTS["POCKETTTS"]["endpoint"]='http://127.0.0.1:8024'; //API endpoint.
$TTS["POCKETTTS"]["language"]='en'; //Language.
$TTS["POCKETTTS"]["voiceid"]='TheNarrator'; //Generated voice file name.
$TTS["POCKETTTS"]["model"]='pocket-tts'; //audio.cpp model id.
$TTS["POCKETTTS"]["voicelogic"]='voicetype';
//ElevenLabs TTS
$TTS["ELEVEN_LABS"]["voice_id"]="EXAVITQu4vr4xnSDxMaL";	//Voice ID.
$TTS["ELEVEN_LABS"]["optimize_streaming_latency"]="0"; //Latency optimization level. 0 keeps default quality/latency balance.
$TTS["ELEVEN_LABS"]["model_id"]="eleven_monolingual_v1"; //ElevenLabs model to use. Set to eleven_v3 to use V3 enhancers/audio tags.
$TTS["ELEVEN_LABS"]["stability"]="0.75"; //Higher values sound steadier and less varied.
$TTS["ELEVEN_LABS"]["similarity_boost"]="0.75"; //Higher values cling more closely to the selected voice.
$TTS["ELEVEN_LABS"]["style"]=0.0; //Adds extra style exaggeration. Higher values can increase latency.
$TTS["ELEVEN_LABS"]["speed"]=1.0; //Speaking rate. 1.0 is normal speed.
$TTS["ELEVEN_LABS"]["use_speaker_boost"]=true; //Boosts resemblance to the original voice. Ignored by eleven_v3.
$TTS["ELEVEN_LABS"]["apply_text_normalization"]="auto"; //Rewrites numbers, dates, abbreviations, etc. before speech. auto|on|off
$TTS["ELEVEN_LABS"]["apply_language_text_normalization"]=false; //Adds extra language-specific cleanup before synthesis.
$TTS["ELEVEN_LABS"]["v3_audio_tags"]=""; //Optional Eleven v3 prompt tags prefixed to the text, such as [whispers].
$TTS["ELEVEN_LABS"]["API_KEY"]=""; //Optional API key. Prefer using an ElevenLabs API Badge in connectors.
// KOKORO

$TTS["KOKORO"]["endpoint"]='http://127.0.0.1:8880'; //API endpoint.
$TTS["KOKORO"]["voiceid"]='af_bella'; //Voice ID.
$TTS["KOKORO"]["speed"]=1.0; //Speech speed.

//PiperTTS
$TTS["PIPERTTS"]["endpoint"]='http://127.0.0.1:5000'; //piper-tts API endpoint.
$TTS["PIPERTTS"]["voiceid"]='en_US-amy-low'; //Voice ID.
$TTS["PIPERTTS"]["length_scale"]=1.0; //speaking speed; defaults to 1
$TTS["PIPERTTS"]["noise_scale"]=0.0; //speaking variability - default 0.667
$TTS["PIPERTTS"]["noise_w_scale"]=0.0; //phoneme width variability - default 0.8
$TTS["PIPERTTS"]["speaker"]=''; // name of speaker for multi-speaker voices
$TTS["PIPERTTS"]["speaker_id"]=0; //id of speaker for multi-speaker voices; overrides speaker

//Cartesia TTS
$TTS["CARTESIA"]["API_KEY"]=''; //API key.
$TTS["CARTESIA"]["voiceid"]=''; //Voice file name. Works like XTTS voiceid.
$TTS["CARTESIA"]["language"]='en'; //Language (en, fr, de, es, etc.).
$TTS["CARTESIA"]["model_id"]='sonic-3'; //Model (sonic-3, sonic-english, sonic-multilingual).
$TTS["CARTESIA"]["speed"]='normal'; //Speed (slowest, slow, normal, fast, fastest).

//Inworld TTS
$TTS["INWORLD"]["workspace"]=''; //Workspace ID (required for voice cloning). Format: workspaces/{workspace} or just the workspace ID.
$TTS["INWORLD"]["voiceid"]=''; //Voice file name. Works like XTTS voiceid. Voice will be automatically cloned to Inworld when first used.
$TTS["INWORLD"]["fallback_voice_id"]=''; //Optional existing Inworld voice ID used when an NPC sample cannot be cloned.
$TTS["INWORLD"]["language"]='en-US'; //Language code (en-US, zh-CN, ko-KR, ja-JP, ru-RU, it-IT, es-ES, pt-BR, de-DE, fr-FR, ar-SA, pl-PL, nl-NL, hi-IN, he-IL).
$TTS["INWORLD"]["model_id"]='inworld-tts-1'; //Model (inworld-tts-1, inworld-tts-1-max).
$TTS["INWORLD"]["temperature"]=1.1; //Sampling temperature (0-2). Higher values make output more random. Default: 1.1.
$TTS["INWORLD"]["speed"]=1.0; //Speaking rate/speed (0.5-1.5). Default: 1.0.

//[Player TTS]
$TTSFUNCTION_PLAYER="none";
$TTSFUNCTION_PLAYER_VOICE="default_male";
$TTSFUNCTION_PLAYER_VOICE_ID=0; // id for multivoice models
$TTSFUNCTION_PLAYER_LANGUAGE="";

//[Speech-to-Text Service]
$STTFUNCTION="parakeet";

//[Speech-to-Text Endpoints]
//OpenAI Whisper STT
$STT["WHISPER"]["LANG"]="en"; //Language.
$STT["WHISPER"]["API_KEY"]=""; //API Key.
//Azure STT
$STT["AZURE"]["LANG"]="en-US"; //Language.
$STT["AZURE"]["profanity"]="masked"; //Profanity handling filter.
$STT["AZURE"]["API_KEY"]=""; //API key.
$STT["AZURE"]["region"]="eastus"; //Azure Speech resource region.
//Local Whisper STT
$STT["LOCALWHISPER"]["URL"]="http://127.0.0.1:9876/api/v0/transcribe"; //API endpoint.
$STT["LOCALWHISPER"]["FORMFIELD"]="audio_file"; //(audio_file,file) Form field name.

//Deepgram STT
$STT["DEEPGRAM"]["LANG"]="en"; //Language.
$STT["DEEPGRAM"]["MODEL"]="nova-3"; //Model to use.

$STT["PARAKEET"]["LANG"]="en";

//Inworld STT
$STT["INWORLD"]["API_KEY"]=""; //Inworld API key. Can be managed from API Badge as "Inworld".
$STT["INWORLD"]["MODEL_ID"]="groq/whisper-large-v3"; //Provider/model identifier.
$STT["INWORLD"]["LANGUAGE"]="en-US"; //BCP-47 language code, blank for auto-detect.

//[Memory Configuration]
//Memory Settings
$FEATURES["MEMORY_EMBEDDING"]["ENABLED"]=true; //Long term memory embedding.
$FEATURES["MEMORY_EMBEDDING"]["TXTAI_URL"]='http://127.0.0.1:8082'; //Text2Vec service
$FEATURES["MEMORY_EMBEDDING"]["USE_TEXT2VEC"]=true; //NOT FUNCTIONAL CURRENTLY. JUST LEAVE AS IS!

$FEATURES["MEMORY_EMBEDDING"]["MEMORY_TIME_DELAY"]=12; //Time in minutes to delay before using a memory in a prompt.
$FEATURES["MEMORY_EMBEDDING"]["MEMORY_CONTEXT_SIZE"]=1; //Amount of memory records that will be injected into the prompt.
$FEATURES["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"]=true; //Combines individual memory logs into larger ones at the cost of tokens.
$FEATURES["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"]=10; //Time frame used to pack summary data. Each point is about 0.24 in-game hours (10 = 2.4h, 50 = 12h).
$FEATURES["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_MIN_EVENTS"]=5; //Minimum events needed in a packed time bucket to create a global memory summary.
$FEATURES["MEMORY_EMBEDDING"]["INDIVIDUAL_MEMORY_SUMMARY_THRESHOLD"]=3; //How many global summaries involving an NPC are needed before creating one NPC-scoped memory.
$FEATURES["MEMORY_EMBEDDING"]["MEMORY_BIAS_A"]=33; //0-100 - Minimal distance to offer memory.
$FEATURES["MEMORY_EMBEDDING"]["MEMORY_BIAS_B"]=66; //0-100 - Minimal distance to endorse memory.
//Other Options
$FEATURES["MISC"]["ADD_TIME_MARKS"]=false; //Add timestamps to the context logs. Assists with memory recollection.
$FEATURES["MISC"]["TTS_RANDOM_PITCH"]=false; //Adjusting the pitch when generating the voice for this actor will add variation.
$FEATURES["MISC"]["WORLDKNOWLEDGE_INFINIUM"]=true;	//Fallout context information will be added to the prompt. Use for small weight LLMs.
$FEATURES["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"]=false; //Reorders properties in the offered JSON schema.

$WORLDKNOWLEDGE_INFINIUM=true;
$LOCATION_WORLDKNOWLEDGE=true; // Force matching current location and worldspace lore into prompts.

$FEATURES["MISC"]["LIFE_LINK_PLUGIN"]=false; // WIP. Use life link plugin for dynamic profiles

$BORED_EVENT_SERVERSIDE=false;
$RECHAT_ALLOW_ACTIONS=true;
$RECHAT_MODE='random'; // tight = listener-only, conversational = focused back-and-forth, group = rotate around nearby NPCs, random = roll one mode per chain
$ENFORCE_STRICT_RECHAT_RESPONSE=false; // if true, rechat responders must address the previous speaker directly
$RANDOM_NARATION=false;
$RANDOM_NARATION_CHANCE=15;
$RANDOM_NARRATION_COOLDOWN=2;

$WORLDKNOWLEDGE_CUSTOM=false;

