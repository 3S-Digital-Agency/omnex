<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    /**
     * Aggregated activity feed. Backed by the persisted event stream
     * (audit_logs) for Phase 2; deployments, alerts and incidents join the
     * same stream in later phases. Supports an incremental cursor so the
     * client can poll only for new events.
     *
     * GET /api/v1/activity?since=<id>&per_page=50
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('audit.read');

        $since = (int) $request->query('since', 0);

        $logs = AuditLog::query()
            ->with('user')
            ->orderByDesc('id')
            ->when($since > 0, fn ($query) => $query->where('id', '>', $since))
            ->limit(min((int) $request->query('per_page', 50), 100))
            ->get();

        return response()->json([
            'data' => $logs->map(fn (AuditLog $log) => $this->toActivity($log))->values(),
            'latest_id' => $logs->max('id') ?? $since,
        ]);
    }

    private function toActivity(AuditLog $log): array
    {
        [$type, $severity, $title] = $this->classify($log->action);

        return [
            'id' => $log->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => $this->describe($log),
            'actor' => $log->user?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function classify(string $action): array
    {
        return match ($action) {
            'user.registered' => ['auth', 'info', 'User registered'],
            'user.logged_in' => ['auth', 'info', 'Sign in'],
            'user.logged_out' => ['auth', 'info', 'Sign out'],
            'user.mfa_enabled' => ['security', 'success', 'MFA enabled'],
            'user.mfa_disabled' => ['security', 'warning', 'MFA disabled'],
            'user.mfa_failed' => ['security', 'warning', 'Failed MFA attempt'],
            'organization.created' => ['organization', 'success', 'Organization created'],
            'organization.switched' => ['organization', 'info', 'Switched organization'],
            'member.invited' => ['member', 'info', 'Member invited'],
            'member.invitation_accepted' => ['member', 'success', 'Invitation accepted'],
            'member.invitation_cancelled' => ['member', 'info', 'Invitation cancelled'],
            'member.role_changed' => ['member', 'warning', 'Role changed'],
            'member.removed' => ['member', 'warning', 'Member removed'],
            'domain.registered' => ['domain', 'success', 'Domain registered'],
            'domain.renewed' => ['domain', 'success', 'Domain renewed'],
            'domain.transferred' => ['domain', 'success', 'Domain transferred'],
            'domain.updated' => ['domain', 'info', 'Domain settings updated'],
            'domain.expiring' => ['domain', 'warning', 'Domain expiring'],
            'dns.record_created' => ['dns', 'info', 'DNS record created'],
            'dns.record_updated' => ['dns', 'warning', 'DNS record updated'],
            'dns.record_deleted' => ['dns', 'warning', 'DNS record deleted'],
            'dns.record_rolled_back' => ['dns', 'warning', 'DNS change rolled back'],
            'dns.record_imported' => ['dns', 'warning', 'DNS zone imported'],
            default => ['system', 'info', $action],
        };
    }

    private function describe(AuditLog $log): ?string
    {
        if ($log->after && isset($log->after['email'])) {
            return $log->after['email'];
        }

        if ($log->before && isset($log->before['email'])) {
            return $log->before['email'];
        }

        if ($log->after && isset($log->after['name'])) {
            return $log->after['name'];
        }

        // DNS record snapshots carry type/name/content.
        if ($log->after && isset($log->after['type'])) {
            $name = $log->after['name'] ?? '@';

            return sprintf('%s %s → %s', $log->after['type'], $name, $log->after['content'] ?? '');
        }

        if ($log->before && isset($log->before['type'])) {
            $name = $log->before['name'] ?? '@';

            return sprintf('%s %s → %s', $log->before['type'], $name, $log->before['content'] ?? '');
        }

        return $log->resource_type !== null
            ? "{$log->resource_type} {$log->resource_id}"
            : null;
    }
}
