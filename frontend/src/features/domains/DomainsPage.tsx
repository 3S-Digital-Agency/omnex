import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CalendarClock, Globe, Plus, Search } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { DomainDto, DomainProviderDto, DomainSearchResult } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate } from '../../lib/utils';

const EXPIRATION_WARNING_DAYS = 30;

const TLDS = ['com', 'io', 'dev', 'net', 'org'];

function providerLabel(name: string, providers?: DomainProviderDto[]): string {
  return providers?.find((p) => p.name === name)?.label ?? name;
}

export function DomainsPage() {
  const { activeOrganization, hasPermission } = useAuth();
  const { t } = useI18n();
  const canManage = hasPermission('domains.manage');
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [registerOpen, setRegisterOpen] = useState(false);

  const domains = useQuery({
    queryKey: ['domains', activeOrganization?.id],
    queryFn: () => api.listDomains(),
    enabled: !!activeOrganization?.id,
  });

  const providers = useQuery({
    queryKey: ['domain-providers'],
    queryFn: () => api.listDomainProviders(),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['domains', activeOrganization?.id] });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('domains.title')}</h1>
          <p className="text-sm text-zinc-400">
            {t('domains.subtitle', { name: activeOrganization?.name ?? '' })}
          </p>
        </div>
        {canManage ? (
          <Button onClick={() => setRegisterOpen(true)}>
            <Plus className="h-4 w-4" /> {t('common.register')}
          </Button>
        ) : null}
      </header>

      <DomainCockpit domains={domains.data ?? []} t={t} isLoading={domains.isLoading} />

      <Card>
        <CardHeader title={t('domains.yourDomains')} description={t('domains.yourDomainsDescription')} />
        <div className="p-5">
          {domains.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : domains.data && domains.data.length > 0 ? (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {domains.data.map((domain) => (
                <Link
                  key={domain.id}
                  to={`/domains/${domain.id}`}
                  className="group rounded-lg border border-edge bg-raised p-4 transition-colors hover:border-brand-700"
                >
                  <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-panel">
                      <Globe className="h-4 w-4 text-brand-400" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-mono text-sm font-medium text-white">{domain.name}</p>
                      <p className="text-xs text-zinc-500">{t('domains.expires', { date: formatDate(domain.expires_at) })}</p>
                    </div>
                    <Badge tone={domain.status === 'active' ? 'success' : 'neutral'}>{domain.status}</Badge>
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs text-zinc-500">
                    <span>{domain.auto_renew ? t('domains.autoRenewOn') : t('domains.autoRenewOff')}</span>
                    <span>{domain.privacy_protection ? t('domains.privacyOn') : t('domains.privacyOff')}</span>
                    <span className="ml-auto uppercase tracking-wide">{providerLabel(domain.provider, providers.data)}</span>
                  </div>
                </Link>
              ))}
            </div>
          ) : (
            <EmptyState
              title={t('domains.noDomains')}
              description={canManage ? t('domains.noDomainsDescription') : undefined}
            />
          )}
        </div>
      </Card>

      <RegisterDomainDialog
        open={registerOpen}
        onClose={() => setRegisterOpen(false)}
        onRegistered={() => {
          setRegisterOpen(false);
          void invalidate();
          toast(t('toast.domains.registered'));
        }}
      />
    </div>
  );
}

export type DomainLifetime = 'active' | 'expiring' | 'expired';

/** Days left until a domain expires (null when no expiry is known). */
function daysLeft(domain: DomainDto): number | null {
  if (!domain.expires_at) return null;
  return Math.ceil((new Date(domain.expires_at).getTime() - Date.now()) / 86400000);
}

function lifetime(domain: DomainDto): DomainLifetime {
  const days = daysLeft(domain);
  if (days === null || days <= 0) return 'expired';
  if (days <= EXPIRATION_WARNING_DAYS) return 'expiring';
  return 'active';
}

/** Cockpit: domain status donut, KPI chips and an expiration timeline. */
function DomainCockpit({
  domains,
  t,
  isLoading,
}: {
  domains: DomainDto[];
  t: (key: string, params?: Record<string, string | number>) => string;
  isLoading: boolean;
}) {
  const counts = { active: 0, expiring: 0, expired: 0 };
  for (const domain of domains) {
    counts[lifetime(domain)] += 1;
  }

  const kpis = [
    { key: 'total', value: domains.length, tone: 'text-white' },
    { key: 'active', value: counts.active, tone: 'text-emerald-400' },
    { key: 'expiring', value: counts.expiring, tone: 'text-amber-400' },
    { key: 'expired', value: counts.expired, tone: counts.expired > 0 ? 'text-red-400' : 'text-zinc-400' },
  ];

  return (
    <Card>
      <CardHeader title={t('domains.cockpit.title')} description={t('domains.cockpit.description')} />
      <div className="space-y-5 p-5">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {kpis.map((kpi) => (
            <div key={kpi.key} className="rounded-xl border border-edge bg-raised p-3">
              <p className={cn('text-2xl font-bold', kpi.tone)}>{isLoading ? '—' : kpi.value}</p>
              <p className="mt-0.5 text-xs text-zinc-500">{t(`domains.cockpit.${kpi.key}`)}</p>
            </div>
          ))}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex items-center justify-center gap-6 rounded-xl border border-edge bg-raised p-4">
            <DistributionDonut
              segments={
                isLoading
                  ? []
                  : [
                      { value: counts.active, color: 'text-emerald-400', label: t('domains.cockpit.active') },
                      { value: counts.expiring, color: 'text-amber-400', label: t('domains.cockpit.expiring') },
                      { value: counts.expired, color: 'text-red-400', label: t('domains.cockpit.expired') },
                    ]
              }
              size={120}
              thickness={11}
              center={
                <div className="text-center">
                  <span className="block text-2xl font-bold text-white">{domains.length}</span>
                  <span className="block text-[10px] uppercase tracking-wider text-zinc-500">{t('domains.cockpit.total')}</span>
                </div>
              }
              label={t('domains.cockpit.title')}
            />
            <ul className="space-y-2 text-xs">
              {(['active', 'expiring', 'expired'] as const).map((state) => (
                <li key={state} className="flex items-center gap-2">
                  <span
                    className={cn(
                      'h-2.5 w-2.5 rounded-sm',
                      state === 'active' ? 'bg-emerald-400' : state === 'expiring' ? 'bg-amber-400' : 'bg-red-400',
                    )}
                    aria-hidden="true"
                  />
                  <span className="text-zinc-400">{t(`domains.cockpit.${state}`)}</span>
                  <span className="ml-auto font-semibold text-white">{counts[state]}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="rounded-xl border border-edge bg-raised p-4">
            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
              <CalendarClock className="h-3.5 w-3.5" />
              {t('domains.cockpit.timeline')}
            </p>
            {isLoading ? (
              <div className="flex justify-center py-6">
                <Spinner />
              </div>
            ) : domains.length > 0 ? (
              <ul className="space-y-3">
                {domains.map((domain) => {
                  const days = daysLeft(domain);
                  const state = lifetime(domain);
                  const percent = days === null ? 0 : Math.max(0, Math.min(100, (days / 365) * 100));
                  return (
                    <li key={domain.id}>
                      <Link to={`/domains/${domain.id}`} className="group block">
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-mono font-medium text-white group-hover:text-brand-400">{domain.name}</span>
                          {days !== null ? (
                            <span
                              className={cn(
                                'inline-flex items-center gap-1',
                                state === 'active' ? 'text-emerald-400' : state === 'expiring' ? 'text-amber-400' : 'text-red-400',
                              )}
                            >
                              {state === 'expired' ? (
                                <AlertTriangle className="h-3 w-3" />
                              ) : null}
                              {days <= 0 ? t('domains.cockpit.expired') : t('domains.cockpit.daysLeft', { days })}
                            </span>
                          ) : (
                            <span className="text-zinc-500">—</span>
                          )}
                        </div>
                        <div className="mt-1.5">
                          <ProgressBar
                            percent={percent}
                            tone={state === 'active' ? 'success' : state === 'expiring' ? 'warning' : 'danger'}
                          />
                        </div>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <p className="py-6 text-center text-sm text-zinc-500">{t('domains.noDomains')}</p>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}

function RegisterDomainDialog({
  open,
  onClose,
  onRegistered,
}: {
  open: boolean;
  onClose: () => void;
  onRegistered: () => void;
}) {
  const { t } = useI18n();
  const [query, setQuery] = useState('');
  const [selectedTlds, setSelectedTlds] = useState<string[]>(['com', 'io', 'dev']);
  const [provider, setProvider] = useState('');
  const [results, setResults] = useState<DomainSearchResult[] | null>(null);
  const [registerError, setRegisterError] = useState<string | null>(null);

  const providers = useQuery({
    queryKey: ['domain-providers'],
    queryFn: () => api.listDomainProviders(),
    enabled: open,
  });

  const search = useMutation({
    mutationFn: () => api.searchDomains(query, selectedTlds, provider || undefined),
    onSuccess: (data) => setResults(data),
    onError: (err) => setRegisterError(errorMessage(err)),
  });

  const register = useMutation({
    mutationFn: (domain: string) => api.registerDomain(domain, 1, provider || undefined),
    onSuccess: onRegistered,
    onError: (err) => setRegisterError(errorMessage(err)),
  });

  function toggleTld(tld: string) {
    setSelectedTlds((current) =>
      current.includes(tld) ? current.filter((item) => item !== tld) : [...current, tld],
    );
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setRegisterError(null);
    setResults(null);
    void search.mutateAsync();
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('domains.registerTitle')}
      description={t('domains.registerDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('domains.name')} htmlFor="domain-query">
          <Input
            id="domain-query"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="omnex"
            required
          />
        </Field>

        <Field label={t('domains.registrar')} htmlFor="domain-provider">
          <Select
            id="domain-provider"
            value={provider}
            onChange={(e) => setProvider(e.target.value)}
          >
            <option value="">{t('domains.defaultRegistrar')}</option>
            {providers.data?.map((p) => (
              <option key={p.name} value={p.name} disabled={!p.configured}>
                {p.label}
                {p.configured ? '' : ` — ${t('domains.notConfigured')}`}
              </option>
            ))}
          </Select>
        </Field>

        <div>
          <p className="mb-1.5 text-sm font-medium text-zinc-300">{t('domains.tlds')}</p>
          <div className="flex flex-wrap gap-2">
            {TLDS.map((tld) => (
              <button
                key={tld}
                type="button"
                onClick={() => toggleTld(tld)}
                className={cn(
                  'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                  selectedTlds.includes(tld)
                    ? 'border-brand-500 bg-brand-700/15 text-white'
                    : 'border-edge text-zinc-400 hover:text-white',
                )}
              >
                .{tld}
              </button>
            ))}
          </div>
        </div>

        <Button type="submit" loading={search.isPending} className="w-full">
          <Search className="h-4 w-4" /> {t('common.search')}
        </Button>
      </form>

      {registerError ? <p className="mt-3 text-sm text-red-400">{registerError}</p> : null}

      {results ? (
        <ul className="mt-4 space-y-2">
          {results.map((result) => (
            <li
              key={result.domain}
              className="flex items-center justify-between gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5"
            >
              <div>
                <p className="font-mono text-sm text-white">{result.domain}</p>
                <p className="text-xs text-zinc-500">
                  ${result.price.amount.toFixed(2)}/{result.price.currency} · {result.price.years} {t('domains.year')}
                  {result.premium ? ` · ${t('domains.premium')}` : ''}
                </p>
              </div>
              {result.available ? (
                <Button
                  size="sm"
                  loading={register.isPending && register.variables === result.domain}
                  onClick={() => register.mutate(result.domain)}
                >
                  {t('common.register')}
                </Button>
              ) : (
                <Badge tone="danger">{t('domains.taken')}</Badge>
              )}
            </li>
          ))}
        </ul>
      ) : null}
    </Dialog>
  );
}
