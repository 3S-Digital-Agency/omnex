import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Activity, AlertTriangle, CheckCircle2, ShieldCheck, Users } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { KpiCard } from '../../components/viz/KpiCard';
import { MiniBars } from '../../components/viz/MiniBars';
import { PeriodSelector } from '../../components/viz/PeriodSelector';
import type { PeriodDays } from '../../components/viz/PeriodSelector';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { StackedBar } from '../../components/viz/StackedBar';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';
import type { AuditLogDto } from '../../lib/api/types';

export function AuditPage() {
  const { activeOrganization } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;

  const audit = useQuery({
    queryKey: ['audit', orgId],
    queryFn: () => api.listAudit(100),
    enabled: !!orgId,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('audit.title')}</h1>
        <p className="text-sm text-zinc-400">
          {t('audit.subtitle', { name: activeOrganization?.name ?? '' })}
        </p>
      </header>

      <AuditCockpit logs={audit.data?.data ?? []} />

      <Card className="overflow-hidden">
        {audit.isLoading ? (
          <div className="flex justify-center p-10">
            <Spinner />
          </div>
        ) : audit.data && audit.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-edge text-left text-xs uppercase tracking-wide text-zinc-500">
                  <th className="px-5 py-3 font-medium">{t('audit.action')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.user')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.ip')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.result')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.time')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-edge">
                {audit.data.data.map((log) => (
                  <tr key={log.id} className="text-zinc-300">
                    <td className="px-5 py-3 font-mono text-xs">{log.action}</td>
                    <td className="px-5 py-3">{log.user?.name ?? '—'}</td>
                    <td className="px-5 py-3 font-mono text-xs text-zinc-500">{log.ip_address ?? '—'}</td>
                    <td className="px-5 py-3">
                      <Badge tone={log.result === 'success' ? 'success' : 'danger'}>{log.result}</Badge>
                    </td>
                    <td className="px-5 py-3 text-zinc-500">{formatDate(log.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="p-5">
            <EmptyState title={t('audit.empty')} />
          </div>
        )}
      </Card>
    </div>
  );
}

const AUDIT_BUCKETS: Array<{ key: string; match: (action: string) => boolean; color: string }> = [
  { key: 'auth', match: (a) => a.startsWith('user.'), color: 'text-zinc-400' },
  { key: 'member', match: (a) => a.startsWith('member.'), color: 'text-teal-400' },
  { key: 'domain', match: (a) => a.startsWith('domain.'), color: 'text-brand-400' },
  { key: 'dns', match: (a) => a.startsWith('dns.'), color: 'text-amber-400' },
  { key: 'organization', match: (a) => a.startsWith('organization.'), color: 'text-fuchsia-400' },
  { key: 'other', match: () => true, color: 'text-zinc-500' },
];

function AuditCockpit({ logs }: { logs: AuditLogDto[] }) {
  const { t } = useI18n();
  const [period, setPeriod] = useState<PeriodDays>(30);

  const filteredLogs = useMemo(() => {
    if (period >= 90) return logs;
    const cutoff = Date.now() - period * 86400000;
    return logs.filter((log) => log.created_at && new Date(log.created_at).getTime() >= cutoff);
  }, [logs, period]);

  const stats = useMemo(() => {
    const logs = filteredLogs;
    const total = logs.length;
    const failures = logs.filter((log) => log.result !== 'success').length;
    const success = total - failures;
    const successRate = total > 0 ? Math.round((success / total) * 100) : 0;
    const actors = new Map<string, number>();
    for (const log of logs) {
      const name =
        log.user?.name ??
        (log.resource_type === 'user' ? t('audit.cockpit.unknown') : t('audit.cockpit.system'));
      actors.set(name, (actors.get(name) ?? 0) + 1);
    }

    const buckets = AUDIT_BUCKETS.map((bucket) => ({
      ...bucket,
      count: logs.filter((log) => bucket.match(log.action)).length,
    }));
    // The catch-all bucket must only count actions not covered by any other bucket.
    const covered = new Set(
      logs
        .filter((log) => AUDIT_BUCKETS.some((bucket) => bucket.key !== 'other' && bucket.match(log.action)))
        .map((log) => log.id),
    );
    buckets.forEach((bucket) => {
      if (bucket.key === 'other') {
        bucket.count = logs.filter((log) => !covered.has(log.id)).length;
      }
    });
    const nonEmpty = buckets.filter((bucket) => bucket.count > 0);

    const topActors = Array.from(actors.entries())
      .sort((a, b) => b[1] - a[1])
      .slice(0, 5)
      .map(([name, count]) => ({ name, count }));

    return { total, success, failures, successRate, actorsCount: actors.size, nonEmpty, topActors };
  }, [filteredLogs, t]);

  const segments = stats.nonEmpty.map((bucket) => ({
    value: bucket.count,
    color: bucket.color,
    label: t(`audit.cockpit.bucket.${bucket.key}`),
  }));

  const actorBars = stats.topActors.map((actor) => ({
    label: actor.name.length > 10 ? `${actor.name.slice(0, 9)}…` : actor.name,
    value: actor.count,
    tone: 'bg-brand-500',
  }));

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label={t('audit.cockpit.total')}
          value={stats.total}
          icon={Activity}
          to="/audit"
          ariaLabel={t('audit.cockpit.totalAria')}
          sub={t('audit.cockpit.totalSub')}
        />
        <KpiCard
          label={t('audit.cockpit.successRate')}
          value={stats.successRate}
          format={(value) => `${Math.round(value)}%`}
          icon={CheckCircle2}
          to="/audit"
          accent={stats.successRate >= 90 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'}
          ariaLabel={t('audit.cockpit.successRateAria')}
          sub={t('audit.cockpit.successCount', { count: stats.success })}
          footer={<ProgressBar percent={stats.successRate} tone={stats.successRate >= 90 ? 'success' : 'warning'} />}
        />
        <KpiCard
          label={t('audit.cockpit.failures')}
          value={stats.failures}
          icon={AlertTriangle}
          to="/audit"
          accent={stats.failures > 0 ? 'bg-red-500/15 text-red-300' : 'bg-emerald-500/15 text-emerald-300'}
          ariaLabel={t('audit.cockpit.failuresAria')}
          sub={stats.failures > 0 ? t('audit.cockpit.failuresSub') : t('audit.cockpit.failuresNone')}
        />
        <KpiCard
          label={t('audit.cockpit.actors')}
          value={stats.actorsCount}
          icon={Users}
          to="/audit"
          ariaLabel={t('audit.cockpit.actorsAria')}
          sub={t('audit.cockpit.actorsSub')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h3 className="text-sm font-semibold text-white">{t('audit.cockpit.actionsTitle')}</h3>
              <p className="mt-1 text-xs text-zinc-500">{t('audit.cockpit.actionsSub')}</p>
            </div>
            <PeriodSelector value={period} onChange={setPeriod} />
          </div>
          <div className="mt-4 flex items-center gap-5">
            <DistributionDonut
              segments={segments}
              size={120}
              thickness={11}
              center={
                <>
                  <span className="text-2xl font-bold text-white tabular-nums">{stats.total}</span>
                  <span className="text-[10px] uppercase tracking-wide text-zinc-500">{t('audit.cockpit.events')}</span>
                </>
              }
              label={t('audit.cockpit.actionsTitle')}
            />
            <ul className="flex-1 space-y-2">
              {segments.map((segment) => (
                <li key={segment.label} className="flex items-center justify-between gap-3 text-sm">
                  <span className="flex items-center gap-2 text-zinc-300">
                    <span className={cn('h-2.5 w-2.5 rounded-full', segment.color)} />
                    {segment.label}
                  </span>
                  <span className="font-medium text-white tabular-nums">{segment.value}</span>
                </li>
              ))}
            </ul>
          </div>
        </Card>

        <Card className="p-5 lg:col-span-2">
          <h3 className="text-sm font-semibold text-white">{t('audit.cockpit.actorsTitle')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('audit.cockpit.actorsSub')}</p>
          <MiniBars data={actorBars} height={110} className="mt-4" />
          <div className="mt-3">
            <div className="mb-1 flex items-center justify-between text-xs">
              <span className="text-zinc-400">{t('audit.cockpit.resultTitle')}</span>
              <span className="flex items-center gap-2">
                <span className="flex items-center gap-1 text-zinc-400">
                  <ShieldCheck className="h-3 w-3 text-emerald-400" />
                  {stats.success}
                </span>
                <span className="flex items-center gap-1 text-zinc-400">
                  <AlertTriangle className="h-3 w-3 text-red-400" />
                  {stats.failures}
                </span>
              </span>
            </div>
            <StackedBar
              showLegend={false}
              items={[
                { value: stats.success, label: t('audit.cockpit.success'), color: 'bg-emerald-500' },
                { value: stats.failures, label: t('audit.cockpit.failure'), color: 'bg-red-500' },
              ]}
            />
          </div>
        </Card>
      </div>
    </div>
  );
}
