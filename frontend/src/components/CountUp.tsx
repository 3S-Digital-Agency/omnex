import { useEffect, useRef, useState } from 'react';
import { usePrefersReducedMotion } from './viz/useMotion';

interface CountUpProps {
  /** Target value. */
  end: number;
  /** Animation duration in ms. */
  duration?: number;
  /** Number of decimal places to show. */
  decimals?: number;
  prefix?: string;
  suffix?: string;
}

/**
 * Animates a number from 0 to `end` the first time it scrolls into view.
 * Respects `prefers-reduced-motion` by rendering the final value immediately.
 */
export function CountUp({ end, duration = 1400, decimals = 0, prefix = '', suffix = '' }: CountUpProps) {
  const ref = useRef<HTMLSpanElement | null>(null);
  const [value, setValue] = useState(0);
  const started = useRef(false);
  const reduced = usePrefersReducedMotion();

  useEffect(() => {
    const el = ref.current;
    if (!el) return;

    if (reduced) {
      setValue(end);
      return;
    }

    let raf = 0;
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting || started.current) return;
        started.current = true;
        observer.disconnect();

        const start = performance.now();
        const tick = (now: number) => {
          const progress = Math.min(1, (now - start) / duration);
          // easeOutCubic for a fast start and a gentle settle.
          const eased = 1 - Math.pow(1 - progress, 3);
          setValue(end * eased);
          if (progress < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
      },
      { threshold: 0.4 },
    );
    observer.observe(el);
    return () => {
      observer.disconnect();
      cancelAnimationFrame(raf);
    };
  }, [end, duration, reduced]);

  const formatted = value.toFixed(decimals);

  return (
    <span ref={ref}>
      {prefix}
      {formatted}
      {suffix}
    </span>
  );
}
