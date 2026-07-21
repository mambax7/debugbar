<?php

declare(strict_types=1);

use Xmf\Request;
use XoopsModules\Debugbar\Analysis\LogCatalog;
use XoopsModules\Debugbar\Analysis\MonologLogParser;

require_once __DIR__ . '/admin_header.php';
$adminObject = \Xmf\Module\Admin::getInstance();
xoops_cp_header();
$adminObject->displayNavigation(basename(__FILE__));

$varPath = defined('XOOPS_VAR_PATH') && XOOPS_VAR_PATH !== ''
    ? XOOPS_VAR_PATH
    : XOOPS_ROOT_PATH . '/xoops_data';
$catalog = new LogCatalog($varPath . '/logs', XOOPS_ROOT_PATH . '/log/log.txt');
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$requested = Request::getString('file', '', 'GET');
$cssFile = XOOPS_ROOT_PATH . '/modules/debugbar/assets-custom/admin-logs.css';
$cssVersion = is_file($cssFile) ? (string) filemtime($cssFile) : '1';
echo '<link rel="stylesheet" href="' . $esc(XOOPS_URL . '/modules/debugbar/assets-custom/admin-logs.css?v=' . rawurlencode($cssVersion)) . '">';

echo '<h2>' . $esc(_AM_DEBUGBAR_LOGS_TITLE) . '</h2>';
if ($requested !== '') {
    echo '<p><a href="logs.php">&larr; ' . $esc(_AM_DEBUGBAR_LOGS_BACK) . '</a></p>';
    $contents = $catalog->read($requested);
    if ($contents === null) {
        echo '<p>' . $esc(_AM_DEBUGBAR_LOGS_MISSING) . '</p>';
    } else {
        $isMonolog = $requested !== 'legacy';
        if (! $isMonolog) {
            echo '<p>' . $esc(_AM_DEBUGBAR_LOGS_TAIL_NOTE) . '</p>';
            echo '<pre class="debugbar-log-raw">' . $esc($contents) . '</pre>';
        } else {
            $entries = array_reverse((new MonologLogParser())->parse($contents));
            echo '<section class="debugbar-log-panel"><header class="debugbar-log-panel-header">'
                . '<h3 class="debugbar-log-panel-title">' . $esc(_AM_DEBUGBAR_LOGS_ACTIVITY)
                . ' <span class="debugbar-log-count">' . $esc(sprintf(_AM_DEBUGBAR_LOGS_ENTRY_COUNT, count($entries))) . '</span></h3>'
                . '<span class="debugbar-log-panel-meta">' . $esc(_AM_DEBUGBAR_LOGS_NEWEST_FIRST) . ' &middot; ' . $esc(_AM_DEBUGBAR_LOGS_TAIL_NOTE) . '</span>'
                . '</header><div class="debugbar-log-table-wrap"><table class="debugbar-log-table"><thead><tr>'
                . '<th>' . $esc(_AM_DEBUGBAR_LOGS_TIME) . '</th><th>' . $esc(_AM_DEBUGBAR_LOGS_LEVEL) . '</th>'
                . '<th>' . $esc(_AM_DEBUGBAR_LOGS_DESCRIPTION_COLUMN) . '</th><th>' . $esc(_AM_DEBUGBAR_LOGS_CHANNEL) . '</th>'
                . '<th>' . $esc(_AM_DEBUGBAR_LOGS_LOCATION) . '</th><th>' . $esc(_AM_DEBUGBAR_LOGS_DETAILS) . '</th>'
                . '</tr></thead><tbody>';
            foreach ($entries as $entry) {
                if (! $entry['parsed']) {
                    echo '<tr><td colspan="6"><pre class="debugbar-log-raw">' . $esc($entry['raw']) . '</pre></td></tr>';

                    continue;
                }
                $level = in_array($entry['level'], ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)
                    ? $entry['level'] : 'debug';

                try {
                    $timestamp = (new DateTimeImmutable($entry['timestamp']))
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                        ->format('Y-m-d H:i:s T');
                } catch (Throwable) {
                    $timestamp = $entry['timestamp'];
                }
                $error = isset($entry['context']['errno']) && is_scalar($entry['context']['errno'])
                    ? '#' . (string) $entry['context']['errno'] : '';
                $file = isset($entry['context']['errfile']) && is_scalar($entry['context']['errfile'])
                    ? str_replace('\\', '/', (string) $entry['context']['errfile']) : '';
                $line = isset($entry['context']['errline']) && is_scalar($entry['context']['errline'])
                    ? ':' . (string) $entry['context']['errline'] : '';
                $root = str_replace('\\', '/', XOOPS_ROOT_PATH);
                if ($file !== '' && str_starts_with($file, $root . '/')) {
                    $file = substr($file, strlen($root) + 1);
                }
                $location = $file . $line;
                echo '<tr><td><time class="debugbar-log-time" datetime="' . $esc($entry['timestamp']) . '" title="' . $esc($timestamp) . '">'
                    . '<strong>' . $esc($timestamp) . '</strong></time></td>'
                    . '<td><span class="debugbar-log-level debugbar-log-level--' . $esc($level) . '">' . $esc($level) . '</span></td>'
                    . '<td class="debugbar-log-description">' . $esc($entry['message'])
                    . ($error !== '' ? ' <span class="debugbar-log-error">' . $esc($error) . '</span>' : '') . '</td>'
                    . '<td><span class="debugbar-log-channel">' . $esc($entry['channel']) . '</span></td>'
                    . '<td class="debugbar-log-location">' . $esc($location !== '' ? $location : '—') . '</td><td>';
                if ($entry['context'] !== [] || $entry['extra'] !== []) {
                    $detail = ['context' => $entry['context']];
                    if ($entry['extra'] !== []) {
                        $detail['extra'] = $entry['extra'];
                    }
                    $json = json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    echo '<details class="debugbar-log-details"><summary>' . $esc(_AM_DEBUGBAR_LOGS_CONTEXT) . '</summary>'
                        . '<pre class="debugbar-log-context">' . $esc($json !== false ? $json : '{}') . '</pre></details>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }
    require_once __DIR__ . '/admin_footer.php';

    return;
}

echo '<p>' . $esc(_AM_DEBUGBAR_LOGS_DESCRIPTION) . '</p>';
$files = $catalog->listFiles();
if ($files === []) {
    echo '<p>' . $esc(_AM_DEBUGBAR_LOGS_EMPTY) . '</p>';
} else {
    echo '<table class="outer" style="border-collapse:collapse;width:100%"><thead><tr>'
        . '<th>' . $esc(_AM_DEBUGBAR_LOGS_SOURCE) . '</th><th>' . $esc(_AM_DEBUGBAR_LOGS_FILE) . '</th>'
        . '<th>' . $esc(_AM_DEBUGBAR_LOGS_MODIFIED) . '</th><th>' . $esc(_AM_DEBUGBAR_LOGS_SIZE) . '</th><th></th></tr></thead><tbody>';
    foreach ($files as $index => $file) {
        $url = 'logs.php?' . http_build_query(['file' => $file['file']], '', '&amp;', PHP_QUERY_RFC3986);
        echo '<tr class="' . ($index % 2 === 0 ? 'even' : 'odd') . '"><td>' . $esc($file['source']) . '</td>'
            . '<td>' . $esc($file['file']) . '</td><td>' . $esc(date('Y-m-d H:i:s', $file['modified'])) . '</td>'
            . '<td>' . $esc(number_format($file['size'] / 1024, 1)) . ' KB</td><td><a href="' . $esc($url) . '">' . $esc(_AM_DEBUGBAR_LOGS_VIEW) . '</a></td></tr>';
    }
    echo '</tbody></table>';
}

require_once __DIR__ . '/admin_footer.php';
