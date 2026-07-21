<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar;

/**
 * DebugBar Logger for XOOPS 2.7.0
 *
 * Collects log information and presents to PHP DebugBar for display.
 * Records information about database queries, blocks, execution time, and various logs.
 *
 * Ported from XOOPS 2.6.0 modules/debugbar/class/debugbarlogger.php
 * Adapted for: maximebf/debugbar v1.x API, PSR-3 v1, no namespaces, XOOPS 2.5 preload system.
 *
 * @copyright       (c) 2000-2026 XOOPS Project (https://xoops.org)
 * @license             GNU GPL 2 (https://www.gnu.org/licenses/gpl-2.0.html)
 * @author              Richard Griffith <richard@geekwright.com>
 * @author              trabis <lusopoemas@gmail.com>
 * @package             debugbar
 * @since               1.0
 */

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

use DebugBar\DataCollector\ConfigCollector;
use DebugBar\DataCollector\ExceptionsCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\TimeDataCollector;
use DebugBar\JavascriptRenderer;
use DebugBar\StandardDebugBar;
use Psr\Log\LogLevel;
use Xmf\Request;
use XoopsModules\Debugbar\Analysis\DiagnosticSanitizer;

/**
 * DebugbarLogger — collects XOOPS debug data and renders via PHP DebugBar.
 *
 * Registers itself with XoopsLogger::addLogger() so it receives all
 * dispatched log entries (queries, blocks, errors, deprecations, extras).
 */
class DebugbarLogger
{
    /**
     * @var StandardDebugBar|false
     */
    private StandardDebugBar|false $debugbar = false;

    /**
     * @var JavascriptRenderer|false
     */
    private JavascriptRenderer|false $renderer = false;

    /**
     * @var bool Whether the debugbar is activated
     */
    private bool $activated = false;

    /**
     * @var bool Quiet mode (suppress output for AJAX)
     */
    private bool $quietmode = false;

    /**
     * @var bool Whether CSS/JS assets have been added to the theme
     */
    private bool $assetsAdded = false;

    /**
     * @var array<string, int> Query tracking for duplicate detection: sql => count
     */
    private array $queryMap = [];

    /**
     * @var int Total query count
     */
    private int $queryCount = 0;

    /**
     * @var int Duplicate query count
     */
    private int $duplicateCount = 0;

    /**
     * @var float Slow query threshold in seconds (default: 0.05 = 50ms)
     */
    private float $slowQueryThreshold = 0.05;

    /**
     * @var bool Whether to show the included files tab
     */
    private bool $showIncludedFiles = false;

    /**
     * @var int Query logging mode: 0 = all queries, 1 = slow & errors only
     */
    private int $queryLogMode = 1;

    /** @var array<int,array<string,mixed>> Compact query log for profiling. */
    private array $queryLog = [];

    private const QUERY_LOG_CAP = 2000;
    private const QUERY_SQL_CAP = 4000;

    /** @var float Request start timestamp used by the request summary. */
    private float $requestStart;

    /** @var float Slow request threshold in seconds. */
    private float $slowRequestThreshold = 1.0;

    /** @var int Slow memory threshold in bytes, zero disables it. */
    private int $memoryThreshold = 0;

    /** @var array<string, array{reads:int,writes:int,deletes:int,hits:int,misses:int}> */
    private array $cacheStats = [];

    /** @var array<string, float> */
    private array $lifecycleStarts = [];

    /** @var array<string, float> */
    private array $lifecycleMeasures = [];

    /** @var string CSRF token for on-demand query EXPLAIN requests. */
    private string $explainToken = '';

    /** Show the opt-in one-shot Xdebug profiler control in the toolbar. */
    private bool $profileButtonEnabled = false;

    /** @var array<string, array<string, mixed>> */
    private array $tags = [];

    private ?DiagnosticSanitizer $diagnosticSanitizer = null;

    /**
     * Constructor — registers this logger with XoopsLogger composite.
     */
    public function __construct()
    {
        $this->requestStart = microtime(true);
        $xoopsLogger = self::xoopsLogger();
        $xoopsLogger->addLogger($this);
    }

    /**
     * Singleton accessor.
     *
     * @return DebugbarLogger
     */
    public static function getInstance(): self
    {
        static $instance = null;
        if (! $instance instanceof self) {
            $instance = new self();
        }

        return $instance;
    }

    /**
     * Get the underlying DebugBar instance.
     *
     * @return StandardDebugBar|false
     */
    public function getDebugbar(): StandardDebugBar|false
    {
        return $this->debugbar;
    }

    /**
     * Get the JavaScript renderer.
     *
     * @return JavascriptRenderer|false
     */
    public function getRenderer(): JavascriptRenderer|false
    {
        return $this->renderer;
    }

    /**
     * Disable the debugbar.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->activated = false;
    }

    /**
     * Enable the debugbar — creates StandardDebugBar and adds custom collectors.
     *
     * @return void
     */
    public function enable(): void
    {
        if ($this->debugbar === false) {
            if (! class_exists('DebugBar\StandardDebugBar')) {
                return;
            }

            try {
                $this->debugbar = new StandardDebugBar();
                $renderer = $this->debugbar->getJavascriptRenderer();
                $this->renderer = $renderer;
                $renderer->setUseDistFiles(false);

                // Add custom collectors for XOOPS channels
                $this->debugbar->addCollector(new MessagesCollector('Deprecated'));
                $this->debugbar->addCollector(new MessagesCollector('Blocks'));
                $this->debugbar->addCollector(new MessagesCollector('Extra'));
                $this->debugbar->addCollector(new MessagesCollector('Queries'));
                $this->debugbar->addCollector(new MessagesCollector('Cache'));
                $this->debugbar->addCollector(new MessagesCollector('HTTP'));
                $this->debugbar->addCollector(new MessagesCollector('Mail'));

                // Preserve source context for diagnostic messages.
                foreach (['messages', 'Deprecated'] as $collectorName) {
                    if ($this->debugbar->hasCollector($collectorName)) {
                        $collector = $this->debugbar->getCollector($collectorName);
                        if ($collector instanceof MessagesCollector) {
                            $collector->collectFileTrace(false);
                        }
                    }
                }

                // v1.x: disable jQuery (already loaded by XOOPS) and noConflict wrapping
                $renderer->disableVendor('jquery');
                if (method_exists($renderer, 'setEnableJqueryNoConflict')) {
                    $renderer->setEnableJqueryNoConflict(false);
                }

                // Set the base path and URL for debugbar assets
                $assetsDir = dirname(__DIR__) . '/assets';
                if (is_dir($assetsDir)) {
                    $renderer->setBasePath($assetsDir);
                    $renderer->setBaseUrl($this->moduleUrl() . '/assets');
                }
            } catch (\Throwable $e) {
                $this->debugbar = false;

                return;
            }
        }

        $this->activated = true;
    }

    /**
     * Report enabled status.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->activated;
    }

    /**
     * Suppress output (for AJAX requests).
     *
     * @return void
     */
    public function quiet(): void
    {
        $this->quietmode = true;
    }

    /**
     * Inject DebugBar CSS/JS assets into the XOOPS theme.
     *
     * Called from preload at core.header.addmeta when $xoTheme is available.
     * Assets added here appear in the <head> via <{$xoops_module_header}>.
     * As a fallback, renderDebugBar() also outputs assets inline if needed.
     *
     * @return void
     */
    public function addToTheme(): void
    {
        if (! $this->activated || $this->assetsAdded || ! $this->debugbar instanceof StandardDebugBar) {
            return;
        }

        $theme = $GLOBALS['xoTheme'] ?? null;
        if (! $theme instanceof \xos_opal_Theme || ! $this->renderer instanceof JavascriptRenderer) {
            return;
        }

        $renderer = $this->renderer;
        $renderer->setIncludeVendors(true);

        // Current php-debugbar returns a structured asset map. Asking for URL
        // paths avoids ever exposing filesystem paths to the rendered page.
        $assets = $renderer->getAssets(JavascriptRenderer::RELATIVE_URL);
        $cssAssets = $assets['css'];
        $jsAssets = $assets['js'];

        // Exclude jQuery (already loaded by XOOPS)
        $filterFn = static function (string $filename): bool {
            return false === strpos(str_replace('\\', '/', $filename), '/vendor/jquery/');
        };

        $cssAssets = array_filter($cssAssets, $filterFn);
        $jsAssets = array_filter($jsAssets, $filterFn);

        foreach ($cssAssets as $css) {
            if ($css !== '') {
                $theme->addStylesheet($css);
            }
        }
        foreach ($jsAssets as $js) {
            if ($js !== '') {
                $theme->addScript($js);
            }
        }

        // Add XOOPS custom settings widget (provides settings gear icon, themes, position)
        $xoopsAssetsUrl = $this->moduleUrl() . '/assets';
        $theme->addStylesheet($xoopsAssetsUrl . '/xoops-debugbar-settings.css');
        $theme->addScript($xoopsAssetsUrl . '/xoops-debugbar-settings.js');

        $this->assetsAdded = true;
    }

    /**
     * Start a timer.
     *
     * @param string      $name  name of the timer
     * @param string|null $label optional label
     * @return void
     */
    public function startTime(string $name = 'XOOPS', ?string $label = null): void
    {
        $this->lifecycleStarts[$name] = microtime(true);
        if ($this->activated && $this->debugbar instanceof StandardDebugBar) {
            try {
                $collector = $this->debugbar->getCollector('time');
                if ($collector instanceof TimeDataCollector) {
                    $collector->startMeasure($name, $label);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Stop a timer.
     *
     * @param string $name name of the timer
     * @return void
     */
    public function stopTime(string $name = 'XOOPS'): void
    {
        if (isset($this->lifecycleStarts[$name])) {
            $this->recordLifecycle($name, microtime(true) - $this->lifecycleStarts[$name]);
            unset($this->lifecycleStarts[$name]);
        }
        if ($this->activated && $this->debugbar instanceof StandardDebugBar) {
            try {
                $collector = $this->debugbar->getCollector('time');
                if ($collector instanceof TimeDataCollector) {
                    $collector->stopMeasure($name);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Log an exception to the exceptions collector.
     *
     * @param \Exception|\Throwable $e
     * @return void
     */
    public function addException(\Throwable $e): void
    {
        if ($this->activated && $this->debugbar instanceof StandardDebugBar) {
            try {
                // v1.x uses addException(); v3.3 uses addThrowable()
                $collector = $this->debugbar->getCollector('exceptions');
                if ($collector instanceof ExceptionsCollector) {
                    $collector->addThrowable($e);
                }
            } catch (\Throwable $ex) {
                // ignore
            }
        }
    }

    /**
     * Dump Smarty template variables into a ConfigCollector.
     *
     * @return void
     */
    public function addSmarty(): void
    {
        if (! $this->activated || ! $this->debugbar instanceof StandardDebugBar) {
            return;
        }
        $template = $GLOBALS['xoopsTpl'] ?? null;
        if (! $template instanceof \XoopsTpl) {
            return;
        }

        $data = $template->getTemplateVars();
        $data = $this->sanitizer()->sanitize($data);

        $helper = Helper::getInstance();
        $helper->loadLanguage('main');

        // Normalize values for display
        foreach ($data as $k => $v) {
            if ($v === '') {
                $data[$k] = _MD_DEBUGBAR_EMPTY_STRING;
            } elseif ($v === null) {
                $data[$k] = _MD_DEBUGBAR_NULL;
            } elseif ($v === true) {
                $data[$k] = _MD_DEBUGBAR_BOOL_TRUE;
            } elseif ($v === false) {
                $data[$k] = _MD_DEBUGBAR_BOOL_FALSE;
            }
        }
        ksort($data, SORT_NATURAL | SORT_FLAG_CASE);

        if (class_exists('DebugBar\DataCollector\ConfigCollector')) {
            $this->debugbar->addCollector(
                new \DebugBar\DataCollector\ConfigCollector($data, 'Smarty')
            );
        }
    }

    /**
     * Enable or disable the included files tab.
     *
     * @param bool $show
     * @return void
     */
    public function setShowIncludedFiles(bool $show): void
    {
        $this->showIncludedFiles = $show;
    }

    /**
     * Set the slow query threshold in seconds.
     *
     * @param float $seconds threshold in seconds (e.g. 0.05 for 50ms)
     * @return void
     */
    public function setSlowQueryThreshold(float $seconds): void
    {
        $this->slowQueryThreshold = $seconds;
    }

    /**
     * Set the query logging mode.
     *
     * @param int $mode 0 = all queries, 1 = slow & errors only
     * @return void
     */
    public function setQueryLogMode(int $mode): void
    {
        $this->queryLogMode = $mode;
    }

    public function setSlowRequestThreshold(float $seconds): void
    {
        if ($seconds > 0) {
            $this->slowRequestThreshold = $seconds;
        }
    }

    public function setMemoryThreshold(int $bytes): void
    {
        $this->memoryThreshold = max(0, $bytes);
    }

    /** Enable the administrator-only toolbar control configured by the module preference. */
    public function setProfileButtonEnabled(bool $enabled): void
    {
        $this->profileButtonEnabled = $enabled;
    }

    /** @return array<int,array<string,mixed>> */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function getRequestStart(): float
    {
        return $this->requestStart;
    }

    /** Return a completed lifecycle duration in milliseconds. */
    public function getLifecycleDurationMs(string $name): float
    {
        return isset($this->lifecycleMeasures[$name]) ? $this->lifecycleMeasures[$name] * 1000.0 : 0.0;
    }

    /** Create the EXPLAIN token while the authenticated request session is open. */
    public function prepareExplainToken(): void
    {
        if (! $this->activated || ! isset($GLOBALS['xoopsSecurity']) || ! is_object($GLOBALS['xoopsSecurity'])) {
            return;
        }

        try {
            // This method runs during authenticated bootstrap, before normal
            // page output. Reopen a closed session here, not during footer
            // rendering, so the token is persisted reliably.
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            // Use a stateless signed token for this read-only admin action.
            // XOOPS session tokens can be lost when another bootstrap phase
            // closes or regenerates the session before footer rendering.
            $this->explainToken = $this->buildExplainToken();
        } catch (\Throwable $e) {
            $this->explainToken = '';
        }
    }

    public function isValidExplainToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $hour = (int) floor(time() / 3600);
        foreach ([$hour, $hour - 1] as $slot) {
            $signature = $this->explainSignature($slot);
            if ($signature !== '' && hash_equals($signature, $token)) {
                return true;
            }
        }

        return false;
    }

    private function buildExplainToken(): string
    {
        return $this->explainSignature((int) floor(time() / 3600));
    }

    private function explainSignature(int $slot): string
    {
        $uid = 0;
        $user = $GLOBALS['xoopsUser'] ?? null;
        if ($user instanceof \XoopsUser) {
            $uid = (int) $user->getVar('uid');
        }
        $identity = $uid . '|' . session_id() . '|' . Request::getString('HTTP_USER_AGENT', '', 'SERVER');
        $secret = (new ExplainSecretStore())->load();
        if ($secret === null) {
            return '';
        }

        return hash_hmac('sha256', $identity . '|' . $slot, $secret);
    }

    /** Record an XOOPS cache operation for the Cache collector. */
    public function recordCache(string $operation, string $key, ?bool $hit = null, float $duration = 0.0, string $backend = ''): void
    {
        $backend = $backend !== '' ? $backend : 'default';
        $this->cacheStats[$backend] ??= ['reads' => 0, 'writes' => 0, 'deletes' => 0, 'hits' => 0, 'misses' => 0];
        if (isset($this->cacheStats[$backend][$operation . 's'])) {
            $this->cacheStats[$backend][$operation . 's']++;
        }
        if ($operation === 'read' && $hit !== null) {
            $this->cacheStats[$backend][$hit ? 'hits' : 'misses']++;
        }
        $this->recordMessage('Cache', sprintf('%s %s%s', strtoupper($operation), $key, $duration > 0 ? sprintf(' (%.2fms)', $duration * 1000) : ''), $hit === false ? LogLevel::WARNING : LogLevel::DEBUG);
    }

    /** Record an outbound HTTP request from a module or HTTP adapter. */
    /** @param array<string, mixed> $request */
    public function recordHttp(array $request): void
    {
        $request = $this->redact($request);
        $this->recordMessage('HTTP', sprintf('%s %s [%s]', $request['method'] ?? 'GET', $request['url'] ?? '', $request['status'] ?? '?'), LogLevel::INFO, $request);
    }

    /** Record a mail delivery attempt without storing message bodies. */
    /** @param array<string, mixed> $mail */
    public function recordMail(array $mail): void
    {
        unset($mail['body'], $mail['html']);
        $mail = $this->redact($mail);
        $mailSucceeded = (bool) ($mail['success'] ?? false);
        $this->recordMessage('Mail', sprintf('%s → %s', $mail['subject'] ?? '(no subject)', $mail['to'] ?? ''), $mailSucceeded ? LogLevel::INFO : LogLevel::ERROR, $mail);
    }

    /** Add a searchable tag to the current request profile. */
    public function tag(string $name, mixed $value = true): void
    {
        $this->tags[$name] = ['value' => $value];
    }

    private function recordLifecycle(string $name, float $duration): void
    {
        $this->lifecycleMeasures[$name] = $duration;
    }

    /** @param array<string, mixed> $context */
    private function recordMessage(string $channel, string $message, string $level, array $context = []): void
    {
        if (! $this->activated || ! $this->debugbar instanceof StandardDebugBar || ! $this->debugbar->hasCollector($channel)) {
            return;
        }
        $collector = $this->debugbar->getCollector($channel);
        if ($collector instanceof MessagesCollector) {
            $collector->log($level, $message, $context);
        }
    }

    /** Add the request-level collectors after all lifecycle events have fired. */
    private function addRuntimeCollectors(): void
    {
        if (! $this->activated || ! $this->debugbar instanceof StandardDebugBar) {
            return;
        }

        $duration = microtime(true) - $this->requestStart;
        $peakMemory = memory_get_peak_usage(true);
        $request = $this->requestContext();
        $request['request_id'] = $this->requestId();
        $status = http_response_code();
        $request['status'] = is_int($status) ? $status : 200;
        $request['duration'] = sprintf('%.2f ms', $duration * 1000);
        $request['peak_memory'] = $this->formatBytes($peakMemory);
        $request['included_files'] = count(get_included_files());
        $request['queries'] = $this->queryCount;
        $request['duplicate_queries'] = $this->duplicateCount;
        $request['content_type'] = $this->headerValue('Content-Type');
        $request['compression'] = $this->compressionDescription();
        $request['cache_headers'] = $this->cacheHeaders();
        $request['curl'] = $this->curlCommand();
        $request['slow_request_threshold'] = sprintf('%.2f ms', $this->slowRequestThreshold * 1000);
        if ($this->memoryThreshold > 0) {
            $request['memory_threshold'] = $this->formatBytes($this->memoryThreshold);
        }

        $this->debugbar->addCollector(new ConfigCollector($request, 'Request Summary'));

        if ($this->lifecycleMeasures !== []) {
            $lifecycle = [];
            foreach ($this->lifecycleMeasures as $name => $measure) {
                $lifecycle[$name] = sprintf('%.2f ms', $measure * 1000);
            }
            arsort($this->lifecycleMeasures);
            $slowest = key($this->lifecycleMeasures);
            $lifecycle['slowest'] = is_string($slowest) ? $slowest : '';
            $this->debugbar->addCollector(new ConfigCollector($lifecycle, 'Lifecycle'));
        }

        if ($this->cacheStats !== []) {
            $cache = [];
            foreach ($this->cacheStats as $backend => $stats) {
                $cache[$backend] = sprintf(
                    'reads %d, writes %d, deletes %d, hits %d, misses %d',
                    $stats['reads'],
                    $stats['writes'],
                    $stats['deletes'],
                    $stats['hits'],
                    $stats['misses']
                );
            }
            $this->debugbar->addCollector(new ConfigCollector($cache, 'Cache summary'));
        }

        if ($this->tags !== []) {
            $tagData = [];
            foreach ($this->tags as $name => $value) {
                $tagData[$name] = $value['value'] ?? $value;
            }
            $this->debugbar->addCollector(new ConfigCollector($tagData, 'Tags'));
        }

        $health = [
            'request' => $duration >= $this->slowRequestThreshold ? 'SLOW' : 'OK',
            'memory' => $this->memoryThreshold > 0 && $peakMemory >= $this->memoryThreshold ? 'OVER BUDGET' : 'OK',
            'queries' => $this->queryCount . ' total, ' . $this->duplicateCount . ' duplicate',
            'messages' => $this->messageCount(),
        ];
        $this->debugbar->addCollector(new ConfigCollector($health, 'Health'));

        if ($duration >= $this->slowRequestThreshold) {
            $this->log(LogLevel::WARNING, sprintf('Slow request: %.2f seconds', $duration), [
                'channel' => 'messages',
                'source' => 'Debugbar performance budget',
            ]);
        }
        if ($this->memoryThreshold > 0 && $peakMemory >= $this->memoryThreshold) {
            $this->log(LogLevel::WARNING, sprintf('Memory budget exceeded: %s', $this->formatBytes($peakMemory)), [
                'channel' => 'messages',
                'source' => 'Debugbar memory budget',
            ]);
        }
    }

    private function messageCount(): int
    {
        if (! $this->debugbar instanceof StandardDebugBar || ! $this->debugbar->hasCollector('messages')) {
            return 0;
        }

        try {
            $collector = $this->debugbar->getCollector('messages');
            if (! $collector instanceof MessagesCollector) {
                return 0;
            }

            return count($collector->collect()['messages'] ?? []);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function requestId(): string
    {
        $id = Request::getString('HTTP_X_REQUEST_ID', '', 'SERVER');
        if ($id !== '') {
            return $id;
        }
        static $generated = '';
        if ($generated === '') {
            try {
                $generated = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                $generated = uniqid('', true);
            }
        }

        return $generated;
    }

    private function headerValue(string $name): string
    {
        foreach (headers_list() as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return '';
    }

    private function compressionDescription(): string
    {
        if (function_exists('ob_list_handlers')) {
            $handlers = ob_list_handlers();
            if ($handlers !== []) {
                return implode(', ', $handlers);
            }
        }

        $compression = ini_get('zlib.output_compression');
        if ($compression === false || $compression === '' || $compression === '0') {
            return 'none';
        }

        return $compression;
    }

    private function cacheHeaders(): string
    {
        $headers = [];
        foreach (headers_list() as $header) {
            if (preg_match('/^(cache-control|etag|expires|last-modified|vary):/i', $header) === 1) {
                $headers[] = $header;
            }
        }

        return implode('; ', $headers);
    }

    private function curlCommand(): string
    {
        $https = Request::getString('HTTPS', '', 'SERVER');
        $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $host = Request::getString('HTTP_HOST', 'localhost', 'SERVER');
        $uri = Request::getString('REQUEST_URI', '/', 'SERVER');
        $method = Request::getString('REQUEST_METHOD', 'GET', 'SERVER');
        $url = $this->sanitizer()->sanitizeUrl($scheme . '://' . $host . $uri);

        return 'curl -X ' . escapeshellarg($method) . ' ' . escapeshellarg($url);
    }

    /**
     * Keep request metadata useful while preventing accidental secret leakage.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        return $this->sanitizer()->sanitize($data);
    }

    private function sanitizer(): DiagnosticSanitizer
    {
        return $this->diagnosticSanitizer ??= new DiagnosticSanitizer();
    }

    /**
     * Add included files list to a ConfigCollector tab.
     *
     * @return void
     */
    public function addIncludedFiles(): void
    {
        if (! $this->activated || ! $this->debugbar instanceof StandardDebugBar) {
            return;
        }

        $files = get_included_files();
        $data = [];
        $rootPath = str_replace('\\', '/', XOOPS_ROOT_PATH);

        foreach ($files as $i => $file) {
            // Show paths relative to XOOPS_ROOT_PATH for readability
            $file = str_replace('\\', '/', $file);
            if (strpos($file, $rootPath) === 0) {
                $display = substr($file, strlen($rootPath));
            } else {
                $display = $file;
            }
            $data[(string) ($i + 1)] = $display;
        }

        if (class_exists('DebugBar\DataCollector\ConfigCollector')) {
            $this->debugbar->addCollector(
                new ConfigCollector($data, 'Files (' . count($files) . ')')
            );
        }
    }

    /**
     * Stack data before a redirect (preserve debug info across redirects).
     *
     * @return void
     */
    public function stackData(): void
    {
        if ($this->activated && $this->debugbar instanceof StandardDebugBar) {
            $this->debugbar->stackData();
            $this->activated = false;
        }
    }

    /**
     * Final render — called at core.footer.end to output the debugbar.
     * This replaces 2.6's core.session.shutdown event.
     *
     * @return void
     */
    public function renderDebugBar(): void
    {
        $debugbar = $this->debugbar;
        $renderer = $this->renderer;
        if (! $this->activated || ! $debugbar instanceof StandardDebugBar || ! $renderer instanceof JavascriptRenderer) {
            return;
        }

        // Add final extra info
        $this->log(LogLevel::INFO, PHP_VERSION, ['channel' => 'Extra', 'name' => _MD_DEBUGBAR_PHP_VERSION]);
        $this->log(LogLevel::INFO, (string) count(get_included_files()), ['channel' => 'Extra', 'name' => _MD_DEBUGBAR_INCLUDED_FILES]);

        // Add database info if available
        try {
            $xoopsDB = \XoopsDatabaseFactory::getDatabaseConnection();
            $this->log(LogLevel::INFO, $xoopsDB->getServerVersion(), [
                'channel' => 'Extra',
                'name' => sprintf(_MD_DEBUGBAR_DB_VERSION, 'MySQL'),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        // Add query summary to Extra
        if ($this->queryCount > 0) {
            $querySummary = sprintf(_MD_DEBUGBAR_QUERY_SUMMARY, $this->queryCount);
            if ($this->duplicateCount > 0) {
                $querySummary .= sprintf(_MD_DEBUGBAR_QUERY_DUPLICATES, $this->duplicateCount);
            }
            $this->log(LogLevel::INFO, $querySummary, [
                'channel' => 'Extra',
                'name' => _MD_DEBUGBAR_DATABASE_QUERIES,
            ]);
        }

        // Add memory usage
        $this->log(LogLevel::INFO, sprintf(_MD_DEBUGBAR_BYTES, memory_get_usage()), [
            'channel' => 'Extra',
            'name' => _MD_DEBUGBAR_MEMORY_USAGE,
        ]);

        // Add included files tab (configurable)
        if ($this->showIncludedFiles) {
            $this->addIncludedFiles();
        }

        // Build request-level summaries only after the complete request lifecycle
        // has finished, so timings and response headers are final.
        \XoopsModules\Debugbar\Profiler::getInstance()->finalize($this);
        $this->addRuntimeCollectors();

        if (! $this->quietmode) {
            $isAjax = Request::getHeader('X-Requested-With') === 'XMLHttpRequest';
            $output = '';

            if ($isAjax) {
                // AJAX: add dataset without new toolbar initialization
                $output = $renderer->render(false);
            } else {
                // Full page: output CSS/JS assets + initialization + data
                // Always render assets inline here for theme-independence.
                // This works across ALL themes without requiring <{$xoops_module_header}>.
                $renderer->setIncludeVendors(true);
                $output = $renderer->renderHead();

                // Load XOOPS custom settings CSS
                $xoopsAssetsUrl = $this->moduleUrl() . '/assets';
                $output .= '<link rel="stylesheet" type="text/css" href="'
                    . $this->escapeAttribute($xoopsAssetsUrl . '/xoops-debugbar-settings.css') . '">' . "\n";

                // Render debugbar initialization and data
                $output .= $renderer->render();

                // Load the settings widget JS as an external script (cacheable by browser)
                $output .= '<script type="text/javascript" src="'
                    . $this->escapeAttribute($xoopsAssetsUrl . '/xoops-debugbar-settings.js') . '"></script>' . "\n";
                $explainToken = $this->explainToken;
                $output .= '<script type="text/javascript" src="'
                    . $this->escapeAttribute($xoopsAssetsUrl . '/frontend.js') . '" data-explain-url="'
                    . $this->escapeAttribute($this->moduleUrl() . '/explain.php') . '" data-explain-token="'
                    . $this->escapeAttribute($explainToken) . '" data-profile-button="'
                    . ($this->profileButtonEnabled ? '1' : '0') . '" data-profile-trigger="1" data-profile-label="'
                    . $this->escapeAttribute(defined('_MD_DEBUGBAR_PROFILE_REQUEST') ? _MD_DEBUGBAR_PROFILE_REQUEST : 'Profile this request')
                    . '" data-profile-loading-label="'
                    . $this->escapeAttribute(defined('_MD_DEBUGBAR_PROFILE_REQUEST_LOADING') ? _MD_DEBUGBAR_PROFILE_REQUEST_LOADING : 'Profiling…')
                    . '"></script>' . "\n";
            }
            $this->writeOutput($output);
        } else {
            $debugbar->sendDataInHeaders();
        }
    }

    /**
     * PSR-3 v1 compatible log method (untyped for broad compat).
     *
     * Routes messages to the appropriate DebugBar collector based on
     * the 'channel' key in the context array.
     *
     * @param mixed  $level   PSR-3 log level
     * @param string $message log message
     * @param array<string, mixed> $context context array, may include 'channel' key
     * @return void
     */
    public function log(mixed $level, string $message, array $context = []): void
    {
        if (! $this->activated || ! $this->debugbar instanceof StandardDebugBar) {
            return;
        }

        $channel = 'messages';
        $msg = $message;

        // Route to appropriate collector based on channel
        if (isset($context['channel'])) {
            $chan = is_scalar($context['channel']) ? strtolower((string) $context['channel']) : 'messages';
            switch ($chan) {
                case 'blocks':
                    $channel = 'Blocks';
                    $msg = $message . ': ';
                    if ((bool) ($context['cached'] ?? false)) {
                        $msg .= sprintf(_MD_DEBUGBAR_CACHED, (int) ($context['cachetime'] ?? 0));
                    } else {
                        $msg .= _MD_DEBUGBAR_NOT_CACHED;
                    }

                    break;
                case 'deprecated':
                    $channel = 'Deprecated';
                    $msg = $message;

                    break;
                case 'extra':
                    $channel = 'Extra';
                    $name = isset($context['name']) && is_scalar($context['name'])
                        ? (string) $context['name']
                        : '';
                    $msg = $name !== '' ? ($name . ': ' . $message) : $message;

                    break;
                case 'queries':
                    $channel = 'Queries';
                    $context['is_query'] = true;
                    $queryTime = is_numeric($context['query_time'] ?? null) ? (float) $context['query_time'] : 0.0;
                    $qt = $queryTime > 0 ? sprintf('%0.6f', $queryTime) : '';
                    $elapsed = microtime(true) - $this->requestStart;
                    if ($elapsed > 0 && $queryTime > 0) {
                        $context['request_percent'] = sprintf('%.1f%% of elapsed request time', ($queryTime / $elapsed) * 100);
                    }

                    // Track duplicates
                    $sqlKey = trim($message);
                    $this->queryCount++;
                    if (count($this->queryLog) < self::QUERY_LOG_CAP) {
                        $this->queryLog[] = [
                            'sql' => substr($sqlKey, 0, self::QUERY_SQL_CAP),
                            'ms' => $queryTime * 1000.0,
                            'error' => $level === LogLevel::ERROR,
                        ];
                    }
                    if (! isset($this->queryMap[$sqlKey])) {
                        $this->queryMap[$sqlKey] = 0;
                    }
                    $this->queryMap[$sqlKey]++;
                    $isDuplicate = ($this->queryMap[$sqlKey] > 1);
                    if ($isDuplicate) {
                        $this->duplicateCount++;
                    }

                    // Build formatted message
                    if ($level === LogLevel::ERROR) {
                        $errno = isset($context['errno']) && is_scalar($context['errno']) ? $context['errno'] : '?';
                        $error = isset($context['error']) && is_scalar($context['error']) ? $context['error'] : '?';
                        $msg .= sprintf(_MD_DEBUGBAR_QUERY_ERROR, $errno, $error);
                    }

                    // Prefix with timing
                    $msg = ($qt !== '' ? $qt . 's - ' : '') . $msg;

                    // Add duplicate indicator
                    if ($isDuplicate) {
                        $msg = '[DUP×' . $this->queryMap[$sqlKey] . '] ' . $msg;
                    }

                    // Override level for slow/duplicate queries to get color labels
                    if ($level !== LogLevel::ERROR) {
                        if ($queryTime > 0 && $queryTime >= $this->slowQueryThreshold) {
                            $level = LogLevel::ERROR;    // red — slow query
                        } elseif ($isDuplicate) {
                            $level = LogLevel::WARNING;  // yellow — duplicate
                        }
                    }

                    // In "slow & errors only" mode, queryCount/duplicateCount are still
                    // tracked above for the summary, but fast normal queries are not
                    // added to the Queries collector — this is the main performance gain.
                    if ($this->queryLogMode === 1 && $level !== LogLevel::ERROR) {
                        return;
                    }

                    break;
                default:
                    $channel = 'messages';

                    break;
            }
        }

        // Fall back to 'messages' if collector doesn't exist
        if (! $this->debugbar->hasCollector($channel)) {
            $channel = 'messages';
        }

        // MessagesCollector::log() preserves context; calling warning(),
        // notice(), etc. directly would lose source and trace details.
        $messageContext = $this->prepareDiagnosticContext($context, $level, $channel);

        // Dispatch to the collector by PSR-3 level
        try {
            $collector = $this->debugbar->getCollector($channel);
            if ($collector instanceof MessagesCollector) {
                $collector->log($level, $msg, $messageContext);
            }
        } catch (\Throwable $e) {
            // Silently ignore collector errors
        }
    }

    /**
     * Convert logger context into readable diagnostic details.
     *
     * @param array<string, mixed> $context Logger context
     * @return array<string, mixed>
     */
    private function prepareDiagnosticContext(array $context, mixed $level, string $channel): array
    {
        unset($context['channel'], $context['name']);

        if (in_array($channel, ['messages', 'Deprecated'], true)) {
            $context['severity'] = strtoupper((string) $level);
            if (isset($context['errno']) && is_numeric($context['errno'])) {
                $context['error_type'] = $this->errorType((int) $context['errno']);
            }
            $context['request'] = $this->requestContext();
            $context['environment'] = [
                'PHP' => PHP_VERSION,
                'XOOPS' => defined('XOOPS_VERSION') ? XOOPS_VERSION : 'unknown',
                'memory' => $this->formatBytes(memory_get_usage(true)),
            ];
        }

        if (isset($context['errfile'], $context['errline'])) {
            $context['source'] = $this->formatSource($context['errfile'], $context['errline']);
            $context['source_file'] = $context['errfile'];
            $context['source_line'] = $context['errline'];
        }
        unset($context['errfile'], $context['errline']);

        if (isset($context['trace']) && is_array($context['trace'])) {
            $context['trace'] = $this->formatTrace($context['trace']);
        }

        return $context;
    }

    /** @param int $errno @return string */
    private function errorType(int $errno): string
    {
        $types = [
            E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE',
            E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        return $types[$errno] ?? 'UNKNOWN (' . $errno . ')';
    }

    /** @return array<string, string> */
    private function requestContext(): array
    {
        $request = [
            'method' => Request::getString('REQUEST_METHOD', 'CLI', 'SERVER'),
            'uri' => Request::getString('REQUEST_URI', '', 'SERVER'),
            'script' => Request::getString('SCRIPT_NAME', '', 'SERVER'),
        ];
        $request['id'] = $this->requestId();
        $user = $GLOBALS['xoopsUser'] ?? null;
        if ($user instanceof \XoopsUser) {
            $request['user'] = (string) ($user->getVar('uname') ?? '');
            $request['uid'] = (string) ($user->getVar('uid') ?? '');
        }
        $module = $GLOBALS['xoopsModule'] ?? null;
        if ($module instanceof \XoopsModule) {
            $request['module'] = (string) ($module->getVar('dirname') ?? '');
        }
        /** @var array<string, string> $safeRequest */
        $safeRequest = $this->sanitizer()->sanitize($request);

        return $safeRequest;
    }

    /** @return array<string, string|int> Safe, bounded context for xWhoops. */
    public function whoopsSnapshot(): array
    {
        $request = $this->redact($this->requestContext());

        return [
            'Request ID' => $this->requestId(),
            'Method' => (string) ($request['method'] ?? ''),
            'URI' => mb_substr((string) ($request['uri'] ?? ''), 0, 500),
            'Module' => (string) ($request['module'] ?? ''),
            'Queries' => $this->queryCount,
            'Duplicate queries' => $this->duplicateCount,
            'Elapsed ms' => number_format((microtime(true) - $this->requestStart) * 1000, 1, '.', ''),
            'Peak memory KB' => (int) ceil(memory_get_peak_usage(true) / 1024),
        ];
    }

    /** Return the module URL without embedding a hardcoded module path segment. */
    private function moduleUrl(): string
    {
        $rootUrl = defined('XOOPS_URL') ? (string) constant('XOOPS_URL') : '';
        $dirname = basename(dirname(__DIR__));

        return rtrim($rootUrl, '/') . '/modules/' . rawurlencode($dirname);
    }

    /** Escape a generated URL or token for a double-quoted HTML attribute. */
    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Write the completed adapter markup in one operation at footer shutdown. */
    private function writeOutput(string $output): void
    {
        if ($output !== '') {
            file_put_contents('php://output', $output, FILE_APPEND);
        }
    }

    /** Resolve the legacy singleton to its concrete type for adapter calls. */
    private static function xoopsLogger(): \XoopsLogger
    {
        $logger = \XoopsLogger::getInstance();
        if (! $logger instanceof \XoopsLogger) {
            throw new \RuntimeException('XOOPS logger is unavailable');
        }

        return $logger;
    }

    /** @param int|float $bytes @return string */
    private function formatBytes(int|float $bytes): string
    {
        return number_format((float) $bytes / 1024, 1) . ' KB';
    }

    /** @param mixed $file @param mixed $line @return string */
    private function formatSource(mixed $file, mixed $line): string
    {
        return (string) $file . ':' . (string) $line;
    }

    /** @param array<int, mixed> $trace */
    private function formatTrace(array $trace): string
    {
        $lines = [];
        foreach ($trace as $index => $frame) {
            if (! is_array($frame)) {
                continue;
            }
            $location = isset($frame['file'])
                ? $this->formatSource($frame['file'], $frame['line'] ?? '?')
                : '(internal)';
            $call = '';
            $arguments = [];
            $frameArguments = $frame['args'] ?? [];
            foreach (is_array($frameArguments) ? $frameArguments : [] as $argument) {
                $arguments[] = $this->formatTraceArgument($argument);
            }
            $callName = '';
            if (isset($frame['class'], $frame['type'], $frame['function'])) {
                $callName = $frame['class'] . $frame['type'] . $frame['function'];
            } elseif (isset($frame['function'])) {
                $callName = $frame['function'];
            }
            if ($callName !== '') {
                $call = ' ' . $callName . '(' . implode(', ', $arguments) . ')';
            }
            $lines[] = '#' . $index . ' ' . $location . $call;
        }

        return implode("\n", $lines);
    }

    /** Format and bound one trace argument without warnings on recursive data. */
    private function formatTraceArgument(mixed $argument): string
    {
        if ($argument === null) {
            return 'null';
        }
        if (is_bool($argument)) {
            return $argument ? 'true' : 'false';
        }
        if (is_scalar($argument)) {
            return substr((string) $argument, 0, 2048);
        }

        $encoded = json_encode(
            $argument,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
            4
        );
        if (! is_string($encoded)) {
            return '[' . get_debug_type($argument) . ']';
        }

        return substr($encoded, 0, 2048);
    }
}
