import type { ReactNode } from 'react';
import { cn } from '../../lib/utils';

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('rounded-xl border border-edge bg-panel', className)}>{children}</div>;
}

export function CardHeader({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-edge px-5 py-4">
      <div>
        <h3 className="text-sm font-semibold text-white">{title}</h3>
        {description ? <p className="mt-0.5 text-sm text-zinc-400">{description}</p> : null}
      </div>
      {action}
    </div>
  );
}
