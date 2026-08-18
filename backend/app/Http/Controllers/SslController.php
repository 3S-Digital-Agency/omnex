<?php

namespace App\Http\Controllers;

use App\Http\Resources\SslCertificateResource;
use App\Models\Domain;
use App\Support\Ssl\SslProviderException;
use App\Support\Ssl\SslService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function __construct(private SslService $ssl) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        return response()->json(['data' => $this->ssl->providers()]);
    }

    public function activeProvider(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        return response()->json(['data' => $this->ssl->activeProvider()]);
    }

    public function setProvider(Request $request): JsonResponse
    {
        $this->authorize('domains.manage');

        $validated = $request->validate(['name' => ['required', 'string', 'max:32']]);

        return response()->json(['data' => $this->ssl->setProvider($validated['name'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        return response()->json([
            'data' => SslCertificateResource::collection($this->ssl->certificates()),
        ]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.read');

        $certificate = $this->ssl->certificate(Domain::findOrFail($domain));

        abort_if($certificate === null, 404, 'No certificate issued for this domain.');

        return response()->json(new SslCertificateResource($certificate->load('domain')));
    }

    public function issue(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.manage');

        $domain = Domain::findOrFail($domain);

        try {
            $certificate = $this->ssl->issue($domain);
        } catch (SslProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new SslCertificateResource($certificate->load('domain')), 201);
    }

    public function renew(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.manage');

        $domain = Domain::findOrFail($domain);

        try {
            $certificate = $this->ssl->renew($domain);
        } catch (SslProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new SslCertificateResource($certificate->load('domain')));
    }

    public function revoke(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.manage');

        $domain = Domain::findOrFail($domain);

        try {
            $certificate = $this->ssl->revoke($domain);
        } catch (SslProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new SslCertificateResource($certificate->load('domain')));
    }
}
