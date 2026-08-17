import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import QRCode from 'react-qr-code';
import {
  Building2,
  Languages,
  Link2,
  MailCheck,
  MailX,
  PlugZap,
  ShieldCheck,
  ShieldOff,
} from 'lucide-react';
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
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { KpiCard } from '../../components/viz/KpiCard';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { errorMessage } from '../../lib/errors';
import { LOCALES, useI18n } from '../../lib/i18n';
import { cn } from '../../lib/utils';
import type { SocialAccountDto, SocialProviderDto, UserDto } from '../../lib/api/types';
import { AuthenticatorsSection } from './AuthenticatorsSection';

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

      <SettingsCockpit
        user={user}
        locale={locale}
        providers={socialProviders.data ?? []}
        accounts={socialAccounts.data ?? []}
      />

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

      <AuthenticatorsSection />

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

function SettingsCockpit({
  user,
  locale,
  providers,
  accounts,
}: {
  user: UserDto | null;
  locale: string;
  providers: SocialProviderDto[];
  accounts: SocialAccountDto[];
}) {
  const { t } = useI18n();
  const { activeOrganization } = useAuth();

  const mfaOn = user?.mfa_enabled ?? false;
  const emailVerified = !!user?.email_verified_at;
  const linked = accounts.length;
  const available = providers.filter((provider) => provider.configured).length;
  const linkedPercent = available > 0 ? Math.round((linked / available) * 100) : 0;

  // Profile completion: name, email, verified email, MFA, language, one linked account.
  const checks = [
    !!user?.name,
    !!user?.email,
    emailVerified,
    mfaOn,
    !!locale,
    linked > 0,
  ];
  const profilePercent = Math.round((checks.filter(Boolean).length / checks.length) * 100);

  const localeLabel = LOCALES.find((option) => option.code === locale)?.label ?? locale ?? '—';

  const integrationSegments = [
    { value: linked, color: 'text-emerald-400', label: t('settings.cockpit.linked') },
    { value: Math.max(0, available - linked), color: 'text-zinc-500', label: t('settings.cockpit.available') },
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label={t('settings.cockpit.mfa')}
          value={mfaOn ? 1 : 0}
          format={(value) => (value >= 1 ? t('settings.cockpit.on') : t('settings.cockpit.off'))}
          icon={mfaOn ? ShieldCheck : ShieldOff}
          to="/security"
          accent={mfaOn ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'}
          ariaLabel={t('settings.cockpit.mfaAria')}
          sub={mfaOn ? t('settings.cockpit.mfaOnSub') : t('settings.cockpit.mfaOffSub')}
          footer={<ProgressBar percent={mfaOn ? 100 : 0} tone={mfaOn ? 'success' : 'warning'} />}
        />
        <KpiCard
          label={t('settings.cockpit.email')}
          value={emailVerified ? 1 : 0}
          format={(value) => (value >= 1 ? t('settings.cockpit.verified') : t('settings.cockpit.unverified'))}
          icon={emailVerified ? MailCheck : MailX}
          to="/settings"
          accent={emailVerified ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'}
          ariaLabel={t('settings.cockpit.emailAria')}
          sub={user?.email ?? ''}
        />
        <KpiCard
          label={t('settings.cockpit.language')}
          value={locale ? 1 : 0}
          format={(value) => (value >= 1 ? localeLabel : '—')}
          icon={Languages}
          to="/settings"
          accent="bg-brand-700/15 text-brand-300"
          ariaLabel={t('settings.cockpit.languageAria')}
          sub={t('settings.cockpit.languageSub')}
          footer={<ProgressBar percent={locale ? 100 : 0} tone="brand" />}
        />
        <KpiCard
          label={t('settings.cockpit.integrations')}
          value={linked}
          icon={PlugZap}
          to="/settings"
          accent={linkedPercent >= 50 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-brand-700/15 text-brand-300'}
          ariaLabel={t('settings.cockpit.integrationsAria')}
          sub={t('settings.cockpit.integrationsSub', { linked, available })}
          footer={<ProgressBar percent={linkedPercent} tone={linkedPercent >= 50 ? 'success' : 'brand'} />}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5">
          <h3 className="text-sm font-semibold text-white">{t('settings.cockpit.profileTitle')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('settings.cockpit.profileSub')}</p>
          <div className="mt-4 flex items-center gap-5">
            <DistributionDonut
              segments={[{ value: profilePercent, color: 'text-brand-400', label: t('settings.cockpit.complete') }]}
              size={110}
              thickness={11}
              center={
                <>
                  <span className="text-xl font-bold text-white tabular-nums">{profilePercent}%</span>
                </>
              }
              label={t('settings.cockpit.profileTitle')}
            />
            <ul className="flex-1 space-y-1.5">
              {[
                { done: !!user?.name, label: t('settings.name') },
                { done: !!user?.email, label: t('settings.email') },
                { done: emailVerified, label: t('settings.cockpit.emailVerified') },
                { done: mfaOn, label: t('settings.cockpit.mfa') },
                { done: !!locale, label: t('settings.language') },
                { done: linked > 0, label: t('settings.cockpit.accountLinked') },
              ].map((item) => (
                <li key={item.label} className="flex items-center gap-2 text-xs">
                  <span
                    className={cn(
                      'flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold',
                      item.done ? 'bg-emerald-500/20 text-emerald-300' : 'bg-white/10 text-zinc-500',
                    )}
                  >
                    {item.done ? '✓' : '•'}
                  </span>
                  <span className={item.done ? 'text-zinc-300' : 'text-zinc-500'}>{item.label}</span>
                </li>
              ))}
            </ul>
          </div>
        </Card>

        <Card className="p-5 lg:col-span-2">
          <h3 className="text-sm font-semibold text-white">{t('settings.cockpit.integrationsTitle')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('settings.cockpit.integrationsSub2')}</p>
          <div className="mt-4 flex items-center gap-5">
            <DistributionDonut
              segments={integrationSegments}
              size={110}
              thickness={11}
              center={
                <>
                  <span className="text-xl font-bold text-white tabular-nums">{linked}</span>
                  <span className="text-[9px] uppercase tracking-wide text-zinc-500">/ {available}</span>
                </>
              }
              label={t('settings.cockpit.integrationsTitle')}
            />
            <ul className="flex-1 space-y-1.5">
              {providers.slice(0, 6).map((provider) => {
                const account = accounts.find((a) => a.provider === provider.name);
                return (
                  <li key={provider.name} className="flex items-center justify-between gap-2 text-xs">
                    <span className="flex items-center gap-2 text-zinc-300">
                      <Link2 className="h-3 w-3 text-zinc-600" />
                      {provider.label}
                    </span>
                    <span
                      className={cn(
                        'rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide',
                        account ? 'bg-emerald-500/10 text-emerald-300' : 'bg-white/5 text-zinc-500',
                      )}
                    >
                      {account ? t('settings.cockpit.linked') : t('settings.cockpit.notLinked')}
                    </span>
                  </li>
                );
              })}
            </ul>
          </div>
        </Card>
      </div>

      <Card className="p-5">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-700/15 text-brand-300">
            <Building2 className="h-5 w-5" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-white">{activeOrganization?.name ?? '—'}</p>
            <p className="truncate text-xs text-zinc-500">
              {t('settings.cockpit.orgSub', { plan: activeOrganization?.plan_tier ?? '—', status: activeOrganization?.status ?? '—' })}
            </p>
          </div>
          <Badge tone="brand">{activeOrganization?.plan_tier ?? '—'}</Badge>
        </div>
        <div className="mt-4">
          <div className="mb-1 flex items-center justify-between text-xs">
            <span className="text-zinc-400">{t('settings.cockpit.orgProgress')}</span>
            <span className="font-medium text-white tabular-nums">{profilePercent}%</span>
          </div>
          <ProgressBar percent={profilePercent} tone={profilePercent >= 80 ? 'success' : profilePercent >= 40 ? 'brand' : 'warning'} />
        </div>
      </Card>
    </div>
  );
}
