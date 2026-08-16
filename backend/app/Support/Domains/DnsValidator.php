<?php

namespace App\Support\Domains;

final class DnsValidator
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    /**
     * Validate a single record payload. Returns a list of human-readable
     * errors; an empty array means the record is valid.
     *
     * @param  array{type?: string, name?: string, content?: string, ttl?: mixed, priority?: mixed}  $data
     * @return array<int, string>
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $type = strtoupper(trim((string) ($data['type'] ?? '')));

        if (! in_array($type, self::TYPES, true)) {
            return ["Unsupported record type [{$type}]."];
        }

        $name = trim((string) ($data['name'] ?? '@'));
        if ($name === '') {
            $name = '@';
        }

        if ($name !== '@' && ! self::isHostname($name)) {
            $errors[] = "Invalid record name [{$name}].";
        }

        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            $errors[] = 'Record content is required.';
        } else {
            $errors = [...$errors, ...self::validateContent($type, $content, $data)];
        }

        $ttl = $data['ttl'] ?? null;
        if ($ttl !== null && (! is_numeric($ttl) || (int) $ttl < 0)) {
            $errors[] = 'TTL must be a non-negative integer.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function validateContent(string $type, string $content, array $data): array
    {
        return match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? []
                : ["[{$content}] is not a valid IPv4 address."],
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? []
                : ["[{$content}] is not a valid IPv6 address."],
            'CNAME', 'NS' => self::isHostname($content)
                ? []
                : ["[{$content}] is not a valid hostname."],
            'MX' => self::validateMx($content, $data),
            'SRV' => self::validateSrv($content),
            'CAA' => self::validateCaa($content),
            'TXT' => strlen($content) <= 4096
                ? []
                : ['TXT records cannot exceed 4096 characters.'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function validateMx(string $content, array $data): array
    {
        $errors = [];

        $priority = $data['priority'] ?? null;
        if ($priority === null || $priority === '' || ! is_numeric($priority)) {
            $errors[] = 'MX records require a numeric priority.';
        } elseif ((int) $priority < 0 || (int) $priority > 65535) {
            $errors[] = 'MX priority must be between 0 and 65535.';
        }

        if (! self::isHostname($content)) {
            $errors[] = "[{$content}] is not a valid mail hostname.";
        }

        return $errors;
    }

    /**
     * SRV content: "priority weight port target".
     *
     * @return array<int, string>
     */
    private static function validateSrv(string $content): array
    {
        $parts = preg_split('/\s+/', trim($content));

        if ($parts === false || count($parts) !== 4) {
            return ['SRV content must be "priority weight port target".'];
        }

        [$priority, $weight, $port, $target] = $parts;

        foreach ([$priority, $weight] as $i => $value) {
            if (! is_numeric($value) || (int) $value < 0 || (int) $value > 65535) {
                $label = $i === 0 ? 'priority' : 'weight';

                return ["SRV {$label} must be between 0 and 65535."];
            }
        }

        if (! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            return ['SRV port must be between 1 and 65535.'];
        }

        return self::isHostname($target) ? [] : ["[{$target}] is not a valid SRV target."];
    }

    /**
     * CAA content: "flags tag value" (value may be quoted).
     *
     * @return array<int, string>
     */
    private static function validateCaa(string $content): array
    {
        if (! preg_match('/^(\d+)\s+([a-z0-9]+)\s+(.+)$/i', trim($content), $matches)) {
            return ['CAA content must be "flags tag value".'];
        }

        if ((int) $matches[1] > 255) {
            return ['CAA flags must be between 0 and 255.'];
        }

        return [];
    }

    public static function isHostname(string $value): bool
    {
        $value = rtrim(trim($value), '.');

        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        // Allow the relative apex target "@".
        if ($value === '@') {
            return true;
        }

        // A label may stand alone (relative to the zone origin, e.g. "www",
        // "mail") or be fully qualified with one or more dotted labels.
        return (bool) preg_match(
            '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            $value
        );
    }
}
