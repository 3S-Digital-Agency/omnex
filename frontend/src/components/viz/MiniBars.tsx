import { useEffect, useState } from 'react';
import { cn } from '../../lib/utils';
import { usePrefersReducedMotion } from './useMotion';

export interface MiniBarDatum {
  label: string;
  value: number;
  /** Bar fill class, e.g. bg-emerald-500. */
  tone?: string;
}

interface MiniBarsProps {
  data: MiniBarDatum[];
  height?: number;
  className?: string;
}

/** Animated vertical bars: they grow from 0 to their value on mount. */
export function MiniBars({ data, height = 72, className }: MiniBarsProps) {
  const reduced = usePrefersReducedMotion();
  const [grown, setGrown] = useState<boolean>(reduced);

  useEffect(() => {
    if (reduced) return;
    const frame = requestAnimationFrame(() => setGrown(true));
    return () => cancelAnimationFrame(frame);
  }, [reduced]);

  const max = Math.max(1, ...data.map((item) => item.value));

  return (
    <div className={cn('flex items-end gap-2', className)} style={{ height }} aria-hidden="true">
      {data.map((item) => {
        const percent = (item.value / max) * 100;
        return (
          <div
            key={item.label}
            className="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
            title={`${item.label}: ${item.value}`}
          >
            <div className="relative flex w-full flex-1 items-end justify-center">
              <div
                className={cn(
                  'w-full max-w-7 rounded-t-md transition-all duration-700 ease-out group-hover:brightness-125',
                  item.tone ?? 'bg-brand-500',
                )}
                style={{ height: grown ? `${percent}%` : '0%' }}
              />
            </div>
            <span className="w-full truncate text-center text-[10px] text-zinc-600">{item.label}</span>
          </div>
        );
      })}
    </div>
  );
}
