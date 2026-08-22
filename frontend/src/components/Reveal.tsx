import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { usePrefersReducedMotion } from './viz/useMotion';

type RevealVariant = 'up' | 'left' | 'right' | 'blur' | 'scale';

interface RevealProps {
  children: ReactNode;
  /** Delay in ms before the reveal transition starts (for staggering). */
  delay?: number;
  /** Vertical travel in px (only used by the `up` variant). */
  y?: number;
  className?: string;
  variant?: RevealVariant;
}

const hiddenTransform: Record<RevealVariant, string> = {
  up: 'translateY(20px)',
  left: 'translateX(-24px)',
  right: 'translateX(24px)',
  blur: 'translateY(12px)',
  scale: 'scale(0.96)',
};

/**
 * Fades and slides its content in the first time it scrolls into view.
 * Respects `prefers-reduced-motion` by showing content immediately.
 */
export function Reveal({
  children,
  delay = 0,
  y = 20,
  className,
  variant = 'up',
}: RevealProps) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [visible, setVisible] = useState(false);
  const reduced = usePrefersReducedMotion();

  useEffect(() => {
    const el = ref.current;
    if (!el) return;

    if (reduced) {
      setVisible(true);
      return;
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setVisible(true);
          observer.disconnect();
        }
      },
      { threshold: 0.12, rootMargin: '0px 0px -40px 0px' },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [reduced]);

  const transform =
    variant === 'up'
      ? hiddenTransform.up.replace('20px', `${y}px`)
      : hiddenTransform[variant];

  const style: CSSProperties = {
    opacity: visible ? 1 : 0,
    transform: visible ? 'none' : transform,
    filter: variant === 'blur' && !visible ? 'blur(6px)' : 'none',
    transition: `opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1) ${delay}ms, transform 0.7s cubic-bezier(0.22, 1, 0.36, 1) ${delay}ms, filter 0.7s cubic-bezier(0.22, 1, 0.36, 1) ${delay}ms`,
    willChange: 'opacity, transform',
  };

  return (
    <div ref={ref} style={style} className={className}>
      {children}
    </div>
  );
}
