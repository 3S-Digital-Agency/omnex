<?php

namespace App\Support\Domains;

/**
 * Minimal BIND zone-file parser/exporter. Supports the common record shapes
 * OMNEX manages (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA), `$ORIGIN`/`$TTL`
 * directives, `@` for the apex, the `IN` class token and `;` comments.
 */
final class ZoneFileParser
{
    /**
     * @return array<int, array{name: string, type: string, content: string, ttl: int, priority: ?int}>
     */
    public static function parse(string $zoneFile, string $origin = ''): array
    {
        $origin = rtrim(trim($origin), '.');
        $defaultTtl = 3600;
        $records = [];

        foreach (preg_split('/\R/', $zoneFile) as $lineNumber => $rawLine) {
            $line = trim($rawLine);

            // Strip comments.
            if (($pos = strpos($line, ';')) !== false) {
                $line = substr($line, 0, $pos);
            }
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Directives.
            if (preg_match('/^\$TTL\s+(\d+)$/i', $line, $m)) {
                $defaultTtl = (int) $m[1];

                continue;
            }
            if (preg_match('/^\$ORIGIN\s+(\S+)$/i', $line, $m)) {
                $origin = rtrim($m[1], '.');

                continue;
            }

            $record = self::parseLine($line, $origin, $defaultTtl);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  iterable<array{name: string, type: string, content: string, ttl: int, priority: ?int}>  $records
     */
    public static function export(string $origin, iterable $records): string
    {
        $origin = rtrim(trim($origin), '.');
        $lines = ['$ORIGIN '.$origin.'.', '$TTL 3600', ''];

        foreach ($records as $record) {
            $name = ($record['name'] ?? '') === '' || ($record['name'] ?? '') === '@' ? '@' : $record['name'];
            $ttl = (int) ($record['ttl'] ?? 3600);
            $type = strtoupper((string) ($record['type'] ?? ''));
            $content = self::formatContent($type, (string) ($record['content'] ?? ''));
            $priority = $record['priority'] ?? null;

            $prefix = "{$name}\t{$ttl}\tIN\t{$type}";
            if ($type === 'MX' || $type === 'SRV') {
                $lines[] = "{$prefix}\t{$priority}\t{$content}";
            } else {
                $lines[] = "{$prefix}\t{$content}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{name: string, type: string, content: string, ttl: int, priority: ?int}|null
     */
    private static function parseLine(string $line, string $origin, int $defaultTtl): ?array
    {
        $tokens = preg_split('/\s+/', $line);
        if ($tokens === false || count($tokens) < 3) {
            return null;
        }

        $name = array_shift($tokens);

        // Optional TTL, then optional IN class.
        $ttl = $defaultTtl;
        if (ctype_digit($tokens[0] ?? '')) {
            $ttl = (int) array_shift($tokens);
        }
        if (strtoupper($tokens[0] ?? '') === 'IN') {
            array_shift($tokens);
        }

        $type = strtoupper((string) array_shift($tokens));

        $priority = null;
        if (in_array($type, ['MX', 'SRV'], true) && ctype_digit($tokens[0] ?? '')) {
            $priority = (int) array_shift($tokens);
        }

        $content = trim(implode(' ', $tokens));
        if ($content === '') {
            return null;
        }

        return [
            'name' => self::relativeName((string) $name, $origin),
            'type' => $type,
            'content' => self::stripTrailingDot($content),
            'ttl' => $ttl,
            'priority' => $priority,
        ];
    }

    private static function relativeName(string $name, string $origin): string
    {
        $name = self::stripTrailingDot($name);

        if ($name === '' || $name === '@') {
            return '@';
        }

        if ($origin !== '' && $name === $origin) {
            return '@';
        }

        if ($origin !== '' && str_ends_with($name, '.'.$origin)) {
            return substr($name, 0, -strlen('.'.$origin));
        }

        return $name;
    }

    private static function formatContent(string $type, string $content): string
    {
        $content = trim($content);

        // Append the root dot to hostname-like content for a valid zone file.
        if (in_array($type, ['CNAME', 'MX', 'NS', 'SRV'], true) && $content !== '@' && $content !== '') {
            if ($type === 'MX' || $type === 'SRV') {
                $parts = preg_split('/\s+/', $content);
                if ($parts !== false && count($parts) > 0) {
                    $last = array_pop($parts);
                    $parts[] = rtrim($last, '.').'.';

                    return implode(' ', $parts);
                }
            }

            return rtrim($content, '.').'.';
        }

        return $content;
    }

    private static function stripTrailingDot(string $value): string
    {
        return rtrim(trim($value), '.');
    }
}
