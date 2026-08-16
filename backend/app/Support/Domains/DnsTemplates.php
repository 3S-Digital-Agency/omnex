<?php

namespace App\Support\Domains;

final class DnsTemplates
{
    /**
     * @return array<string, array<int, array{name: string, type: string, content: string, ttl: int, priority: ?int}>>
     */
    public static function all(): array
    {
        return [
            'website' => [
                ['name' => '@', 'type' => 'A', 'content' => '192.0.2.10', 'ttl' => 3600, 'priority' => null],
                ['name' => '@', 'type' => 'AAAA', 'content' => '2001:db8::10', 'ttl' => 3600, 'priority' => null],
                ['name' => 'www', 'type' => 'CNAME', 'content' => '@', 'ttl' => 3600, 'priority' => null],
            ],
            'email' => [
                ['name' => '@', 'type' => 'MX', 'content' => 'mail', 'priority' => 10, 'ttl' => 3600],
                ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 include:spf.omnex.io ~all', 'ttl' => 3600, 'priority' => null],
                ['name' => 'default._domainkey', 'type' => 'TXT', 'content' => 'v=DKIM1; k=rsa; p=PENDING', 'ttl' => 3600, 'priority' => null],
                ['name' => '_dmarc', 'type' => 'TXT', 'content' => 'v=DMARC1; p=none', 'ttl' => 3600, 'priority' => null],
                ['name' => '@', 'type' => 'CAA', 'content' => '0 issue "letsencrypt.org"', 'ttl' => 3600, 'priority' => null],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<int, array{name: string, type: string, content: string, ttl: int, priority: ?int}>
     */
    public static function get(string $name): array
    {
        return self::all()[$name] ?? [];
    }
}
