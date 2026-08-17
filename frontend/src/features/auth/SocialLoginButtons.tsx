import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../lib/api';
import type { SocialProviderDto } from '../../lib/api/types';
import { track } from '../../lib/analytics';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { PasskeyLoginButton } from './PasskeyLoginButton';

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
    case 'github':
      return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0 text-white" fill="currentColor" aria-hidden="true">
          <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
        </svg>
      );
    case 'openai':
      return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0 text-white" fill="currentColor" aria-hidden="true">
          <path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z" />
        </svg>
      );
    case 'sdp':
      return (
        <img
          src="/sdp.png"
          alt=""
          aria-hidden="true"
          className="h-5 w-5 shrink-0"
        />
      );
    default:
      return null;
  }
}

export function SocialLoginButtons({ standalone = false }: { standalone?: boolean }) {
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
    track('signup_started', { provider });
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

  const orderedProviders = [
    ...providers.filter((provider) => provider.name !== 'sdp'),
    ...providers.filter((provider) => provider.name === 'sdp'),
  ];

  const sdp = orderedProviders.find((provider) => provider.name === 'sdp');
  const iconProviders = orderedProviders.filter((provider) => provider.name !== 'sdp');

  return (
    <div className="mt-2">
      {standalone ? (
        <h2 className="mb-4 text-center text-sm font-semibold uppercase tracking-wider text-zinc-400">
          {t('auth.signInWith')}
        </h2>
      ) : (
        <div className="mb-2 flex items-center gap-3 py-2">
          <div className="h-px flex-1 bg-edge" />
          <span className="text-xs text-zinc-500">{t('auth.orContinue')}</span>
          <div className="h-px flex-1 bg-edge" />
        </div>
      )}
      <div className="flex flex-col gap-2">
        <PasskeyLoginButton />
        <div className="flex flex-wrap gap-2">
          {iconProviders.map((provider) => (
            <button
              key={provider.name}
              type="button"
              onClick={() => start(provider.name)}
              disabled={busy !== null}
              aria-label={provider.label}
              title={provider.label}
              className="flex h-10 min-w-10 flex-1 items-center justify-center rounded-md border border-edge bg-raised px-2 transition-colors hover:bg-edge disabled:cursor-not-allowed disabled:opacity-50"
            >
              <ProviderIcon provider={provider.name} />
            </button>
          ))}
        </div>
        {sdp ? (
          <button
            type="button"
            onClick={() => start(sdp.name)}
            disabled={busy !== null}
            className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-edge bg-raised px-4 transition-colors hover:bg-edge disabled:cursor-not-allowed disabled:opacity-50"
          >
            <ProviderIcon provider="sdp" />
            <span className="text-sm font-medium text-zinc-200">{sdp.label}</span>
          </button>
        ) : null}
      </div>
      {error ? <p className="mt-2 text-xs text-red-300">{error}</p> : null}
    </div>
  );
}
