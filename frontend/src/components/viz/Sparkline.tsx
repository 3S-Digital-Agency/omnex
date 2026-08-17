import { useId } from 'react';
import { cn } from '../../lib/utils';

interface SparklineProps {
  values: number[];
  height?: number;
  className?: string;
  strokeWidth?: number;
  /** Set a minimum for the Y domain so small values still show a curve. */
  minDomain?: number;
}

/**
 * Lightweight sparkline: an SVG polyline stretched to the container width
 * (preserveAspectRatio="none") with a soft gradient area underneath. Colors
 * inherit from the parent via currentColor, so it blends with any accent.
 */
export function Sparkline({ values, height = 48, className, strokeWidth = 1.5, minDomain }: SparklineProps) {
  const gradientId = useId();

  if (values.length < 2) return null;

  const width = 100;
  const min = Math.min(minDomain ?? Infinity, ...values);
  const max = Math.max(...values);
  const range = Math.max(max - min, 1);
  const pad = 3;

  const points = values.map((value, index) => {
    const x = (index / (values.length - 1)) * width;
    const y = height - pad - ((value - min) / range) * (height - pad * 2);
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  });

  const line = points.join(' ');
  const area = `0,${height} ${line} ${width},${height}`;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-full w-full', className)}
      aria-hidden="true"
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
          <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
        </linearGradient>
      </defs>
      <polygon points={area} style={{ fill: `url(#${gradientId})` }} />
      <polyline
        points={line}
        fill="none"
        stroke="currentColor"
        strokeWidth={strokeWidth}
        strokeLinejoin="round"
        strokeLinecap="round"
      />
    </svg>
  );
}
