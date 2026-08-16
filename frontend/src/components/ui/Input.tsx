import { forwardRef } from 'react';
import type { InputHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function Input({ className, ...props }, ref) {
    return (
      <input
        ref={ref}
        className={cn(
          'h-9 w-full rounded-md border border-edge bg-panel px-3 text-sm text-zinc-100 placeholder:text-zinc-500 focus:border-brand-500 focus:outline-none',
          className,
        )}
        {...props}
      />
    );
  },
);
