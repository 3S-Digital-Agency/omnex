import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../lib/api';
import type { SocialProviderDto } from '../../lib/api/types';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';

function ProviderIcon({ provider }: { provider: string }) {
  switch (provider) {
    case 'google':
      return (
        <svg viewBox="0 0 48 48" className="h-4 w-4 shrink-0" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
        </svg>
      );
    case 'microsoft':
      return (
        <svg viewBox="0 0 48 48" className="h-4 w-4 shrink-0" aria-hidden="true">
          <rect x="6" y="6" width="16" height="16" fill="#F25022" />
          <rect x="26" y="6" width="16" height="16" fill="#7FBA00" />
          <rect x="6" y="26" width="16" height="16" fill="#00A4EF" />
          <rect x="26" y="26" width="16" height="16" fill="#FFB900" />
        </svg>
      );
    case 'apple':
      return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0 text-white" fill="currentColor" aria-hidden="true">
          <path d="M16.36 12.64c0-2.4 1.96-3.55 2.05-3.61-1.11-1.63-2.85-1.85-3.47-1.88-1.48-.15-2.89.87-3.64.87-.75 0-1.9-.85-3.13-.83-1.61.02-3.09.94-3.92 2.38-1.67 2.9-.43 7.2 1.2 9.56.8 1.15 1.75 2.44 2.99 2.39 1.2-.05 1.66-.78 3.11-.78 1.45 0 1.86.78 3.13.75 1.29-.02 2.11-1.17 2.9-2.33.91-1.33 1.29-2.63 1.31-2.7-.03-.01-2.51-.96-2.53-3.82zM14.02 6.12c.66-.8 1.1-1.91.98-3.02-.95.04-2.1.63-2.78 1.43-.61.71-1.15 1.85-1 2.94 1.06.08 2.14-.54 2.8-1.35z" />
        </svg>
      );
    case 'facebook':
      return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" aria-hidden="true">
          <path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.95.93-1.95 1.89v2.26h3.32l-.53 3.49h-2.79V24C19.61 23.09 24 18.1 24 12.07z" />
        </svg>
      );
    case 'amazon':
      return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" aria-hidden="true">
          <path fill="#FF9900" d="M13.93 13.53c-1.72 1.14-4.22 1.75-6.37 1.75-3.02 0-5.73-1.12-7.79-2.98-.16-.15-.02-.35.18-.23 2.22 1.29 4.96 2.07 7.79 2.07 1.91 0 4.01-.4 5.94-1.22.29-.13.54.2.25.61z" />
          <path fill="#FF9900" d="M15.78 11.42c-.22-.28-1.45-.13-2-.07-.17.02-.19-.13-.04-.23.99-.7 2.61-.5 2.8-.26.19.23-.05 1.84-.97 2.61-.14.12-.28.06-.21-.1.2-.51.64-1.66.42-1.95z" />
        </svg>
      );
    default:
      return null;
  }
}

export function SocialLoginButtons() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const [providers, setProviders] = useState<SocialProviderDto[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .socialProviders()
      .then((list) => {
        if (!cancelled) setProviders(list.filter((p) => p.configured));
      })
      .catch(() => {
        if (!cancelled) setProviders([]);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function start(provider: string) {
    setBusy(provider);
    setError(null);
    try {
      const { url } = await api.socialRedirect(provider);
      if (!url) return;
      if (url.startsWith('/')) {
        navigate(url);
      } else {
        window.location.href = url;
      }
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(null);
    }
  }

  if (providers.length === 0) return null;

  return (
    <div className="mt-4">
      <div className="flex items-center gap-3 py-2">
        <div className="h-px flex-1 bg-edge" />
        <span className="text-xs text-zinc-500">{t('auth.orContinue')}</span>
        <div className="h-px flex-1 bg-edge" />
      </div>
      <div className="space-y-2">
        {providers.map((provider) => (
          <button
            key={provider.name}
            type="button"
            onClick={() => start(provider.name)}
            disabled={busy !== null}
            className="flex w-full items-center justify-center gap-2 rounded-md border border-edge bg-raised px-4 py-2 text-sm font-medium text-zinc-200 transition-colors hover:bg-edge disabled:cursor-not-allowed disabled:opacity-50"
          >
            <ProviderIcon provider={provider.name} />
            <span>{t('auth.socialSignInWith', { provider: provider.label })}</span>
          </button>
        ))}
      </div>
      {error ? <p className="mt-2 text-xs text-red-300">{error}</p> : null}
    </div>
  );
}
