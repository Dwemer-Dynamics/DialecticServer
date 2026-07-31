<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = __DIR__ . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
$bootstrapMetadata = json_decode(trim(strval($_POST['metadata'] ?? '')), true);
$isPortraitRequest = is_array($bootstrapMetadata) &&
    strtolower(trim(strval($bootstrapMetadata['interaction_mode'] ?? ''))) === 'npc_portrait';

function dialecticIttRespond(int $statusCode, bool $ok, array $extra = []): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'schema' => 'dialectic.visual_context.response.v1',
        'request_id' => class_exists('Logger') ? Logger::getRequestId() : '',
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}

try {
    dialecticRuntimeBootstrap($enginePath, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_itt_connector' => !$isPortraitRequest,
        'run_db_updates' => false,
    ]);
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php');
    if (!$isPortraitRequest) {
        require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'itt_service.php');
    }
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php');
    Logger::bootstrapRequestId('itt');
} catch (Throwable $e) {
    dialecticIttRespond(500, false, ['error' => 'PipVision ITT bootstrap failed']);
    exit;
}

if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    dialecticIttRespond(405, false, ['error' => 'Method Not Allowed']);
    exit;
}

$rawMetadata = trim(strval($_POST['metadata'] ?? ''));
$metadata = json_decode($rawMetadata, true);
if (!is_array($metadata) || strval($metadata['schema'] ?? '') !== 'dialectic.visual_context.capture.v1') {
    dialecticIttRespond(400, false, ['error' => 'Invalid PipVision metadata']);
    exit;
}

$captureId = dialecticVisualContextText($metadata['capture_id'] ?? '', 160);
if ($captureId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $captureId)) {
    dialecticIttRespond(400, false, ['error' => 'Invalid PipVision capture ID']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || intval($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    dialecticIttRespond(400, false, ['capture_id' => $captureId, 'error' => 'No screenshot uploaded']);
    exit;
}

$maxBytes = 8 * 1024 * 1024;
$size = intval($file['size'] ?? 0);
$tmpPath = strval($file['tmp_name'] ?? '');
if ($size < 1 || $size > $maxBytes || !is_uploaded_file($tmpPath)) {
    dialecticIttRespond(413, false, ['capture_id' => $captureId, 'error' => 'Screenshot exceeds the 8 MB limit']);
    exit;
}

$imageInfo = @getimagesize($tmpPath);
$width = intval($imageInfo[0] ?? 0);
$height = intval($imageInfo[1] ?? 0);
$mime = strtolower(strval($imageInfo['mime'] ?? ''));
if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192 || ($width * $height) > 32000000) {
    dialecticIttRespond(400, false, ['capture_id' => $captureId, 'error' => 'Invalid screenshot dimensions']);
    exit;
}
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/bmp', 'image/gif'], true)) {
    dialecticIttRespond(415, false, ['capture_id' => $captureId, 'error' => 'Unsupported screenshot format']);
    exit;
}

$rawImage = @file_get_contents($tmpPath);
$image = is_string($rawImage) ? @imagecreatefromstring($rawImage) : false;
if ($image === false) {
    dialecticIttRespond(400, false, ['capture_id' => $captureId, 'error' => 'Screenshot could not be decoded']);
    exit;
}

$interactionMode = strtolower(dialecticVisualContextText($metadata['interaction_mode'] ?? 'store_only', 50));
if ($interactionMode === 'npc_portrait') {
    $subject = is_array($metadata['subject'] ?? null) ? $metadata['subject'] : [];
    $responseActor = is_array($metadata['response_actor'] ?? null) ? $metadata['response_actor'] : [];
    $actorName = dialecticVisualContextText($responseActor['name'] ?? ($subject['name'] ?? ''), 300);
    $actorRefId = dialecticVisualContextText($responseActor['refid'] ?? ($subject['refid'] ?? ''), 32);

    $npcMaster = new NpcMaster();
    $npcData = null;
    foreach (array_unique([$actorRefId, strtoupper($actorRefId), strtolower($actorRefId)]) as $refIdCandidate) {
        if ($refIdCandidate === '') {
            continue;
        }
        $npcData = $npcMaster->getByRefId($refIdCandidate);
        if ($npcData) {
            break;
        }
    }
    if (!$npcData && $actorName !== '') {
        $npcData = $npcMaster->getByName($actorName);
    }
    if (!$npcData) {
        imagedestroy($image);
        dialecticIttRespond(404, false, [
            'capture_id' => $captureId,
            'error' => 'Targeted NPC profile was not found',
        ]);
        exit;
    }

    $galleryRoot = $enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery';
    $profileRoot = $enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'profile';
    if ((!is_dir($galleryRoot) && !@mkdir($galleryRoot, 0775, true)) ||
        (!is_dir($profileRoot) && !@mkdir($profileRoot, 0775, true))) {
        imagedestroy($image);
        dialecticIttRespond(500, false, ['capture_id' => $captureId, 'error' => 'NPC portrait storage is unavailable']);
        exit;
    }

    $phaseStarted = microtime(true);
    $safeCapture = preg_replace('/[^A-Za-z0-9._-]+/', '_', $captureId) ?: ('pv_' . time());
    $galleryFileName = 'PipVisionPortrait_' . gmdate('Ymd_His') . '_' . $safeCapture . '.jpg';
    $galleryPath = $galleryRoot . DIRECTORY_SEPARATOR . $galleryFileName;
    $profilePath = '';
    Logger::phaseStart('itt_portrait', [
        'capture_id' => $captureId,
        'npc' => Logger::summarizePayload(strval($npcData['npc_name'] ?? $actorName), 100),
        'refid' => strval($npcData['refid'] ?? $actorRefId),
        'width' => $width,
        'height' => $height,
    ]);

    try {
        $targetWidth = 512;
        $targetHeight = 768;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $width / $height;
        if ($sourceRatio > $targetRatio) {
            $cropHeight = $height;
            $cropWidth = max(1, intval(round($height * $targetRatio)));
        } else {
            $cropWidth = $width;
            $cropHeight = max(1, intval(round($width / $targetRatio)));
        }
        $sourceX = max(0, intval(($width - $cropWidth) / 2));
        $sourceY = max(0, intval(($height - $cropHeight) / 2));
        $portrait = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($portrait === false) {
            throw new RuntimeException('NPC portrait canvas creation failed');
        }
        if (!imagecopyresampled(
            $portrait,
            $image,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        )) {
            imagedestroy($portrait);
            throw new RuntimeException('NPC portrait crop failed');
        }

        $quality = max(45, min(dialecticGetGeneralSettingInt('PIPVISION_IMAGE_QUALITY', 88), 95));
        $encoded = @imagejpeg($portrait, $galleryPath, $quality);
        imagedestroy($portrait);
        imagedestroy($image);
        $image = false;
        if (!$encoded || !is_file($galleryPath)) {
            throw new RuntimeException('NPC portrait encoding failed');
        }

        $npcName = dialecticVisualContextText($npcData['npc_name'] ?? $actorName, 300);
        $npcRefId = dialecticVisualContextText($npcData['refid'] ?? $actorRefId, 32);
        $profileBase = dialecticVisualContextText($npcData['md5'] ?? '', 64);
        if ($profileBase === '' && $npcName !== '') {
            $profileBase = md5($npcName);
        }
        if ($profileBase === '') {
            $profileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', $npcRefId) ?: '';
        }
        if ($profileBase === '') {
            throw new RuntimeException('NPC portrait filename could not be determined');
        }

        $profilePath = $profileRoot . DIRECTORY_SEPARATOR . $profileBase . '.jpg';
        if (!@copy($galleryPath, $profilePath)) {
            throw new RuntimeException('NPC portrait copy failed');
        }

        $npcMetadata = [];
        if (!empty($npcData['metadata'])) {
            $decodedMetadata = json_decode(strval($npcData['metadata']), true);
            if (is_array($decodedMetadata)) {
                $npcMetadata = $decodedMetadata;
            }
        }
        $portraitRelativePath = 'profile/' . $profileBase . '.jpg';
        $npcMetadata['portrait'] = $portraitRelativePath;
        if ($npcMaster->update(intval($npcData['id'] ?? 0), [
            'metadata' => json_encode($npcMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]) === false) {
            throw new RuntimeException('NPC portrait metadata update failed');
        }

        $recordId = dialecticVisualContextStore([
            'capture_id' => $captureId,
            'subject_type' => 'actor',
            'subject_key' => 'actor:' . strtolower($npcRefId !== '' ? $npcRefId : $npcName),
            'subject_name' => $npcName,
            'baseid' => $subject['baseid'] ?? '',
            'refid' => $npcRefId,
            'cell_id' => $metadata['cell_formid'] ?? '',
            'worldspace_id' => $metadata['worldspace_formid'] ?? '',
            'location_name' => $metadata['location'] ?? '',
            'worldspace_name' => $metadata['worldspace'] ?? '',
            'image_path' => 'data/pictures/gallery/' . $galleryFileName,
            'image_sha256' => hash_file('sha256', $galleryPath) ?: '',
            'description' => '',
            'perspective' => $metadata['perspective'] ?? 'first_person',
            'provider' => '',
            'model' => '',
            'metadata' => $metadata,
        ]);

        Logger::phaseEnd('itt_portrait', [
            'capture_id' => $captureId,
            'npc' => $npcName,
            'refid' => $npcRefId,
            'record_id' => $recordId,
            'portrait' => $portraitRelativePath,
            'elapsed_ms' => intval(round((microtime(true) - $phaseStarted) * 1000)),
        ]);
        dialecticIttRespond(200, true, [
            'capture_id' => $captureId,
            'record_id' => $recordId,
            'npc' => $npcName,
            'refid' => $npcRefId,
            'portrait' => $portraitRelativePath,
        ]);
    } catch (Throwable $e) {
        if ($image !== false) {
            imagedestroy($image);
        }
        @unlink($galleryPath);
        Logger::phaseEnd('itt_portrait', [
            'capture_id' => $captureId,
            'error' => $e->getMessage(),
            'elapsed_ms' => intval(round((microtime(true) - $phaseStarted) * 1000)),
        ], 'error');
        dialecticIttRespond(500, false, ['capture_id' => $captureId, 'error' => $e->getMessage()]);
    }
    exit;
}

$galleryRoot = $enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery';
if (!is_dir($galleryRoot) && !@mkdir($galleryRoot, 0775, true)) {
    imagedestroy($image);
    dialecticIttRespond(500, false, ['capture_id' => $captureId, 'error' => 'PipVision gallery is unavailable']);
    exit;
}

$safeCapture = preg_replace('/[^A-Za-z0-9._-]+/', '_', $captureId) ?: ('pv_' . time());
$fileName = 'PipVision_' . gmdate('Ymd_His') . '_' . $safeCapture . '.jpg';
$outputPath = $galleryRoot . DIRECTORY_SEPARATOR . $fileName;
$quality = max(45, min(dialecticGetGeneralSettingInt('PIPVISION_IMAGE_QUALITY', 88), 95));
$encoded = @imagejpeg($image, $outputPath, $quality);
imagedestroy($image);
if (!$encoded || !is_file($outputPath)) {
    dialecticIttRespond(500, false, ['capture_id' => $captureId, 'error' => 'PipVision screenshot normalization failed']);
    exit;
}

$phaseStarted = microtime(true);
Logger::phaseStart('itt', [
    'capture_id' => $captureId,
    'bytes' => filesize($outputPath) ?: 0,
    'width' => $width,
    'height' => $height,
    'location' => Logger::summarizePayload(strval($metadata['location'] ?? ''), 100),
    'worldspace' => Logger::summarizePayload(strval($metadata['worldspace'] ?? ''), 100),
]);

try {
    $descriptionResult = dialecticIttDescribe($outputPath, $metadata);
    $subject = is_array($metadata['subject'] ?? null) ? $metadata['subject'] : [];
    $subjectType = dialecticVisualContextSubjectType($metadata['visual_type'] ?? ($subject['type'] ?? 'scene'));
    $subjectName = dialecticVisualContextText($subject['name'] ?? '', 300);
    $subjectKey = dialecticVisualContextText($metadata['visual_key'] ?? '', 500);
    if ($subjectKey === '') {
        $subjectKey = $subjectType . ':' . strtolower(strval($subject['refid'] ?? ($subjectName !== '' ? $subjectName : $captureId)));
    }

    $recordId = dialecticVisualContextStore([
        'capture_id' => $captureId,
        'subject_type' => $subjectType,
        'subject_key' => $subjectKey,
        'subject_name' => $subjectName,
        'plugin' => $subject['plugin'] ?? '',
        'baseid' => $subject['baseid'] ?? '',
        'refid' => $subject['refid'] ?? '',
        'cell_id' => $metadata['cell_formid'] ?? '',
        'worldspace_id' => $metadata['worldspace_formid'] ?? '',
        'location_name' => $metadata['location'] ?? '',
        'worldspace_name' => $metadata['worldspace'] ?? '',
        'image_path' => 'data/pictures/gallery/' . $fileName,
        'image_sha256' => hash_file('sha256', $outputPath) ?: '',
        'description' => $descriptionResult['description'],
        'perspective' => $metadata['perspective'] ?? 'first_person',
        'provider' => $descriptionResult['provider'],
        'model' => $descriptionResult['model'],
        'metadata' => $metadata,
    ]);
    if ($recordId < 1) {
        throw new RuntimeException('PipVision could not persist the visual context record');
    }

    Logger::phaseEnd('itt', [
        'capture_id' => $captureId,
        'record_id' => $recordId,
        'provider' => $descriptionResult['provider'],
        'model' => $descriptionResult['model'],
        'description_length' => strlen($descriptionResult['description']),
        'elapsed_ms' => intval(round((microtime(true) - $phaseStarted) * 1000)),
    ]);
    dialecticIttRespond(200, true, [
        'capture_id' => $captureId,
        'record_id' => $recordId,
        'description' => $descriptionResult['description'],
        'provider' => $descriptionResult['provider'],
        'model' => $descriptionResult['model'],
    ]);
} catch (Throwable $e) {
    @unlink($outputPath);
    Logger::phaseEnd('itt', [
        'capture_id' => $captureId,
        'error' => $e->getMessage(),
        'elapsed_ms' => intval(round((microtime(true) - $phaseStarted) * 1000)),
    ], 'error');
    dialecticIttRespond(502, false, ['capture_id' => $captureId, 'error' => $e->getMessage()]);
}
