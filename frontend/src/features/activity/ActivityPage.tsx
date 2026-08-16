import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BellOff, CheckCheck, ChevronLeft, ChevronRight } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { useI18n } from '../../lib/i18n';
import { useActivityFeed } from '../../lib/useActivityFeed';
import { ActivityFeedList } from '../../components/activity/ActivityFeedList';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { Select } from '../../components/ui/Select';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { api } from '../../lib/api';
import type { NotificationDto, NotificationSeverity } from '../../lib/api/types';
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

      {tab === 'notifications' ? <NotificationsPanel /> : <ActivityPanel />}
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

function ActivityPanel() {
  const { activeOrganization } = useAuth();
  const { t } = useI18n();
  const { items, isLoading } = useActivityFeed(!!activeOrganization);

  return (
    <Card className="p-5">
      {isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : items.length > 0 ? (
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
