import { forwardRef } from 'react';
import type { SelectHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, children, ...props }, ref) {
    return (
      <select
        ref={ref}
        className={cn(
          'h-9 w-full rounded-md border border-edge bg-panel px-3 text-sm text-zinc-100 focus:border-brand-500 focus:outline-none',
          className,
        )}
        {...props}
      >
        {children}
      </select>
    );
  },
);
