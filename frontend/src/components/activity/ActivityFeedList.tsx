import type { ActivityItem, ActivitySeverity } from '../../lib/api/types';
import { cn, formatDate } from '../../lib/utils';
import { Badge } from '../ui/Badge';

const severityTone: Record<ActivitySeverity, 'neutral' | 'success' | 'warning' | 'danger'> = {
  info: 'neutral',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
};

const severityDot: Record<ActivitySeverity, string> = {
  info: 'bg-zinc-500',
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-red-500',
};

export function ActivityFeedList({ items }: { items: ActivityItem[] }) {
  return (
    <ul className="divide-y divide-edge">
      {items.map((item) => (
        <li key={item.id} className="flex items-start gap-3 py-2.5">
          <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', severityDot[item.severity])} />
          <div className="min-w-0 flex-1">
            <p className="text-sm text-zinc-200">{item.title}</p>
            {item.description ? (
              <p className="truncate text-xs text-zinc-500">{item.description}</p>
            ) : null}
            {item.actor ? <p className="text-xs text-zinc-600">{item.actor}</p> : null}
          </div>
          <div className="flex shrink-0 flex-col items-end gap-1">
            <Badge tone={severityTone[item.severity]}>{item.type}</Badge>
            <span className="text-xs text-zinc-600">{formatDate(item.created_at)}</span>
          </div>
        </li>
      ))}
    </ul>
  );
}
