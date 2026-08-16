<?php

namespace App\Support\Leads;

use App\Models\ContactLead;
use App\Models\Membership;
use App\Models\Role;
use App\Support\Notifications\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class ContactLeadService
{
    /**
     * Create a public contact lead. Throws ValidationException on invalid
     * input, on a failed reCAPTCHA challenge (when configured), or on a
     * filled honeypot (`website`) which is silently treated as spam.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta  source / ip_address / user_agent
     */
    public function create(array $input, array $meta = []): ContactLead
    {
        $this->verifyRecaptcha($input['recaptcha_token'] ?? null);
        $input = $this->validate($input);

        $lead = ContactLead::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'company' => $input['company'] ?? null,
            'subject' => $input['subject'],
            'message' => $input['message'],
            'source' => $meta['source'] ?? 'marketing-site',
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            'status' => ContactLead::STATUS_NEW,
        ]);

        $this->notifyOwners($lead);

        return $lead;
    }

    /**
     * Optional reCAPTCHA v3 gate. Disabled until OMNEX_RECAPTCHA_SECRET is
     * set. When enabled, the visitor must send a token that scores at or
     * above the configured threshold.
     */
    private function verifyRecaptcha(?string $token): void
    {
        $secret = config('omnex.leads.recaptcha_secret');
        if ($secret === null || $secret === '') {
            return;
        }

        if ($token === null || $token === '') {
            throw ValidationException::withMessages(['recaptcha' => ['The recaptcha verification failed.']]);
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => request()->ip(),
        ])->json();

        $threshold = (float) config('omnex.leads.recaptcha_score_threshold', 0.5);
        $score = (float) ($response['score'] ?? 0);
        $success = (bool) ($response['success'] ?? false);

        if (! $success || $score < $threshold) {
            throw ValidationException::withMessages(['recaptcha' => ['The recaptcha verification failed.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $validator = validator($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Honeypot: real visitors never see this field. If it is filled,
            // the submission is spam — reject with a generic validation error.
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'website.max' => 'The name field is required.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /** Notify every platform owner so the team can follow up on the lead. */
    private function notifyOwners(ContactLead $lead): void
    {
        $ownerRoleId = Role::where('key', 'owner')->value('id');
        if ($ownerRoleId === null) {
            return;
        }

        $ownerUserIds = Membership::where('role_id', $ownerRoleId)
            ->where('status', 'active')
            ->distinct()
            ->pluck('user_id');

        foreach ($ownerUserIds as $userId) {
            NotificationService::send(
                userId: $userId,
                type: 'lead',
                title: "New contact lead — {$lead->name}",
                body: $lead->subject,
                data: ['lead_id' => $lead->id, 'route' => '/marketing/contact'],
                organizationId: null,
                severity: 'info',
            );
        }
    }
}
