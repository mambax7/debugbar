<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar;

use DebugBar\DataCollector\ConfigCollector;
use Xmf\Request;
use XoopsModules\Debugbar\Analysis\BudgetChecker;
use XoopsModules\Debugbar\Analysis\DiagnosticSanitizer;
use XoopsModules\Debugbar\Analysis\QueryAnalyzer;

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/** Final request analysis, persistence, and developer-facing performance warnings. */
final class Profiler
{
    private static ?self $instance = null;
    private bool $finalized = false;
    private string $requestId;
    private ?DiagnosticSanitizer $diagnosticSanitizer = null;

    private function __construct()
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function finalize(DebugbarLogger $logger): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;

        try {
            $debugbar = $logger->getDebugbar();
            $queries = $logger->getQueryLog();
            $budgets = $this->budgets();
            $budgets['nplus1_threshold'] = QueryAnalyzer::normalizeRepeatThreshold((int) ($budgets['nplus1_threshold'] ?? 0));
            $stats = QueryAnalyzer::analyze($queries, $logger->getSlowQueryThreshold(), $budgets['nplus1_threshold']);
            $totalMs = (microtime(true) - $logger->getRequestStart()) * 1000.0;
            $bootMs = $logger->getLifecycleDurationMs('XOOPS Boot');
            $memoryMb = memory_get_peak_usage(true) / 1048576;
            $module = '';
            if (isset($GLOBALS['xoopsModule']) && is_object($GLOBALS['xoopsModule']) && method_exists($GLOBALS['xoopsModule'], 'getVar')) {
                $module = (string) $GLOBALS['xoopsModule']->getVar('dirname', 'n');
            }
            $url = $this->path(Request::getString('REQUEST_URI', '/', 'SERVER'));
            $metrics = ['queries' => $stats['count'], 'query_ms' => $stats['total_ms'], 'boot_ms' => $bootMs, 'total_ms' => $totalMs, 'memory_mb' => $memoryMb, 'payload_kb' => $this->payloadKb(), 'worst_repeat' => $stats['worst_repeat']];
            $verdict = BudgetChecker::check($metrics, $budgets);
            $decodedFlags = BudgetChecker::decodeFlags($verdict['flags']);
            if (is_object($debugbar)) {
                $debugbar->addCollector(new ConfigCollector([
                    'Request ID' => $this->requestId,
                    'URL' => $url,
                    'Module' => $module !== '' ? $module : '(none)',
                    'Fragment' => $this->isFragment() ? 'yes' : 'no',
                    'Total' => sprintf('%.1f ms', $totalMs),
                    'Bootstrap' => sprintf('%.1f ms', $bootMs),
                    'Queries' => (string) $stats['count'],
                    'SQL time' => sprintf('%.1f ms', $stats['total_ms']),
                    'Peak memory' => sprintf('%.1f MB', $memoryMb),
                    'Payload' => sprintf('%.1f KB', $metrics['payload_kb']),
                ], 'Profiler'));
                $debugbar->addCollector(new ConfigCollector([
                    'Method' => Request::getString('REQUEST_METHOD', 'GET', 'SERVER'),
                    'Query parameters' => $this->sanitizer()->sanitize($_GET),
                    'POST parameters' => $this->sanitizer()->sanitize($_POST),
                    'Cookies' => $this->sanitizer()->sanitizeCookies($_COOKIE),
                    'Headers' => $this->safeHeaders(),
                    'Locale' => (string) ($GLOBALS['xoopsConfig']['language'] ?? ''),
                    'Theme' => (string) ($GLOBALS['xoopsConfig']['theme_set'] ?? ''),
                    'User' => isset($GLOBALS['xoopsUser']) && is_object($GLOBALS['xoopsUser']) && method_exists($GLOBALS['xoopsUser'], 'getVar') ? (string) $GLOBALS['xoopsUser']->getVar('uname') : '(anonymous)',
                ], 'Request details'));
                $debugbar->addCollector(new ConfigCollector([
                    'Flags' => $decodedFlags === [] ? 'none' : implode(', ', $decodedFlags),
                    'Findings' => $verdict['findings'] === [] ? ['none'] : $verdict['findings'],
                    'N+1 candidates' => $stats['n_plus_one'] === [] ? ['none'] : $stats['n_plus_one'],
                ], 'Performance'));
            }
            foreach ($verdict['findings'] as $finding) {
                $logger->log(\Psr\Log\LogLevel::WARNING, $finding, ['channel' => 'messages', 'source' => 'Debugbar performance budget']);
            }
            (new ProfileRepository())->insert(['request_id' => $this->requestId, 'created' => time(), 'url' => $url, 'url_hash' => hash('xxh128', $url), 'dirname' => $module, 'is_fragment' => $this->isFragment(), 'is_admin_side' => str_contains($url, '/admin'), 'total_ms' => $totalMs, 'boot_ms' => $bootMs, 'query_count' => $stats['count'], 'query_ms' => $stats['total_ms'], 'slowest_ms' => $stats['slowest_ms'], 'slowest_fp' => $stats['slowest_fp'], 'n_plus_one' => $stats['worst_repeat'], 'peak_mem_kb' => (int) round(memory_get_peak_usage(true) / 1024), 'payload_bytes' => (int) round($metrics['payload_kb'] * 1024), 'flags' => $verdict['flags']], (int) ($budgets['profiles_retention_days'] ?? 7), (int) ($budgets['profiles_max_rows'] ?? 10000));
            (new FlightRecorder())->record($this->requestId, ['request_id' => $this->requestId, 'url' => $url, 'module' => $module, 'metrics' => $metrics, 'flags' => $decodedFlags, 'findings' => $verdict['findings'], 'n_plus_one' => $stats['n_plus_one'], 'slow' => $stats['slow']], $verdict['flags'] !== 0, 30);
            if (is_object($debugbar) && ! headers_sent()) {
                header('Server-Timing: xoops;dur=' . round($totalMs, 1) . ', sql;dur=' . round((float) $stats['total_ms'], 1), false);
            }
        } catch (\Throwable $e) {
            trigger_error('debugbar profiler failed: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** @return array<string, mixed> */
    private function budgets(): array
    {
        $config = [];

        try {
            $config = DebugbarCoreConfig::get();
        } catch (\Throwable) {
        }

        return $config + ['budget_queries' => 30, 'budget_query_ms' => 120, 'budget_boot_ms' => 0, 'budget_total_ms' => 300, 'budget_memory_mb' => 32, 'budget_payload_kb' => 250, 'nplus1_threshold' => 5, 'profiles_retention_days' => 7, 'profiles_max_rows' => 10000];
    }

    private function payloadKb(): float
    {
        return ob_get_level() > 0 ? strlen((string) ob_get_contents()) / 1024 : 0.0;
    }

    private function isFragment(): bool
    {
        return Request::getString('HTTP_X_REQUESTED_WITH', '', 'SERVER') === 'XMLHttpRequest' || Request::getString('HTTP_HX_REQUEST', '', 'SERVER') !== '' || Request::getString('HTTP_X_REQUESTED_FRAGMENT', '', 'SERVER') !== '';
    }

    private function path(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        return substr(is_string($path) && $path !== '' ? $path : '/', 0, 500);
    }

    /** @return array<array-key, mixed> */
    private function safeHeaders(): array
    {
        /** @var array<array-key, mixed> $headers */
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        return $this->sanitizer()->sanitizeHeaders($headers);
    }

    private function sanitizer(): DiagnosticSanitizer
    {
        return $this->diagnosticSanitizer ??= new DiagnosticSanitizer();
    }
}
