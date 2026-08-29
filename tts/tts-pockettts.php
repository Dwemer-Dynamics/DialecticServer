<?php


if (!function_exists('insertNoise')) {
function insertNoise($inputString, $noiseArray) {
    // Split the string into words
    $words = explode(' ', $inputString);

	if (!is_array($words))
		return $inputString;
    // Shuffle the noise array to ensure randomness
    shuffle($noiseArray);

    // Calculate the number of insert positions (between words)
    $numInsertPositions = count($words) - 1;

    // Ensure we don't have more noises than insert positions
    $numNoises = min(count($noiseArray), $numInsertPositions);

    // Get a random subset of the insert positions
    $insertPositions = array_rand(array_fill(0, $numInsertPositions, 1), $numNoises);

    // Ensure $insertPositions is an array even if there's only one position
    if (!is_array($insertPositions)) {
        $insertPositions = array($insertPositions);
    }

    // Sort insert positions in descending order to avoid shifting positions
    rsort($insertPositions);

    // Insert the noise elements at the chosen positions
    foreach ($insertPositions as $index => $pos) {
        array_splice($words, $pos + 1, 0, $noiseArray[$index]);
		break; //Comment  to more noise
    }

    // Join the words back into a string
    return implode(' ', $words);
}
}

if (!function_exists('normalize_endpoint_url')) {
    function normalize_endpoint_url($url) {
        // Remove trailing slashes
        $url = rtrim($url, '/');
        return $url;
    }
}

function pockettts_scalar_setting($key, $default = '') {
	if (!isset($GLOBALS["TTS"]["POCKETTTS"][$key]) || !is_scalar($GLOBALS["TTS"]["POCKETTTS"][$key])) {
		return $default;
	}

	$value = trim(strval($GLOBALS["TTS"]["POCKETTTS"][$key]));
	return $value !== '' ? $value : $default;
}

function pockettts_is_audio_cpp($endpoint = null) {
	$endpoint = $endpoint === null ? pockettts_scalar_setting('endpoint', '') : normalize_endpoint_url($endpoint);
	if (preg_match('/\:8086(?:\/|$)/', $endpoint) || strpos($endpoint, '/v1/audio/speech') !== false) {
		return true;
	}

	return false;
}

function pockettts_backend_voice_payload($voice) {
	$cleanName = basename((string)$voice, '.wav');
	if ($cleanName !== '') {
		$paths = [
			dirname(__FILE__) . '/../data/voices/' . $cleanName . '.wav',
			'/home/dwemer/pocket-tts/speakers/' . $cleanName . '.wav',
			'/home/dwemer/audio.cpp/speakers/' . $cleanName . '.wav',
		];
		foreach ($paths as $path) {
			if (is_readable($path)) {
				return ['voice_ref' => $path];
			}
		}
	}

	return ['voice' => 'alba'];
}

// Builds a PocketTTS request for either the Python or audio.cpp API.
function pockettts_build_request($endpoint, $text, $voice, $language) {
	$endpoint = normalize_endpoint_url($endpoint);
	if (pockettts_is_audio_cpp($endpoint)) {
		$data = [
			'model' => pockettts_scalar_setting('model', 'pocket-tts'),
			'input' => $text,
			'language' => $language ?: 'en',
			'response_format' => 'wav',
		];
		$data = array_merge($data, pockettts_backend_voice_payload($voice));
		$url = substr($endpoint, -16) === '/v1/audio/speech'
			? $endpoint
			: $endpoint . '/v1/audio/speech';
		return ['url' => $url, 'data' => $data];
	}

	$pocketSpeaker = $voice;
	if (is_string($pocketSpeaker) && preg_match('/\.wav$/i', $pocketSpeaker) && is_file($pocketSpeaker)) {
		$pocketSpeaker = pathinfo($pocketSpeaker, PATHINFO_FILENAME);
	}
	return [
		'url' => $endpoint . '/tts_to_audio',
		'data' => [
			'text' => $text,
			'speaker_wav' => $pocketSpeaker,
			'language' => $language ?: 'en',
		],
	];
}

function pockettts_post_request(array $request) {
	$options = [
		'http' => [
			'header' => "Content-type: application/json\r\nAccept: audio/wav\r\n",
			'method' => 'POST',
			'content' => json_encode($request['data']),
		],
	];
	$context = stream_context_create($options);
	$response = @file_get_contents($request['url'], false, $context);
	$responseHeaders = isset($http_response_header) && is_array($http_response_header)
		? $http_response_header
		: [];
	$httpCode = 0;
	if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})(?:\s|$)/', $responseHeaders[0], $matches)) {
		$httpCode = intval($matches[1]);
	}
	return [
		'response' => $response,
		'http_code' => $httpCode,
		'headers' => $responseHeaders,
		'options' => $options,
	];
}

function pockettts_probe_json($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => ['Accept: application/json'],
		CURLOPT_CONNECTTIMEOUT_MS => 350,
		CURLOPT_TIMEOUT_MS => 1000,
	]);
	$response = curl_exec($ch);
	$httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	curl_close($ch);
	return [
		'ok' => is_string($response) && $httpCode >= 200 && $httpCode < 300,
		'decoded' => is_string($response) ? json_decode($response, true) : null,
	];
}

function pockettts_detect_endpoint_mode($endpoint) {
	$endpoint = normalize_endpoint_url($endpoint);
	$endpoint = preg_replace('#/(?:v1/audio/speech|tts_to_audio)/?$#', '', $endpoint);
	$health = pockettts_probe_json($endpoint . '/health');
	$models = $health['ok'] ? pockettts_probe_json($endpoint . '/v1/models') : ['ok' => false, 'decoded' => null];
	if ($health['ok'] && $models['ok']) {
		foreach (($models['decoded']['data'] ?? []) as $model) {
			$modelId = strtolower(trim(strval($model['id'] ?? '')));
			$family = strtolower(trim(strval($model['family'] ?? '')));
			if ($modelId === 'pocket-tts' || $family === 'pocket_tts') {
				return 'audio_cpp';
			}
		}
	}

	$providerInfo = pockettts_probe_json($endpoint . '/provider_info');
	$provider = strtolower(trim(strval($providerInfo['decoded']['provider'] ?? '')));
	if ($providerInfo['ok'] && in_array($provider, ['pockettts', 'pocket_tts', 'pocket-tts'], true)) {
		return 'standard';
	}

	$openApi = pockettts_probe_json($endpoint . '/openapi.json');
	if (!$openApi['ok'] || !is_array($openApi['decoded'])) {
		return '';
	}
	$paths = array_keys(is_array($openApi['decoded']['paths'] ?? null) ? $openApi['decoded']['paths'] : []);
	if (in_array('/languages', $paths, true) || in_array('/get_models_list', $paths, true)) {
		return '';
	}
	if (in_array('/tts_to_audio_form', $paths, true)
		|| (in_array('/tts_to_audio', $paths, true) && in_array('/voices/{voice_id}', $paths, true))) {
		return 'standard';
	}
	return '';
}

function pockettts_should_try_fallback($endpoint, $httpCode) {
	$httpCode = intval($httpCode);
	return $httpCode === 0
		|| (in_array($httpCode, [404, 405], true) && pockettts_detect_endpoint_mode($endpoint) === '');
}

// Finds a compatible PocketTTS service on the same host after a stale known port fails.
function pockettts_find_fallback_endpoint($configuredEndpoint) {
	$normalized = normalize_endpoint_url($configuredEndpoint);
	$parts = parse_url($normalized);
	$configuredPort = intval($parts['port'] ?? 0);
	if (!in_array($configuredPort, [8020, 8024, 8086], true) || empty($parts['host'])) {
		return null;
	}
	$scheme = strtolower(strval($parts['scheme'] ?? 'http'));
	if (!in_array($scheme, ['http', 'https'], true)) {
		return null;
	}
	$host = strval($parts['host']);
	if (strpos($host, ':') !== false && $host[0] !== '[') {
		$host = '[' . $host . ']';
	}
	$auth = '';
	if (isset($parts['user'])) {
		$auth = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
	}
	$path = preg_replace(
		'#/(?:v1/audio/speech|tts_to_audio)/?$#',
		'',
		strval($parts['path'] ?? '')
	);
	$path = $path === '/' ? '' : rtrim($path, '/');

	foreach ([8086, 8024, 8020] as $port) {
		if ($port === $configuredPort) {
			continue;
		}
		$candidate = $scheme . '://' . $auth . $host . ':' . $port . $path;
		$mode = pockettts_detect_endpoint_mode($candidate);
		if ($mode === '') {
			continue;
		}
		if ($mode === 'audio_cpp' && !pockettts_is_audio_cpp($candidate)) {
			$candidate .= '/v1/audio/speech';
		}
		return $candidate;
	}
	return null;
}

function pockettts_settings($settings,$resetAfter=false) {
	if (pockettts_is_audio_cpp()) {
		return;
	}

	$url = normalize_endpoint_url($GLOBALS["TTS"]["POCKETTTS"]["endpoint"]).'/set_tts_settings';
	$data = json_decode('{
		"stream_chunk_size": 20,
		"temperature": 0.9,
		"speed": 1,
		"length_penalty": 1,
		"repetition_penalty": 5,
		"top_p": 0.85,
		"top_k": 50,
		"enable_text_splitting": true
		}',true);
	
	$finalData=array_merge($data,$settings);
	
	if ($resetAfter)
		$GLOBALS["TTS"]["POCKETTTS"]["RESET"]=true;

	$options = array(
		'http' => array(
			'header' => "Content-type: application/json\r\n" .
						"Accept: application/json\r\n",
			'method' => 'POST',
			'content' => json_encode($finalData)
		)
	);
	$context = stream_context_create($options);
	$result = file_get_contents($url, false, $context);
	
	if ($result === FALSE) {
		// Handle error
		Logger::error("Error occurred. ".__FILE__." ".__LINE__." ".__FUNCTION__);
	} else {
		;//ok
	}
}

// convert numbers into Japanese kanji
if (!function_exists('num2kan_decimal')) {
function num2kan_decimal($instr) {
    // Check if the input is exactly 0. Return katakana zero in that case.
    if ($instr === '0') {
        return "\u{30BC}\u{30ED}";
    }

    static $kantbl1 = array(0=>'', 1=>"\u{4E00}", 2=>"\u{4E8C}", 3=>"\u{4E09}", 4=>"\u{56DB}", 5=>"\u{4E94}", 6=>"\u{516D}", 7=>"\u{4E03}", 8=>"\u{516B}", 9=>"\u{4E5D}", '.'=>"\u{FF0E}", '-'=>"\u{FF0D}");
    static $kantbl2 = array(0=>'', 1=>"\u{5341}", 2=>"\u{767E}", 3=>"\u{5343}");
    static $kantbl3 = array(0=>'', 1=>"\u{4E07}", 2=>"\u{5104}", 3=>"\u{5146}", 4=>"\u{4EAC}");

	$outstr = '';
	$len = strlen($instr);
	$m = (int)($len / 4);
	//repeat for each grouping of numbers (single digits, ten thousands, etc)
	for ($i = 0; $i <= $m; $i++) {
		$s2 = '';
		//repeat for each grouping of numbers inside a larger grouping (single digits, tens, hundreds, thousands)
		for ($j = 0; $j < 4; $j++) {
			$pos = $len - $i * 4 - $j - 1;
			if ($pos >= 0) {
				$ch  = substr($instr, $pos, 1);
				if ($ch == ',') continue;       //ignore commas
				$ch1 = isset($kantbl1[$ch]) ? $kantbl1[$ch] : '';
				$ch2 = isset($kantbl2[$j])  ? $kantbl2[$j]  : '';
                // handle case when leading one is present (10 should be ten and not one-ten)
				if ($ch1 != '') {
                    if ($ch1 == "\u{4E00}" && $ch2 != '') $s2 = $ch2 . $s2;
					else                                $s2 = $ch1 . $ch2 . $s2;
				}
			}
		}
		if ($s2 != '')  $outstr = $s2 . $kantbl3[$i] . $outstr;
	}

	return $outstr;
}
}


$GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash) {

		//pockettts_settings([]); //Check this
		
		if (isset($GLOBALS["AVOID_TTS_CACHE"]) && $GLOBALS["AVOID_TTS_CACHE"]===false )
			if (file_exists(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
		
		
		
		$newString=$textString;
		
	    $starTime = microtime(true);

		// Request headers
		$headers = array(
			'Accept: audio/wav',
			'Content-Type: application/json'
		);
		
		$lang=isset($GLOBALS["TTS"]["FORCED_LANG_DEV"])?$GLOBALS["TTS"]["FORCED_LANG_DEV"]:$GLOBALS["TTS"]["POCKETTTS"]["language"];
		
		
		if ((isset($GLOBALS["LLM_LANG"]))&&(isset($GLOBALS["LANG_LLM_XTTS"]))&&$GLOBALS["LANG_LLM_XTTS"]) {
			$lang=$GLOBALS["LLM_LANG"];

		}
		
		if (isset($GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]))
        	$lang=$GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"];

		if (empty($lang))
			$lang=$GLOBALS["TTS"]["POCKETTTS"]["language"];

		// Sanitize language code - remove any extra characters from LLM parsing
		// Valid XTTS language codes
		$validLangs = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'tr', 'ru', 'nl', 'cs', 'ar', 'zh-cn', 'ja', 'hu', 'ko', 'hi'];
		$lang = preg_replace('/[^a-z\-]/i', '', strtolower(trim($lang ?? '')));
		if (!in_array($lang, $validLangs)) {
			Logger::warn("Invalid TTS language code '{$lang}', defaulting to 'en'");
			$lang = 'en';
		}

		// xtts has trouble reading numbers when lang is Japanese
		// PATCH it by converting numbers into kanji, which it can read
		if ($lang == 'ja') {
			$callback=function ($matches) {
				return num2kan_decimal($matches[0]);
			};
			// remove commas between digits
			$newString = preg_replace('/(?<=\d),(?=\d)/', '', $newString);
			// replace numbers with kanji
			$newString=preg_replace_callback('/\d+/', $callback, $newString);
		}
	
		// Hook for connector-specific text modifiers.
		if (isset($GLOBALS["HOOKS"]) && isset($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"]) && is_array($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"])) {
			foreach ($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"] as $hook) {
				Logger::info("Calling hook.".__FILE__." ".__LINE__." ".__FUNCTION__);
				$newString=call_user_func($hook,$newString);
	
			}
		}
		$voice = $GLOBALS["PATCH_OVERRIDE_VOICE"]
			?? $GLOBALS["TTS"]["FORCED_VOICE_DEV"]
			?? ($GLOBALS["TTS"]["POCKETTTS"]["voiceid"] ?? '');

		if (empty($voice))
			$voice = $GLOBALS["TTS"]["POCKETTTS"]["voiceid"] ?? '';

		$endpoint = normalize_endpoint_url($GLOBALS["TTS"]["POCKETTTS"]["endpoint"] ?? '');
		$request = pockettts_build_request($endpoint, $newString, $voice, $lang);
		$requestResult = pockettts_post_request($request);
		$response = $requestResult['response'];
		$options = $requestResult['options'];
		$http_response_header = $requestResult['headers'];

		if ($response === false && pockettts_should_try_fallback($endpoint, $requestResult['http_code'])) {
			$fallbackEndpoint = pockettts_find_fallback_endpoint($endpoint);
			if ($fallbackEndpoint !== null) {
				$endpoint = $fallbackEndpoint;
				$logEndpoint = preg_replace('#//[^/@]+@#', '//', $endpoint);
				Logger::warn("PocketTTS endpoint unavailable; using compatible endpoint {$logEndpoint}");
				$request = pockettts_build_request($endpoint, $newString, $voice, $lang);
				$requestResult = pockettts_post_request($request);
				$response = $requestResult['response'];
				$options = $requestResult['options'];
				$http_response_header = $requestResult['headers'];
			}
		}

		if ($response === FALSE) {
			// Handle error
			Logger::error("Error occurred. ".__FILE__." ".__LINE__." ".__FUNCTION__);
			
			// Lets try to use standard scheme:
			$codename = str_replace(" ", "_", mb_strtolower($GLOBALS["DIALECTIC_NAME"], 'UTF-8'));
			$codename = str_replace("'", "+", $codename);
			$codename=preg_replace('/[^a-zA-Z0-9_+]/u', '', $codename);
			
			$request = pockettts_build_request($endpoint, $newString, $codename, $lang);
			$requestResult = pockettts_post_request($request);
			$response = $requestResult['response'];
			$options = $requestResult['options'];
			$http_response_header = $requestResult['headers'];


		}


		if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
			$GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
			$FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';
			
		} else {
			$FFMPEG_FILTER='-filter:a "adelay=150|150"';
		}
		
		if (isset($GLOBALS["TTS"]["POCKETTTS"]["RESET"]) && $GLOBALS["TTS"]["POCKETTTS"]["RESET"]) {
			pockettts_settings([]);
		}

		// Handle the response
		if ($response !== false ) {
			// Handle the successful response
			$size=strlen($response);
			$oname=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . "_o.wav";
			$fname=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
			file_put_contents($oname, $response); // Save the audio response to a file
			$startTimeTrans = microtime(true);
			//shell_exec("ffmpeg -y -i $oname  -af \"adelay=150|150,silenceremove=start_periods=1:start_silence=0.1:start_threshold=-25dB,areverse,silenceremove=start_periods=1:start_silence=0.1:start_threshold=-40dB,areverse,speechnorm=e=3:r=0.0001:l=1:p=0.75\" $fname 2>/dev/null >/dev/null");
			$ffmpegCandidates = array_filter([
				getenv('FFMPEG_PATH') ?: '',
				'C:\\Program Files\\ShareX\\ffmpeg.exe',
				'ffmpeg',
			]);
			$ffmpegPath = 'ffmpeg';
			foreach ($ffmpegCandidates as $candidate) {
				if ($candidate === 'ffmpeg' || file_exists($candidate)) {
					$ffmpegPath = $candidate;
					break;
				}
			}
			$nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
			$command = escapeshellarg($ffmpegPath) . " -y -i " . escapeshellarg($oname) . " $FFMPEG_FILTER " . escapeshellarg($fname) . " >$nullDevice 2>$nullDevice";
			shell_exec($command);
			if (!file_exists($fname) || filesize($fname) < 44) {
				Logger::error("PocketTTS ffmpeg conversion failed: {$command}");
			}
			//error_log("ffmpeg -y -i $oname  $FFMPEG_FILTER $fname ".__FILE__." ".__LINE__." ".__FUNCTION__);
			$endTimeTrans = microtime(true)-$startTimeTrans;
			
			$textString.=PHP_EOL.print_r($options,true);
			$textString.=PHP_EOL.print_r($http_response_header,true);
			
            file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n$FFMPEG_FILTER\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
			$GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in pockettts call";

			if (isset($GLOBALS["DEVELOP_STORE_AUDIO_FOR_TRANING"]) && $GLOBALS["DEVELOP_STORE_AUDIO_FOR_TRANING"]) {
				$rootPath=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" ;
				$tfolder=$rootPath."/$voice";
				@mkdir($tfolder);
				copy($fname,$tfolder."/".basename($fname));

			}
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
		} else {
			$textString.=PHP_EOL.print_r($options,true);
			$textString.=PHP_EOL.print_r(isset($http_response_header) ? $http_response_header : 'No HTTP response headers available',true);
			file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
            return false;
			
		}

};

/*
$GLOBALS["TTS"]["POCKETTTS"]["endpoint"]='http://localhost:8024';
$GLOBALS["TTS"]["POCKETTTS"]["voiceid"]='svenja';
$GLOBALS["TTS"]["POCKETTTS"]["language"]='en';

$textTosay="Hello fellows...this is a new text to speech connector";

echo tts($textTosay,'',$textTosay).PHP_EOL;
*/



