import { cn } from '../../lib/utils';

/** Shimmering placeholder block for skeleton loaders. */
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('omnex-skeleton rounded-md', className)} aria-hidden="true" />;
}
