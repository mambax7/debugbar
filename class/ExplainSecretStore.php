<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar;

defined('XOOPS_ROOT_PATH') || exit('Restricted access');

/** Persist the EXPLAIN signing secret in protected XOOPS variable data. */
final class ExplainSecretStore
{
    private const FILE_NAME = 'debugbar-explain.key';
    private const KEY_PATTERN = '/^[a-f0-9]{64}$/D';

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? (defined('XOOPS_VAR_PATH') ? XOOPS_VAR_PATH . '/data' : '');
    }

    public function load(): ?string
    {
        $path = $this->path();
        if ($path === '' || is_link($this->directory) || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $secret = $this->withoutWarnings(fn () => file_get_contents($path));
        if (! is_string($secret)) {
            return null;
        }
        $secret = trim($secret);

        return preg_match(self::KEY_PATTERN, $secret) === 1 ? $secret : null;
    }

    public function ensure(): bool
    {
        try {
            if ($this->load() !== null) {
                return true;
            }
            if ($this->directory === '' || is_link($this->directory) || is_link($this->path())) {
                return false;
            }
            if (file_exists($this->path()) && ! is_file($this->path())) {
                return false;
            }
            if (! is_dir($this->directory) && ! $this->withoutWarnings(fn (): bool => mkdir($this->directory, 0700, true)) && ! is_dir($this->directory)) {
                return false;
            }
            if (! is_writable($this->directory)) {
                return false;
            }

            $temporary = $this->directory . '/.debugbar-explain-' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = $this->withoutWarnings(fn () => fopen($temporary, 'xb'));
            if (! is_resource($handle)) {
                return false;
            }

            $contents = bin2hex(random_bytes(32)) . PHP_EOL;
            $offset = 0;

            try {
                while ($offset < strlen($contents)) {
                    $bytes = $this->withoutWarnings(fn () => fwrite($handle, substr($contents, $offset)));
                    if (! is_int($bytes) || $bytes < 1) {
                        break;
                    }
                    $offset += $bytes;
                }
                $written = $offset === strlen($contents)
                    && $this->withoutWarnings(fn (): bool => fflush($handle));
            } finally {
                fclose($handle);
            }
            if (! $written) {
                $this->removeTemporary($temporary);

                return false;
            }
            $this->withoutWarnings(fn (): bool => chmod($temporary, 0600));

            if (! $this->withoutWarnings(fn (): bool => rename($temporary, $this->path()))) {
                $this->removeTemporary($temporary);

                return $this->load() !== null;
            }

            return $this->load() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function status(): string
    {
        $path = $this->path();
        if ($path === '' || is_link($this->directory) || is_link($path)) {
            return 'unsafe';
        }
        if (file_exists($path) && ! is_file($path)) {
            return 'unsafe';
        }
        if (is_file($path)) {
            return $this->load() !== null ? 'available' : 'invalid';
        }

        return is_dir($this->directory) && is_writable($this->directory) ? 'missing' : 'unwritable';
    }

    private function path(): string
    {
        return $this->directory === '' ? '' : rtrim($this->directory, '/\\') . '/' . self::FILE_NAME;
    }

    private function removeTemporary(string $path): void
    {
        if (is_file($path)) {
            $this->withoutWarnings(fn (): bool => unlink($path));
        }
    }

    /**
     * @phpstan-template TResult
     * @phpstan-param callable(): TResult $operation
     * @phpstan-return TResult
     * @phpstan-impure
     */
    private function withoutWarnings(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
