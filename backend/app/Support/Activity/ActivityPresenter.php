<?php

namespace App\Support\Activity;

use App\Models\AuditLog;

/**
 * Formats an AuditLog entry into the activity-feed shape consumed by both the
 * REST endpoint (GET /activity) and the real-time SSE stream.
 */
final class ActivityPresenter
{
    /**
     * @return array{id: int, type: string, severity: string, title: string, description: ?string, actor: ?string, created_at: ?string}
     */
    public static function toArray(AuditLog $log): array
    {
        [$type, $severity, $title] = self::classify($log->action);

        return [
            'id' => $log->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => self::describe($log),
            'actor' => $log->user?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function classify(string $action): array
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
            'drive.folder_created' => ['storage', 'info', 'Folder created'],
            'drive.folder_renamed' => ['storage', 'info', 'Folder renamed'],
            'drive.folder_deleted' => ['storage', 'warning', 'Folder deleted'],
            'drive.file_uploaded' => ['storage', 'success', 'File uploaded'],
            'drive.file_updated' => ['storage', 'info', 'File updated'],
            'drive.file_trashed' => ['storage', 'warning', 'File trashed'],
            'drive.file_restored' => ['storage', 'success', 'File restored'],
            'drive.file_deleted' => ['storage', 'warning', 'File deleted'],
            'drive.file_versioned' => ['storage', 'info', 'File versioned'],
            'site.created' => ['deployment', 'info', 'Site created'],
            'site.updated' => ['deployment', 'info', 'Site updated'],
            'site.deployed' => ['deployment', 'success', 'Deployment completed'],
            'site.deploy_failed' => ['deployment', 'danger', 'Deployment failed'],
            'site.deploy_failed_rolled_back' => ['deployment', 'warning', 'Deploy failed — rolled back'],
            'site.rolled_back' => ['deployment', 'warning', 'Rolled back'],
            'site.deleted' => ['deployment', 'warning', 'Site deleted'],
            'security.finding_dismissed' => ['security', 'info', 'Finding dismissed'],
            'security.finding_reopened' => ['security', 'warning', 'Finding reopened'],
            default => ['system', 'info', $action],
        };
    }

    public static function describe(AuditLog $log): ?string
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
