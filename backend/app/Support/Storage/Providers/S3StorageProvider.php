<?php

namespace App\Support\Storage\Providers;

use App\Contracts\StorageProviderInterface;
use App\Support\Storage\StorageProviderException;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Real S3-compatible object storage (AWS S3, Cloudflare R2, MinIO, OVH
 * Object Storage) using AWS Signature Version 4 over Guzzle — no SDK
 * dependency. Activates only when NEXUS_STORAGE_S3_* credentials are set.
 *
 * Objects are addressed path-style: {endpoint}/{bucket}/{key}, which every
 * S3-compatible backend supports.
 */
final class S3StorageProvider implements StorageProviderInterface
{
    private ?Client $client = null;

    public function name(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3';
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['endpoint'] !== '' && $c['bucket'] !== '' && $c['key'] !== '' && $c['secret'] !== '';
    }

    public function put(string $key, string $contents, string $mimeType = 'application/octet-stream'): array
    {
        $response = $this->send('PUT', $key, [
            'body' => $contents,
            'headers' => ['Content-Type' => $mimeType],
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new StorageProviderException("S3 PUT failed ({$response->getStatusCode()}).");
        }

        return [
            'etag' => trim((string) $response->getHeaderLine('ETag'), '"'),
            'size' => strlen($contents),
        ];
    }

    public function get(string $key): ?string
    {
        $response = $this->send('GET', $key);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        if ($response->getStatusCode() >= 300) {
            throw new StorageProviderException("S3 GET failed ({$response->getStatusCode()}).");
        }

        return (string) $response->getBody();
    }

    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key);

        if ($response->getStatusCode() >= 300 && $response->getStatusCode() !== 404) {
            throw new StorageProviderException("S3 DELETE failed ({$response->getStatusCode()}).");
        }
    }

    public function exists(string $key): bool
    {
        $response = $this->send('HEAD', $key);

        return $response->getStatusCode() === 200;
    }

    public function signedDownloadUrl(string $key, string $fileName, int $ttl = 300): string
    {
        return $this->presign('GET', $key, $ttl, [
            'response-content-disposition' => 'attachment; filename="'.addslashes($fileName).'"',
        ]);
    }

    public function signedUploadUrl(string $key, string $mimeType, int $ttl = 300): string
    {
        return $this->presign('PUT', $key, $ttl);
    }

    /**
     * @return array{endpoint: string, region: string, bucket: string, key: string, secret: string}
     */
    private function config(): array
    {
        return [
            'endpoint' => rtrim((string) config('nexus.storage.s3.endpoint'), '/'),
            'region' => (string) config('nexus.storage.s3.region', 'us-east-1'),
            'bucket' => (string) config('nexus.storage.s3.bucket'),
            'key' => (string) config('nexus.storage.s3.key'),
            'secret' => (string) config('nexus.storage.s3.secret'),
        ];
    }

    private function client(): Client
    {
        return $this->client ??= new Client(['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false]);
    }

    private function authority(): string
    {
        $host = parse_url($this->config()['endpoint'], PHP_URL_HOST) ?? '';
        $port = parse_url($this->config()['endpoint'], PHP_URL_PORT);

        return $port ? $host.':'.$port : $host;
    }

    private function objectPath(string $objectKey): string
    {
        $bucket = rawurlencode($this->config()['bucket']);
        $key = implode('/', array_map('rawurlencode', explode('/', $objectKey)));

        return '/'.$bucket.'/'.$key;
    }

    private function objectUrl(string $objectKey): string
    {
        return $this->config()['endpoint'].$this->objectPath($objectKey);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $objectKey, array $options = []): ResponseInterface
    {
        $c = $this->config();

        $body = (string) ($options['body'] ?? '');
        $payloadHash = hash('sha256', $body);

        $now = now()->utc();
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $signed = ['host' => $this->authority()];
        $outgoing = [];

        foreach (($options['headers'] ?? []) as $name => $value) {
            $signed[strtolower((string) $name)] = (string) $value;
            $outgoing[(string) $name] = (string) $value;
        }

        $signed['x-amz-content-sha256'] = $payloadHash;
        $signed['x-amz-date'] = $amzDate;
        $outgoing['X-Amz-Content-Sha256'] = $payloadHash;
        $outgoing['X-Amz-Date'] = $amzDate;

        $path = $this->objectPath($objectKey);

        $signature = $this->sign($method, $path, '', $signed, $payloadHash, $amzDate, $dateStamp, $c['region']);

        $outgoing['Authorization'] = 'AWS4-HMAC-SHA256 Credential='.$c['key'].'/'.$signature['scope']
            .', SignedHeaders='.$signature['signed_headers'].', Signature='.$signature['signature'];

        try {
            return $this->client()->request($method, $this->objectUrl($objectKey), [
                'headers' => $outgoing,
                'body' => $options['body'] ?? null,
            ]);
        } catch (\Throwable $e) {
            throw new StorageProviderException('S3 request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, string>  $extraQuery
     */
    private function presign(string $method, string $objectKey, int $ttl, array $extraQuery = []): string
    {
        $c = $this->config();

        $now = now()->utc();
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $scope = $dateStamp.'/'.$c['region'].'/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $c['key'].'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, $ttl),
            'X-Amz-SignedHeaders' => 'host',
        ];

        foreach ($extraQuery as $name => $value) {
            $query[$name] = $value;
        }

        $signed = ['host' => $this->authority()];
        $canonicalQuery = $this->canonicalQuery($query);
        $path = $this->objectPath($objectKey);

        $signature = $this->sign($method, $path, $canonicalQuery, $signed, 'UNSIGNED-PAYLOAD', $amzDate, $dateStamp, $c['region']);

        $query['X-Amz-Signature'] = $signature['signature'];

        return $this->objectUrl($objectKey).'?'.$this->canonicalQuery($query);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{signature: string, signed_headers: string, scope: string}
     */
    private function sign(
        string $method,
        string $path,
        string $canonicalQuery,
        array $headers,
        string $payloadHash,
        string $amzDate,
        string $dateStamp,
        string $region,
    ): array {
        $names = array_keys($headers);
        sort($names);

        $canonicalHeaders = '';
        foreach ($names as $name) {
            $value = trim(preg_replace('/\s+/', ' ', (string) $headers[$name]));
            $canonicalHeaders .= $name.':'.$value."\n";
        }
        $signedHeaders = implode(';', $names);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp.'/'.$region.'/s3/aws4_request';

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $secret = $this->config()['secret'];
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return [
            'signature' => hash_hmac('sha256', $stringToSign, $kSigning),
            'signed_headers' => $signedHeaders,
            'scope' => $scope,
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);

        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return implode('&', $pairs);
    }
}
