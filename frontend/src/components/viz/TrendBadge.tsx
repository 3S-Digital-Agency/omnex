import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { cn } from '../../lib/utils';

interface TrendBadgeProps {
  value: number | null | undefined;
  className?: string;
  suffix?: string;
}

/** Compact trend pill: ▲ value / ▼ value / — 0. */
export function TrendBadge({ value, className, suffix = '' }: TrendBadgeProps) {
  if (value === null || value === undefined || value === 0) {
    return (
      <span className={cn('inline-flex items-center gap-1 rounded-full bg-raised px-1.5 py-0.5 text-[11px] text-zinc-500', className)}>
        <Minus className="h-3 w-3" />
        {suffix}
      </span>
    );
  }

  const up = value > 0;

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[11px] font-medium',
        up ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400',
        className,
      )}
    >
      {up ? <ArrowUpRight className="h-3 w-3" /> : <ArrowDownRight className="h-3 w-3" />}
      {Math.abs(value)}
      {suffix ? <span>{suffix}</span> : null}
    </span>
  );
}
