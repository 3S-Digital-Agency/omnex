import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { SecurityFindingDto, SecuritySeverity } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn } from '../../lib/utils';

const SEVERITY_TONE: Record<SecuritySeverity, 'danger' | 'warning' | 'neutral'> = {
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
};

const FINDING_ACTIONS: Record<string, { to: string; labelKey: string }> = {
  mfa: { to: '/settings', labelKey: 'security.action.mfa' },
  email: { to: '/settings', labelKey: 'security.action.email' },
  single_member: { to: '/members', labelKey: 'security.action.singleMember' },
  domain_expiring: { to: '/domains', labelKey: 'security.action.domainExpiring' },
  dnssec_disabled: { to: '/domains', labelKey: 'security.action.dnssec' },
};

export function SecurityPage() {
  const { activeOrganization, hasPermission } = useAuth();
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const orgId = activeOrganization?.id;
  const canManage = hasPermission('security.manage');

  const report = useQuery({
    queryKey: ['security', orgId],
    queryFn: () => api.getSecurityScore(),
    enabled: !!orgId,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['security', orgId] });

  const scan = useMutation({
    mutationFn: () => api.scanSecurity(),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.security.scanned'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const dismiss = useMutation({
    mutationFn: (id: string) => api.dismissSecurityFinding(id),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.security.dismissed'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const reopen = useMutation({
    mutationFn: (id: string) => api.reopenSecurityFinding(id),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.security.reopened'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const score = report.data?.score ?? 0;
  const summary = report.data?.summary;

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('security.title')}</h1>
          <p className="text-sm text-zinc-400">{t('security.subtitle', { name: activeOrganization?.name ?? '' })}</p>
        </div>
        {canManage ? (
          <Button variant="outline" loading={scan.isPending} onClick={() => scan.mutate()}>
            <RefreshCw className="h-4 w-4" /> {t('security.scan')}
          </Button>
        ) : null}
      </header>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="flex flex-col items-center justify-center p-6 text-center">
          <p className="text-sm text-zinc-400">{t('security.score')}</p>
          <p
            className={cn(
              'mt-2 text-5xl font-bold',
              score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400',
            )}
          >
            {score}
          </p>
          <p className="text-xs text-zinc-500">/ 100</p>

          {summary ? (
            <div className="mt-4 flex flex-wrap justify-center gap-2 text-xs">
              <Badge tone={summary.high > 0 ? 'danger' : 'neutral'}>{summary.high} high</Badge>
              <Badge tone={summary.medium > 0 ? 'warning' : 'neutral'}>{summary.medium} medium</Badge>
              <Badge tone={summary.low > 0 ? 'neutral' : 'neutral'}>{summary.low} low</Badge>
              <Badge tone="neutral">{summary.dismissed} {t('security.dismissed').toLowerCase()}</Badge>
            </div>
          ) : null}
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('security.findings')} description={t('security.findingsDescription')} />
          {report.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : report.data && report.data.findings.length > 0 ? (
            <ul className="divide-y divide-edge">
              {report.data.findings.map((finding) => (
                <FindingRow
                  key={finding.id}
                  finding={finding}
                  canManage={canManage}
                  onDismiss={() => dismiss.mutate(finding.id)}
                  onReopen={() => reopen.mutate(finding.id)}
                />
              ))}
            </ul>
          ) : (
            <div className="p-5">
              <EmptyState title={t('security.allClear')} description={t('security.allClearDescription')} />
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

function FindingRow({
  finding,
  canManage,
  onDismiss,
  onReopen,
}: {
  finding: SecurityFindingDto;
  canManage: boolean;
  onDismiss: () => void;
  onReopen: () => void;
}) {
  const { t } = useI18n();
  const action = FINDING_ACTIONS[finding.rule];

  return (
    <li className="flex items-start gap-3 px-5 py-4">
      <Badge tone={finding.status === 'dismissed' ? 'neutral' : SEVERITY_TONE[finding.severity]}>
        {finding.status === 'dismissed' ? t('security.dismissed') : finding.severity}
      </Badge>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-white">
          {t(`security.finding.${finding.rule}`)}
          {finding.status === 'dismissed' ? <span className="ml-2 text-xs text-zinc-500">({t('security.dismissed')})</span> : null}
        </p>
        <p className="mt-0.5 text-xs text-zinc-500">{t(`security.finding.${finding.rule}Impact`)}</p>
        <p className="mt-1 text-xs text-zinc-400">
          {t(`security.finding.${finding.rule}Remediation`)}
        </p>
        {contextLine(finding) ? <p className="mt-1 font-mono text-xs text-zinc-500">{contextLine(finding)}</p> : null}
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {action ? (
          <Link to={action.to} className="text-sm text-brand-400 hover:underline">
            {t(action.labelKey)}
          </Link>
        ) : null}
        {canManage ? (
          finding.status === 'dismissed' ? (
            <Button size="sm" variant="outline" onClick={onReopen}>
              {t('security.reopen')}
            </Button>
          ) : (
            <Button size="sm" variant="ghost" onClick={onDismiss}>
              {t('security.dismiss')}
            </Button>
          )
        ) : null}
      </div>
    </li>
  );
}

function contextLine(finding: SecurityFindingDto): string | null {
  const metadata = finding.metadata ?? {};
  const { t } = useI18n();

  switch (finding.rule) {
    case 'mfa':
    case 'email':
      return typeof metadata.email === 'string' ? metadata.email : null;
    case 'domain_expiring':
      return `${metadata.domain} · ${t('security.expiresIn', { days: Number(metadata.days ?? 0) })}`;
    case 'dnssec_disabled':
      return t('security.forDomain', { domain: String(metadata.domain ?? '') });
    default:
      return null;
  }
}
