<?php
// CDN is opt-in. The hash must match the stylesheet in the deployed application.
$stylesheetPath = '/assets/css/style.css';
$stylesheetBase = rtrim(AppConfig::fromEnvironment()->assetBaseUrl, '/');
$stylesheetUrl = parse_url($stylesheetBase);
$stylesheetHash = $stylesheetBase !== '' ? hash_file('sha256', dirname(__DIR__, 2) . '/public' . $stylesheetPath) : false;
$stylesheetCdn = is_array($stylesheetUrl) && ($stylesheetUrl['scheme'] ?? '') === 'https'
    && !empty($stylesheetUrl['host']) && !isset($stylesheetUrl['user']) && !isset($stylesheetUrl['pass'])
    && !isset($stylesheetUrl['query']) && !isset($stylesheetUrl['fragment'])
    && in_array($stylesheetUrl['path'] ?? '', ['', '/'], true) && $stylesheetHash !== false;
if ($stylesheetCdn):
    $stylesheetRemote = $stylesheetBase . '/assets/css/style.' . substr($stylesheetHash, 0, 16) . '.css';
    $stylesheetIntegrity = 'sha256-' . base64_encode(hex2bin($stylesheetHash));
?>
<link rel="stylesheet" href="<?= htmlspecialchars($stylesheetRemote, ENT_QUOTES, 'UTF-8') ?>" integrity="<?= $stylesheetIntegrity ?>" crossorigin="anonymous" onerror="this.onerror=null;this.removeAttribute('crossorigin');this.href='/assets/css/style.css'">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/style.css">
<?php endif; ?>
