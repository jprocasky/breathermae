<?php
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -------- ACCESS CONTROL --------
$allowedIps = ['185.164.120.177','98.179.7.195'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIps, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// -------- CONFIG --------
$linesToRead = isset($_GET['lines']) ? max(1, (int)$_GET['lines']) : 50;
$refreshSeconds = isset($_GET['refresh']) ? max(1, (int)$_GET['refresh']) : 5;
$highlightMinutes = isset($_GET['highlight']) ? max(0, (int)$_GET['highlight']) : 3;
$recentOnly = !empty($_GET['recent_only']);

$displayTimezone = new DateTimeZone('America/Chicago');

// -------- LOG FILES --------
$logFiles = [
    'public_html/php_errorlog' => [
        'path' => $_SERVER['DOCUMENT_ROOT'] . '/php_errorlog',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/php_errorlog.history.log',
    ],
    'wp-admin/php_errorlog' => [
        'path' => $_SERVER['DOCUMENT_ROOT'] . '/wp-admin/php_errorlog',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/wp-admin/php_errorlog.history.log',
    ],
    'BM Global Plugin Log' => [
        'path' => $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/breathermae-logs/global.log',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/breathermae-logs/global.history.log',
    ],
];

// -------- ARCHIVE --------
$archiveMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_key'])) {
    $key = $_POST['archive_key'];

    if (isset($logFiles[$key])) {
        $active  = $logFiles[$key]['path'];
        $archive = $logFiles[$key]['archive'];

        if (is_readable($active)) {
            $contents = file_get_contents($active);

            if ($contents) {
                $stamp = "\n\n===== Archived {$key} at " . date('c') . " =====\n";
                file_put_contents($archive, $stamp . $contents, FILE_APPEND | LOCK_EX);
            }

            file_put_contents($active, '');
            $archiveMessage = "Archived & reset: {$key}";
        }
    }
}

// -------- FUNCTIONS --------
function tailFile(string $file, int $lines): array
{
    if (!is_readable($file)) return [];

    $size = filesize($file);
    if (!$size) return [];

    $handle = fopen($file, 'rb');
    $bytes = min($size, 65536);

    fseek($handle, -$bytes, SEEK_END);
    $data = fread($handle, $bytes);
    fclose($handle);

    $linesArray = preg_split("/\r?\n/", $data);
    $linesArray = array_filter($linesArray, 'strlen');

    return array_reverse(array_slice($linesArray, -$lines));
}

function convertUtcTimestampToLocal(string $line, DateTimeZone $tz): string
{
    try {
        // PHP error log
        if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
            $dt = DateTime::createFromFormat('d-M-Y H:i:s', $m[1], new DateTimeZone('UTC'));
        }
        // BM log
        elseif (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1], new DateTimeZone('UTC'));
        } else return $line;

        if (!$dt) return $line;

        $dt->setTimezone($tz);
        return str_replace($m[0], '[' . $dt->format('Y-m-d H:i:s T') . ']', $line);

    } catch (Throwable $e) {
        return $line;
    }
}

function extractUtcTimestamp(string $line): ?DateTime
{
    if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
        return DateTime::createFromFormat('d-M-Y H:i:s', $m[1], new DateTimeZone('UTC'));
    }
    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
        return DateTime::createFromFormat('Y-m-d H:i:s', $m[1], new DateTimeZone('UTC'));
    }
    return null;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Error Log Viewer</title>

<meta http-equiv="refresh" content="<?= $refreshSeconds ?>">

<style>
body { font-family: monospace; background:#0f172a; color:#e5e7eb; padding:16px; }
.log-block { background:#020617; border:1px solid #1e293b; padding:12px; margin-bottom:20px; }
.log-line { white-space:pre-wrap; }
.log-line.recent { color:#67e8f9; background:rgba(56,189,248,0.12); box-shadow: inset 3px 0 0 #38bdf8; }
.controls { position:absolute; top:16px; right:16px; background:#020617; padding:10px; border:1px solid #1e293b; }
button { font-size:11px; padding:4px 6px; margin-bottom:5px; }
</style>

<script>
setInterval(() => location.reload(), <?= $refreshSeconds * 1000 ?>);

function copyLog(btn) {
    const block = btn.closest('.log-block');
    const lines = block.querySelectorAll('.log-line');
    let text = '';
    lines.forEach(l => text += l.innerText + '\n');

    navigator.clipboard.writeText(text);
    btn.innerText = 'Copied!';
    setTimeout(() => btn.innerText = 'Copy Visible', 1200);
}
</script>
</head>
<body>

<h1>Log Viewer (Last <?= $linesToRead ?> Lines)</h1>

<form method="get" class="controls">
    Refresh <input name="refresh" value="<?= $refreshSeconds ?>" size="3"><br>
    Lines <input name="lines" value="<?= $linesToRead ?>" size="3"><br>
    Highlight(min) <input name="highlight" value="<?= $highlightMinutes ?>" size="3"><br>
    <label><input type="checkbox" name="recent_only" value="1" <?= $recentOnly?'checked':'' ?>> Recent Only</label><br>
    <button>Apply</button>
</form>

<?php if ($archiveMessage): ?>
<div><?= htmlspecialchars($archiveMessage) ?></div>
<?php endif; ?>

<?php foreach ($logFiles as $label => $cfg): ?>

<?php $lines = tailFile($cfg['path'], $linesToRead); ?>

<div class="log-block">
<form method="post" onsubmit="return confirm('Archive & reset?');">
    <input type="hidden" name="archive_key" value="<?= htmlspecialchars($label) ?>">
    <button>Archive & Reset</button>
</form>

<button onclick="copyLog(this)">Copy Visible</button>

<strong><?= htmlspecialchars($label) ?></strong><br>

<?php foreach ($lines as $line):

    $dt = extractUtcTimestamp($line);
    $isRecent = false;

    if ($dt && $highlightMinutes > 0) {
        $cutoff = new DateTime('now', new DateTimeZone('UTC'));
        $cutoff->modify("-{$highlightMinutes} minutes");
        if ($dt >= $cutoff) $isRecent = true;
    }

    if ($recentOnly && !$isRecent) continue;

    $line = convertUtcTimestampToLocal($line, $displayTimezone);

?>

<div class="log-line<?= $isRecent ? ' recent' : '' ?>"><?= htmlspecialchars($line) ?></div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</body>
</html>
