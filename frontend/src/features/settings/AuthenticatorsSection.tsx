import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { KeyRound, Plus, ShieldAlert, Smartphone, Trash2, Usb, Waves } from 'lucide-react';
import { api } from '../../lib/api';
import type { AuthenticatorDto, PasskeyCredentialDto, SecurityLevel } from '../../lib/api/types';
import { useAuth } from '../../app/AuthProvider';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';

const SECURITY_LEVELS: Array<{ value: SecurityLevel; icon: typeof KeyRound }> = [
  { value: 'standard', icon: KeyRound },
  { value: 'enhanced', icon: Waves },
  { value: 'critical', icon: ShieldAlert },
];

function base64UrlToArrayBuffer(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
  return bytes.buffer;
}

/** ArrayBuffer/Uint8Array → base64url (WebAuthn attestation/assertion encoding). */
function base64UrlEncode(data: ArrayBuffer | Uint8Array): string {
  const bytes = data instanceof Uint8Array ? data : new Uint8Array(data);
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function TransportIcon({ transport }: { transport?: string | null }) {
  const { t } = useI18n();
  switch (transport) {
    case 'usb':
    case 'nfc':
      return <Usb className="h-4 w-4" aria-hidden />;
    case 'ble':
      return <Waves className="h-4 w-4" aria-hidden />;
    case 'internal':
      return <Smartphone className="h-4 w-4" aria-hidden />;
    default:
      return <KeyRound className="h-4 w-4" aria-hidden />;
  }
}

function transportLabel(transport?: string | null): string {
  switch (transport) {
    case 'usb':
      return 'USB';
    case 'nfc':
      return 'NFC';
    case 'ble':
      return 'Bluetooth';
    case 'internal':
      return 'Biométrie / appareil';
    default:
      return 'Sécurité';
  }
}

export function AuthenticatorsSection() {
  const { t } = useI18n();
  const { user } = useAuth();
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [registerOpen, setRegisterOpen] = useState(false);
  const [revokeTarget, setRevokeTarget] = useState<AuthenticatorDto | null>(null);
  const [name, setName] = useState('');
  const [transport, setTransport] = useState('internal');
  const [busy, setBusy] = useState(false);

  const authenticators = useQuery({
    queryKey: ['authenticators'],
    queryFn: () => api.listAuthenticators(),
    enabled: !!user,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['authenticators'] });
    void queryClient.invalidateQueries({ queryKey: ['me'] });
  };

  const revoke = useMutation({
    mutationFn: (id: string) => api.revokeAuthenticator(id),
    onSuccess: () => {
      toast(t('settings.authenticators.revoked'));
      setRevokeTarget(null);
      invalidate();
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const changeLevel = useMutation({
    mutationFn: (level: SecurityLevel) => api.updateSecurityLevel(level),
    onSuccess: () => {
      toast(t('settings.authenticators.levelUpdated'));
      invalidate();
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  async function registerAuthenticator(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    try {
      const options = await api.passkeyRegisterOptions();

      let credential: PasskeyCredentialDto | null = null;
      if (typeof navigator !== 'undefined' && navigator.credentials && window.PublicKeyCredential) {
        try {
          const created = await navigator.credentials.create({
            publicKey: {
              challenge: base64UrlToArrayBuffer(options.challenge),
              rp: options.rp,
              user: {
                id: base64UrlToArrayBuffer(options.user.id),
                name: options.user.name,
                displayName: options.user.display_name,
              },
              pubKeyCredParams: options.pub_key_cred_params.map((param) => ({ ...param, type: 'public-key' as const })),
              timeout: options.timeout ?? 60_000,
              authenticatorSelection: { userVerification: 'preferred' },
            },
          });
          if (created && created.type === 'public-key') {
            const pk = created as PublicKeyCredential;
            const response = pk.response as AuthenticatorAttestationResponse;
            credential = {
              id: pk.id,
              raw_id: base64UrlEncode(pk.rawId),
              type: pk.type,
              response: {
                client_data_json: base64UrlEncode(response.clientDataJSON),
                attestation_object: base64UrlEncode(response.attestationObject),
                transports: response.getTransports?.() ?? [],
              },
              client_extension_results: (pk.getClientExtensionResults?.() ?? {}) as Record<string, unknown>,
            };
          }
        } catch {
          // No authenticator / user cancelled — fall back to the sandbox flow.
        }
      }

      const deviceName = name.trim() || 'Security key';
      await api.registerPasskey({
        registration_token: options.registration_token,
        credential: credential ?? {
          id: `sandbox-${Date.now()}`,
          raw_id: '',
          type: 'public-key',
          response: { client_data_json: '', authenticator_data: '', signature: '' },
        },
        name: deviceName,
        transport: transport || (credential ? 'usb' : 'internal'),
      });
      toast(t('settings.authenticators.added'));
      setRegisterOpen(false);
      setName('');
      invalidate();
    } catch (err) {
      toast(errorMessage(err), 'error');
    } finally {
      setBusy(false);
    }
  }

  const level = user?.security_level ?? 'standard';
  const items = authenticators.data ?? [];

  return (
    <Card>
      <CardHeader
        title={t('settings.authenticators.title')}
        description={t('settings.authenticators.description')}
      />
      <div className="space-y-5 p-5">
        {/* Adaptive security level */}
        <div>
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">
            {t('settings.authenticators.level')}
          </p>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {SECURITY_LEVELS.map(({ value, icon: Icon }) => {
              const active = level === value;
              return (
                <button
                  key={value}
                  type="button"
                  onClick={() => changeLevel.mutate(value)}
                  disabled={active || changeLevel.isPending}
                  className={cn(
                    'rounded-lg border p-3 text-left transition-colors',
                    active
                      ? 'border-brand-600/60 bg-brand-900/30'
                      : 'border-edge bg-raised hover:border-brand-700/40 hover:bg-edge',
                  )}
                >
                  <Icon className={cn('h-4 w-4', active ? 'text-brand-300' : 'text-zinc-500')} />
                  <p className="mt-1.5 text-sm font-semibold text-white">
                    {t(`settings.authenticators.level.${value}`)}
                  </p>
                  <p className="mt-0.5 text-xs text-zinc-500">
                    {t(`settings.authenticators.level.${value}.desc`)}
                  </p>
                </button>
              );
            })}
          </div>
        </div>

        {/* Authenticator list */}
        <div className="flex items-center justify-between">
          <p className="text-xs font-medium uppercase tracking-wide text-zinc-500">
            {t('settings.authenticators.my')}
          </p>
          <Button size="sm" onClick={() => setRegisterOpen(true)}>
            <Plus className="h-4 w-4" /> {t('settings.authenticators.add')}
          </Button>
        </div>

        {authenticators.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : items.length > 0 ? (
          <ul className="space-y-2">
            {items.map((authenticator) => (
              <li
                key={authenticator.id}
                className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-4 py-3"
              >
                <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-400" aria-hidden />
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-700/15 text-brand-300">
                  <TransportIcon transport={authenticator.transport} />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2 text-sm font-medium text-white">
                    {authenticator.name}
                    <Badge tone="neutral">{transportLabel(authenticator.transport)}</Badge>
                  </p>
                  <p className="mt-0.5 text-xs text-zinc-500">
                    {t('settings.authenticators.lastUsed')}{' '}
                    {authenticator.last_used_at ? formatDate(authenticator.last_used_at) : '—'}
                    <span className="mx-1.5 text-zinc-700">·</span>
                    {t('settings.authenticators.registered')}{' '}
                    {authenticator.created_at ? formatDate(authenticator.created_at) : '—'}
                  </p>
                </div>
                <Button variant="ghost" size="icon" aria-label={t('settings.authenticators.revoke')} onClick={() => setRevokeTarget(authenticator)}>
                  <Trash2 className="h-4 w-4 text-red-400" />
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="rounded-lg border border-dashed border-edge px-4 py-6 text-center text-sm text-zinc-500">
            {t('settings.authenticators.empty')}
          </p>
        )}

        <p className="text-xs leading-relaxed text-zinc-500">{t('settings.authenticators.privacy')}</p>
      </div>

      <Dialog
        open={registerOpen}
        onClose={() => setRegisterOpen(false)}
        title={t('settings.authenticators.addTitle')}
        description={t('settings.authenticators.addDescription')}
      >
        <form onSubmit={(event) => void registerAuthenticator(event)} className="space-y-4">
          <Field label={t('settings.authenticators.deviceName')} htmlFor="auth-name">
            <Input
              id="auth-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('settings.authenticators.devicePlaceholder')}
            />
          </Field>
          <Field label={t('settings.authenticators.transport')} htmlFor="auth-transport">
            <Select id="auth-transport" value={transport} onChange={(e) => setTransport(e.target.value)}>
              <option value="internal">{t('settings.authenticators.transport.internal')}</option>
              <option value="usb">{t('settings.authenticators.transport.usb')}</option>
              <option value="nfc">{t('settings.authenticators.transport.nfc')}</option>
              <option value="ble">{t('settings.authenticators.transport.ble')}</option>
            </Select>
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" type="button" onClick={() => setRegisterOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" loading={busy}>
              {t('settings.authenticators.addBtn')}
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={revokeTarget !== null}
        onClose={() => setRevokeTarget(null)}
        title={t('settings.authenticators.revokeTitle', { name: revokeTarget?.name ?? '' })}
        description={t('settings.authenticators.revokeDescription')}
      >
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setRevokeTarget(null)}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" onClick={() => revokeTarget && revoke.mutate(revokeTarget.id)} loading={revoke.isPending}>
            {t('settings.authenticators.revoke')}
          </Button>
        </div>
      </Dialog>
    </Card>
  );
}
