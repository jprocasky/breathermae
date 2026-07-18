<?php
/************************************************************
 * Simple PHP Error Log Viewer + Archive & Reset
 * - Standalone (no WordPress)
 * - IP-restricted
 * - Read-only + safe archive/reset
 ************************************************************/
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// -------- ACCESS CONTROL --------
$allowedIps = [
    '185.164.120.177',
    '98.179.7.195'
];

if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIps, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// -------- USER CONFIG --------
$linesToRead = isset($_GET['lines']) ? max(1, (int)$_GET['lines']) : 10;
$refreshSeconds = isset($_GET['refresh']) ? max(1, (int)$_GET['refresh']) : 10;
$displayTimezone = new DateTimeZone('America/Chicago');
$highlightMinutes = isset($_GET['highlight'])
    ? max(0, (int)$_GET['highlight'])
    : 5; // default: last 5 minutes


// -------- LOG DEFINITIONS --------
$logFiles = [
    'public_html/php_errorlog' => [
        'path'    => $_SERVER['DOCUMENT_ROOT'] . '/php_errorlog',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/php_errorlog.history.log',
    ],
    'wp-admin/php_errorlog' => [
        'path'    => $_SERVER['DOCUMENT_ROOT'] . '/wp-admin/php_errorlog',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/wp-admin/php_errorlog.history.log',
    ],
    'BM Global Plugin Log' => [
        'path'    => $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/breathermae-logs/global.log',
        'archive' => $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/breathermae-logs/global.history.log',
    ],
];

// -------- HANDLE ARCHIVE REQUEST --------
$archiveMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_key'])) {
    $key = $_POST['archive_key'];

    if (isset($logFiles[$key])) {
        $active  = $logFiles[$key]['path'];
        $archive = $logFiles[$key]['archive'];

        if (is_readable($active)) {
            $contents = file_get_contents($active);

            if ($contents !== false && trim($contents) !== '') {
                $stamp = "\n\n===== Archived {$key} at " . date('c') . " =====\n";

                file_put_contents(
                    $archive,
                    $stamp . $contents,
                    FILE_APPEND | LOCK_EX
                );
            }

            // Truncate active log safely
            file_put_contents($active, '');

            $archiveMessage = "✅ Archived & reset: {$key}";
        } else {
            $archiveMessage = "⚠️ Cannot read log: {$key}";
        }
    }
}

// -------- FUNCTIONS --------
// Extract UTC timestamp from a log line (returns DateTime or null)
function extractUtcTimestamp(string $line): ?DateTime
{
    // [21-Apr-2026 23:53:04 UTC]
    if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
        return DateTime::createFromFormat(
            'd-M-Y H:i:s',
            $m[1],
            new DateTimeZone('UTC')
        );
    }

    // [2026-04-26 18:46:47 UTC]
    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
        return DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $m[1],
            new DateTimeZone('UTC')
        );
    }

    return null;
}

/// Convert UTC timestamps in log lines to local timezone
function convertUtcTimestampToLocal(string $line, DateTimeZone $tz): string
{
    try {

        // Pattern 1: PHP error log
        // [21-Apr-2026 23:53:04 UTC]
        if (preg_match(
            '/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/',
            $line,
            $m
        )) {
            $dt = DateTime::createFromFormat(
                'd-M-Y H:i:s',
                $m[1],
                new DateTimeZone('UTC')
            );
        }

        // Pattern 2: BM logs (new normalized format)
        // [2026-04-26 18:46:47 UTC]
        elseif (preg_match(
            '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/',
            $line,
            $m
        )) {
            $dt = DateTime::createFromFormat(
                'Y-m-d H:i:s',
                $m[1],
                new DateTimeZone('UTC')
            );
        }

        else {
            return $line; // No recognizable timestamp
        }

        if (!$dt) {
            return $line;
        }

        $dt->setTimezone($tz);
        $local = $dt->format('Y-m-d H:i:s T');

        return str_replace(
            $m[0],
            '[' . $local . ']',
            $line
        );

    } catch (Throwable $e) {
        return $line;
    }
}

function tailFile(string $file, int $lines): array
{
    $debug = [];

    clearstatcache(true, $file);

    $debug[] = "File: {$file}";
    $debug[] = "exists: " . (file_exists($file) ? 'YES' : 'NO');
    $debug[] = "readable: " . (is_readable($file) ? 'YES' : 'NO');

    if (!is_readable($file)) {
        return ['lines' => [], 'debug' => $debug];
    }

    $size = filesize($file);
    if (!$size) {
        return ['lines' => [], 'debug' => $debug];
    }

    $handle = fopen($file, 'rb');
    $bytesToRead = min($size, 65536);

    fseek($handle, -$bytesToRead, SEEK_END);
    $data = fread($handle, $bytesToRead);
    fclose($handle);

    $linesArray = preg_split("/\r?\n/", $data);
    $linesArray = array_filter($linesArray, 'strlen');

    $tail = array_slice($linesArray, -$lines);
    $tail = array_reverse($tail);

    return [
        'lines' => $tail,
        'debug' => $debug
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Error Log Viewer</title>
<meta http-equiv="refresh" content="<?= $refreshSeconds ?>">

<style>
body { font-family: Consolas, monospace; background:#0f172a; color:#e5e7eb; padding:16px; }
h1 { color:#93c5fd; }
.log-block { background:#020617; border:1px solid #1e293b; border-radius:6px; padding:12px; margin-bottom:28px; }
.log-line { white-space:pre-wrap; font-size:13px; }
.log-path { font-size:12px; color:#94a3b8; margin-bottom:6px; }
.controls {
    position:absolute; top:16px; right:16px;
    background:#020617; border:1px solid #1e293b;
    padding:8px 10px; border-radius:6px; font-size:12px;
}
button { font-size:11px; padding:4px 6px; cursor:pointer; }
.archive-btn { margin-bottom:8px; background:#1e293b; color:#e5e7eb; border:1px solid #334155; }
.notice { margin-bottom:12px; color:#7dd3fc; }
.log-line.recent {
    color: #ffe600; /* cyan-300 */
    background: rgba(214, 238, 0, 0.2);
    /* box-shadow: inset 3px 0 0 #38bdf8; */
}

</style>

<script>
setInterval(() => location.reload(), <?= $refreshSeconds * 1000 ?>);
</script>
</head>

<body>

<h1>PHP Error Logs (Last <?= $linesToRead ?> Lines)</h1>

<form method="get" class="controls">
    Refresh <input type="number" name="refresh" value="<?= $refreshSeconds ?>" min="1" style="width:50px"><br>
    Lines <input type="number" name="lines" value="<?= $linesToRead ?>" min="1" style="width:50px"><br>
    <button>Apply</button>
</form>

<?php if ($archiveMessage): ?>
<div class="notice"><?= htmlspecialchars($archiveMessage) ?></div>
<?php endif; ?>

<?php foreach ($logFiles as $label => $cfg): ?>
<?php $result = tailFile($cfg['path'], $linesToRead); ?>

<div class="log-block">
    <form method="post" onsubmit="return confirm('Archive and reset this log?');">
        <input type="hidden" name="archive_key" value="<?= htmlspecialchars($label) ?>">
        <button class="archive-btn">Archive & Reset Log</button>
    </form>

    <strong><?= htmlspecialchars($label) ?></strong>
    <div class="log-path"><?= htmlspecialchars($cfg['path']) ?></div>

    <?php if (empty($result['lines'])): ?>
        <div class="log-line" style="color:#94a3b8;">No entries found.</div>
    <?php else: ?>
        <?php foreach ($result['lines'] as $line): ?>
            <?php
            $isRecent = false;

            if ($highlightMinutes > 0) {
                $dt = extractUtcTimestamp($line);

                if ($dt) {
                    $cutoff = new DateTime('now', new DateTimeZone('UTC'));
                    $cutoff->modify("-{$highlightMinutes} minutes");

                    if ($dt >= $cutoff) {
                        $isRecent = true;
                    }
                }
            }
            $line = convertUtcTimestampToLocal($line, $displayTimezone);
            ?>
            <div class="log-line<?= $isRecent ? ' recent' : '' ?>"><?= htmlspecialchars(convertUtcTimestampToLocal($line, $displayTimezone)) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endforeach; ?>

</body>
</html>