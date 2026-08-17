<?php

namespace App\Support\Security;

use App\Contracts\SslCheckerInterface;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SecurityFinding;
use App\Models\SecurityScoreSample;
use App\Models\Server;
use App\Models\Site;
use App\Models\SslCheck;
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
        $this->runSslChecks();

        $candidates = $this->runChecks();

        $this->reconcile($candidates);

        $this->recordSample(force: $audit);

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

        $penalties = config('omnex.security.severity_penalties', []);

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

        $this->recordSample();

        return $finding;
    }

    public function reopen(SecurityFinding $finding): SecurityFinding
    {
        $finding->update([
            'status' => 'open',
            'dismissed_at' => null,
        ]);

        AuditLogger::record('security.finding_reopened', 'security_finding', $finding->id, null, ['rule' => $finding->rule]);

        $this->recordSample();

        return $finding;
    }

    /**
     * Persist one score sample. A new row is only written when the score
     * actually changed (or `force` is set, e.g. a manual scan) so the
     * timeline stays meaningful and the table does not grow on every read.
     */
    public function recordSample(bool $force = false): void
    {
        $report = $this->report();
        $score = $report['score'];

        $last = SecurityScoreSample::query()->latest('created_at')->first();
        if (! $force && $last !== null && (int) $last->score === $score) {
            return;
        }

        SecurityScoreSample::create([
            'score' => $score,
            'open' => $report['summary']['open'],
            'high' => $report['summary']['high'],
            'medium' => $report['summary']['medium'],
            'low' => $report['summary']['low'],
        ]);
    }

    /**
     * Chronological score samples (newest last) — the scan history / score
     * evolution for the cockpit.
     */
    public function history(int $limit = 30): array
    {
        return SecurityScoreSample::query()
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->reverse()
            ->values()
            ->all();
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

        $policy = Organization::find(app(TenantContext::class)->id())?->mfa_policy ?? 'optional';
        $membersWithoutMfa = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user === null) {
                continue;
            }

            if (! $user->mfa_enabled) {
                $membersWithoutMfa[] = ['name' => $user->name, 'email' => $user->email];

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

        // MFA enforcement policy: when the organization requires MFA, an
        // org-level finding stays open until every active member complies.
        if ($policy === 'required' && $membersWithoutMfa !== []) {
            $candidates[] = $this->candidate('mfa_enforcement', 'high', null, null, [
                'policy' => 'required',
                'affected_users' => $membersWithoutMfa,
            ]);
        }

        if ($memberships->count() <= 1) {
            $candidates[] = $this->candidate('single_member', 'medium', null, null, [
                'member_count' => $memberships->count(),
            ]);
        }

        $warningDays = (int) config('omnex.domain.expiration_warning_days', 30);

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

        // SSL / certificate monitoring: certificates that are expiring or
        // invalid (including sites not served over HTTPS), from the checks.
        foreach (SslCheck::query()->whereIn('status', [SslCheck::STATUS_EXPIRING, SslCheck::STATUS_INVALID])->get() as $check) {
            $severity = $check->status === SslCheck::STATUS_INVALID ? 'high' : 'medium';

            $candidates[] = $this->candidate('ssl_'.$check->status, $severity, $check->target_type, $check->target_id, [
                'target' => $check->details['target'] ?? null,
                'days_remaining' => $check->days_remaining,
                'checked_at' => $check->checked_at?->toIso8601String(),
            ]);
        }

        // Backup status: servers with scheduled snapshots disabled have no
        // recovery point and are flagged until a frequency is configured.
        foreach (Server::query()->get() as $server) {
            if ($server->snapshot_frequency === 'disabled') {
                $candidates[] = $this->candidate('backup_disabled', 'medium', 'server', $server->id, [
                    'server' => $server->name,
                    'provider' => $server->provider,
                ]);
            }
        }

        return $candidates;
    }

    /**
     * Refresh the certificate-monitoring checks for every managed site and
     * domain through the configured checker, before deriving findings.
     */
    private function runSslChecks(): void
    {
        $checker = app(SslCheckerInterface::class);

        $targets = [];
        foreach (Site::query()->get() as $site) {
            $targets[] = ['site', $site->id, (string) ($site->url ?? '')];
        }
        foreach (Domain::query()->get() as $domain) {
            $targets[] = ['domain', $domain->id, $domain->name];
        }

        foreach ($targets as [$type, $id, $name]) {
            $result = $checker->check($type, $id, $name);

            SslCheck::updateOrCreate(
                ['target_type' => $type, 'target_id' => $id],
                [
                    'status' => $result['status'],
                    'days_remaining' => $result['days_remaining'],
                    'details' => $result['details'],
                    'checked_at' => $result['checked_at'],
                ],
            );
        }
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
