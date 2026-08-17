import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Activity, Fingerprint, RefreshCw, ShieldCheck, Smartphone, TrendingUp } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type {
  MfaPolicy,
  SecurityFindingDto,
  SecuritySeverity,
  SessionDto,
  SslCheckDto,
  SslCheckStatus,
} from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Select } from '../../components/ui/Select';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { AreaChart } from '../../components/viz/AreaChart';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { Donut } from '../../components/viz/Donut';
import { PeriodSelector } from '../../components/viz/PeriodSelector';
import type { PeriodDays } from '../../components/viz/PeriodSelector';
import { StackedBar } from '../../components/viz/StackedBar';
import { useAnimatedNumber } from '../../components/viz/useMotion';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';

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
  backup_disabled: { to: '/cloud', labelKey: 'security.action.backup' },
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

  const settings = useQuery({
    queryKey: ['security', orgId, 'settings'],
    queryFn: () => api.getSecuritySettings(),
    enabled: !!orgId,
  });

  const updatePolicy = useMutation({
    mutationFn: (mfa_policy: MfaPolicy) => api.updateSecuritySettings({ mfa_policy }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['security', orgId, 'settings'] });
      void invalidate();
      toast(t('toast.security.policyUpdated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const sslChecks = useQuery({
    queryKey: ['security', orgId, 'ssl'],
    queryFn: () => api.listSslChecks(),
    enabled: !!orgId,
  });

  const history = useQuery({
    queryKey: ['security', orgId, 'history'],
    queryFn: () => api.getSecurityHistory(),
    enabled: !!orgId,
  });

  const sessions = useQuery({ queryKey: ['sessions'], queryFn: () => api.listSessions() });

  const revokeSession = useMutation({
    mutationFn: (id: string) => api.revokeSession(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['sessions'] });
      toast(t('toast.security.sessionRevoked'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const revokeOthers = useMutation({
    mutationFn: () => api.revokeOtherSessions(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['sessions'] });
      toast(t('toast.security.sessionsRevoked'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const score = report.data?.score ?? 0;
  const animatedScore = Math.round(useAnimatedNumber(score));
  const summary = report.data?.summary;

  const [period, setPeriod] = useState<PeriodDays>(90);
  const filteredSamples = useMemo(() => {
    const samples = history.data?.samples ?? [];
    if (period >= 90) return samples;
    const cutoff = Date.now() - period * 86400000;
    return samples.filter((sample) => sample.created_at && new Date(sample.created_at).getTime() >= cutoff);
  }, [history.data, period]);

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

      {/* Cockpit row: score donut + score evolution timeline */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="flex flex-col items-center justify-center p-6 text-center">
          <p className="text-sm text-zinc-400">{t('security.score')}</p>
          <div className="mt-3">
            <Donut
              value={score}
              size={148}
              thickness={12}
              className={cn(
                score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400',
              )}
              label={`${t('security.score')}: ${score}/100`}
            >
              <span
                className={cn(
                  'text-3xl font-bold',
                  score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400',
                )}
              >
                {animatedScore}
              </span>
              <span className="text-xs text-zinc-500">/ 100</span>
            </Donut>
          </div>

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
          <CardHeader
            title={t('security.timeline.title')}
            description={t('security.timeline.description')}
            action={
              <span className="flex items-center gap-2">
                <span className="inline-flex items-center gap-1.5 text-xs text-zinc-400">
                  <TrendingUp className="h-4 w-4" />
                  {filteredSamples.length
                    ? `+${Math.max(0, (filteredSamples[filteredSamples.length - 1]?.score ?? 0) - (filteredSamples[0]?.score ?? 0))}`
                    : 0}
                </span>
                <PeriodSelector value={period} onChange={setPeriod} />
              </span>
            }
          />
          {history.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : filteredSamples.length > 1 ? (
            <div className="px-5 pb-2 pt-4">
              <AreaChart
                values={filteredSamples.map((sample) => sample.score)}
                labels={filteredSamples.map((sample) =>
                  sample.created_at ? formatDate(sample.created_at) : ''
                )}
                height={110}
                className={score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400'}
                label={`${t('security.timeline.title')}: ${filteredSamples.map((s) => s.score).join(', ')}`}
              />
              <ScanHistory samples={filteredSamples} t={t} />
            </div>
          ) : (
            <div className="p-5">
              <EmptyState title={t('security.timeline.empty')} />
            </div>
          )}
        </Card>
      </div>

      {/* Cockpit row: severity distribution + remediation progress */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader title={t('security.distribution.title')} description={t('security.distribution.description')} />
          <div className="flex items-center justify-center gap-6 px-5 pb-5">
            <DistributionDonut
              segments={
                summary
                  ? [
                      { value: summary.high, color: 'text-red-400', label: t('security.severity.high') },
                      { value: summary.medium, color: 'text-amber-400', label: t('security.severity.medium') },
                      { value: summary.low, color: 'text-zinc-300', label: t('security.severity.low') },
                    ]
                  : []
              }
              size={120}
              thickness={11}
              center={
                <div className="text-center">
                  <span className="block text-2xl font-bold text-white">{summary?.open ?? 0}</span>
                  <span className="block text-[10px] uppercase tracking-wider text-zinc-500">{t('security.open')}</span>
                </div>
              }
              label={t('security.distribution.title')}
            />
            <ul className="space-y-2 text-xs">
              {(['high', 'medium', 'low'] as const).map((severity) => (
                <li key={severity} className="flex items-center gap-2">
                  <span
                    className={cn(
                      'h-2.5 w-2.5 rounded-sm',
                      severity === 'high'
                        ? 'bg-red-400'
                        : severity === 'medium'
                          ? 'bg-amber-400'
                          : 'bg-zinc-300',
                    )}
                    aria-hidden="true"
                  />
                  <span className="text-zinc-400">{t(`security.severity.${severity}`)}</span>
                  <span className="ml-auto font-semibold text-white">{summary?.[severity] ?? 0}</span>
                </li>
              ))}
            </ul>
          </div>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('security.remediation.title')} description={t('security.remediation.description')} />
          <div className="px-5 pb-5">
            {summary ? (
              <StackedBar
                items={[
                  { value: summary.resolved, color: 'bg-emerald-400', label: t('security.remediation.resolved') },
                  { value: summary.open, color: 'bg-amber-400', label: t('security.remediation.open') },
                  { value: summary.dismissed, color: 'bg-zinc-500', label: t('security.remediation.dismissed') },
                ]}
                total={summary.resolved + summary.open + summary.dismissed}
                height={12}
              />
            ) : null}
            <div className="mt-4 grid grid-cols-3 gap-3">
              <div className="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-3 text-center">
                <p className="text-2xl font-bold text-emerald-300">{summary?.resolved ?? 0}</p>
                <p className="mt-0.5 text-xs text-zinc-500">{t('security.remediation.resolved')}</p>
              </div>
              <div className="rounded-xl border border-amber-400/20 bg-amber-400/5 p-3 text-center">
                <p className="text-2xl font-bold text-amber-300">{summary?.open ?? 0}</p>
                <p className="mt-0.5 text-xs text-zinc-500">{t('security.remediation.open')}</p>
              </div>
              <div className="rounded-xl border border-edge p-3 text-center">
                <p className="text-2xl font-bold text-white">
                  {summary
                    ? Math.round(
                        (summary.resolved /
                          Math.max(summary.resolved + summary.open + summary.dismissed, 1)) *
                          100,
                      )
                    : 0}
                  %
                </p>
                <p className="mt-0.5 text-xs text-zinc-500">{t('security.remediation.rate')}</p>
              </div>
            </div>
          </div>
        </Card>
      </div>

      {/* Findings */}
      <Card>
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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* MFA enforcement policy */}
        <Card>
          <CardHeader title={t('security.policy.title')} description={t('security.policy.description')} />
          <div className="px-5 py-4">
            <div className="flex items-center gap-2 text-zinc-400">
              <Fingerprint className="h-4 w-4" />
              <span className="text-xs font-semibold uppercase tracking-wider">{t('security.policy.title')}</span>
            </div>
            <Select
              className="mt-3"
              value={settings.data?.mfa_policy ?? 'optional'}
              disabled={!canManage || updatePolicy.isPending || settings.isLoading}
              onChange={(event) => updatePolicy.mutate(event.target.value as MfaPolicy)}
              aria-label={t('security.policy.title')}
            >
              <option value="optional">{t('security.policy.optional')}</option>
              <option value="required">{t('security.policy.required')}</option>
            </Select>
            <p className="mt-2 text-xs leading-relaxed text-zinc-500">
              {settings.data?.mfa_policy === 'required'
                ? t('security.policy.requiredHint')
                : t('security.policy.optionalHint')}
            </p>
          </div>
        </Card>

        {/* SSL / certificate monitoring */}
        <Card>
          <CardHeader title={t('security.ssl.title')} description={t('security.ssl.description')} />
          {sslChecks.isLoading ? (
            <div className="flex justify-center py-8">
              <Spinner />
            </div>
          ) : sslChecks.data && sslChecks.data.length > 0 ? (
            <ul className="divide-y divide-edge">
              {sslChecks.data.map((check) => (
                <li key={check.id} className="flex items-center justify-between gap-3 px-5 py-3">
                  <div className="flex min-w-0 items-center gap-2">
                    <ShieldCheck className="h-4 w-4 shrink-0 text-zinc-500" />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-white">
                        {sslTargetLabel(t, check)}
                      </p>
                      <p className="text-xs text-zinc-500">
                        {t(`security.ssl.status.${check.status}`)}
                        {check.days_remaining !== null
                          ? ` · ${t('security.ssl.days', { days: check.days_remaining })}`
                          : ''}
                      </p>
                    </div>
                  </div>
                  <Badge tone={SSL_TONE[check.status]}>{t(`security.ssl.status.${check.status}`)}</Badge>
                </li>
              ))}
            </ul>
          ) : (
            <div className="p-5">
              <EmptyState title={t('security.ssl.empty')} description={t('security.ssl.emptyDescription')} />
            </div>
          )}
        </Card>
      </div>

      {/* Active sessions */}
      <Card>
        <CardHeader
          title={t('security.sessions.title')}
          description={t('security.sessions.description')}
          action={
            <Button variant="outline" size="sm" loading={revokeOthers.isPending} onClick={() => revokeOthers.mutate()}>
              {t('security.sessions.revokeOthers')}
            </Button>
          }
        />
        {sessions.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : sessions.data && sessions.data.length > 0 ? (
          <ul className="divide-y divide-edge">
            {sessions.data.map((session) => (
              <li key={session.id} className="flex items-center justify-between gap-3 px-5 py-3.5">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-edge bg-panel">
                    <Smartphone className="h-4 w-4 text-zinc-400" />
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="truncate text-sm font-medium text-white">{sessionDeviceLabel(session)}</p>
                      {session.is_current ? (
                        <Badge tone="brand">{t('security.sessions.current')}</Badge>
                      ) : null}
                    </div>
                    <p className="mt-0.5 text-xs text-zinc-500">
                      {session.ip_address ?? '—'}
                      {session.last_used_at
                        ? ` · ${t('security.sessions.lastUsed', { date: formatDate(session.last_used_at) })}`
                        : ''}
                    </p>
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red-400 hover:text-red-300"
                  loading={revokeSession.isPending}
                  onClick={() => revokeSession.mutate(session.id)}
                >
                  {t('security.sessions.revoke')}
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <div className="p-5">
            <EmptyState title={t('security.sessions.empty')} />
          </div>
        )}
      </Card>
    </div>
  );
}

const SSL_TONE: Record<SslCheckStatus, 'success' | 'warning' | 'danger'> = {
  valid: 'success',
  expiring: 'warning',
  invalid: 'danger',
};

function sslTargetLabel(t: (key: string) => string, check: SslCheckDto): string {
  const name = check.details?.url ?? check.details?.target;
  if (typeof name === 'string' && name !== '') return name;
  return check.target_type === 'site' ? t('security.ssl.site') : t('security.ssl.domain');
}

function sessionDeviceLabel(session: SessionDto): string {
  const ua = session.user_agent ?? '';
  const lower = ua.toLowerCase();

  let browser = 'Unknown';
  if (lower.includes('edg/')) browser = 'Edge';
  else if (lower.includes('chrome')) browser = 'Chrome';
  else if (lower.includes('firefox')) browser = 'Firefox';
  else if (lower.includes('safari')) browser = 'Safari';

  let os = 'Unknown OS';
  if (lower.includes('iphone')) os = 'iOS';
  else if (lower.includes('android')) os = 'Android';
  else if (lower.includes('mac os')) os = 'macOS';
  else if (lower.includes('windows')) os = 'Windows';
  else if (lower.includes('linux')) os = 'Linux';

  return `${browser} · ${os}`;
}

function ScanHistory({ samples, t }: { samples: { score: number; open: number; created_at: string | null }[]; t: (key: string) => string }) {
  const recent = samples.slice(-6).reverse();

  return (
    <div className="mt-4">
      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
        <Activity className="h-3.5 w-3.5" />
        {t('security.timeline.scans')}
      </p>
      <ul className="mt-2 space-y-1">
        {recent.map((sample) => (
          <li key={sample.created_at ?? sample.score} className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-xs transition-colors hover:bg-white/5">
            <span
              className={cn(
                'h-1.5 w-1.5 shrink-0 rounded-full',
                sample.score >= 90 ? 'bg-emerald-400' : sample.score >= 70 ? 'bg-amber-400' : 'bg-red-400',
              )}
              aria-hidden="true"
            />
            <span className="w-8 font-semibold text-white">{sample.score}</span>
            <span className="text-zinc-500">{sample.open} {t('security.open').toLowerCase()}</span>
            <span className="ml-auto text-zinc-500">
              {sample.created_at ? formatDate(sample.created_at) : ''}
            </span>
          </li>
        ))}
      </ul>
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
    case 'mfa_enforcement':
      return t('security.affectedUsers', {
        count: Number((metadata.affected_users as unknown[] | undefined)?.length ?? 0),
      });
    case 'ssl_invalid':
      return typeof metadata.target === 'string' && metadata.target !== '' ? metadata.target : null;
    case 'ssl_expiring':
      return `${metadata.target ?? ''} · ${t('security.expiresIn', { days: Number(metadata.days_remaining ?? 0) })}`;
    case 'backup_disabled':
      return String(metadata.server ?? '');
    default:
      return null;
  }
}
