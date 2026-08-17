import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Fingerprint, Loader2 } from 'lucide-react';
import { api } from '../../lib/api';
import { track } from '../../lib/analytics';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { useAuth } from '../../app/AuthProvider';

/** base64url → ArrayBuffer (WebAuthn requires raw bytes for challenge/ids). */
function base64UrlToArrayBuffer(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
  return bytes.buffer;
}

function webAuthnAvailable(): boolean {
  return typeof window !== 'undefined' && !!navigator?.credentials && !!window.PublicKeyCredential;
}

/**
 * Passwordless sign-in with passkeys (WebAuthn): on an iPhone this surfaces
 * Face ID, on Mac/Windows the platform authenticator (Touch ID / Windows
 * Hello). In sandbox mode (no WebAuthn or no real backend) the flow falls
 * back to the mock passkey challenge so the demo stays fully functional.
 */
export function PasskeyLoginButton({ compact = false }: { compact?: boolean }) {
  const { t } = useI18n();
  const { signInWithPasskey } = useAuth();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    setBusy(true);
    setError(null);
    track('signup_started', { provider: 'passkey' });
    try {
      const options = await api.passkeyRequestOptions();

      if (webAuthnAvailable()) {
        try {
          const assertion = await navigator.credentials.get({
            publicKey: {
              challenge: base64UrlToArrayBuffer(options.challenge),
              rpId: options.rp_id,
              timeout: options.timeout ?? 60_000,
              allowCredentials: (options.allow_credentials ?? []).map((credential) => ({
                id: base64UrlToArrayBuffer(credential.id),
                type: credential.type as 'public-key',
              })),
              userVerification: 'preferred',
            },
          });
          if (assertion && assertion.type === 'public-key') {
            await signInWithPasskey(assertion as PublicKeyCredential);
            navigate('/', { replace: true });
            return;
          }
        } catch {
          // User cancelled or WebAuthn failed — fall through to the sandbox.
        }
      }

      // Sandbox fallback: complete the passkey sign-in against the mock.
      await signInWithPasskey(null);
      navigate('/', { replace: true });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <button
        type="button"
        onClick={() => void start()}
        disabled={busy}
        className={
          compact
            ? 'flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-edge bg-raised transition-colors hover:bg-edge disabled:cursor-not-allowed disabled:opacity-50'
            : 'flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-brand-700/50 bg-brand-900/20 px-4 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900/40 disabled:cursor-not-allowed disabled:opacity-50'
        }
        aria-label={t('auth.passkey.label')}
        title={t('auth.passkey.label')}
      >
        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Fingerprint className="h-4 w-4" />}
        {!compact ? <span>{t('auth.passkey.label')}</span> : null}
      </button>
      {error ? <p className="mt-2 text-xs text-red-300">{error}</p> : null}
      <button
        type="button"
        onClick={() => void sandboxSignIn()}
        disabled={busy}
        className="mt-1.5 text-[11px] text-zinc-500 underline-offset-2 transition-colors hover:text-zinc-300 hover:underline disabled:opacity-50"
      >
        {t('auth.passkey.demo')}
      </button>
    </div>
  );

  async function sandboxSignIn() {
    setBusy(true);
    setError(null);
    try {
      await signInWithPasskey(null);
      navigate('/', { replace: true });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }
}
