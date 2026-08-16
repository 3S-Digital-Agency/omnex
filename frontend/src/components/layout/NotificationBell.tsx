import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertCircle,
  AlertTriangle,
  Bell,
  BellOff,
  CheckCheck,
  CheckCircle2,
  Info,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { NotificationDto, NotificationListDto, NotificationSeverity } from '../../lib/api/types';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';

const SEVERITY_META: Record<NotificationSeverity, { icon: typeof Info; className: string }> = {
  info: { icon: Info, className: 'text-brand-300' },
  success: { icon: CheckCircle2, className: 'text-emerald-400' },
  warning: { icon: AlertTriangle, className: 'text-amber-400' },
  danger: { icon: AlertCircle, className: 'text-red-400' },
};

export function NotificationBell() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Real-time: each pushed notification invalidates this query, so the badge
  // updates instantly. A slow poll remains as a fallback if the stream drops.
  const notifications = useQuery({
    queryKey: ['notifications'],
    queryFn: () => api.listNotifications(),
    refetchInterval: 60_000,
  });

  useEffect(() => {
    return api.subscribeNotifications(() => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] });
    });
  }, [queryClient]);

  const unread = notifications.data?.unread ?? 0;

  const markRead = useMutation({
    mutationFn: (id: string) => api.markNotificationRead(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ['notifications'] });
      const previous = queryClient.getQueryData<NotificationListDto>(['notifications']);
      if (previous) {
        queryClient.setQueryData<NotificationListDto>(['notifications'], {
          ...previous,
          unread: Math.max(0, previous.unread - 1),
          data: previous.data.map((n) =>
            n.id === id && !n.read_at ? { ...n, read_at: new Date().toISOString() } : n,
          ),
        });
      }
      return { previous };
    },
    onError: (_error, _id, context) => {
      if (context?.previous) queryClient.setQueryData(['notifications'], context.previous);
    },
  });

  const markAllRead = useMutation({
    mutationFn: () => api.markAllNotificationsRead(),
    onMutate: async () => {
      await queryClient.cancelQueries({ queryKey: ['notifications'] });
      const previous = queryClient.getQueryData<NotificationListDto>(['notifications']);
      if (previous) {
        const now = new Date().toISOString();
        queryClient.setQueryData<NotificationListDto>(['notifications'], {
          ...previous,
          unread: 0,
          data: previous.data.map((n) => (n.read_at ? n : { ...n, read_at: now })),
        });
      }
      return { previous };
    },
    onError: (_error, _variables, context) => {
      if (context?.previous) queryClient.setQueryData(['notifications'], context.previous);
    },
  });

  useEffect(() => {
    function onMouseDown(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }
    document.addEventListener('mousedown', onMouseDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onMouseDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, []);

  function openNotification(notification: NotificationDto) {
    if (!notification.read_at) markRead.mutate(notification.id);
    setOpen(false);
    if (notification.route) navigate(notification.route);
  }

  const label = unread > 0 ? t('notifications.toggleUnread', { count: unread }) : t('notifications.toggle');

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        className="relative flex h-9 w-9 items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-raised hover:text-white"
      >
        <Bell className="h-4 w-4" />
        {unread > 0 ? (
          <span
            aria-hidden
            className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white"
          >
            {unread > 99 ? '99+' : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <div
          role="menu"
          aria-label={t('notifications.title')}
          className="animate-omnex-pop-in absolute right-0 top-full z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-edge bg-panel shadow-2xl"
        >
          <div className="flex items-center justify-between border-b border-edge px-4 py-3">
            <div>
              <h2 className="text-sm font-semibold text-white">{t('notifications.title')}</h2>
              <p className="text-xs text-zinc-500">
                {unread > 0 ? t('notifications.unreadCount', { count: unread }) : t('notifications.allRead')}
              </p>
            </div>
            {unread > 0 ? (
              <button
                onClick={() => markAllRead.mutate()}
                disabled={markAllRead.isPending}
                className="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-brand-300 transition-colors hover:bg-raised disabled:opacity-50"
              >
                <CheckCheck className="h-3.5 w-3.5" /> {t('notifications.markAllRead')}
              </button>
            ) : null}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {notifications.isLoading ? (
              <div className="flex justify-center py-10 text-sm text-zinc-500">{t('notifications.loading')}</div>
            ) : notifications.data && notifications.data.data.length > 0 ? (
              <ul>
                {notifications.data.data.map((notification) => (
                  <NotificationItem key={notification.id} notification={notification} onOpen={openNotification} />
                ))}
              </ul>
            ) : (
              <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
                <BellOff className="h-6 w-6 text-zinc-600" />
                <p className="text-sm text-zinc-500">{t('notifications.empty')}</p>
              </div>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function NotificationItem({
  notification,
  onOpen,
}: {
  notification: NotificationDto;
  onOpen: (notification: NotificationDto) => void;
}) {
  const { t } = useI18n();
  const meta = SEVERITY_META[notification.severity] ?? SEVERITY_META.info;
  const Icon = meta.icon;
  const unread = !notification.read_at;

  return (
    <li>
      <button
        role="menuitem"
        onClick={() => onOpen(notification)}
        className={cn(
          'flex w-full items-start gap-3 border-b border-edge/60 px-4 py-3 text-left transition-colors hover:bg-raised',
          unread && 'bg-brand-900/20',
        )}
      >
        <span className={cn('mt-0.5 shrink-0', meta.className)}>
          <Icon className="h-4 w-4" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span className={cn('truncate text-sm', unread ? 'font-semibold text-white' : 'font-medium text-zinc-300')}>
              {notification.title}
            </span>
            {unread ? <span aria-hidden className="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" /> : null}
          </span>
          {notification.body ? <span className="mt-0.5 block text-sm text-zinc-400">{notification.body}</span> : null}
          <span className="mt-1 block text-xs text-zinc-500">{formatDate(notification.created_at)}</span>
        </span>
        {unread ? <span className="sr-only">{t('notifications.unread')}</span> : null}
      </button>
    </li>
  );
}
