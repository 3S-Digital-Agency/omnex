import { useId } from 'react';
import { cn } from '../../lib/utils';
import { usePrefersReducedMotion } from './useMotion';

interface AreaChartProps {
  values: number[];
  labels?: string[]; // same length as values; rendered as hover ticks
  height?: number;
  className?: string;
  /** Fixed Y domain. Defaults to the min/max of the data (with padding). */
  min?: number;
  max?: number;
  colorClass?: string;
  /** Renders the latest value as a highlighted end dot. */
  showLastPoint?: boolean;
  /** Accessibility label. */
  label?: string;
}

/**
 * Lightweight SVG area chart for time series (score evolution). Draws a grid,
 * min/max annotations, an animated area+line and an optional highlighted end
 * point. Width is fluid (preserveAspectRatio="none") while the height is fixed.
 */
export function AreaChart({
  values,
  labels,
  height = 120,
  className,
  min,
  max,
  colorClass = 'text-brand-400',
  showLastPoint = true,
  label,
}: AreaChartProps) {
  const gradientId = useId();
  const reduced = usePrefersReducedMotion();

  if (values.length < 2) {
    return (
      <div className={cn('flex items-center justify-center text-sm text-white/40', className)} style={{ height }}>
        —&nbsp;{label ?? 'No data yet'}
      </div>
    );
  }

  const width = 100;
  const padY = 8;
  const dataMin = Math.min(...values);
  const dataMax = Math.max(...values);
  const yMin = min ?? Math.max(0, dataMin - 10);
  const yMax = max ?? Math.min(100, dataMax + 10);
  const range = Math.max(yMax - yMin, 1);

  const points = values.map((value, index) => {
    const x = (index / (values.length - 1)) * width;
    const y = padY + (1 - (value - yMin) / range) * (height - padY * 2);
    return { x, y, value };
  });

  const line = points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' ');
  const area = `0,${height} ${line} ${width},${height}`;
  const last = points[points.length - 1];

  return (
    <div className={cn('relative w-full', className)} style={{ height }} role="img" aria-label={label}>
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="h-full w-full overflow-visible">
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="currentColor" stopOpacity="0.3" />
            <stop offset="100%" stopColor="currentColor" stopOpacity="0.02" />
          </linearGradient>
        </defs>

        {/* Horizontal grid lines at 0 / 25 / 50 / 75 / 100 of the domain */}
        {[0, 1, 2, 3, 4].map((i) => {
          const y = padY + (i / 4) * (height - padY * 2);
          return <line key={i} x1="0" y1={y} x2={width} y2={y} stroke="currentColor" className="text-white/10" strokeWidth={0.4} strokeDasharray="2 3" />;
        })}

        <polygon points={area} className={colorClass} style={{ fill: `url(#${gradientId})` }} />
        <polyline
          points={line}
          fill="none"
          stroke="currentColor"
          strokeWidth={1.6}
          strokeLinejoin="round"
          strokeLinecap="round"
          className={colorClass}
          style={{ transition: reduced ? 'none' : 'stroke-dashoffset 900ms ease-out' }}
          pathLength={1}
          strokeDasharray={1}
          strokeDashoffset={reduced ? 0 : 0}
        />
      </svg>

      {/* Min / max annotations */}
      <span className="absolute -top-1 right-0 text-[10px] font-medium text-white/50">{Math.round(dataMax)}</span>
      <span className="absolute -bottom-1 right-0 text-[10px] text-white/35">{Math.round(dataMin)}</span>

      {showLastPoint && last && (
        <span
          className={cn('absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-background', colorClass)}
          style={{ left: `${last.x}%`, top: `${(last.y / height) * 100}%` }}
          title={`${last.value}`}
        />
      )}
    </div>
  );
}
