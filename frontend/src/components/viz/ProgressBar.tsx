import { useEffect, useState } from 'react';
import { cn } from '../../lib/utils';
import { usePrefersReducedMotion } from './useMotion';

type ProgressTone = 'brand' | 'success' | 'warning' | 'danger';

const TONES: Record<ProgressTone, string> = {
  brand: 'bg-brand-500',
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-red-500',
};

interface ProgressBarProps {
  /** 0..100 */
  percent: number;
  tone?: ProgressTone;
  className?: string;
  /** Overrides the tone color entirely. */
  barClass?: string;
}

/** Animated progress bar: fills to `percent` on mount, transitions on change. */
export function ProgressBar({ percent, tone = 'brand', className, barClass }: ProgressBarProps) {
  const reduced = usePrefersReducedMotion();
  const [grown, setGrown] = useState<boolean>(reduced);
  const clamped = Math.max(0, Math.min(100, percent));

  useEffect(() => {
    if (reduced) {
      setGrown(true);
      return;
    }
    const frame = requestAnimationFrame(() => setGrown(true));
    return () => cancelAnimationFrame(frame);
  }, [reduced, clamped]);

  return (
    <div
      className={cn('h-2 w-full overflow-hidden rounded-full bg-raised', className)}
      role="progressbar"
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className={cn(
          'h-full rounded-full transition-all duration-700 ease-out',
          barClass ?? TONES[tone],
        )}
        style={{ width: grown ? `${clamped}%` : '0%' }}
      />
    </div>
  );
}
