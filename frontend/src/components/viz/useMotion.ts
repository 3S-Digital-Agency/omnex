import { useEffect, useState } from 'react';

/** Respect the user's OS-level "reduce motion" preference. */
export function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
  });

  useEffect(() => {
    const mq = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    if (!mq) return;
    const onChange = () => setReduced(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  return reduced;
}

/**
 * Animates a number toward `target` with an ease-out curve (rAF). Renders
 * the final value instantly when the user prefers reduced motion. Safe for
 * long-running dashboards: cancels the frame on unmount or target change.
 */
export function useAnimatedNumber(target: number, duration = 700): number {
  const reduced = usePrefersReducedMotion();
  const [display, setDisplay] = useState<number>(reduced ? target : 0);

  useEffect(() => {
    if (reduced) {
      setDisplay(target);
      return;
    }

    let frame = 0;
    const start = performance.now();
    const tick = (now: number) => {
      const progress = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      setDisplay((current) => current + (target - current) * eased);
      if (progress < 1) {
        frame = requestAnimationFrame(tick);
      } else {
        setDisplay(target);
      }
    };
    frame = requestAnimationFrame(tick);

    return () => cancelAnimationFrame(frame);
  }, [target, duration, reduced]);

  return display;
}
