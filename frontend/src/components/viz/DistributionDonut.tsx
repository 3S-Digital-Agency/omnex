import { useId } from 'react';
import { usePrefersReducedMotion } from './useMotion';

export interface DonutSegment {
  value: number;
  color: string; // tailwind text/stroke color class
  label: string;
}

interface DistributionDonutProps {
  segments: DonutSegment[];
  size?: number;
  thickness?: number;
  /** Content rendered in the center (usually a total). */
  center?: React.ReactNode;
  label?: string;
}

/**
 * Multi-segment donut (stacked SVG circles with stroke-dasharray offsets).
 * Each segment animates in on mount. Zero-valued segments are skipped so
 * the ring reads cleanly when a severity bucket is empty.
 */
export function DistributionDonut({
  segments,
  size = 140,
  thickness = 12,
  center,
  label,
}: DistributionDonutProps) {
  const gradientId = useId();
  const reduced = usePrefersReducedMotion();
  const total = segments.reduce((sum, segment) => sum + segment.value, 0);
  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;

  const nonEmpty = segments.filter((segment) => segment.value > 0);
  let offset = 0;

  return (
    <div
      className="relative inline-flex shrink-0 items-center justify-center"
      style={{ width: size, height: size }}
      role="img"
      aria-label={label ?? `${total}`}
    >
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={thickness}
          className="text-white/10"
        />
        {nonEmpty.map((segment, index) => {
          const fraction = segment.value / Math.max(total, 1);
          const dash = fraction * circumference;
          const dashOffset = -offset * circumference;
          offset += fraction;
          return (
            <circle
              key={index}
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              strokeWidth={thickness}
              strokeDasharray={`${dash} ${circumference - dash}`}
              strokeDashoffset={dashOffset}
              className={segment.color}
              style={{
                transition: reduced ? 'none' : 'stroke-dasharray 700ms cubic-bezier(0.22, 1, 0.36, 1), stroke-dashoffset 700ms cubic-bezier(0.22, 1, 0.36, 1)',
              }}
            />
          );
        })}
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">{center}</div>
    </div>
  );
}
