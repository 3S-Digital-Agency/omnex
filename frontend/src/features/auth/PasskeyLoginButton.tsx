import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Fingerprint, Loader2, MailCheck } from 'lucide-react';
import { api } from '../../lib/api';
import { track } from '../../lib/analytics';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { DeviceVerificationRequired, useAuth } from '../../app/AuthProvider';

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
  const { signInWithPasskey, verifyDevice } = useAuth();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [verificationToken, setVerificationToken] = useState<string | null>(null);
  const [code, setCode] = useState('');

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
            navigate('/overview', { replace: true });
            return;
          }
        } catch {
          // User cancelled or WebAuthn failed — fall through to the sandbox.
        }
      }

      // Sandbox fallback: complete the passkey sign-in against the mock.
      await signInWithPasskey(null);
      navigate('/overview', { replace: true });
    } catch (err) {
      if (err instanceof DeviceVerificationRequired) {
        setVerificationToken(err.verificationToken);
        setError(null);
      } else {
        setError(errorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  }

  async function submitCode() {
    if (!verificationToken) return;
    setBusy(true);
    setError(null);
    try {
      await verifyDevice(verificationToken, code.trim());
      navigate('/overview', { replace: true });
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
      {verificationToken ? (
        <div className="mt-3 rounded-lg border border-edge bg-raised p-3">
          <div className="flex items-center gap-2 text-xs font-medium text-zinc-300">
            <MailCheck className="h-4 w-4 text-brand-300" />
            {t('auth.crossDevice.verifyHint')}
          </div>
          <div className="mt-2 flex gap-2">
            <input
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void submitCode();
              }}
              placeholder="••••••"
              aria-label={t('auth.code6')}
              className="w-28 rounded-md border border-edge bg-panel px-3 py-1.5 text-center font-mono text-base tracking-[0.3em] text-white focus:border-brand-500 focus:outline-none"
            />
            <button
              type="button"
              onClick={() => void submitCode()}
              disabled={busy || code.length !== 6}
              className="flex-1 rounded-md border border-brand-700/50 bg-brand-900/20 px-3 py-1.5 text-xs font-medium text-brand-200 transition-colors hover:bg-brand-900/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? <Loader2 className="mx-auto h-3.5 w-3.5 animate-spin" /> : t('auth.crossDevice.verifyCode')}
            </button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => void sandboxSignIn()}
          disabled={busy}
          className="mt-1.5 text-[11px] text-zinc-500 underline-offset-2 transition-colors hover:text-zinc-300 hover:underline disabled:opacity-50"
        >
          {t('auth.passkey.demo')}
        </button>
      )}
    </div>
  );

  async function sandboxSignIn() {
    setBusy(true);
    setError(null);
    try {
      await signInWithPasskey(null);
      navigate('/overview', { replace: true });
    } catch (err) {
      if (err instanceof DeviceVerificationRequired) {
        setVerificationToken(err.verificationToken);
        setError(null);
      } else {
        setError(errorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  }
}
