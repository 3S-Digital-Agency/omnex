import type { ReactNode } from 'react';
import { cn } from '../../lib/utils';

export function Label({
  htmlFor,
  children,
  className,
}: {
  htmlFor?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <label htmlFor={htmlFor} className={cn('text-sm font-medium text-zinc-300', className)}>
      {children}
    </label>
  );
}

export function Field({
  label,
  htmlFor,
  error,
  hint,
  children,
}: {
  label?: string;
  htmlFor?: string;
  error?: string | null;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      {label ? <Label htmlFor={htmlFor}>{label}</Label> : null}
      {children}
      {error ? <p className="text-sm text-red-400">{error}</p> : null}
      {!error && hint ? <p className="text-sm text-zinc-500">{hint}</p> : null}
    </div>
  );
}
