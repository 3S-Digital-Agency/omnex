import { Link } from 'react-router-dom';
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

interface ActivityFeedListProps {
  items: ActivityItem[];
  /** Optional drill-down: return a destination for an item to make it clickable. */
  to?: (item: ActivityItem) => string | undefined;
}

export function ActivityFeedList({ items, to }: ActivityFeedListProps) {
  return (
    <ul className="divide-y divide-edge">
      {items.map((item) => {
        const destination = to?.(item);

        const row = (
          <span className="flex items-start gap-3 py-2.5">
            <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', severityDot[item.severity])} />
            <span className="min-w-0 flex-1">
              <span className="block text-sm text-zinc-200">{item.title}</span>
              {item.description ? (
                <span className="block truncate text-xs text-zinc-500">{item.description}</span>
              ) : null}
              {item.actor ? <span className="block text-xs text-zinc-600">{item.actor}</span> : null}
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1">
              <Badge tone={severityTone[item.severity]}>{item.type}</Badge>
              <span className="text-xs text-zinc-600">{formatDate(item.created_at)}</span>
            </span>
          </span>
        );

        return (
          <li key={item.id} className="group">
            {destination ? (
              <Link
                to={destination}
                className="block -mx-2 rounded-md px-2 transition-colors hover:bg-raised focus:outline-none focus-visible:ring-1 focus-visible:ring-brand-500"
                aria-label={item.title}
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
  );
}
