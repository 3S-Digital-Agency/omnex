import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Bell,
  Globe,
  HardDrive,
  LayoutTemplate,
  Server,
  ShieldCheck,
  UserPlus,
  Zap,
} from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { ActivityItem, SecurityFindingDto, SecuritySeverity } from '../../lib/api/types';
import { modules } from '../../lib/modules';
import { useI18n } from '../../lib/i18n';
import { useActivityFeed } from '../../lib/useActivityFeed';
import { ActivityFeedList } from '../../components/activity/ActivityFeedList';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { KpiCard } from '../../components/viz/KpiCard';
import { Donut } from '../../components/viz/Donut';
import { MiniBars } from '../../components/viz/MiniBars';
import type { MiniBarDatum } from '../../components/viz/MiniBars';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { useAnimatedNumber } from '../../components/viz/useMotion';
import { cn, formatBytes } from '../../lib/utils';

const WARNING_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

const SEVERITY_TONE: Record<SecuritySeverity, 'danger' | 'warning' | 'neutral'> = {
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
};

/** Every finding leads to the page where it can be fixed. */
const FINDING_TO: Record<string, string> = {
  mfa: '/settings',
  mfa_enforcement: '/security',
  email: '/settings',
  single_member: '/members',
  domain_expiring: '/domains',
  dnssec_disabled: '/domains',
  ssl_invalid: '/security',
  ssl_expiring: '/security',
  backup_disabled: '/cloud',
};

function activityRoute(item: ActivityItem): string | undefined {
  const type = item.type;
  if (type.startsWith('domain') || type.startsWith('dns')) return '/domains';
  if (type.startsWith('server') || type.startsWith('ssh') || type.startsWith('snapshot') || type.startsWith('cloud')) return '/cloud';
  if (type.startsWith('site') || type.startsWith('deploy')) return '/sites';
  if (type.startsWith('drive') || type.startsWith('storage') || type.startsWith('file')) return '/storage';
  if (type.startsWith('security')) return '/security';
  if (type.startsWith('billing') || type.startsWith('payment') || type.startsWith('invoice') || type.startsWith('subscription') || type.startsWith('coupon')) return '/billing';
  if (type.startsWith('member') || type.startsWith('invitation') || type.startsWith('role')) return '/members';
  return undefined;
}

function AnimatedNumber({
  value,
  className,
  format,
}: {
  value: number;
  className?: string;
  format?: (value: number) => string;
}) {
  const animated = useAnimatedNumber(value);
  return <span className={cn('tabular-nums', className)}>{format ? format(animated) : Math.round(animated).toLocaleString()}</span>;
}

export function DashboardPage() {
  const { activeOrganization, hasPermission } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;

  const domains = useQuery({
    queryKey: ['domains', orgId],
    queryFn: () => api.listDomains(),
    enabled: !!orgId,
  });
  const servers = useQuery({
    queryKey: ['servers', orgId],
    queryFn: () => api.listServers(),
    enabled: !!orgId,
  });
  const sites = useQuery({
    queryKey: ['sites', orgId],
    queryFn: () => api.listSites(),
    enabled: !!orgId,
  });
  const drive = useQuery({
    queryKey: ['drive', orgId, 'root'],
    queryFn: () => api.listDrive(),
    enabled: !!orgId,
  });
  const members = useQuery({
    queryKey: ['members', orgId],
    queryFn: () => api.listMembers(orgId!),
    enabled: !!orgId,
  });
  const invitations = useQuery({
    queryKey: ['invitations', orgId],
    queryFn: () => api.listInvitations(orgId!),
    enabled: !!orgId && hasPermission('organizations.invite'),
  });
  const notifications = useQuery({
    queryKey: ['notifications', orgId],
    queryFn: () => api.listNotifications(),
    enabled: !!orgId,
  });
  const activity = useActivityFeed(!!orgId && hasPermission('audit.read'));
  const security = useQuery({
    queryKey: ['security', orgId],
    queryFn: () => api.getSecurityScore(),
    enabled: !!orgId,
  });

  const domainCount = domains.data?.length ?? 0;
  const expiringDomains =
    domains.data?.filter((d) => d.expires_at && new Date(d.expires_at).getTime() - Date.now() < WARNING_WINDOW_MS).length ?? 0;
  const activeDomains = Math.max(0, domainCount - expiringDomains);

  const serverCount = servers.data?.length ?? 0;
  const runningServers = servers.data?.filter((s) => s.status === 'running').length ?? 0;
  const stoppedServers = servers.data?.filter((s) => s.status === 'stopped').length ?? 0;

  const siteCount = sites.data?.length ?? 0;
  const readySites = sites.data?.filter((s) => s.status === 'ready').length ?? 0;

  const storageUsed = drive.data?.quota.used ?? 0;
  const storageLimit = drive.data?.quota.limit ?? 0;
  const storagePct = storageLimit > 0 ? Math.min(100, (storageUsed / storageLimit) * 100) : 0;

  const score = security.data?.score ?? 0;
  const summary = security.data?.summary;
  const openFindings = summary?.open ?? 0;
  const findings = (security.data?.findings ?? []).filter((finding) => finding.status === 'open');

  const memberCount = members.data?.length ?? 0;
  const inviteCount = invitations.data?.length ?? 0;

  const scoreTone = score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400';

  const quickActions = [
    { to: '/domains', icon: Globe, label: t('dashboard.quick.registerDomain'), gate: true },
    { to: '/cloud', icon: Server, label: t('dashboard.quick.provisionServer'), gate: true },
    { to: '/sites', icon: LayoutTemplate, label: t('dashboard.quick.deploySite'), gate: true },
    { to: '/members', icon: UserPlus, label: t('dashboard.quick.inviteMember'), gate: hasPermission('organizations.invite') },
  ].filter((action) => action.gate);

  const estate: { label: string; to: string; total: number; bars: MiniBarDatum[] }[] = [
    {
      label: t('dashboard.estate.domains'),
      to: '/domains',
      total: domainCount,
      bars: [
        { label: t('dashboard.secLow'), value: activeDomains, tone: 'bg-emerald-500' },
        { label: t('dashboard.kpi.domainsDelta'), value: expiringDomains, tone: 'bg-amber-500' },
      ],
    },
    {
      label: t('dashboard.estate.servers'),
      to: '/cloud',
      total: serverCount,
      bars: [
        { label: t('dashboard.secHigh'), value: runningServers, tone: 'bg-emerald-500' },
        { label: t('dashboard.kpi.serversDelta'), value: serverCount - runningServers - stoppedServers, tone: 'bg-brand-500' },
        { label: t('dashboard.secMedium'), value: stoppedServers, tone: 'bg-zinc-600' },
      ],
    },
    {
      label: t('dashboard.estate.sites'),
      to: '/sites',
      total: siteCount,
      bars: [
        { label: t('dashboard.kpi.sitesDelta'), value: siteCount - readySites, tone: 'bg-brand-500' },
        { label: t('dashboard.secHigh'), value: readySites, tone: 'bg-emerald-500' },
      ],
    },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('dashboard.title')}</h1>
          <p className="text-sm text-zinc-400">
            {t('dashboard.subtitle', { name: activeOrganization?.name ?? '' })}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {quickActions.map((action) => (
            <Link key={action.to + action.label} to={action.to}>
              <Button variant="outline" size="sm">
                <action.icon className="h-3.5 w-3.5" />
                {action.label}
              </Button>
            </Link>
          ))}
        </div>
      </header>

      {/* KPI row */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <KpiCard
          label={t('dashboard.kpi.domains')}
          value={domainCount}
          icon={Globe}
          to="/domains"
          sub={t('dashboard.kpi.domainsSub', { active: activeDomains, expiring: expiringDomains })}
          ariaLabel={t('dashboard.kpi.domains')}
        />
        <KpiCard
          label={t('dashboard.kpi.servers')}
          value={serverCount}
          icon={Server}
          to="/cloud"
          sub={t('dashboard.kpi.serversSub', { running: runningServers, stopped: stoppedServers })}
          ariaLabel={t('dashboard.kpi.servers')}
        />
        <KpiCard
          label={t('dashboard.kpi.sites')}
          value={siteCount}
          icon={LayoutTemplate}
          to="/sites"
          sub={t('dashboard.kpi.sitesSub', { ready: readySites })}
          ariaLabel={t('dashboard.kpi.sites')}
        />
        <KpiCard
          label={t('dashboard.kpi.storage')}
          value={storagePct}
          icon={HardDrive}
          to="/storage"
          format={(value) => `${Math.round(value)}%`}
          sub={t('dashboard.kpi.storageSub', { used: formatBytes(storageUsed), limit: formatBytes(storageLimit) })}
          footer={<ProgressBar percent={storagePct} tone={storagePct > 90 ? 'danger' : storagePct > 70 ? 'warning' : 'brand'} />}
          ariaLabel={t('dashboard.kpi.storage')}
        />
        <KpiCard
          label={t('dashboard.kpi.members')}
          value={memberCount}
          icon={UserPlus}
          to="/members"
          sub={t('dashboard.kpi.membersSub', { invites: inviteCount })}
          ariaLabel={t('dashboard.kpi.members')}
        />
      </div>

      {/* Security + estate */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="flex items-center gap-6 p-6">
          <Donut value={score} size={132} thickness={12} className={scoreTone} label={`${score}/100`}>
            <AnimatedNumber value={score} className={cn('text-3xl font-bold', scoreTone)} />
            <span className="text-[10px] uppercase tracking-wider text-zinc-500">/ 100</span>
          </Donut>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <ShieldCheck className="h-4 w-4 text-brand-400" />
              <h3 className="text-sm font-semibold text-white">{t('dashboard.securityScore')}</h3>
            </div>
            <p className="mt-1 text-xs text-zinc-500">{t('dashboard.kpi.securitySub', { open: openFindings })}</p>
            {summary ? (
              <div className="mt-3 flex flex-wrap gap-1.5">
                <Badge tone={summary.high > 0 ? 'danger' : 'neutral'}>{summary.high} {t('dashboard.secHigh')}</Badge>
                <Badge tone={summary.medium > 0 ? 'warning' : 'neutral'}>{summary.medium} {t('dashboard.secMedium')}</Badge>
                <Badge tone={summary.low > 0 ? 'warning' : 'neutral'}>{summary.low} {t('dashboard.secLow')}</Badge>
              </div>
            ) : null}
            <Link
              to="/security"
              className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-400 hover:underline"
            >
              {t('dashboard.reviewFindings')} <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </div>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('dashboard.estate.title')} description={t('dashboard.estate.subtitle')} />
          <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            {estate.map((metric) => (
              <Link
                key={metric.label}
                to={metric.to}
                className="group rounded-lg border border-edge bg-raised/40 p-3 transition-colors hover:border-brand-700/60"
                aria-label={metric.label}
              >
                <div className="flex items-baseline justify-between">
                  <span className="text-xs font-medium text-zinc-400">{metric.label}</span>
                  <span className="text-sm font-bold text-white">{metric.total}</span>
                </div>
                <MiniBars data={metric.bars} height={56} className="mt-2" />
              </Link>
            ))}
            <Link
              to="/storage"
              className="group rounded-lg border border-edge bg-raised/40 p-3 transition-colors hover:border-brand-700/60"
              aria-label={t('dashboard.estate.storage')}
            >
              <div className="flex items-baseline justify-between">
                <span className="text-xs font-medium text-zinc-400">{t('dashboard.estate.storage')}</span>
                <span className="text-sm font-bold text-white">{Math.round(storagePct)}%</span>
              </div>
              <div className="mt-3">
                <ProgressBar percent={storagePct} tone={storagePct > 90 ? 'danger' : 'brand'} />
                <p className="mt-1.5 text-[10px] text-zinc-600">
                  {formatBytes(storageUsed)} / {formatBytes(storageLimit)}
                </p>
              </div>
            </Link>
          </div>
        </Card>
      </div>

      {/* Security insights → action */}
      <Card>
        <CardHeader title={t('dashboard.insights.title')} description={t('dashboard.insights.empty')} />
        {security.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : findings.length > 0 ? (
          <ul className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            {findings.slice(0, 4).map((finding) => (
              <li key={finding.id}>
                <Link
                  to={FINDING_TO[finding.rule] ?? '/security'}
                  className="group flex h-full flex-col rounded-xl border border-edge bg-panel p-4 transition-colors hover:border-amber-700/50"
                >
                  <div className="flex items-center justify-between gap-2">
                    <Badge tone={SEVERITY_TONE[finding.severity]}>{finding.severity}</Badge>
                    <span className="truncate font-mono text-[10px] text-zinc-600">{finding.rule}</span>
                  </div>
                  <p className="mt-2 text-sm font-medium text-white">{t(`security.finding.${finding.rule}`)}</p>
                  <p className="mt-1 line-clamp-1 text-xs text-zinc-500">{insightLine(finding)}</p>
                  <span className="mt-auto pt-2 text-xs font-medium text-brand-400 group-hover:underline">
                    {t('dashboard.insights.act')} →
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <div className="p-5">
            <EmptyState title={t('dashboard.insights.empty')} />
          </div>
        )}
      </Card>

      {/* Activity + notifications */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader
            title={t('dashboard.liveActivity')}
            action={
              <div className="flex items-center gap-3">
                <Badge tone="success">{t('common.live')}</Badge>
                <Link to="/activity" className="text-sm text-brand-400 hover:underline">
                  {t('dashboard.viewAll')}
                </Link>
              </div>
            }
          />
          <div className="px-5 pb-3">
            {activity.isLoading ? (
              <Spinner />
            ) : activity.items.length > 0 ? (
              <ActivityFeedList items={activity.items.slice(0, 12)} to={activityRoute} />
            ) : (
              <EmptyState title={t('dashboard.noActivity')} />
            )}
          </div>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader title={t('dashboard.notifications')} />
            <div className="p-5">
              {notifications.isLoading ? (
                <Spinner />
              ) : notifications.data && notifications.data.data.length > 0 ? (
                <ul className="space-y-2">
                  {notifications.data.data.slice(0, 5).map((notification) => {
                    const row = (
                      <span className="flex items-start gap-3">
                        <Bell className="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                        <span className="min-w-0">
                          <span className="block text-sm font-medium text-white">{notification.title}</span>
                          {notification.body ? (
                            <span className="block truncate text-sm text-zinc-400">{notification.body}</span>
                          ) : null}
                        </span>
                      </span>
                    );
                    return (
                      <li key={notification.id}>
                        {notification.route ? (
                          <Link
                            to={notification.route}
                            className="block -mx-2 rounded-md px-2 py-1 transition-colors hover:bg-raised"
                          >
                            {row}
                          </Link>
                        ) : (
                          row
                        )}
                      </li>
                    );
                  })}
                </ul>
              ) : (
                <EmptyState title={t('dashboard.noNotifications')} />
              )}
            </div>
          </Card>

          <Card>
            <CardHeader title={t('dashboard.quickActions')} />
            <div className="flex flex-wrap gap-2 p-5">
              {quickActions.map((action) => (
                <Link key={action.to + action.label} to={action.to}>
                  <Button variant="outline" size="sm">
                    <action.icon className="h-3.5 w-3.5" />
                    {action.label}
                  </Button>
                </Link>
              ))}
            </div>
          </Card>
        </div>
      </div>

      {/* Modules */}
      <section>
        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-zinc-500">
          <Zap className="h-4 w-4" />
          {t('dashboard.modules')}
        </h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {modules
            .filter((module) => module.id !== 'activity')
            .map((module) => {
              const stat = moduleStat(module.id, domainCount, serverCount, siteCount, Math.round(storagePct), score);
              return (
                <Link
                  key={module.id}
                  to={module.path}
                  className="group rounded-xl border border-edge bg-panel p-5 transition-all hover:border-brand-700 hover:bg-raised"
                >
                  <div className="flex items-center justify-between">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-raised transition-colors group-hover:bg-brand-700/15">
                      <module.icon className="h-5 w-5 text-brand-400" />
                    </div>
                    <div className="flex items-center gap-1.5">
                      {stat ? <Badge tone={stat.tone}>{stat.value}</Badge> : null}
                      {module.live ? <Badge tone="success">{t('common.live')}</Badge> : <Badge tone="neutral">{module.phase}</Badge>}
                    </div>
                  </div>
                  <h3 className="mt-3 text-sm font-semibold text-white">{t(`module.${module.id}.name`)}</h3>
                  <p className="mt-1 line-clamp-2 text-xs text-zinc-400">{t(`module.${module.id}.tagline`)}</p>
                </Link>
              );
            })}
        </div>
      </section>
    </div>
  );
}

function moduleStat(
  moduleId: string,
  domains: number,
  servers: number,
  sites: number,
  storagePct: number,
  score: number,
): { value: string; tone: 'success' | 'warning' | 'neutral' | 'danger' } | null {
  switch (moduleId) {
    case 'domains':
      return { value: String(domains), tone: domains > 0 ? 'success' : 'neutral' };
    case 'cloud':
      return { value: String(servers), tone: servers > 0 ? 'success' : 'neutral' };
    case 'sites':
      return { value: String(sites), tone: sites > 0 ? 'success' : 'neutral' };
    case 'storage':
      return { value: `${storagePct}%`, tone: storagePct > 90 ? 'danger' : storagePct > 70 ? 'warning' : 'success' };
    case 'security':
      return { value: String(score), tone: score >= 70 ? 'success' : 'warning' };
    default:
      return null;
  }
}

function insightLine(finding: SecurityFindingDto): string {
  const metadata = finding.metadata ?? {};
  switch (finding.rule) {
    case 'mfa':
    case 'email':
      return typeof metadata.email === 'string' ? metadata.email : '';
    case 'domain_expiring':
      return `${metadata.domain ?? ''} · ${Number(metadata.days ?? 0)} j`;
    case 'dnssec_disabled':
      return String(metadata.domain ?? '');
    case 'ssl_expiring':
      return `${metadata.target ?? ''} · ${Number(metadata.days_remaining ?? 0)} j`;
    case 'ssl_invalid':
      return String(metadata.target ?? '');
    case 'backup_disabled':
      return String(metadata.server ?? '');
    case 'mfa_enforcement':
      return `${Number((metadata.affected_users as unknown[] | undefined)?.length ?? 0)} membres`;
    case 'single_member':
      return String(metadata.member_count ?? '');
    default:
      return '';
  }
}
