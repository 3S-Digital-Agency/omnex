<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactLeadResource;
use App\Models\ContactLead;
use App\Support\Leads\ContactLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactLeadController extends Controller
{
    public function __construct(private readonly ContactLeadService $leads) {}

    /**
     * Public entry point for the marketing-site contact form. No auth, no
     * tenant — the visitor is not signed in.
     */
    public function store(Request $request): JsonResponse
    {
        $lead = $this->leads->create(
            $request->only(['name', 'email', 'company', 'subject', 'message', 'website', 'recaptcha_token']),
            [
                'source' => $request->input('source') ?: 'marketing-site',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        return (new ContactLeadResource($lead))->response()->setStatusCode(201);
    }

    /**
     * Back-office listing for platform owners (auth required).
     */
    public function index(Request $request): JsonResponse
    {
        $leads = ContactLead::query()
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ContactLeadResource::collection($leads)->response();
    }
}
