<?php

declare(strict_types=1);

use Xmf\Request;
use XoopsModules\Debugbar\Analysis\BudgetChecker;
use XoopsModules\Debugbar\Analysis\CachegrindCatalog;
use XoopsModules\Debugbar\Analysis\XdebugStatus;
use XoopsModules\Debugbar\FlightRecorder;
use XoopsModules\Debugbar\ProfileRepository;

require_once __DIR__ . '/admin_header.php';

$adminObject = \Xmf\Module\Admin::getInstance();

$xdebug = XdebugStatus::read();
$cachegrindCatalog = new CachegrindCatalog($xdebug['output_dir']);
$action = Request::getCmd('action', '', 'POST');
if ($action === 'purge_cachegrind') {
    if (! isset($GLOBALS['xoopsSecurity'])
        || ! $GLOBALS['xoopsSecurity'] instanceof \XoopsSecurity
        || ! $GLOBALS['xoopsSecurity']->check(true, false, 'DEBUGBAR_CACHEGRIND')) {
        redirect_header('analytics.php', 3, _AM_DEBUGBAR_AN_CG_BAD_TOKEN);
    }

    $purged = $cachegrindCatalog->purgeOlderThan(30);
    redirect_header('analytics.php', 2, sprintf(_AM_DEBUGBAR_AN_CG_PURGED, $purged));
}

xoops_cp_header();
$adminObject->displayNavigation(basename(__FILE__));

$repository = new ProfileRepository();
$recorder = new FlightRecorder();
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$number = static fn (mixed $value, int $decimals = 1): string => number_format((float) $value, $decimals);

/**
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 */
$renderTable = static function (string $title, array $headers, array $rows) use ($esc): void {
    if ($title !== '') {
        echo '<h2>' . $esc($title) . '</h2>';
    }
    if ($rows === []) {
        echo '<p>' . $esc(_AM_DEBUGBAR_AN_NODATA) . '</p>';

        return;
    }

    echo '<table class="outer" style="border-collapse:collapse;width:100%"><thead><tr>';
    foreach ($headers as $header) {
        echo '<th style="padding:5px;text-align:start">' . $esc($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        echo '<tr class="' . ($index % 2 === 0 ? 'even' : 'odd') . '">';
        foreach ($row as $value) {
            if (is_array($value) && isset($value['html'])) {
                echo '<td style="padding:5px">' . $value['html'] . '</td>';

                continue;
            }
            echo '<td style="padding:5px">' . $esc($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
};

$requestedRecord = Request::getString('record', '', 'GET');
if ($requestedRecord !== '') {
    $record = $recorder->load($requestedRecord);
    echo '<p><a href="analytics.php">&larr; ' . $esc(_AM_DEBUGBAR_AN_BACK) . '</a></p>';
    echo '<h2>' . $esc(sprintf(_AM_DEBUGBAR_AN_RECORD, $record['url'] ?? $requestedRecord)) . '</h2>';
    if ($record === null) {
        echo '<p>' . $esc(_AM_DEBUGBAR_AN_RECORD_MISSING) . '</p>';
    } else {
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo '<pre style="max-height:70vh;overflow:auto;padding:12px;border:1px solid #ccc">' . $esc($json !== false ? $json : '{}') . '</pre>';
    }
    require_once __DIR__ . '/admin_footer.php';

    return;
}

$days = Request::getInt('days', 7, 'GET');
if (! in_array($days, [1, 7, 30], true)) {
    $days = 7;
}

echo '<nav aria-label="Analytics range">';
foreach ([1, 7, 30] as $range) {
    $label = sprintf(_AM_DEBUGBAR_AN_DAYS, $range);
    echo $range === $days
        ? '<strong>' . $esc($label) . '</strong>'
        : '<a href="analytics.php?days=' . $range . '">' . $esc($label) . '</a>';
    echo $range === 30 ? '' : ' | ';
}
echo ' &mdash; ' . $esc(sprintf(_AM_DEBUGBAR_AN_ROWCOUNT, number_format($repository->count()))) . '</nav>';

echo '<h2>' . $esc(_AM_DEBUGBAR_AN_OPCACHE) . '</h2>';
$opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if (! is_array($opcache)) {
    echo '<p>' . $esc(_AM_DEBUGBAR_AN_OPCACHE_UNAVAILABLE) . '</p>';
} else {
    $hits = (int) ($opcache['opcache_statistics']['hits'] ?? 0);
    $misses = (int) ($opcache['opcache_statistics']['misses'] ?? 0);
    $hitRate = $hits + $misses > 0 ? 100 * $hits / ($hits + $misses) : 0;
    $usedMb = (float) ($opcache['memory_usage']['used_memory'] ?? 0) / 1048576;
    $freeMb = (float) ($opcache['memory_usage']['free_memory'] ?? 0) / 1048576;
    $wastedMb = (float) ($opcache['memory_usage']['wasted_memory'] ?? 0) / 1048576;
    echo '<p><strong>' . $esc(_AM_DEBUGBAR_AN_HIT_RATE) . ':</strong> ' . $esc($number($hitRate, 2)) . '% &nbsp; '
        . '<strong>' . $esc(_AM_DEBUGBAR_AN_MEMORY) . ':</strong> ' . $esc($number($usedMb)) . ' MB used / ' . $esc($number($freeMb)) . ' MB free / ' . $esc($number($wastedMb)) . ' MB wasted &nbsp; '
        . '<strong>' . $esc(_AM_DEBUGBAR_AN_CACHED_SCRIPTS) . ':</strong> ' . $esc(number_format((int) ($opcache['opcache_statistics']['num_cached_scripts'] ?? 0))) . ' &nbsp; '
        . '<strong>' . $esc(_AM_DEBUGBAR_AN_RESTARTS) . ':</strong> ' . $esc(number_format((int) ($opcache['opcache_statistics']['oom_restarts'] ?? 0) + (int) ($opcache['opcache_statistics']['hash_restarts'] ?? 0) + (int) ($opcache['opcache_statistics']['manual_restarts'] ?? 0))) . '</p>';
}

$worstRows = [];
foreach ($repository->worstUrls($days) as $row) {
    $worstRows[] = [$row['url'], $row['dirname'] !== '' ? $row['dirname'] : '—', $row['hits'], $number($row['avg_ms']), $number($row['max_ms']), $number($row['avg_queries']), $row['max_nplus1'], $row['violations']];
}
$renderTable(_AM_DEBUGBAR_AN_WORST, [_AM_DEBUGBAR_AN_URL, _AM_DEBUGBAR_AN_MODULE, _AM_DEBUGBAR_AN_HITS, _AM_DEBUGBAR_AN_AVG_MS, _AM_DEBUGBAR_AN_MAX_MS, _AM_DEBUGBAR_AN_AVG_QUERIES, _AM_DEBUGBAR_AN_MAX_NPLUS1, _AM_DEBUGBAR_AN_VIOLATIONS], $worstRows);

$nPlusOneRows = [];
foreach ($repository->nPlusOneLeaders($days) as $row) {
    $nPlusOneRows[] = [$row['url'], $row['dirname'] !== '' ? $row['dirname'] : '—', $row['hits'], $row['max_nplus1'], $number($row['avg_queries']), $row['sample_fp']];
}
$renderTable(_AM_DEBUGBAR_AN_NPLUS1, [_AM_DEBUGBAR_AN_URL, _AM_DEBUGBAR_AN_MODULE, _AM_DEBUGBAR_AN_HITS, _AM_DEBUGBAR_AN_MAX_NPLUS1, _AM_DEBUGBAR_AN_AVG_QUERIES, _AM_DEBUGBAR_AN_SAMPLE_FP], $nPlusOneRows);

$moduleRows = [];
foreach ($repository->moduleAggregates($days) as $row) {
    $moduleRows[] = [$row['dirname'], $row['hits'], $number($row['avg_ms']), $number($row['avg_queries']), $number($row['avg_payload_kb']), $row['fragment_hits'], $row['violations']];
}
$renderTable(_AM_DEBUGBAR_AN_MODULES, [_AM_DEBUGBAR_AN_MODULE, _AM_DEBUGBAR_AN_HITS, _AM_DEBUGBAR_AN_AVG_MS, _AM_DEBUGBAR_AN_AVG_QUERIES, _AM_DEBUGBAR_AN_AVG_PAYLOAD, _AM_DEBUGBAR_AN_FRAGMENTS, _AM_DEBUGBAR_AN_VIOLATIONS], $moduleRows);

$violationRows = [];
foreach ($repository->recentViolations() as $row) {
    $violationRows[] = [date('Y-m-d H:i:s', (int) $row['created']), $row['url'], $row['dirname'] !== '' ? $row['dirname'] : '—', $number($row['total_ms']), $row['query_count'], implode(', ', BudgetChecker::decodeFlags((int) $row['flags']))];
}
$renderTable(_AM_DEBUGBAR_AN_VIOLATIONS_FEED, [_AM_DEBUGBAR_AN_WHEN, _AM_DEBUGBAR_AN_URL, _AM_DEBUGBAR_AN_MODULE, _AM_DEBUGBAR_AN_TOTAL_MS, _AM_DEBUGBAR_AN_QUERIES, _AM_DEBUGBAR_AN_FLAGS], $violationRows);

$flightRows = [];
foreach ($recorder->listRecords() as $record) {
    $link = 'analytics.php?' . http_build_query(['record' => $record['file']], '', '&amp;', PHP_QUERY_RFC3986);
    $flightRows[] = [
        date('Y-m-d H:i:s', $record['created']),
        $record['violation'] ? _AM_DEBUGBAR_AN_VIOLATION : _AM_DEBUGBAR_AN_OK,
        $record['request_id'],
        $number($record['bytes'] / 1024) . ' KB',
        ['html' => '<a href="' . $esc($link) . '">' . $esc(_AM_DEBUGBAR_AN_VIEW) . '</a>'],
    ];
}
$renderTable(_AM_DEBUGBAR_AN_FLIGHT, [_AM_DEBUGBAR_AN_WHEN, _AM_DEBUGBAR_AN_STATUS, _AM_DEBUGBAR_AN_REQUEST, _AM_DEBUGBAR_AN_SIZE, ''], $flightRows);

echo '<h2>' . $esc(_AM_DEBUGBAR_AN_CG_SECTION) . '</h2>';
$xdebugModes = implode(', ', $xdebug['modes']);
echo '<p><strong>' . $esc(_AM_DEBUGBAR_AN_CG_EXTENSION) . ':</strong> ' . $esc($xdebug['loaded'] ? _AM_DEBUGBAR_AN_CG_LOADED : _AM_DEBUGBAR_AN_CG_NOT_LOADED) . ' &nbsp; '
    . '<strong>' . $esc(_AM_DEBUGBAR_AN_CG_MODES) . ':</strong> ' . $esc($xdebugModes !== '' ? $xdebugModes : '—') . ' &nbsp; '
    . '<strong>' . $esc(_AM_DEBUGBAR_AN_CG_START) . ':</strong> ' . $esc($xdebug['start_with_request'] !== '' ? $xdebug['start_with_request'] : '—') . '<br>'
    . '<strong>' . $esc(_AM_DEBUGBAR_AN_CG_DIR) . ':</strong> ' . $esc($xdebug['output_dir'] !== '' ? $xdebug['output_dir'] : '—') . ' (' . $esc($xdebug['directory_state']) . ') &nbsp; '
    . '<strong>' . $esc(_AM_DEBUGBAR_AN_CG_ZLIB) . ':</strong> ' . $esc($xdebug['zlib'] ? _AM_DEBUGBAR_AN_CG_LOADED : _AM_DEBUGBAR_AN_CG_NOT_LOADED) . '</p>';

$cachegrindRows = [];
foreach ($cachegrindCatalog->listFiles() as $file) {
    $cachegrindRows[] = [date('Y-m-d H:i:s', $file['modified']), $file['file'], $number($file['size'] / 1024) . ' KB'];
}
$renderTable('', [_AM_DEBUGBAR_AN_WHEN, _AM_DEBUGBAR_AN_CG_FILE, _AM_DEBUGBAR_AN_SIZE], $cachegrindRows);
$purgeConfirmation = json_encode(_AM_DEBUGBAR_AN_CG_PURGE_CONFIRM, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
echo '<form method="post" action="analytics.php" style="margin-block:12px">'
    . $GLOBALS['xoopsSecurity']->getTokenHTML('DEBUGBAR_CACHEGRIND')
    . '<input type="hidden" name="action" value="purge_cachegrind">'
    . '<button class="formButton" type="submit" onclick="return confirm(' . $esc($purgeConfirmation !== false ? $purgeConfirmation : '""') . ')">'
    . $esc(_AM_DEBUGBAR_AN_CG_PURGE)
    . '</button></form>';
echo '<p>' . $esc(_AM_DEBUGBAR_AN_CG_ALTERNATIVES) . '</p>';

require_once __DIR__ . '/admin_footer.php';
