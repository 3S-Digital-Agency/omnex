import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Activity,
  BellOff,
  CheckCheck,
  ChevronLeft,
  ChevronRight,
  Clock,
  Flame,
  TrendingUp,
} from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { useI18n } from '../../lib/i18n';
import { useActivityFeed } from '../../lib/useActivityFeed';
import { ActivityFeedList } from '../../components/activity/ActivityFeedList';
import { AreaChart } from '../../components/viz/AreaChart';
import { Heatmap } from '../../components/viz/Heatmap';
import { KpiCard } from '../../components/viz/KpiCard';
import { MiniBars } from '../../components/viz/MiniBars';
import { PeriodSelector } from '../../components/viz/PeriodSelector';
import type { PeriodDays } from '../../components/viz/PeriodSelector';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { Select } from '../../components/ui/Select';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { api } from '../../lib/api';
import type { ActivityItem, NotificationDto, NotificationSeverity } from '../../lib/api/types';
import {
  NOTIFICATION_SEVERITY_META,
  NOTIFICATION_SEVERITIES,
  NOTIFICATION_TYPES,
} from '../../lib/notifications';
import { cn, formatDate } from '../../lib/utils';

type Tab = 'notifications' | 'activity';
type StatusFilter = 'all' | 'unread' | 'read';

const PER_PAGE = 8;

const badgeTone: Record<NotificationSeverity, 'neutral' | 'success' | 'warning' | 'danger'> = {
  info: 'neutral',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
};

export function ActivityPage() {
  const { activeOrganization } = useAuth();
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>('notifications');
  const { items: activityItems } = useActivityFeed(!!activeOrganization);

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('activity.mergedTitle')}</h1>
          <p className="text-sm text-zinc-400">
            {t('activity.mergedSubtitle', { name: activeOrganization?.name ?? '' })}
          </p>
        </div>
        <Badge tone="success">{t('common.live')}</Badge>
      </header>

      <ActivityCockpit items={activityItems} />

      <div
        role="tablist"
        aria-label={t('activity.mergedTitle')}
        className="flex gap-1 rounded-lg border border-edge bg-panel p-1"
      >
        <TabButton active={tab === 'notifications'} onClick={() => setTab('notifications')}>
          {t('activity.tabNotifications')}
        </TabButton>
        <TabButton active={tab === 'activity'} onClick={() => setTab('activity')}>
          {t('activity.tabActivity')}
        </TabButton>
      </div>

      {tab === 'notifications' ? <NotificationsPanel /> : <ActivityPanel items={activityItems} />}
    </div>
  );
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={cn(
        'flex-1 rounded-md px-4 py-2 text-sm font-medium transition-colors',
        active ? 'bg-raised text-white' : 'text-zinc-400 hover:text-white',
      )}
    >
      {children}
    </button>
  );
}

function ActivityPanel({ items }: { items: ActivityItem[] }) {
  const { t } = useI18n();

  return (
    <Card className="p-5">
      {items.length > 0 ? (
        <ActivityFeedList items={items} />
      ) : (
        <EmptyState title={t('activity.noActivity')} />
      )}
    </Card>
  );
}

function NotificationsPanel() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [type, setType] = useState('');
  const [severity, setSeverity] = useState('');
  const [status, setStatus] = useState<StatusFilter>('all');
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['notifications-page', { type, severity, status, page }],
    queryFn: () =>
      api.listNotificationsPage({
        type: type || undefined,
        severity: (severity || undefined) as NotificationSeverity | undefined,
        unread: status === 'all' ? undefined : status === 'unread',
        page,
        perPage: PER_PAGE,
      }),
    placeholderData: (previous) => previous,
  });

  useEffect(() => {
    return api.subscribeNotifications(() => {
      void queryClient.invalidateQueries({ queryKey: ['notifications-page'] });
    });
  }, [queryClient]);

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['notifications-page'] });
    void queryClient.invalidateQueries({ queryKey: ['notifications'] });
  };

  const markRead = useMutation({
    mutationFn: (id: string) => api.markNotificationRead(id),
    onSuccess: invalidate,
  });

  const markAllRead = useMutation({
    mutationFn: () => api.markAllNotificationsRead(),
    onSuccess: invalidate,
  });

  function changeFilter(apply: () => void) {
    setPage(1);
    apply();
  }

  function openNotification(notification: NotificationDto) {
    if (!notification.read_at) markRead.mutate(notification.id);
    if (notification.route) navigate(notification.route);
  }

  const data = query.data;
  const items = data?.data ?? [];
  const unread = data?.unread ?? 0;
  const currentPage = data?.meta.current_page ?? 1;
  const lastPage = data?.meta.last_page ?? 1;
  const total = data?.meta.total ?? 0;

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center gap-2 border-b border-edge px-4 py-3">
        <Select
          value={type}
          onChange={(e) => changeFilter(() => setType(e.target.value))}
          className="w-40 text-xs"
          aria-label={t('notifications.filterType')}
        >
          <option value="">{t('notifications.filterAllTypes')}</option>
          {NOTIFICATION_TYPES.map((option) => (
            <option key={option} value={option}>
              {t(`notifications.type.${option}`)}
            </option>
          ))}
        </Select>

        <Select
          value={severity}
          onChange={(e) => changeFilter(() => setSeverity(e.target.value))}
          className="w-40 text-xs"
          aria-label={t('notifications.filterSeverity')}
        >
          <option value="">{t('notifications.filterAllSeverities')}</option>
          {NOTIFICATION_SEVERITIES.map((option) => (
            <option key={option} value={option}>
              {t(`notifications.severity.${option}`)}
            </option>
          ))}
        </Select>

        <Select
          value={status}
          onChange={(e) => changeFilter(() => setStatus(e.target.value as StatusFilter))}
          className="w-36 text-xs"
          aria-label={t('notifications.filterStatus')}
        >
          <option value="all">{t('notifications.filterAllStatuses')}</option>
          <option value="unread">{t('notifications.filterUnread')}</option>
          <option value="read">{t('notifications.filterRead')}</option>
        </Select>

        <div className="ml-auto">
          {unread > 0 ? (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => markAllRead.mutate()}
              loading={markAllRead.isPending}
            >
              <CheckCheck className="h-4 w-4" /> {t('notifications.markAllRead')}
            </Button>
          ) : (
            <span className="text-xs text-zinc-500">{t('notifications.allRead')}</span>
          )}
        </div>
      </div>

      {query.isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : items.length > 0 ? (
        <ul className="divide-y divide-edge/60">
          {items.map((notification) => (
            <NotificationRow
              key={notification.id}
              notification={notification}
              onOpen={openNotification}
              onMarkRead={(id) => markRead.mutate(id)}
            />
          ))}
        </ul>
      ) : (
        <div className="p-5">
          <EmptyState
            title={t('notifications.empty')}
            description={t('activity.noActivity')}
          />
        </div>
      )}

      {total > 0 ? (
        <div className="flex items-center justify-between border-t border-edge px-4 py-3">
          <span className="text-xs text-zinc-500">
            {t('notifications.page', { page: currentPage, last: lastPage })}
          </span>
          <div className="flex gap-1">
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              <ChevronLeft className="h-4 w-4" /> {t('notifications.previous')}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage >= lastPage}
              onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
            >
              {t('notifications.next')} <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      ) : null}
    </Card>
  );
}

const ACTIVITY_TYPES = ['deployment', 'domain', 'ssl', 'security', 'backup', 'incident', 'auth', 'member', 'organization'] as const;

const TYPE_TONES: Record<string, string> = {
  deployment: 'bg-sky-500',
  domain: 'bg-brand-500',
  ssl: 'bg-amber-500',
  security: 'bg-emerald-500',
  backup: 'bg-violet-500',
  incident: 'bg-red-500',
  auth: 'bg-zinc-400',
  member: 'bg-teal-500',
  organization: 'bg-fuchsia-500',
};

/** Deterministic PRNG so the demo history is stable across re-renders. */
function mulberry32(seed: number): () => number {
  let a = seed;
  return () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Activity cockpit: 30-day volume trend, per-type volume bars and a
 * (day × hour) intensity heatmap. The last 30 days come from a deterministic
 * demo history; today's bucket merges the live SSE feed so the charts update
 * in real time.
 */
function ActivityCockpit({ items }: { items: ActivityItem[] }) {
  const { t } = useI18n();
  const [period, setPeriod] = useState<PeriodDays>(30);

  const history = useMemo(() => {
    const random = mulberry32(20260816);
    const now = new Date();
    const dayMs = 86400000;
    const days: number[] = [];
    const dayTotals: number[] = [];
    const typeCounts = new Map<string, number>();
    for (const type of ACTIVITY_TYPES) typeCounts.set(type, 0);

    for (let offset = period - 1; offset >= 0; offset--) {
      const date = new Date(now.getTime() - offset * dayMs);
      const weekday = date.getDay();
      // Weekends are quieter, occasional burst days stand out.
      const weekend = weekday === 0 || weekday === 6 ? 0.55 : 1;
      const burst = random() > 0.85 ? 1.9 : 1;
      const base = Math.round((3 + random() * 7) * weekend * burst);
      const hourly = Array.from({ length: 24 }, (_, hour) => {
        const daytime = hour >= 8 && hour <= 18 ? 2.2 : hour >= 19 && hour <= 23 ? 1.2 : 0.4;
        return Math.max(0, Math.round((random() * 0.9 + 0.1) * base * daytime * 0.14));
      });
      const total = hourly.reduce((sum, value) => sum + value, 0);
      days.push(...hourly);
      dayTotals.push(total);
      if (total === 0) continue;
      for (const type of ACTIVITY_TYPES) {
        const share = type === 'deployment' ? 0.2 : type === 'domain' ? 0.16 : type === 'ssl' ? 0.12 : type === 'security' ? 0.12 : type === 'backup' ? 0.1 : type === 'incident' ? 0.06 : type === 'auth' ? 0.1 : type === 'member' ? 0.08 : 0.06;
        typeCounts.set(type, (typeCounts.get(type) ?? 0) + Math.round(total * share * (0.75 + random() * 0.5)));
      }
    }

    // Merge the live feed into today's buckets.
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayBase = new Array(24).fill(0);
    for (let offset = 0; offset < 24; offset++) {
      const index = days.length - 24 + offset;
      todayBase[offset] = days[index];
    }
    for (const item of items) {
      if (!item.created_at) continue;
      const date = new Date(item.created_at);
      if (date.getTime() < today.getTime()) continue;
      const hour = date.getHours();
      const index = days.length - 24 + hour;
      days[index] = (days[index] ?? 0) + 1;
      todayBase[hour] = (todayBase[hour] ?? 0) + 1;
      if (typeCounts.has(item.type)) typeCounts.set(item.type, (typeCounts.get(item.type) ?? 0) + 1);
      else typeCounts.set('system', (typeCounts.get('system') ?? 0) + 1);
    }
    if (!typeCounts.has('system')) typeCounts.set('system', 0);

    const liveToday = items.filter((item) => item.created_at && new Date(item.created_at).getTime() >= today.getTime()).length;
    const dayTotalsLive = [...dayTotals];
    dayTotalsLive[dayTotalsLive.length - 1] += liveToday;
    const total30 = dayTotalsLive.reduce((sum, value) => sum + value, 0);
    const maxDay = Math.max(...dayTotalsLive, 1);

    // 7 most recent days × 24 hours for the heatmap.
    const last7 = days.slice(-24 * 7);
    const heatRows: { value: number; label: string }[][] = [];
    for (let row = 0; row < 7; row++) {
      const offset = row * 24;
      const date = new Date(today.getTime() + (row - 6) * dayMs);
      const rowCells: { value: number; label: string }[] = [];
      for (let hour = 0; hour < 24; hour++) {
        const value = last7[offset + hour] ?? 0;
        const label = `${date.toLocaleDateString(undefined, { weekday: 'short' })} ${String(hour).padStart(2, '0')}:00`;
        rowCells.push({ value, label });
      }
      heatRows.push(rowCells);
    }

    return { dayTotals: dayTotalsLive, total30, maxDay, typeCounts, heatRows, todayBase, liveToday };
  }, [items, period]);

  const total30 = history.total30;
  const todayTotal = history.todayBase.reduce((sum, value) => sum + value, 0);
  const avg = Math.round((total30 - history.liveToday) / Math.max(1, period));
  const activeHours = history.todayBase.filter((value) => value > 0).length;

  const bars = ACTIVITY_TYPES.map((type) => ({
    label: t(`activity.type.${type}`),
    value: history.typeCounts.get(type) ?? 0,
    tone: TYPE_TONES[type],
  }));
  const systemCount = history.typeCounts.get('system') ?? 0;
  if (systemCount > 0) bars.push({ label: t('activity.type.system'), value: systemCount, tone: 'bg-zinc-500' });
  bars.sort((a, b) => b.value - a.value);

  const hourColumns = Array.from({ length: 24 }, (_, hour) => String(hour).padStart(2, '0'));

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label={t('activity.cockpit.total', { days: period })}
          value={total30}
          icon={Activity}
          to="/activity"
          ariaLabel={t('activity.cockpit.totalAria', { days: period })}
          sub={t('activity.cockpit.totalSub')}
        />
        <KpiCard
          label={t('activity.cockpit.today')}
          value={todayTotal}
          icon={Flame}
          to="/activity"
          accent={todayTotal > 0 ? 'bg-amber-500/15 text-amber-300' : 'bg-brand-700/15 text-brand-300'}
          ariaLabel={t('activity.cockpit.todayAria')}
          sub={t('activity.cockpit.todaySub')}
        />
        <KpiCard
          label={t('activity.cockpit.avg')}
          value={avg}
          icon={TrendingUp}
          to="/activity"
          ariaLabel={t('activity.cockpit.avgAria')}
          sub={t('activity.cockpit.avgSub')}
        />
        <KpiCard
          label={t('activity.cockpit.hours')}
          value={activeHours}
          icon={Clock}
          to="/activity"
          accent={activeHours >= 16 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-brand-700/15 text-brand-300'}
          ariaLabel={t('activity.cockpit.hoursAria')}
          sub={t('activity.cockpit.hoursSub')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">            <div className="flex items-baseline justify-between">
              <div>
                <h3 className="text-sm font-semibold text-white">{t('activity.cockpit.trendTitle', { days: period })}</h3>
                <p className="mt-1 text-xs text-zinc-500">{t('activity.cockpit.trendSub', { days: period })}</p>
              </div>
              <PeriodSelector value={period} onChange={setPeriod} />
            </div>
          <AreaChart
            values={history.dayTotals}
            height={110}
            className="mt-4 text-brand-400"
            label={t('activity.cockpit.trendTitle', { days: period })}
          />
        </Card>

        <Card className="p-5">
          <h3 className="text-sm font-semibold text-white">{t('activity.cockpit.byType')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('activity.cockpit.byTypeSub')}</p>
          <MiniBars data={bars} height={110} className="mt-4" />
        </Card>
      </div>

      <Card className="p-5">
        <div className="flex items-baseline justify-between">
          <div>
            <h3 className="text-sm font-semibold text-white">{t('activity.cockpit.heatmapTitle')}</h3>
            <p className="mt-1 text-xs text-zinc-500">{t('activity.cockpit.heatmapSub')}</p>
          </div>
          <div className="flex items-center gap-1.5 text-[10px] text-zinc-500">
            {t('activity.cockpit.less')}
            <span className="h-2.5 w-2.5 rounded-[3px] bg-white/[0.04]" />
            <span className="h-2.5 w-2.5 rounded-[3px] bg-brand-800/70" />
            <span className="h-2.5 w-2.5 rounded-[3px] bg-brand-700" />
            <span className="h-2.5 w-2.5 rounded-[3px] bg-brand-600" />
            <span className="h-2.5 w-2.5 rounded-[3px] bg-brand-500" />
            {t('activity.cockpit.more')}
          </div>
        </div>
        <div className="mt-4">
          <Heatmap
            data={history.heatRows}
            columns={hourColumns}
            label={t('activity.cockpit.heatmapTitle')}
          />
        </div>
        <p className="mt-3 text-center text-[10px] text-zinc-600">
          {t('activity.cockpit.heatmapFooter')}
        </p>
      </Card>
    </div>
  );
}

function NotificationRow({
  notification,
  onOpen,
  onMarkRead,
}: {
  notification: NotificationDto;
  onOpen: (notification: NotificationDto) => void;
  onMarkRead: (id: string) => void;
}) {
  const { t } = useI18n();
  const meta = NOTIFICATION_SEVERITY_META[notification.severity] ?? NOTIFICATION_SEVERITY_META.info;
  const Icon = meta.icon;
  const unread = !notification.read_at;
  const typeLabel = NOTIFICATION_TYPES.includes(notification.type)
    ? t(`notifications.type.${notification.type}`)
    : notification.type;

  return (
    <li className={cn('flex items-start gap-3 px-5 py-3', unread && 'bg-brand-900/20')}>
      <button
        onClick={() => onOpen(notification)}
        className="flex min-w-0 flex-1 items-start gap-3 rounded text-left transition-colors hover:bg-raised"
      >
        <span className={cn('mt-0.5 shrink-0', meta.className)}>
          <Icon className="h-4 w-4" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span
              className={cn(
                'truncate text-sm',
                unread ? 'font-semibold text-white' : 'font-medium text-zinc-300',
              )}
            >
              {notification.title}
            </span>
            {unread ? (
              <span aria-hidden className="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" />
            ) : null}
          </span>
          {notification.body ? (
            <span className="mt-0.5 block text-sm text-zinc-400">{notification.body}</span>
          ) : null}
          <span className="mt-1 block text-xs text-zinc-500">
            {formatDate(notification.created_at)}
          </span>
        </span>
      </button>
      <span className="flex shrink-0 flex-col items-end gap-1.5">
        <Badge tone={badgeTone[notification.severity] ?? 'neutral'}>{typeLabel}</Badge>
        {unread ? (
          <button
            onClick={() => onMarkRead(notification.id)}
            aria-label={t('notifications.markRead')}
            title={t('notifications.markRead')}
            className="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-brand-300 transition-colors hover:bg-edge hover:text-white"
          >
            <CheckCheck className="h-3 w-3" />
            {t('notifications.markRead')}
          </button>
        ) : null}
      </span>
    </li>
  );
}
