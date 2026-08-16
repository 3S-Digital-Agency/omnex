import type { ReactNode } from 'react';
import { brand } from '../../lib/brand';

export function AuthLayout({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center text-center">
          <img src="/logo.png" alt={`${brand.name} logo`} className="mb-5 h-40 w-40 rounded-2xl object-cover" />
          <h1 className="text-2xl font-bold tracking-wide text-white">{brand.name}</h1>
          <p className="text-sm text-zinc-500">{brand.tagline}</p>
        </div>
        <div className="rounded-xl border border-edge bg-panel p-6 shadow-xl">
          <h2 className="mb-1 text-lg font-semibold text-white">{title}</h2>
          {subtitle ? <p className="mb-4 text-sm text-zinc-400">{subtitle}</p> : <div className="mb-4" />}
          {children}
        </div>
      </div>
    </div>
  );
}
