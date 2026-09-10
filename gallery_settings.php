<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

require_once __DIR__ . '/cloud/Gallery/Image/GallerySettings.php';

use SentryIQCloud\Gallery\Image\GallerySettings;

$csrf = sentryiq_csrf_token();
$message = '';
$messageClass = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sentryiq_require_csrf();
    try {
        $webpQuality = filter_input(INPUT_POST, 'webp_quality', FILTER_VALIDATE_INT);
        $thumbnailQuality = filter_input(INPUT_POST, 'thumbnail_quality', FILTER_VALIDATE_INT);
        $thumbnailMaxDimension = filter_input(INPUT_POST, 'thumbnail_max_dimension', FILTER_VALIDATE_INT);
        if ($webpQuality === false || $webpQuality === null || $webpQuality < 1 || $webpQuality > 100) throw new RuntimeException('WebP quality must be between 1 and 100.');
        if ($thumbnailQuality === false || $thumbnailQuality === null || $thumbnailQuality < 1 || $thumbnailQuality > 100) throw new RuntimeException('Thumbnail quality must be between 1 and 100.');
        if ($thumbnailMaxDimension === false || $thumbnailMaxDimension === null || $thumbnailMaxDimension < 100 || $thumbnailMaxDimension > 2000) throw new RuntimeException('Thumbnail size must be between 100 and 2000 pixels.');
        GallerySettings::save($dataDir, [
            'webp_quality' => $webpQuality,
            'thumbnail_quality' => $thumbnailQuality,
            'thumbnail_max_dimension' => $thumbnailMaxDimension,
            'preserve_transparency' => isset($_POST['preserve_transparency']),
        ]);
        $message = 'Gallery settings saved successfully.';
        log_security_event('GALLERY_SETTINGS_UPDATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');
    } catch (Throwable $exception) {
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Gallery settings could not be saved.';
        $messageClass = 'error';
    }
}

$settings = GallerySettings::load($dataDir);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ — Gallery Settings</title>
<link rel="stylesheet" href="pm_style.css">
</head>
<body>
<div class="box">
    <div class="sentryiq-page-header">
        <div class="sentryiq-vault-brand-wrap">
            <img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ">
            <span class="sentryiq-vault-status">Gallery Settings</span>
        </div>
        <div class="sentryiq-mobile-header-actions">
            <form method="POST" class="sentryiq-lock-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="lock_vault" value="1">
                <button type="submit" class="btn btn-primary sentryiq-lock-button">Lock Vault</button>
            </form>
            <div class="vault-mobile-menu-bar">
                <button type="button" class="vault-mobile-menu-toggle" aria-expanded="false" aria-controls="vault-mobile-menu"><span class="vault-mobile-menu-icon" aria-hidden="true">☰</span><span id="vault-mobile-menu-label">Menu</span></button>
            </div>
        </div>
    </div>

    <div id="vault-mobile-menu" class="vault-tabs">
        <button class="tab-btn" type="button" onclick="window.location.href='index.php?pane=view'">📋 Vault</button>
        <button class="tab-btn" type="button" onclick="window.location.href='gallery.php'">🖼️ Gallery</button>
        <button class="tab-btn" type="button" onclick="window.location.href='passkeys.php'">🔑 Passkeys</button>
        <button class="tab-btn" type="button" onclick="window.location.href='system_log.php'">🔐 System Log</button>
        <button class="tab-btn active" type="button" onclick="window.location.href='index.php?pane=settings'">⚙️ System</button>
        <form method="POST" class="vault-menu-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="tab-btn vault-menu-lock-button">🔒 Lock Vault</button></form>
    </div>

    <div class="form-box">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin-bottom:6px;">🖼️ Gallery Settings</h2>
                <p style="margin-top:0;color:#666;">Control how uploaded images are normalized and how Gallery thumbnails are generated.</p>
            </div>
            <a href="index.php?pane=settings" class="btn" style="text-decoration:none;background:#6c757d;color:#fff;">← Back to System</a>
        </div>

        <?php if ($message !== ''): ?><p class="<?php echo htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

        <form method="POST" style="margin-top:20px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="webp_quality">WebP Quality: <strong id="webp-quality-value"><?php echo (int)$settings['webp_quality']; ?></strong></label>
                <input id="webp_quality" type="range" name="webp_quality" min="1" max="100" value="<?php echo (int)$settings['webp_quality']; ?>" style="width:100%;" oninput="document.getElementById('webp-quality-value').textContent=this.value">
                <small style="display:block;margin-top:5px;color:#777;">Higher values retain more image detail and normally produce larger files. 85 is the default.</small>
            </div>

            <div class="form-group">
                <label for="thumbnail_quality">Thumbnail Quality: <strong id="thumbnail-quality-value"><?php echo (int)$settings['thumbnail_quality']; ?></strong></label>
                <input id="thumbnail_quality" type="range" name="thumbnail_quality" min="1" max="100" value="<?php echo (int)$settings['thumbnail_quality']; ?>" style="width:100%;" oninput="document.getElementById('thumbnail-quality-value').textContent=this.value">
                <small style="display:block;margin-top:5px;color:#777;">Controls the WebP quality used for Gallery thumbnails. 80 is the default.</small>
            </div>

            <div class="form-group">
                <label for="thumbnail_max_dimension">Thumbnail Maximum Dimension (px)</label>
                <input id="thumbnail_max_dimension" type="number" name="thumbnail_max_dimension" class="input-field" min="100" max="2000" step="1" value="<?php echo (int)$settings['thumbnail_max_dimension']; ?>" required>
                <small style="display:block;margin-top:5px;color:#777;">The longest side of a generated thumbnail. 600 px is the default.</small>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                    <input type="checkbox" name="preserve_transparency" value="1" <?php echo $settings['preserve_transparency'] ? 'checked' : ''; ?> style="width:18px;height:18px;">
                    <span>Preserve image transparency</span>
                </label>
                <small style="display:block;margin-top:5px;color:#777;">Keeps transparent backgrounds when the source image supports transparency.</small>
            </div>

            <div style="padding:16px;border:1px solid #e9ecef;border-radius:10px;background:#f8f9fa;margin-top:20px;">
                <strong>WebP conversion</strong>
                <p style="margin:6px 0 0;color:#666;font-size:13px;">Gallery uploads are normalized to WebP before storage. The application currently keeps this conversion enabled so duplicate detection and Gallery storage remain consistent.</p>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:20px;">💾 Save Gallery Settings</button>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var toggle=document.querySelector('.vault-mobile-menu-toggle');
    var menu=document.getElementById('vault-mobile-menu');
    if(toggle&&menu){toggle.addEventListener('click',function(){var open=menu.classList.toggle('mobile-open');toggle.setAttribute('aria-expanded',open?'true':'false');});}
});
</script>
</body>
</html>
