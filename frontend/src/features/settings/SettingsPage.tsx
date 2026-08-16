import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import QRCode from 'react-qr-code';
import { ShieldCheck } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { LOCALES, useI18n } from '../../lib/i18n';

export function SettingsPage() {
  const { user, refresh } = useAuth();
  const { locale, t } = useI18n();
  const { toast } = useToast();
  const navigate = useNavigate();

  const [setupUri, setSetupUri] = useState<string | null>(null);
  const [unlinkProvider, setUnlinkProvider] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [disablePassword, setDisablePassword] = useState('');
  const [showDisable, setShowDisable] = useState(false);

  const setup = useMutation({
    mutationFn: () => api.setupMfa(),
    onSuccess: (res) => {
      setSetupUri(res.otpauth_uri);
      setCode('');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const confirm = useMutation({
    mutationFn: () => api.confirmMfa(code),
    onSuccess: async (res) => {
      setRecoveryCodes(res.recovery_codes);
      setSetupUri(null);
      setCode('');
      await refresh();
      toast(t('toast.settings.mfaEnabled'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const disable = useMutation({
    mutationFn: () => api.disableMfa(disablePassword),
    onSuccess: async () => {
      await refresh();
      setShowDisable(false);
      setDisablePassword('');
      toast(t('toast.settings.mfaDisabled'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const saveLocale = useMutation({
    mutationFn: (newLocale: string) => api.updateProfile({ locale: newLocale }),
    onSuccess: async () => {
      await refresh();
      toast(t('toast.settings.languageUpdated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const socialProviders = useQuery({
    queryKey: ['social-providers'],
    queryFn: () => api.socialProviders(),
  });

  const socialAccounts = useQuery({
    queryKey: ['social-accounts'],
    queryFn: () => api.listSocialAccounts(),
  });

  const linkSocial = useMutation({
    mutationFn: (provider: string) => api.socialRedirect(provider, true),
    onSuccess: async (res) => {
      if (!res.url) {
        await socialAccounts.refetch();
        toast(t('toast.settings.socialLinked'));
      } else if (res.url.startsWith('/')) {
        navigate(res.url);
      } else {
        window.location.href = res.url;
      }
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const unlinkSocial = useMutation({
    mutationFn: (provider: string) => api.unlinkSocial(provider),
    onSuccess: async () => {
      setUnlinkProvider(null);
      await socialAccounts.refetch();
      toast(t('toast.settings.socialUnlinked'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const unlinkLabel = (socialProviders.data ?? []).find((p) => p.name === unlinkProvider)?.label ?? (unlinkProvider ?? '');

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('settings.title')}</h1>
        <p className="text-sm text-zinc-400">{t('settings.subtitle')}</p>
      </header>

      <Card>
        <CardHeader title={t('settings.language')} description={t('settings.language.description')} />
        <div className="p-5">
          <Select
            aria-label={t('settings.language')}
            value={locale}
            disabled={saveLocale.isPending}
            onChange={(e) => saveLocale.mutate(e.target.value)}
          >
            {LOCALES.map((option) => (
              <option key={option.code} value={option.code}>
                {option.label}
              </option>
            ))}
          </Select>
        </div>
      </Card>

      <Card>
        <CardHeader title={t('settings.profile')} />
        <div className="space-y-4 p-5">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label={t('settings.name')}>
              <Input value={user?.name ?? ''} disabled />
            </Field>
            <Field label={t('settings.email')}>
              <Input value={user?.email ?? ''} disabled />
            </Field>
          </div>
          <div className="flex gap-2">
            <Badge tone="brand">{user?.status}</Badge>
            {user?.email_verified_at ? (
              <Badge tone="success">{t('settings.emailVerified')}</Badge>
            ) : (
              <Badge tone="warning">{t('settings.emailNotVerified')}</Badge>
            )}
          </div>
        </div>
      </Card>

      <Card>
        <CardHeader
          title={t('settings.connectedAccounts')}
          description={t('settings.connectedAccounts.description')}
        />
        <div className="divide-y divide-edge">
          {(socialProviders.data ?? []).map((provider) => {
            const account = (socialAccounts.data ?? []).find((a) => a.provider === provider.name);
            return (
              <div key={provider.name} className="flex items-center justify-between gap-4 px-5 py-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-white">{provider.label}</p>
                  <p className="truncate text-xs text-zinc-500">
                    {account
                      ? t('settings.socialLinked', {
                          email: account.provider_email ?? account.name ?? provider.label,
                        })
                      : t('settings.socialNotLinked')}
                  </p>
                </div>
                {account ? (
                  <Button variant="outline" size="sm" onClick={() => setUnlinkProvider(provider.name)}>
                    {t('settings.socialUnlink')}
                  </Button>
                ) : (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => linkSocial.mutate(provider.name)}
                    loading={linkSocial.isPending}
                  >
                    {t('settings.socialLink')}
                  </Button>
                )}
              </div>
            );
          })}
        </div>
      </Card>

      <Card>
        <CardHeader
          title={t('settings.security')}
          description={t('settings.security.description')}
        />
        <div className="p-5">
          {user?.mfa_enabled ? (
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <ShieldCheck className="h-5 w-5 text-emerald-400" />
                <span className="text-sm text-zinc-200">{t('settings.mfaEnabledText')}</span>
              </div>
              <Button variant="outline" onClick={() => setShowDisable(true)}>
                {t('settings.disable')}
              </Button>
            </div>
          ) : setupUri ? (
            <div className="space-y-4">
              <p className="text-sm text-zinc-400">
                {t('settings.scanQr')}
              </p>
              <div className="inline-block rounded-lg bg-white p-3">
                <QRCode value={setupUri} size={160} />
              </div>
              <Field label={t('settings.enterCode')} htmlFor="mfa-code">
                <Input
                  id="mfa-code"
                  inputMode="numeric"
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                />
              </Field>
              <div className="flex gap-2">
                <Button
                  onClick={() => confirm.mutate()}
                  loading={confirm.isPending}
                  disabled={code.length !== 6}
                >
                  {t('settings.confirm')}
                </Button>
                <Button variant="ghost" onClick={() => setSetupUri(null)}>
                  {t('common.cancel')}
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex items-center justify-between">
              <p className="text-sm text-zinc-400">{t('settings.mfaDisabled')}</p>
              <Button onClick={() => setup.mutate()} loading={setup.isPending}>
                {t('settings.enableMfa')}
              </Button>
            </div>
          )}
        </div>
      </Card>

      <Dialog
        open={recoveryCodes !== null}
        onClose={() => setRecoveryCodes(null)}
        title={t('settings.saveRecoveryCodes')}
        description={t('settings.recoveryDescription')}
      >
        <ul className="grid grid-cols-2 gap-2">
          {(recoveryCodes ?? []).map((recoveryCode) => (
            <li
              key={recoveryCode}
              className="rounded bg-raised px-2 py-1 font-mono text-xs text-zinc-200"
            >
              {recoveryCode}
            </li>
          ))}
        </ul>
        <Button className="mt-4 w-full" onClick={() => setRecoveryCodes(null)}>
          {t('settings.iSaved')}
        </Button>
      </Dialog>

      <Dialog
        open={showDisable}
        onClose={() => setShowDisable(false)}
        title={t('settings.disableMfaTitle')}
        description={t('settings.disableMfaDescription')}
      >
        <Field label={t('auth.password')} htmlFor="disable-password">
          <Input
            id="disable-password"
            type="password"
            value={disablePassword}
            onChange={(e) => setDisablePassword(e.target.value)}
            autoComplete="current-password"
          />
        </Field>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setShowDisable(false)}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" onClick={() => disable.mutate()} loading={disable.isPending}>
            {t('settings.disableMfaBtn')}
          </Button>
        </div>
      </Dialog>

      <Dialog
        open={unlinkProvider !== null}
        onClose={() => setUnlinkProvider(null)}
        title={t('settings.socialUnlinkTitle', { provider: unlinkLabel })}
        description={t('settings.socialUnlinkDescription', { provider: unlinkLabel })}
      >
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setUnlinkProvider(null)}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="danger"
            onClick={() => unlinkProvider && unlinkSocial.mutate(unlinkProvider)}
            loading={unlinkSocial.isPending}
          >
            {t('settings.socialUnlink')}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
