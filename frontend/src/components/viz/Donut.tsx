import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { usePrefersReducedMotion } from './useMotion';

interface DonutProps {
  /** 0..100 */
  value: number;
  size?: number;
  thickness?: number;
  /** Stroke color for the progress arc. */
  className?: string;
  /** Track (background ring) color. */
  trackClass?: string;
  /** Content rendered in the center of the ring. */
  children?: ReactNode;
  label?: string;
}

/** Animated progress ring (SVG stroke-dasharray). Grows to `value` on mount. */
export function Donut({
  value,
  size = 120,
  thickness = 10,
  className = 'text-brand-400',
  trackClass = 'text-white/10',
  children,
  label,
}: DonutProps) {
  const reduced = usePrefersReducedMotion();
  const [progress, setProgress] = useState<number>(reduced ? value : 0);
  const clamped = Math.max(0, Math.min(100, value));

  useEffect(() => {
    if (reduced) {
      setProgress(clamped);
      return;
    }

    let frame = 0;
    const start = performance.now();
    const duration = 800;
    const from = progress;
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      setProgress(from + (clamped - from) * eased);
      if (p < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);

    return () => cancelAnimationFrame(frame);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clamped, reduced]);

  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference * (1 - progress / 100);

  return (
    <div
      className="relative inline-flex shrink-0 items-center justify-center"
      style={{ width: size, height: size }}
      role="img"
      aria-label={label ?? `${Math.round(clamped)}%`}
    >
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={thickness} className={trackClass} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={thickness}
          strokeLinecap="round"
          className={className}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          style={{ transition: reduced ? 'none' : 'stroke-dashoffset 120ms linear' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">{children}</div>
    </div>
  );
}
