import { cn } from '../../lib/utils';
import { usePrefersReducedMotion } from './useMotion';

export interface HeatmapCell {
  /** Value used for intensity coloring (event count). */
  value: number;
  /** Short label shown in the tooltip, e.g. "Mon 09:00". */
  label: string;
}

interface HeatmapProps {
  /** Rows (days) × columns (hours). Row 0 = oldest day. */
  data: HeatmapCell[][];
  /** Optional column headers (hour labels). */
  columns?: string[];
  className?: string;
  /** Accessibility label for the whole chart. */
  label?: string;
}

/**
 * GitHub-style activity heatmap: each cell is a (day, hour) bucket colored by
 * intensity. Cells animate in with a small stagger; hover shows a tooltip.
 * Zero-value cells stay dim so bursts of activity stand out.
 */
export function Heatmap({ data, columns, className, label }: HeatmapProps) {
  const reduced = usePrefersReducedMotion();
  const max = Math.max(1, ...data.flat().map((cell) => cell.value));
  const rowCount = data.length;

  return (
    <div className={cn('w-full', className)} role="img" aria-label={label}>
      {columns && columns.length > 0 ? (
        <div
          className="mb-1 grid gap-1"
          style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}
        >
          {columns.map((column, index) => (
            <span
              key={column + index}
              className={cn(
                'text-center text-[9px] leading-tight text-zinc-600',
                index % 3 !== 0 && 'opacity-0',
              )}
            >
              {column}
            </span>
          ))}
        </div>
      ) : null}

      <div
        className="grid gap-1"
        style={{ gridTemplateColumns: `repeat(${data[0]?.length ?? 24}, minmax(0, 1fr))` }}
      >
        {data.map((row, rowIndex) =>
          row.map((cell, colIndex) => {
            const intensity = cell.value / max;
            const tone =
              cell.value === 0
                ? 'bg-white/[0.04]'
                : intensity > 0.75
                  ? 'bg-brand-500'
                  : intensity > 0.45
                    ? 'bg-brand-600'
                    : intensity > 0.2
                      ? 'bg-brand-700'
                      : 'bg-brand-800/70';
            return (
              <div
                key={`${rowIndex}-${colIndex}`}
                className={cn('aspect-square w-full rounded-[3px] transition-colors duration-300', tone)}
                title={`${cell.label}: ${cell.value}`}
                style={{
                  opacity: reduced || cell.value === 0 ? undefined : 0,
                  animation: reduced ? undefined : `heat-fade 400ms ${(rowIndex + colIndex) * 6}ms ease forwards`,
                }}
              />
            );
          }),
        )}
      </div>

      <style>{`
        @keyframes heat-fade { from { opacity: 0; transform: scale(0.6); } to { opacity: 1; transform: scale(1); } }
      `}</style>

    </div>
  );
}
