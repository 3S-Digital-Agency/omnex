<?php

namespace App\Support\Ssl\Acme;

use App\Support\Ssl\SslProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal, dependency-free ACME v2 client (RFC 8555) built on the Laravel HTTP
 * client so every request is interceptable by Http::fake in tests.
 *
 * It performs the full issuance flow — directory discovery, account
 * registration, order + authorizations, the dns-01 challenge (delegated to a
 * caller-supplied solver), finalization with a generated CSR and certificate
 * download — plus revocation. Requests are signed with RS256 (RSA) JSON Web
 * Signatures, which Let's Encrypt and every RFC 8555 CA accept.
 */
final class AcmeClient
{
    /** @var \OpenSSLAsymmetricKey */
    private mixed $accountKey;

    private ?string $kid = null;

    private ?string $nonce = null;

    /** @var array<string, mixed> */
    private ?array $directory = null;

    /** @var array{e: string, kty: string, n: string} */
    private array $publicJwk;

    public function __construct(
        private readonly string $directoryUrl,
        string $accountKeyPem,
        private readonly string $email,
        private readonly bool $termsOfServiceAgreed = true,
        private readonly int $pollIntervalMs = 3000,
        private readonly int $pollAttempts = 10,
    ) {
        $this->accountKey = openssl_pkey_get_private($accountKeyPem)
            ?: throw new SslProviderException('Invalid Let\'s Encrypt account private key.');
        $this->publicJwk = $this->buildPublicJwk();
    }

    /**
     * Issue a certificate for one or more DNS names using the dns-01 challenge.
     *
     * @param  array<int, string>  $domains
     * @param  callable(string, string): ?callable  $dns01Solver  fn(domain, txtValue) => ?cleanup
     * @return array{fullchain: string, private_key: string, certificate_url: string, order_url: string}
     */
    public function issue(array $domains, callable $dns01Solver): array
    {
        $directory = $this->directory();

        $this->registerAccount($directory['newAccount']);

        $order = $this->newOrder($directory['newOrder'], $domains);

        $cleanups = [];
        foreach ($order['authorizations'] as $authorizationUrl) {
            $authorization = $this->postAsGet($authorizationUrl)->json() ?? [];

            if (($authorization['status'] ?? null) === 'valid') {
                continue;
            }

            $challenge = $this->dns01Challenge($authorization);
            $dnsValue = $this->dns01Value($challenge['token']);
            $cleanup = $dns01Solver($challenge['domain'], $dnsValue);
            if ($cleanup !== null) {
                $cleanups[] = $cleanup;
            }

            // Ask the CA to validate, then wait until the authorization leaves
            // the pending state.
            $this->request($challenge['url'], '{}');
            $authorization = $this->waitForStatus($authorizationUrl, ['valid', 'invalid']);
        }

        $fullchain = $this->finalizeAndDownload($order['finalize'], $domains);

        foreach ($cleanups as $cleanup) {
            $cleanup();
        }

        return $fullchain;
    }

    /**
     * Revoke a previously issued certificate (the full chain PEM).
     */
    public function revoke(string $fullchain): void
    {
        $directory = $this->directory();
        $this->registerAccount($directory['newAccount']);

        $this->request($directory['revokeCert'], json_encode([
            'certificate' => self::base64url(self::derFromPem($fullchain, 'CERTIFICATE')),
        ]));
    }

    /**
     * @return array{fullchain: string, private_key: string, certificate_url: string, order_url: string}
     */
    private function finalizeAndDownload(string $finalizeUrl, array $domains): array
    {
        [$csrPem, $privateKey] = $this->generateCsr($domains);

        $response = $this->request($finalizeUrl, json_encode([
            'csr' => self::base64url(self::derFromPem($csrPem, 'CERTIFICATE REQUEST')),
        ]));

        $orderUrl = $response->header('Location') ?: $finalizeUrl;
        $order = $this->waitForStatus($orderUrl, ['valid', 'invalid'], $this->json($response));

        if (($order['status'] ?? null) !== 'valid' || empty($order['certificate'])) {
            throw new SslProviderException('Let\'s Encrypt did not return a certificate URL.');
        }

        $response = Http::withHeaders(['Accept' => 'application/pem-certificate-chain'])
            ->withHeaders(['Content-Type' => 'application/jose+json'])
            ->post($order['certificate'], $this->jws('', $order['certificate']));
        $this->captureNonce($response);
        $this->throwOnError($response);

        return [
            'fullchain' => trim($response->body()),
            'private_key' => $privateKey,
            'certificate_url' => $order['certificate'],
            'order_url' => $orderUrl,
        ];
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{0: string, 1: string} [csrPem, privateKeyPem]
     */
    private function generateCsr(array $domains): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => (int) config('letsencrypt.certificate_key_bits', 2048),
        ];

        $privateKey = openssl_pkey_new($config)
            ?: throw new SslProviderException('Could not generate the certificate private key.');

        $commonName = ltrim($domains[0], '*.');
        $san = implode(',', array_map(fn (string $domain) => 'DNS:'.ltrim($domain, '*.'), $domains));

        $opensslConfig = tempnam(sys_get_temp_dir(), 'omnex-acme-').'.cnf';
        file_put_contents($opensslConfig, "[req]\ndistinguished_name = dn\nreq_extensions = ext\nprompt = no\n[dn]\nCN = {$commonName}\n[ext]\nsubjectAltName = {$san}\n");

        $csr = openssl_csr_new(
            ['commonName' => $commonName],
            $privateKey,
            ['digest_alg' => 'sha256', 'req_extensions' => 'ext', 'config' => $opensslConfig],
        );

        @unlink($opensslConfig);

        if ($csr === false) {
            throw new SslProviderException('Could not generate the certificate signing request.');
        }

        openssl_csr_export($csr, $csrPem);
        openssl_pkey_export($privateKey, $privateKeyPem);

        return [$csrPem, $privateKeyPem];
    }

    /**
     * Register (or fetch) the ACME account and remember its kid.
     */
    private function registerAccount(string $newAccountUrl): void
    {
        if ($this->kid !== null) {
            return;
        }

        $response = $this->request($newAccountUrl, json_encode([
            'termsOfServiceAgreed' => $this->termsOfServiceAgreed,
            'contact' => ['mailto:'.$this->email],
        ]));

        $this->kid = $response->header('Location');

        if (empty($this->kid)) {
            throw new SslProviderException('Let\'s Encrypt did not return an account URL.');
        }
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{status: string, authorizations: array<int, string>, finalize: string, certificate?: string}
     */
    private function newOrder(string $newOrderUrl, array $domains): array
    {
        $response = $this->request($newOrderUrl, json_encode([
            'identifiers' => array_map(
                fn (string $domain) => ['type' => 'dns', 'value' => $domain],
                $domains,
            ),
        ]));

        $order = $this->json($response);

        if (empty($order['authorizations']) || empty($order['finalize'])) {
            throw new SslProviderException('Let\'s Encrypt returned an incomplete order.');
        }

        return $order;
    }

    /**
     * Find the dns-01 challenge inside an authorization object.
     *
     * @param  array<string, mixed>  $authorization
     * @return array{url: string, token: string, domain: string}
     */
    private function dns01Challenge(array $authorization): array
    {
        $challenge = collect($authorization['challenges'] ?? [])
            ->first(fn (array $challenge) => ($challenge['type'] ?? null) === 'dns-01');

        if ($challenge === null) {
            throw new SslProviderException('Let\'s Encrypt offered no dns-01 challenge for the domain.');
        }

        return [
            'url' => $challenge['url'],
            'token' => $challenge['token'],
            'domain' => (string) ($authorization['identifier']['value'] ?? ''),
        ];
    }

    /**
     * The TXT record value for the dns-01 challenge (base64url(SHA256(keyAuthorization))).
     */
    private function dns01Value(string $token): string
    {
        return self::base64url(hash('sha256', $token.'.'.$this->thumbprint(), true));
    }

    /**
     * Poll an authorization/order URL until its status is terminal (or attempts run out).
     *
     * @param  array<int, string>  $terminal
     * @return array<string, mixed>
     */
    private function waitForStatus(string $url, array $terminal, ?array $first = null): array
    {
        $body = $first;

        for ($attempt = 0; $attempt < $this->pollAttempts; $attempt++) {
            if ($body === null) {
                $body = $this->postAsGet($url)->json() ?? [];
            }

            $status = $body['status'] ?? null;

            if ($status === null || in_array($status, $terminal, true)) {
                return $body;
            }

            if ($this->pollIntervalMs > 0) {
                usleep($this->pollIntervalMs * 1000);
            }

            $body = null;
        }

        throw new SslProviderException('Timed out waiting for the Let\'s Encrypt authorization.');
    }

    /**
     * GET the directory and remember the well-known endpoints.
     *
     * @return array<string, string>
     */
    private function directory(): array
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $response = Http::timeout(30)->get($this->directoryUrl);
        $this->throwOnError($response);

        $this->directory = $response->json() ?? [];

        if (empty($this->directory['newNonce']) || empty($this->directory['newAccount']) || empty($this->directory['newOrder'])) {
            throw new SslProviderException('Invalid ACME directory response.');
        }

        return $this->directory;
    }

    private function postAsGet(string $url): Response
    {
        return $this->request($url, '');
    }

    private function request(string $url, string $payloadJson): Response
    {
        $response = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/jose+json'])
            ->post($url, $this->jws($payloadJson, $url));

        $this->captureNonce($response);
        $this->throwOnError($response);

        return $response;
    }

    /**
     * @return array{protected: string, payload: string, signature: string}
     */
    private function jws(string $payloadJson, string $url): array
    {
        $protected = [
            'alg' => 'RS256',
            'nonce' => $this->nonce(),
            'url' => $url,
        ];

        if ($this->kid !== null) {
            $protected['kid'] = $this->kid;
        } else {
            $protected['jwk'] = $this->publicJwk;
        }

        $protectedB64 = self::base64url(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $payloadB64 = self::base64url($payloadJson);

        $signingInput = $protectedB64.'.'.$payloadB64;
        $signature = '';

        openssl_sign($signingInput, $signature, $this->accountKey, OPENSSL_ALGO_SHA256);

        return [
            'protected' => $protectedB64,
            'payload' => $payloadB64,
            'signature' => self::base64url($signature),
        ];
    }

    private function nonce(): string
    {
        if ($this->nonce !== null) {
            $nonce = $this->nonce;
            $this->nonce = null;

            return $nonce;
        }

        $directory = $this->directory();

        $response = Http::timeout(30)->head($directory['newNonce']);
        $this->throwOnError($response);

        $nonce = $response->header('Replay-Nonce');

        if (empty($nonce)) {
            throw new SslProviderException('Let\'s Encrypt did not return a Replay-Nonce.');
        }

        return $nonce;
    }

    private function captureNonce(Response $response): void
    {
        $nonce = $response->header('Replay-Nonce');
        if (! empty($nonce)) {
            $this->nonce = $nonce;
        }
    }

    /**
     * RFC 7638 JWK thumbprint (SHA-256 over the canonical, lexicographically
     * sorted JWK JSON, base64url-encoded).
     */
    private function thumbprint(): string
    {
        return self::base64url(hash('sha256', json_encode($this->publicJwk, JSON_UNESCAPED_SLASHES), true));
    }

    /**
     * @return array{e: string, kty: string, n: string}
     */
    private function buildPublicJwk(): array
    {
        $details = openssl_pkey_get_details($this->accountKey);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new SslProviderException('The Let\'s Encrypt account key must be an RSA key.');
        }

        // Field order is canonical (e, kty, n) so json_encode yields the exact
        // RFC 7638 serialization.
        return [
            'e' => self::base64url($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64url($details['rsa']['n']),
        ];
    }

    private function throwOnError(Response $response): void
    {
        if (! $response->failed() && $response->status() < 400) {
            return;
        }

        $problem = $response->json();
        $detail = is_array($problem) ? ($problem['detail'] ?? null) : null;

        throw new SslProviderException(
            'Let\'s Encrypt request failed ('.($response->status() ?: 'network').'): '.($detail ?: $response->body()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function derFromPem(string $pem, string $label): string
    {
        $pattern = '/-----BEGIN '.preg_quote($label, '/').'-----([A-Za-z0-9+\/\s=]+)-----END '.preg_quote($label, '/').'-----/';

        if (preg_match($pattern, $pem, $matches) !== 1) {
            throw new SslProviderException("Could not parse the {$label} PEM block.");
        }

        return base64_decode(preg_replace('/\s+/', '', $matches[1]));
    }
}
