import { cn } from '../../lib/utils';
import { usePrefersReducedMotion } from './useMotion';

export interface StackedBarItem {
  value: number;
  color: string;
  label: string;
}

interface StackedBarProps {
  items: StackedBarItem[];
  total?: number;
  height?: number;
  /** Show the item legend under the bar. */
  showLegend?: boolean;
  className?: string;
}

/**
 * Animated stacked horizontal bar — used for remediation progress broken
 * down by status (resolved / open / dismissed). Widths animate on mount.
 */
export function StackedBar({ items, total, height = 10, showLegend = true, className }: StackedBarProps) {
  const reduced = usePrefersReducedMotion();
  const sum = items.reduce((acc, item) => acc + item.value, 0);
  const denominator = total ?? sum;

  return (
    <div className={cn('w-full', className)}>
      <div
        className="flex w-full overflow-hidden rounded-full bg-white/5"
        style={{ height }}
        role="img"
        aria-label={items.map((item) => `${item.label}: ${item.value}`).join(', ')}
      >
        {items.map((item, index) => {
          const fraction = denominator > 0 ? item.value / denominator : 0;
          if (fraction <= 0) return null;
          return (
            <div
              key={index}
              className={cn('h-full transition-[width] duration-700 ease-out', item.color)}
              style={{
                width: reduced ? `${fraction * 100}%` : 0,
                // Re-trigger the width animation after mount when motion is enabled.
              }}
              ref={(node) => {
                if (node && !reduced) {
                  requestAnimationFrame(() => {
                    node.style.width = `${fraction * 100}%`;
                  });
                }
              }}
            />
          );
        })}
      </div>

      {showLegend && (
        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
          {items.map((item, index) => (
            <span key={index} className="inline-flex items-center gap-1.5 text-xs text-white/60">
              <span className={cn('h-2 w-2 rounded-sm', item.color)} aria-hidden="true" />
              {item.label}
              <span className="font-semibold text-white/90">{item.value}</span>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
