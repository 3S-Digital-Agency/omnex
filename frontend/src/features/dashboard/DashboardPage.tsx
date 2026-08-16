import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight, Bell, ScrollText, UserPlus, Users } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { modules } from '../../lib/modules';
import { useI18n } from '../../lib/i18n';
import { useActivityFeed } from '../../lib/useActivityFeed';
import { ActivityFeedList } from '../../components/activity/ActivityFeedList';
import { Badge } from '../../components/ui/Badge';
import { Card, CardHeader } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { cn } from '../../lib/utils';

export function DashboardPage() {
  const { activeOrganization, user, hasPermission } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;

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
  const audit = useQuery({
    queryKey: ['audit', orgId],
    queryFn: () => api.listAudit(1),
    enabled: !!orgId && hasPermission('audit.read'),
  });
  const notifications = useQuery({
    queryKey: ['notifications', orgId],
    queryFn: () => api.listNotifications(),
    enabled: !!orgId,
  });
  const activity = useActivityFeed(!!orgId && hasPermission('audit.read'));

  const score = securityScore(user?.mfa_enabled ?? false, members.data?.length ?? 1, !!user?.email_verified_at);

  const stats = [
    { label: t('dashboard.members'), value: members.data?.length ?? '—', icon: Users },
    { label: t('dashboard.pendingInvites'), value: invitations.data?.length ?? '—', icon: UserPlus },
    { label: t('dashboard.auditEvents'), value: audit.data?.meta.total ?? '—', icon: ScrollText },
  ];

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('dashboard.title')}</h1>
        <p className="text-sm text-zinc-400">
          {t('dashboard.subtitle', { name: activeOrganization?.name ?? '' })}
        </p>
      </header>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {stats.map((stat) => (
          <Card key={stat.label} className="p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-zinc-400">{stat.label}</p>
                <p className="mt-1 text-2xl font-bold text-white">{stat.value}</p>
              </div>
              <stat.icon className="h-5 w-5 text-brand-400" />
            </div>
          </Card>
        ))}
      </div>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{t('dashboard.modules')}</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {modules.map((module) => (
            <Link
              key={module.id}
              to={module.path}
              className="group rounded-xl border border-edge bg-panel p-5 transition-colors hover:border-brand-700"
            >
              <div className="flex items-center justify-between">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-raised">
                  <module.icon className="h-5 w-5 text-brand-400" />
                </div>
                {module.live ? <Badge tone="success">{t('common.live')}</Badge> : <Badge tone="neutral">{module.phase}</Badge>}
              </div>
              <h3 className="mt-3 text-sm font-semibold text-white">{t(`module.${module.id}.name`)}</h3>
              <p className="mt-1 line-clamp-2 text-xs text-zinc-400">{t(`module.${module.id}.tagline`)}</p>
            </Link>
          ))}
        </div>
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title={t('dashboard.liveActivity')} action={<Badge tone="success">{t('common.live')}</Badge>} />
          <div className="p-5">
            {activity.isLoading ? (
              <Spinner />
            ) : activity.items.length > 0 ? (
              <ActivityFeedList items={activity.items.slice(0, 12)} />
            ) : (
              <EmptyState title={t('dashboard.noActivity')} />
            )}
          </div>
        </Card>

        <div className="space-y-6">
          <Card className="p-5 text-center">
            <p className="text-sm text-zinc-400">{t('dashboard.securityScore')}</p>
            <p
              className={cn(
                'mt-1 text-4xl font-bold',
                score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400',
              )}
            >
              {score}
            </p>
            <p className="text-xs text-zinc-500">/ 100</p>
            <Link
              to="/security"
              className="mt-3 inline-flex items-center gap-1 text-sm text-brand-400 hover:underline"
            >
              {t('dashboard.reviewFindings')} <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </Card>

          <Card>
            <CardHeader title={t('dashboard.notifications')} />
            <div className="p-5">
              {notifications.isLoading ? (
                <Spinner />
              ) : notifications.data && notifications.data.length > 0 ? (
                <ul className="space-y-3">
                  {notifications.data.slice(0, 4).map((notification) => (
                    <li key={notification.id} className="flex items-start gap-3">
                      <Bell className="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                      <div>
                        <p className="text-sm font-medium text-white">{notification.title}</p>
                        {notification.body ? (
                          <p className="text-sm text-zinc-400">{notification.body}</p>
                        ) : null}
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState title={t('dashboard.noNotifications')} />
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

function securityScore(mfaEnabled: boolean, memberCount: number, emailVerified: boolean): number {
  let penalty = 0;
  if (!mfaEnabled) penalty += 25;
  if (memberCount <= 1) penalty += 15;
  if (!emailVerified) penalty += 10;
  return Math.max(0, 100 - penalty);
}
