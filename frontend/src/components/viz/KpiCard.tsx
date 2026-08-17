import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { ArrowRight } from 'lucide-react';
import { cn } from '../../lib/utils';
import { useAnimatedNumber } from './useMotion';
import { Sparkline } from './Sparkline';
import { TrendBadge } from './TrendBadge';

interface KpiCardProps {
  label: string;
  value: number;
  icon: LucideIcon;
  /** Destination — the card becomes a real link (drill-down). */
  to?: string;
  /** Alternative action when there is no route. */
  onClick?: () => void;
  /** Format the animated value (currency, percentages, units…). */
  format?: (value: number) => string;
  sub?: ReactNode;
  delta?: number | null;
  sparkline?: number[];
  /** Accent classes for the icon tile. */
  accent?: string;
  /** Accessible label for the whole card. */
  ariaLabel?: string;
  /** Extra content rendered below the sub-line (progress bars, chips…). */
  footer?: ReactNode;
}

/**
 * Command-center KPI card: animated counter, optional trend pill, sparkline
 * and a one-line context. Every card is a navigation target — a KPI must
 * lead somewhere.
 */
export function KpiCard({
  label,
  value,
  icon: Icon,
  to,
  onClick,
  format,
  sub,
  delta,
  sparkline,
  accent = 'bg-brand-700/15 text-brand-300',
  ariaLabel,
  footer,
}: KpiCardProps) {
  const animated = useAnimatedNumber(value);
  const display = format ? format(animated) : Math.round(animated).toLocaleString();

  const inner = (
    <>
      <div className="flex items-start justify-between">
        <div className={cn('flex h-10 w-10 items-center justify-center rounded-lg', accent)}>
          <Icon className="h-5 w-5" />
        </div>
        {delta !== null && delta !== undefined ? <TrendBadge value={delta} /> : null}
      </div>
      <p className="mt-3 text-3xl font-bold tracking-tight text-white tabular-nums">{display}</p>
      <p className="text-sm text-zinc-400">{label}</p>
      {sub ? <div className="mt-2 text-xs text-zinc-500">{sub}</div> : null}
      {footer ? <div className="mt-3">{footer}</div> : null}
      {sparkline && sparkline.length >= 2 ? (
        <div className="mt-3 h-8 text-brand-400/80">
          <Sparkline values={sparkline} height={32} />
        </div>
      ) : null}
    </>
  );

  const cardClass = cn(
    'group relative block overflow-hidden rounded-xl border border-edge bg-panel p-5 transition-all duration-200 hover:border-brand-700/60 hover:bg-raised',
    to || onClick ? 'cursor-pointer' : '',
  );

  const content = (
    <>
      {inner}
      {to || onClick ? (
        <ArrowRight className="absolute bottom-4 right-4 h-4 w-4 text-zinc-700 opacity-0 transition-opacity group-hover:opacity-100" />
      ) : null}
    </>
  );

  if (to) {
    return (
      <Link to={to} className={cardClass} aria-label={ariaLabel ?? `${label}: ${Math.round(value)}`}>
        {content}
      </Link>
    );
  }

  if (onClick) {
    return (
      <button type="button" onClick={onClick} className={cn(cardClass, 'w-full text-left')} aria-label={ariaLabel}>
        {content}
      </button>
    );
  }

  return <div className={cardClass}>{content}</div>;
}
