<?php declare(strict_types=1);
/**
 * DebugBar Module - Install/Update callbacks
 *
 * Copies DebugBar vendor assets to web-accessible module directory.
 *
 * @copyright       (c) 2000-2026 XOOPS Project (https://xoops.org)
 * @license             GNU GPL 2 (https://www.gnu.org/licenses/gpl-2.0.html)
 * @package             debugbar
 */

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/**
 * Copy DebugBar vendor assets to modules/debugbar/assets/ for web access.
 *
 * @param XoopsModule $module
 * @return bool
 */
function xoops_module_install_debugbar($module)
{
    $assetsReady = _debugbar_copy_assets();
    $tableReady = _debugbar_create_profiles_table();
    _debugbar_ensure_explain_secret();

    return $assetsReady && $tableReady;
}

/**
 * Copy assets on module update too.
 *
 * @param XoopsModule $module
 * @param int $previousVersion
 * @return bool
 */
function xoops_module_update_debugbar($module, $previousVersion)
{
    $assetsReady = _debugbar_copy_assets();
    $tableReady = _debugbar_create_profiles_table();
    _debugbar_ensure_explain_secret();

    return $assetsReady && $tableReady;
}

/** Create the optional EXPLAIN signing key without making module setup fatal. */
function _debugbar_ensure_explain_secret(): bool
{
    require_once dirname(__DIR__) . '/class/ExplainSecretStore.php';

    try {
        $ready = (new \XoopsModules\Debugbar\ExplainSecretStore())->ensure();
    } catch (\Throwable $exception) {
        trigger_error('DebugBar EXPLAIN signing key setup failed: ' . $exception->getMessage(), E_USER_WARNING);

        return false;
    }
    if (! $ready) {
        trigger_error('DebugBar EXPLAIN signing key is unavailable; EXPLAIN actions remain disabled.', E_USER_WARNING);
    }

    return $ready;
}

function _debugbar_create_profiles_table(): bool
{
    $db = $GLOBALS['xoopsDB'];
    $sql = 'CREATE TABLE IF NOT EXISTS ' . $db->prefix('debugbar_profiles') . ' (
        profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id CHAR(16) NOT NULL DEFAULT \'\', created INT UNSIGNED NOT NULL DEFAULT 0,
        url VARCHAR(500) NOT NULL DEFAULT \'\', url_hash CHAR(32) NOT NULL DEFAULT \'\',
        dirname VARCHAR(64) NOT NULL DEFAULT \'\', is_fragment TINYINT(1) NOT NULL DEFAULT 0,
        is_admin_side TINYINT(1) NOT NULL DEFAULT 0, total_ms DECIMAL(10,1) NOT NULL DEFAULT 0,
        boot_ms DECIMAL(10,1) NOT NULL DEFAULT 0, query_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        query_ms DECIMAL(10,1) NOT NULL DEFAULT 0, slowest_ms DECIMAL(10,1) NOT NULL DEFAULT 0,
        slowest_fp VARCHAR(255) NOT NULL DEFAULT \'\', n_plus_one SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        peak_mem_kb INT UNSIGNED NOT NULL DEFAULT 0, payload_bytes INT UNSIGNED NOT NULL DEFAULT 0,
        flags SMALLINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (profile_id), KEY idx_created (created),
        KEY idx_url_created (url_hash, created), KEY idx_dirname_created (dirname, created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    try {
        return false !== $db->exec($sql);
    } catch (\Throwable $e) {
        trigger_error('DebugBar profiles table creation failed: ' . $e->getMessage(), E_USER_WARNING);

        return false;
    }
}

/**
 * Internal: copy vendor debugbar Resources to module assets directory.
 *
 * @return bool
 */
function _debugbar_copy_assets()
{
    // Source: vendor debugbar resources — check all possible vendor locations
    $vendorBases = [];
    if (defined('XOOPS_LIB_PATH')) {
        $vendorBases[] = XOOPS_LIB_PATH . '/vendor';
    }
    if (defined('XOOPS_PATH')) {
        $vendorBases[] = XOOPS_PATH . '/vendor';
    }
    $vendorBases[] = XOOPS_ROOT_PATH . '/xoops_lib/vendor';
    $vendorBases[] = XOOPS_ROOT_PATH . '/class/libraries/vendor';
    $vendorBases = array_unique($vendorBases);

    // Check multiple possible resource layouts (newest first, then legacy paths)
    $resourceSuffixes = [
        '/php-debugbar/php-debugbar/resources',
        '/php-debugbar/php-debugbar/src/DebugBar/Resources',
        '/maximebf/debugbar/src/DebugBar/Resources',
    ];
    $vendorPaths = [];
    foreach ($vendorBases as $vendorBase) {
        foreach ($resourceSuffixes as $suffix) {
            $vendorPaths[] = $vendorBase . $suffix;
        }
    }

    $srcDir = false;
    foreach ($vendorPaths as $path) {
        if (is_dir($path)) {
            $srcDir = $path;

            break;
        }
    }

    if (! $srcDir) {
        $checkedDirs = [];
        foreach ($vendorPaths as $path) {
            $checkedDirs[] = basename(dirname($path, 4)) . '/…/' . basename($path);
        }
        trigger_error(
            'DebugBar installation: assets not found in vendor directories ('
            . implode(', ', $checkedDirs) . '); module JavaScript/CSS assets were not installed.',
            E_USER_WARNING
        );

        return false;
    }

    // Destination: modules/debugbar/assets/
    $destDir = XOOPS_ROOT_PATH . '/modules/debugbar/assets';
    if (! is_dir($destDir)) {
        if (! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            throw new \RuntimeException(sprintf(_MD_DEBUGBAR_ERR_DIR_CREATE, basename($destDir)));
        }
    }

    // Copy the vendor baseline first. Composer may replace these files during
    // a dependency update, so the module-owned overlay is applied afterward.
    $vendorCopied = _debugbar_recursive_copy($srcDir, $destDir);

    $customDir = XOOPS_ROOT_PATH . '/modules/debugbar/assets-custom';
    $customCopied = true;
    if (is_dir($customDir)) {
        $customCopied = _debugbar_recursive_copy($customDir, $destDir);
    }

    $vendorPatched = _debugbar_patch_vendor_assets($destDir);

    return $vendorCopied && $customCopied && $vendorPatched;
}

/**
 * Apply small compatibility and security fixes to vendor files after each copy.
 *
 * These files are owned by php-debugbar and therefore are not duplicated in
 * assets-custom. Keeping the transformations here prevents a module update
 * from restoring known-bad vendor code over the corrected web assets.
 */
function _debugbar_patch_vendor_assets(string $destDir): bool
{
    $patches = [
        'openhandler.js' => [
            [
                "(function () {\n    const csscls = function (cls) {",
                "(function () {\n    if (typeof PhpDebugBar === 'undefined') {\n        return;\n    }\n\n    const csscls = function (cls) {",
            ],
            [
                "        handleFind(data) {\n            const self = this;",
                "        handleFind(data) {\n            if (!Array.isArray(data)) {\n                return;\n            }\n            const self = this;",
            ],
            [
                "                .catch((err) => {\n                    callback(null, err);\n                });",
                "                .catch((err) => {\n                    console.error('phpdebugbar openhandler', err);\n                    callback([], err);\n                });",
            ],
        ],
        'highlight.css' => [
            [
                "[data-theme='dark'] .phpdebugbar-hljs-meta [data-theme='dark'] .phpdebugbar-hljs-keyword,",
                "[data-theme='dark'] .phpdebugbar-hljs-meta .phpdebugbar-hljs-keyword,",
            ],
            [
                "[data-theme='dark'] .phpdebugbar-hljs-meta [data-theme='dark'] .phpdebugbar-hljs-string {",
                "[data-theme='dark'] .phpdebugbar-hljs-meta .phpdebugbar-hljs-string {",
            ],
        ],
        'vardumper.css' => [
            [
                ".phpdebugbar[data-theme='dark'] pre.sf-dump, pre.sf-dump .sf-dump-default {",
                ".phpdebugbar[data-theme='dark'] pre.sf-dump,\n.phpdebugbar[data-theme='dark'] pre.sf-dump .sf-dump-default {",
            ],
            [
                "    line-height: 1.2em;\n    font: 12px Menlo, Monaco, Consolas, monospace;",
                '    font: 12px/1.2 Menlo, Monaco, Consolas, monospace;',
            ],
        ],
        'vardumper.js' => [
            [
                "                if (v._vd) return (v._vd[2] || '') + ' {…}';",
                "                if (v._vd) return this.esc(v._vd[2] || '') + ' {…}';",
            ],
        ],
        'widgets/mails/widget.js' => [
            [
                "                        const popup = window.open('about:blank', 'Mail Preview', 'width=650,height=440,scrollbars=yes');\n                        const documentToWriteTo = popup.document;",
                "                        const popup = window.open('about:blank', 'Mail Preview', 'width=650,height=440,scrollbars=yes');\n                        if (!popup || !popup.document) {\n                            return;\n                        }\n                        const documentToWriteTo = popup.document;",
            ],
            [
                '                        documentToWriteTo.write(headersHTML + bodyHTML + htmlIframeHTML);',
                "                        documentToWriteTo.write(\n                            '<meta http-equiv=\"Content-Security-Policy\" content=\"default-src \\'none\\'; img-src data:; style-src \\'unsafe-inline\\';\">'\n                            + headersHTML + bodyHTML + htmlIframeHTML\n                        );",
            ],
        ],
        'widgets/http/widget.js' => [
            [
                '                            PhpDebugBar.Widgets.renderValueInto(valueTd, request.details[key]);',
                '                            PhpDebugBar.Widgets.renderSafeValueInto(valueTd, request.details[key]);',
            ],
        ],
        'widgets/templates/widget.js' => [
            [
                "                if (tpl.html) {\n                    name.innerHTML = tpl.html;\n                } else {\n                    name.textContent = tpl.name;\n                }",
                "                name.textContent = String(tpl.name ?? tpl.html ?? '');",
            ],
            [
                '                            PhpDebugBar.Widgets.renderValueInto(valueTd, tpl.params[key]);',
                '                            PhpDebugBar.Widgets.renderSafeValueInto(valueTd, tpl.params[key]);',
            ],
        ],
    ];

    $success = true;
    foreach ($patches as $relativePath => $replacements) {
        $path = $destDir . '/' . $relativePath;
        if (! is_file($path) || ! is_readable($path)) {
            trigger_error('DebugBar asset patch skipped unreadable file: ' . $relativePath, E_USER_WARNING);
            $success = false;

            continue;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            trigger_error('DebugBar asset patch could not read file: ' . $relativePath, E_USER_WARNING);
            $success = false;

            continue;
        }

        $patched = $contents;
        foreach ($replacements as [$search, $replacement]) {
            if (str_contains($patched, $search)) {
                $patched = str_replace($search, $replacement, $patched);

                continue;
            }

            // Keep direct calls idempotent while still detecting vendor drift.
            if (str_contains($patched, $replacement)) {
                continue;
            }

            trigger_error('DebugBar asset patch target not found in ' . $relativePath, E_USER_WARNING);
            $success = false;
        }

        if ($patched !== $contents && false === file_put_contents($path, $patched)) {
            trigger_error('DebugBar asset patch could not write file: ' . $relativePath, E_USER_WARNING);
            $success = false;
        }
    }

    return $success;
}

/**
 * Recursively copy a directory.
 *
 * @param string $src  source directory
 * @param string $dest destination directory
 * @return bool
 */
function _debugbar_recursive_copy($src, $dest)
{
    if (! is_dir($src)) {
        return false;
    }
    if (! is_dir($dest)) {
        if (! mkdir($dest, 0755, true) && ! is_dir($dest)) {
            throw new \RuntimeException(sprintf(_MD_DEBUGBAR_ERR_DIR_COPY, basename($dest)));
        }
    }

    $dir = opendir($src);
    if (! $dir) {
        return false;
    }

    $success = true;
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $srcPath = $src . '/' . $file;
        $destPath = $dest . '/' . $file;
        if (is_dir($srcPath)) {
            if (! _debugbar_recursive_copy($srcPath, $destPath)) {
                $success = false;
            }
        } else {
            if (! copy($srcPath, $destPath)) {
                $success = false;
            }
        }
    }
    closedir($dir);

    return $success;
}
