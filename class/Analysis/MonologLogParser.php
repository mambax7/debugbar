<?php

declare(strict_types=1);

namespace XoopsModules\Debugbar\Analysis;

final class MonologLogParser
{
    /**
     * @return list<array{parsed:bool,raw:string,timestamp:string,channel:string,level:string,message:string,context:array<array-key,mixed>,extra:array<array-key,mixed>}>
     */
    public function parse(string $contents): array
    {
        $entries = [];
        $lines = preg_split('/\R/', $contents);
        foreach ($lines !== false ? $lines : [] as $line) {
            if ($line === '') {
                continue;
            }
            $entry = $this->parseLine($line);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array{parsed:bool,raw:string,timestamp:string,channel:string,level:string,message:string,context:array<array-key,mixed>,extra:array<array-key,mixed>}
     */
    private function parseLine(string $line): array
    {
        $fallback = [
            'parsed' => false, 'raw' => $line, 'timestamp' => '', 'channel' => '',
            'level' => '', 'message' => $line, 'context' => [], 'extra' => [],
        ];
        if (preg_match('/^\[([^\]]+)]\s+([^\s.]+)\.([A-Z]+):\s+(.*?)\s+(\{.*}|\[.*])\s+(\{.*}|\[.*])\s*$/', $line, $matches) !== 1) {
            return $fallback;
        }
        $context = json_decode($matches[5], true);
        $extra = json_decode($matches[6], true);
        if (! is_array($context) || ! is_array($extra)) {
            return $fallback;
        }

        return [
            'parsed' => true,
            'raw' => $line,
            'timestamp' => $matches[1],
            'channel' => $matches[2],
            'level' => strtolower($matches[3]),
            'message' => $matches[4],
            'context' => $context,
            'extra' => $extra,
        ];
    }
}
