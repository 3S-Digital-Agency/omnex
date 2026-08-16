<?php

namespace App\Support\Security;

use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\SecurityFinding;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;

/**
 * OMNEX Security Center engine. A scan evaluates a set of deterministic rules
 * against the tenant's live state, upserts the result into `security_findings`
 * and derives a 100-point Security Score from the severity of the open
 * findings. Dismissed findings are acknowledged risks (excluded from the
 * score); resolved findings are fixed risks kept for the audit trail.
 */
final class SecurityService
{
    /**
     * Evaluate every rule, reconcile the findings table with the result and
     * return the fresh report.
     *
     * @return array{score: int, summary: array<string, int>, findings: array<int, SecurityFinding>}
     */
    public function scan(bool $audit = false): array
    {
        $candidates = $this->runChecks();

        $this->reconcile($candidates);

        if ($audit) {
            $open = SecurityFinding::query()->where('status', 'open')->count();

            AuditLogger::record(
                'security.scan_completed',
                'organization',
                app(TenantContext::class)->id(),
                null,
                ['open_findings' => $open],
            );
        }

        return $this->report();
    }

    /**
     * @return array{score: int, summary: array<string, int>, findings: array<int, SecurityFinding>}
     */
    public function report(): array
    {
        $findings = SecurityFinding::query()
            ->where('status', 'open')
            ->orderBy('severity', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $penalties = config('nexus.security.severity_penalties', []);

        $score = 100 - $findings->sum(fn (SecurityFinding $finding) => (int) ($penalties[$finding->severity] ?? 0));
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'summary' => $this->summary(),
            'findings' => $findings->all(),
        ];
    }

    public function dismiss(SecurityFinding $finding): SecurityFinding
    {
        $finding->update([
            'status' => 'dismissed',
            'dismissed_at' => now(),
            'resolved_at' => null,
        ]);

        AuditLogger::record('security.finding_dismissed', 'security_finding', $finding->id, null, ['rule' => $finding->rule]);

        return $finding;
    }

    public function reopen(SecurityFinding $finding): SecurityFinding
    {
        $finding->update([
            'status' => 'open',
            'dismissed_at' => null,
        ]);

        AuditLogger::record('security.finding_reopened', 'security_finding', $finding->id, null, ['rule' => $finding->rule]);

        return $finding;
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $findings = SecurityFinding::query()->get();

        return [
            'open' => $findings->where('status', 'open')->count(),
            'resolved' => $findings->where('status', 'resolved')->count(),
            'dismissed' => $findings->where('status', 'dismissed')->count(),
            'high' => $findings->where('status', 'open')->where('severity', 'high')->count(),
            'medium' => $findings->where('status', 'open')->where('severity', 'medium')->count(),
            'low' => $findings->where('status', 'open')->where('severity', 'low')->count(),
        ];
    }

    /**
     * Each candidate represents one failing check: a stable identity (rule +
     * resource) plus its severity and display metadata.
     *
     * @return array<int, array{rule: string, dedupe_key: string, severity: string, resource_type: ?string, resource_id: ?string, metadata: array<string, mixed>}>
     */
    private function runChecks(): array
    {
        $candidates = [];

        $memberships = Membership::query()
            ->where('status', 'active')
            ->with('user')
            ->get();

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user === null) {
                continue;
            }

            if (! $user->mfa_enabled) {
                $candidates[] = $this->candidate('mfa', 'high', 'user', $user->id, [
                    'name' => $user->name,
                    'email' => $user->email,
                ]);
            }

            if ($user->email_verified_at === null) {
                $candidates[] = $this->candidate('email', 'low', 'user', $user->id, [
                    'name' => $user->name,
                    'email' => $user->email,
                ]);
            }
        }

        if ($memberships->count() <= 1) {
            $candidates[] = $this->candidate('single_member', 'medium', null, null, [
                'member_count' => $memberships->count(),
            ]);
        }

        $warningDays = (int) config('nexus.domain.expiration_warning_days', 30);

        foreach (Domain::query()->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays($warningDays))->get() as $domain) {
            $candidates[] = $this->candidate('domain_expiring', 'medium', 'domain', $domain->id, [
                'domain' => $domain->name,
                'expires_at' => $domain->expires_at?->toIso8601String(),
                'days' => max(0, (int) now()->diffInDays($domain->expires_at)),
            ]);
        }

        foreach (DnsZone::query()->where('dnssec_enabled', false)->with('domain')->get() as $zone) {
            $candidates[] = $this->candidate('dnssec_disabled', 'low', 'dns_zone', $zone->id, [
                'domain' => $zone->domain?->name,
            ]);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{rule: string, dedupe_key: string, severity: string, resource_type: ?string, resource_id: ?string, metadata: array<string, mixed>}
     */
    private function candidate(string $rule, string $severity, ?string $resourceType, ?string $resourceId, array $metadata): array
    {
        return [
            'rule' => $rule,
            'dedupe_key' => $rule.':'.($resourceId ?? 'org'),
            'severity' => $severity,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
        ];
    }

    /**
     * Reconcile the persisted findings with the freshly computed candidates:
     * create new open findings, resolve ones that are now fixed, and keep
     * dismissed findings dismissed while their underlying risk persists.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function reconcile(array $candidates): void
    {
        $candidateKeys = array_column($candidates, 'dedupe_key');
        $candidatesByKey = collect($candidates)->keyBy('dedupe_key');

        $existing = SecurityFinding::query()->get();

        foreach ($existing as $finding) {
            if (! in_array($finding->dedupe_key, $candidateKeys, true) && $finding->status !== 'resolved') {
                $finding->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'dismissed_at' => null,
                ]);
            }
        }

        foreach ($candidates as $candidate) {
            $finding = $existing->firstWhere('dedupe_key', $candidate['dedupe_key']);

            if ($finding === null) {
                SecurityFinding::create([
                    'rule' => $candidate['rule'],
                    'dedupe_key' => $candidate['dedupe_key'],
                    'severity' => $candidate['severity'],
                    'status' => 'open',
                    'resource_type' => $candidate['resource_type'],
                    'resource_id' => $candidate['resource_id'],
                    'metadata' => $candidate['metadata'],
                ]);

                continue;
            }

            if ($finding->status === 'resolved') {
                $finding->update([
                    'status' => 'open',
                    'severity' => $candidate['severity'],
                    'metadata' => $candidate['metadata'],
                    'resolved_at' => null,
                    'dismissed_at' => null,
                ]);

                continue;
            }

            if ($finding->status === 'open') {
                $finding->update([
                    'severity' => $candidate['severity'],
                    'metadata' => $candidate['metadata'],
                ]);
            }
        }
    }
}
