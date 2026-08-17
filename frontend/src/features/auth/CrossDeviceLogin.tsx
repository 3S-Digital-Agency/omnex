import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, Fingerprint, Loader2, MailCheck, QrCode, ScanFace, ScanLine, Smartphone } from 'lucide-react';
import QRCode from 'react-qr-code';
import { DeviceVerificationRequired, useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { CrossDevicePlatform, CrossDeviceStartDto } from '../../lib/api/types';
import { getDeviceId } from '../../lib/device';
import { track } from '../../lib/analytics';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { Dialog } from '../../components/ui/Dialog';

function base64UrlToArrayBuffer(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
  return bytes.buffer;
}

type Step = 'scan' | 'verify' | 'done';

/**
 * Cross-device sign-in (PC ↔ phone): the desktop shows a QR code, the phone
 * scans it and authenticates with Face ID / Touch ID (iPhone), fingerprint /
 * face unlock (Android) or a passkey. When the platform supports WebAuthn the
 * phone (or this device) produces a signed assertion verified by the backend;
 * otherwise the sandbox pairing completes the flow so the demo stays fully
 * functional.
 */
export function CrossDeviceLogin() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const { signInWithCrossDevice, signInWithPasskey, verifyDevice } = useAuth();
  const [open, setOpen] = useState(false);
  const [step, setStep] = useState<Step>('scan');
  const [platform, setPlatform] = useState<CrossDevicePlatform>('iphone');
  const [pairing, setPairing] = useState<CrossDeviceStartDto | null>(null);
  const [verificationToken, setVerificationToken] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(300);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (!open) return;
    track('signup_started', { provider: 'cross_device' });
    setStep('scan');
    setError(null);
    setSecondsLeft(300);
    api
      .startCrossDevice()
      .then(setPairing)
      .catch((err) => setError(errorMessage(err)));

    timerRef.current = setInterval(() => {
      setSecondsLeft((s) => {
        if (s <= 1) {
          if (timerRef.current) clearInterval(timerRef.current);
          return 0;
        }
        return s - 1;
      });
    }, 1000);

    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, [open]);

  function close() {
    setOpen(false);
    setPairing(null);
    setStep('scan');
    setError(null);
  }

  /** Real path: a signed WebAuthn assertion (this device or the phone). */
  async function startWebAuthn() {
    if (!pairing) return;
    setBusy(true);
    setError(null);
    try {
      const assertion = await navigator.credentials.get({
        publicKey: {
          challenge: base64UrlToArrayBuffer(pairing.challenge),
          rpId: pairing.rp_id,
          timeout: pairing.timeout ?? 60_000,
          allowCredentials: [],
          userVerification: 'preferred',
        },
      });
      if (assertion && assertion.type === 'public-key') {
        await signInWithPasskey(assertion as PublicKeyCredential);
        setStep('done');
        navigate('/', { replace: true });
      }
    } catch {
      // Cancelled or unsupported — fall through to the sandbox pairing.
    } finally {
      setBusy(false);
    }
  }

  /** Sandbox path: approve the pairing from the (simulated) phone. */
  async function approveFromPhone() {
    if (!pairing) return;
    setBusy(true);
    setError(null);
    try {
      await signInWithCrossDevice({
        pairing_code: pairing.pairing_code,
        device: platform === 'iphone' ? 'iphone' : 'android',
        method: platform === 'iphone' ? 'face_id' : 'fingerprint',
        device_id: getDeviceId(),
      });
      setStep('done');
      navigate('/', { replace: true });
    } catch (err) {
      if (err instanceof DeviceVerificationRequired) {
        setVerificationToken(err.verificationToken);
        setStep('verify');
      } else {
        setError(errorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  }

  /** Verify the brand-new device with the e-mailed 6-digit code. */
  async function submitCode() {
    if (!verificationToken) return;
    setBusy(true);
    setError(null);
    try {
      await verifyDevice(verificationToken, code.trim());
      setStep('done');
      navigate('/', { replace: true });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  const expired = secondsLeft <= 0;

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-brand-700/50 bg-brand-900/20 px-4 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900/40"
      >
        <Smartphone className="h-4 w-4" />
        <span>{t('auth.crossDevice.cta')}</span>
      </button>

      <Dialog open={open} onClose={close} title={t('auth.crossDevice.title')} description={t('auth.crossDevice.subtitle')}>
        {!pairing ? (
          <div className="flex flex-col items-center gap-3 py-8">
            <Loader2 className="h-6 w-6 animate-spin text-brand-300" />
            <p className="text-sm text-zinc-400">{t('auth.crossDevice.preparing')}</p>
          </div>
        ) : step === 'scan' ? (
          <div className="flex flex-col items-center gap-4">
            <div className="flex w-full items-center justify-center gap-2" role="group" aria-label={t('auth.crossDevice.platformLabel')}>
              <button
                type="button"
                onClick={() => setPlatform('iphone')}
                aria-pressed={platform === 'iphone'}
                className={`flex flex-1 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-colors ${
                  platform === 'iphone'
                    ? 'border-brand-600 bg-brand-900/40 text-brand-100'
                    : 'border-edge bg-raised text-zinc-400 hover:bg-edge'
                }`}
              >
                <Smartphone className="h-3.5 w-3.5" />
                {t('auth.crossDevice.platformIphone')}
              </button>
              <button
                type="button"
                onClick={() => setPlatform('android')}
                aria-pressed={platform === 'android'}
                className={`flex flex-1 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-colors ${
                  platform === 'android'
                    ? 'border-brand-600 bg-brand-900/40 text-brand-100'
                    : 'border-edge bg-raised text-zinc-400 hover:bg-edge'
                }`}
              >
                <Fingerprint className="h-3.5 w-3.5" />
                {t('auth.crossDevice.platformAndroid')}
              </button>
            </div>
            <div className="rounded-2xl border border-edge bg-white p-4">
              <QRCode value={pairing.qr_payload} size={190} />
            </div>
            <div className="flex items-center gap-2 text-sm text-zinc-300">
              <ScanLine className="h-4 w-4 text-brand-300" />
              <span>{t('auth.crossDevice.scanHint')}</span>
            </div>
            <div className="flex w-full items-center justify-between rounded-lg border border-edge bg-raised px-3 py-2">
              <div className="flex items-center gap-2">
                <QrCode className="h-4 w-4 text-zinc-400" />
                <span className="font-mono text-xs tracking-widest text-zinc-200">{pairing.pairing_code}</span>
              </div>
              <span className={expired ? 'text-xs font-medium text-red-300' : 'text-xs text-zinc-500'}>
                {expired ? t('auth.crossDevice.expired') : `${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`}
              </span>
            </div>
            <div className="flex items-start gap-2 text-center">
              {platform === 'iphone' ? (
                <ScanFace className="mt-0.5 h-4 w-4 shrink-0 text-brand-300" />
              ) : (
                <Fingerprint className="mt-0.5 h-4 w-4 shrink-0 text-brand-300" />
              )}
              <p className="text-xs leading-relaxed text-zinc-500">{t('auth.crossDevice.biometricHint')}</p>
            </div>
            {typeof navigator !== 'undefined' && !!navigator.credentials && !!window.PublicKeyCredential ? (
              <button
                type="button"
                onClick={() => void startWebAuthn()}
                disabled={busy}
                className="w-full rounded-md border border-edge bg-raised px-4 py-2 text-sm font-medium text-zinc-200 transition-colors hover:bg-edge disabled:opacity-50"
              >
                {busy ? <Loader2 className="mx-auto h-4 w-4 animate-spin" /> : t('auth.crossDevice.useThisDevice')}
              </button>
            ) : null}
            <button
              type="button"
              onClick={() => void approveFromPhone()}
              disabled={busy || expired}
              className="w-full rounded-md border border-brand-700/50 bg-brand-900/20 px-4 py-2 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? <Loader2 className="mx-auto h-4 w-4 animate-spin" /> : t('auth.crossDevice.phoneApproved')}
            </button>
            {error ? <p className="text-xs text-red-300">{error}</p> : null}
          </div>
        ) : step === 'verify' ? (
          <div className="flex flex-col items-center gap-4">
            <MailCheck className="h-8 w-8 text-brand-300" />
            <p className="text-center text-sm leading-relaxed text-zinc-400">{t('auth.crossDevice.verifyHint')}</p>
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
              className="w-40 rounded-md border border-edge bg-raised px-3 py-2 text-center font-mono text-lg tracking-[0.4em] text-white focus:border-brand-500 focus:outline-none"
            />
            <button
              type="button"
              onClick={() => void submitCode()}
              disabled={busy || code.length !== 6}
              className="w-full rounded-md border border-brand-700/50 bg-brand-900/20 px-4 py-2 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? <Loader2 className="mx-auto h-4 w-4 animate-spin" /> : t('auth.crossDevice.verifyCode')}
            </button>
            <button
              type="button"
              onClick={() => {
                setStep('scan');
                setCode('');
              }}
              className="text-xs text-zinc-500 underline-offset-2 transition-colors hover:text-zinc-300 hover:underline"
            >
              {t('auth.crossDevice.backToScan')}
            </button>
            {error ? <p className="text-xs text-red-300">{error}</p> : null}
          </div>
        ) : (
          <div className="flex flex-col items-center gap-4 py-8">
            <CheckCircle2 className="h-10 w-10 text-emerald-400" />
            <p className="text-center text-sm text-zinc-300">{t('auth.crossDevice.success')}</p>
          </div>
        )}
      </Dialog>
    </div>
  );
}
