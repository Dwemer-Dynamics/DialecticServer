<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
]);
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php');

$isEmbed = strval($_GET['embed'] ?? '') === '1';
$message = '';
$error = '';

function pipVisionGalleryH($value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $action = strval($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            if (!dialecticVisualContextUpdate($id, [
                'description' => $_POST['description'] ?? '',
                'locked' => !empty($_POST['locked']),
                'active' => !empty($_POST['active']),
            ])) {
                throw new RuntimeException('PipVision record update failed');
            }
            $message = 'PipVision context updated.';
        } elseif ($action === 'delete') {
            $record = $GLOBALS['db']->fetchOne('SELECT image_path FROM public.visual_context WHERE id=' . $id);
            if (!dialecticVisualContextDelete($id)) {
                throw new RuntimeException('PipVision record deletion failed');
            }
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, strval($record['image_path'] ?? ''));
            $galleryRoot = realpath($enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery');
            $candidate = realpath($enginePath . $relative);
            if ($galleryRoot && $candidate && str_starts_with($candidate, $galleryRoot . DIRECTORY_SEPARATOR)) {
                @unlink($candidate);
            }
            $message = 'PipVision capture deleted.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$records = dialecticVisualContextList(500);
$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPosition = strpos($scriptPath, '/ui/');
$webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
$webRoot = rtrim($webRoot, '/');

$TITLE = 'PipVision Gallery';
$BODY_CLASS = 'hub-page';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
if (!$isEmbed) include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php');
?>
<link rel="stylesheet" href="css/main.css">
<style>
body{background:#1d1d1d;color:#f5f5f5}.pv-gallery{padding:18px;max-width:1400px;margin:0 auto}.pv-gallery h1{font-size:1.7rem;color:rgb(255,182,65);margin:0 0 4px}.pv-sub{color:#aaa;margin-bottom:16px}.pv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px}.pv-card{background:#292929;border:1px solid #474747;border-radius:6px;overflow:hidden}.pv-card.locked{border-color:rgb(255,182,65)}.pv-image{display:block;width:100%;height:210px;object-fit:cover;background:#111}.pv-body{padding:12px}.pv-title{display:flex;justify-content:space-between;gap:8px;margin-bottom:5px}.pv-meta{font-size:.82rem;color:#aaa;margin-bottom:9px;overflow-wrap:anywhere}.pv-body textarea{width:100%;min-height:105px;box-sizing:border-box;background:#181818;color:#fff;border:1px solid #555;border-radius:4px;padding:8px;resize:vertical}.pv-options,.pv-actions{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin-top:9px}.pv-button{border:1px solid #666;background:#353535;color:#fff;border-radius:4px;padding:7px 10px;cursor:pointer}.pv-button.primary{background:rgb(255,182,65);border-color:rgb(255,182,65);color:#151515}.pv-message{padding:10px;border-radius:4px;margin-bottom:12px;background:#213a29;border:1px solid #3e8050}.pv-error{background:#4a2222;border-color:#9b4949}.pv-empty{padding:30px;text-align:center;background:#292929;border:1px solid #474747;border-radius:6px}
</style>
<main class="pv-gallery">
  <h1>PipVision Gallery</h1><div class="pv-sub">Recent visual context captured from Fallout New Vegas and Tale of Two Wastelands.</div>
  <?php if($message!==''):?><div class="pv-message"><?=pipVisionGalleryH($message)?></div><?php endif;?>
  <?php if($error!==''):?><div class="pv-message pv-error"><?=pipVisionGalleryH($error)?></div><?php endif;?>
  <?php if(!$records):?><div class="pv-empty">No PipVision captures yet. Use the PipVision hotkey in game to capture the current scene.</div><?php endif;?>
  <div class="pv-grid">
    <?php foreach($records as $record): $locked=!empty($record['locked'])&&$record['locked']!=='f'; $active=!empty($record['active'])&&$record['active']!=='f'; $path=ltrim(str_replace('\\','/',strval($record['image_path']??'')),'/'); ?>
    <article class="pv-card <?=$locked?'locked':''?>">
      <a href="<?=$webRoot?>/<?=pipVisionGalleryH($path)?>" target="_blank" rel="noopener"><img class="pv-image" loading="lazy" src="<?=$webRoot?>/<?=pipVisionGalleryH($path)?>" alt="PipVision capture"></a>
      <div class="pv-body"><div class="pv-title"><strong><?=pipVisionGalleryH($record['subject_name']?:ucfirst($record['subject_type']))?></strong><span><?=$locked?'LOCKED':''?></span></div>
      <div class="pv-meta"><?=pipVisionGalleryH($record['worldspace_name'])?><?=!empty($record['worldspace_name'])&&!empty($record['location_name'])?' / ':''?><?=pipVisionGalleryH($record['location_name'])?><br><?=pipVisionGalleryH($record['captured_at'])?> | <?=pipVisionGalleryH($record['provider'])?> / <?=pipVisionGalleryH($record['model'])?></div>
      <form method="post"><input type="hidden" name="id" value="<?=intval($record['id'])?>"><textarea name="description" aria-label="PipVision description"><?=pipVisionGalleryH($record['description'])?></textarea>
      <div class="pv-options"><label><input type="checkbox" name="locked" value="1" <?=$locked?'checked':''?>> Lock as location context</label><label><input type="checkbox" name="active" value="1" <?=$active?'checked':''?>> Include in prompts</label></div>
      <div class="pv-actions"><button class="pv-button primary" type="submit" name="action" value="save">Save</button><button class="pv-button" type="submit" name="action" value="delete" onclick="return confirm('Delete this PipVision capture and its image?');">Delete</button></div></form></div>
    </article>
    <?php endforeach; ?>
  </div>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
