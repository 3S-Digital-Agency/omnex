import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
  ArrowLeft,
  ExternalLink,
  GitBranch,
  LayoutTemplate,
  Plus,
  Rocket,
  RotateCcw,
  TerminalSquare,
  Trash2,
} from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { SiteDeploymentDto, SiteDto, SiteFramework } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { Textarea } from '../../components/ui/Textarea';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { StackedBar } from '../../components/viz/StackedBar';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn } from '../../lib/utils';

const FRAMEWORKS: SiteFramework[] = ['static', 'laravel', 'next'];

function parseEnvVars(text: string): Record<string, string> {
  const result: Record<string, string> = {};
  for (const line of text.split('\n')) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    const index = trimmed.indexOf('=');
    if (index <= 0) continue;
    result[trimmed.slice(0, index).trim()] = trimmed.slice(index + 1).trim();
  }
  return result;
}

function statusTone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'ready':
    case 'live':
      return 'success';
    case 'failed':
      return 'danger';
    case 'rolled_back':
      return 'warning';
    default:
      return 'neutral';
  }
}

export function SitesPage() {
  const { hasPermission, activeOrganization } = useAuth();
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const canManage = hasPermission('sites.manage');

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [editFor, setEditFor] = useState<SiteDto | null>(null);
  const [logsFor, setLogsFor] = useState<SiteDeploymentDto | null>(null);

  const list = useQuery({
    queryKey: ['sites', activeOrganization?.id],
    queryFn: () => api.listSites(),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['sites', activeOrganization?.id] });

  const selected = list.data?.find((site) => site.id === selectedId) ?? null;

  const remove = useMutation({
    mutationFn: (site: SiteDto) => api.deleteSite(site.id),
    onSuccess: () => {
      setSelectedId(null);
      void invalidate();
      toast(t('toast.sites.deleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  if (selectedId && selected) {
    return (
      <>
        <SiteDetail
          site={selected}
          canManage={canManage}
          onBack={() => setSelectedId(null)}
          onEdit={() => setEditFor(selected)}
          onDelete={() => remove.mutate(selected)}
          onLogs={setLogsFor}
          onChanged={() => void invalidate()}
        />
        {editFor ? (
          <EditSiteDialog
            site={editFor}
            onClose={() => setEditFor(null)}
            onSaved={() => {
              setEditFor(null);
              void invalidate();
              toast(t('toast.sites.updated'));
            }}
          />
        ) : null}
        <LogsDialog deployment={logsFor} onClose={() => setLogsFor(null)} />
      </>
    );
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('sites.title')}</h1>
          <p className="text-sm text-zinc-400">{t('sites.subtitle')}</p>
        </div>
        {canManage ? (
          <Button onClick={() => setCreateOpen(true)}>
            <Plus className="h-4 w-4" /> {t('sites.newSite')}
          </Button>
        ) : null}
      </header>

      <SitesCockpit sites={list.data ?? []} t={t} isLoading={list.isLoading} onOpen={setSelectedId} />

      <Card>
        <CardHeader title={t('sites.sites')} description={t('sites.sitesDescription')} />
        <div className="p-5">
          {list.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : list.data && list.data.length > 0 ? (
            <ul className="space-y-2">
              {list.data.map((site) => (
                <li key={site.id}>
                  <button
                    onClick={() => setSelectedId(site.id)}
                    className="flex w-full items-center gap-3 rounded-lg border border-edge bg-raised px-4 py-3 text-left transition-colors hover:border-brand-700"
                  >
                    <LayoutTemplate className="h-4 w-4 shrink-0 text-brand-400" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-white">{site.name}</p>
                      <p className="truncate text-xs text-zinc-500">
                        {site.framework} · {site.git_branch} · {site.url ?? '—'}
                      </p>
                    </div>
                    <Badge tone={statusTone(site.status)}>{site.status}</Badge>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState
              title={t('sites.empty')}
              description={canManage ? t('sites.emptyDescription') : undefined}
            />
          )}
        </div>
      </Card>

      <CreateSiteDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(site) => {
          setCreateOpen(false);
          void invalidate();
          setSelectedId(site.id);
          toast(t('toast.sites.created'));
        }}
      />

      {editFor ? (
        <EditSiteDialog
          site={editFor}
          onClose={() => setEditFor(null)}
          onSaved={() => {
            setEditFor(null);
            void invalidate();
            toast(t('toast.sites.updated'));
          }}
        />
      ) : null}

      <LogsDialog deployment={logsFor} onClose={() => setLogsFor(null)} />
    </div>
  );
}

/** Fleet cockpit: deployment-status donut + per-site health bars. */
function SitesCockpit({
  sites,
  t,
  isLoading,
  onOpen,
}: {
  sites: SiteDto[];
  t: (key: string, params?: Record<string, string | number>) => string;
  isLoading: boolean;
  onOpen: (siteId: string) => void;
}) {
  const counts = { ready: 0, live: 0, failed: 0, rolled_back: 0, provisioning: 0 };
  for (const site of sites) {
    if (site.status in counts) counts[site.status as keyof typeof counts] += 1;
    else counts.ready += 1;
  }
  const ok = counts.ready + counts.live;

  const kpis = [
    { key: 'total', value: sites.length, tone: 'text-white' },
    { key: 'ok', value: ok, tone: 'text-emerald-400' },
    { key: 'failed', value: counts.failed, tone: counts.failed > 0 ? 'text-red-400' : 'text-zinc-400' },
    { key: 'rolled', value: counts.rolled_back, tone: counts.rolled_back > 0 ? 'text-amber-400' : 'text-zinc-400' },
  ];

  return (
    <Card>
      <CardHeader title={t('sites.cockpit.title')} description={t('sites.cockpit.description')} />
      <div className="space-y-5 p-5">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {kpis.map((kpi) => (
            <div key={kpi.key} className="rounded-xl border border-edge bg-raised p-3">
              <p className={cn('text-2xl font-bold', kpi.tone)}>{isLoading ? '—' : kpi.value}</p>
              <p className="mt-0.5 text-xs text-zinc-500">{t(`sites.cockpit.${kpi.key}`)}</p>
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
                      { value: ok, color: 'text-emerald-400', label: t('sites.cockpit.ok') },
                      { value: counts.failed, color: 'text-red-400', label: t('sites.cockpit.failed') },
                      { value: counts.rolled_back, color: 'text-amber-400', label: t('sites.cockpit.rolled') },
                      { value: counts.provisioning, color: 'text-brand-400', label: t('sites.cockpit.provisioning') },
                    ]
              }
              size={120}
              thickness={11}
              center={
                <div className="text-center">
                  <span className="block text-2xl font-bold text-white">{sites.length}</span>
                  <span className="block text-[10px] uppercase tracking-wider text-zinc-500">{t('sites.cockpit.total')}</span>
                </div>
              }
              label={t('sites.cockpit.title')}
            />
            <ul className="space-y-2 text-xs">
              {(['ok', 'failed', 'rolled', 'provisioning'] as const).map((state) => (
                <li key={state} className="flex items-center gap-2">
                  <span
                    className={cn(
                      'h-2.5 w-2.5 rounded-sm',
                      state === 'ok' ? 'bg-emerald-400' : state === 'failed' ? 'bg-red-400' : state === 'rolled' ? 'bg-amber-400' : 'bg-brand-500',
                    )}
                    aria-hidden="true"
                  />
                  <span className="text-zinc-400">{t(`sites.cockpit.${state}`)}</span>
                  <span className="ml-auto font-semibold text-white">
                    {state === 'ok' ? ok : state === 'rolled' ? counts.rolled_back : counts[state]}
                  </span>
                </li>
              ))}
            </ul>
          </div>

          <div className="space-y-3 rounded-xl border border-edge bg-raised p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-500">{t('sites.cockpit.health')}</p>
            {isLoading ? (
              <div className="flex justify-center py-6">
                <Spinner />
              </div>
            ) : sites.length > 0 ? (
              sites.map((site) => {
                const tone = site.status === 'ready' || site.status === 'live' ? 'success' : site.status === 'failed' ? 'danger' : site.status === 'rolled_back' ? 'warning' : 'brand';
                return (
                  <button
                    key={site.id}
                    onClick={() => onOpen(site.id)}
                    className="block w-full text-left"
                  >
                    <div className="flex items-center justify-between text-xs">
                      <span className="font-medium text-white">{site.name}</span>
                      <Badge tone={statusTone(site.status)}>{site.status}</Badge>
                    </div>
                    <div className="mt-1.5">
                      <ProgressBar percent={site.status === 'ready' || site.status === 'live' ? 100 : site.status === 'provisioning' ? 40 : 15} tone={tone} />
                    </div>
                  </button>
                );
              })
            ) : (
              <p className="py-6 text-center text-sm text-zinc-500">{t('sites.empty')}</p>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}

function SiteDetail({
  site,
  canManage,
  onBack,
  onEdit,
  onDelete,
  onLogs,
  onChanged,
}: {
  site: SiteDto;
  canManage: boolean;
  onBack: () => void;
  onEdit: () => void;
  onDelete: () => void;
  onLogs: (deployment: SiteDeploymentDto) => void;
  onChanged: () => void;
}) {
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { activeOrganization } = useAuth();

  const deployments = useQuery({
    queryKey: ['sites', activeOrganization?.id, 'deployments', site.id],
    queryFn: () => api.listSiteDeployments(site.id),
  });

  const invalidate = () => {
    onChanged();
    void queryClient.invalidateQueries({ queryKey: ['sites', activeOrganization?.id, 'deployments', site.id] });
  };

  const deploy = useMutation({
    mutationFn: () => api.deploySite(site.id),
    onSuccess: (deployment) => {
      void invalidate();
      if (deployment.status === 'failed') {
        toast(t('toast.sites.deployFailedRolledBack'), 'error');
      } else {
        toast(t('toast.sites.deployed'));
      }
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const rollback = useMutation({
    mutationFn: (deploymentId: string) => api.rollbackSite(site.id, deploymentId),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.sites.rolledBack'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="icon" onClick={onBack} title={t('sites.back')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold text-white">{site.name}</h1>
              <Badge tone={statusTone(site.status)}>{site.status}</Badge>
            </div>
            <p className="text-sm text-zinc-400">{site.url ?? '—'}</p>
          </div>
        </div>
        {canManage ? (
          <div className="flex gap-2">
            <Button variant="outline" onClick={onEdit}>{t('common.edit')}</Button>
            <Button variant="danger" onClick={onDelete}>
              <Trash2 className="h-4 w-4" /> {t('common.delete')}
            </Button>
          </div>
        ) : null}
      </header>

      <Card>
        <CardHeader title={t('sites.configuration')} />
        <dl className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('sites.framework')}</dt>
            <dd className="mt-1 text-sm text-white">{site.framework}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('sites.gitBranch')}</dt>
            <dd className="mt-1 flex items-center gap-1.5 text-sm text-white">
              <GitBranch className="h-3.5 w-3.5 text-zinc-400" /> {site.git_branch}
            </dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('sites.gitUrl')}</dt>
            <dd className="mt-1 break-all text-sm text-white">{site.git_url}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('sites.envVars')}</dt>
            <dd className="mt-1 text-sm text-zinc-300">
              {site.environment_variable_keys.length > 0
                ? site.environment_variable_keys.join(', ')
                : t('sites.noEnvVars')}
            </dd>
          </div>
        </dl>
      </Card>

      <Card>
        <CardHeader
          title={t('sites.deployments')}
          description={t('sites.deploymentsDescription')}
          action={
            canManage ? (
              <Button size="sm" onClick={() => deploy.mutate()} loading={deploy.isPending}>
                <Rocket className="h-4 w-4" /> {t('sites.deploy')}
              </Button>
            ) : undefined
          }
        />
        <div className="space-y-4 p-5">
          {deployments.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : deployments.data && deployments.data.length > 0 ? (
            <>
              <DeploymentProgress data={deployments.data} t={t} />
              <ol className="relative space-y-4 border-l border-edge pl-5">
                {deployments.data.map((deployment) => {
                  const isCurrent = site.current_deployment_id === deployment.id;
                  return (
                    <li key={deployment.id} className="relative">
                      <span
                        className={cn(
                          'absolute -left-[27px] top-1.5 h-3 w-3 rounded-full border-2 border-background',
                          deployment.status === 'live' || deployment.status === 'ready'
                            ? 'bg-emerald-400'
                            : deployment.status === 'failed'
                              ? 'bg-red-400'
                              : deployment.status === 'rolled_back'
                                ? 'bg-amber-400'
                                : 'bg-brand-500',
                        )}
                        aria-hidden="true"
                      />
                      <div className="rounded-lg border border-edge bg-raised px-3 py-2.5">
                        <div className="flex items-center gap-2">
                          <p className="text-sm font-medium text-white">
                            {t('sites.deploymentN', { n: deployment.number })}
                          </p>
                          <Badge tone={statusTone(deployment.status)}>{deployment.status}</Badge>
                          {isCurrent ? <Badge tone="brand">{t('sites.cockpit.current')}</Badge> : null}
                        </div>
                        <p className="truncate text-xs text-zinc-500">
                          {deployment.commit_sha ?? '—'} · {deployment.deployed_at ?? '—'}
                        </p>
                        <div className="mt-2 flex items-center gap-1">
                          {deployment.url ? (
                            <Button size="sm" variant="ghost" title={t('sites.open')} onClick={() => window.open(deployment.url!, '_blank', 'noopener,noreferrer')}>
                              <ExternalLink className="h-4 w-4" />
                            </Button>
                          ) : null}
                          {deployment.logs ? (
                            <Button size="sm" variant="ghost" title={t('sites.logs')} onClick={() => onLogs(deployment)}>
                              <TerminalSquare className="h-4 w-4" />
                            </Button>
                          ) : null}
                          {canManage && deployment.status === 'live' && !isCurrent ? (
                            <Button
                              size="sm"
                              variant="outline"
                              loading={rollback.isPending && rollback.variables === deployment.id}
                              onClick={() => rollback.mutate(deployment.id)}
                            >
                              <RotateCcw className="h-3.5 w-3.5" /> {t('sites.rollback')}
                            </Button>
                          ) : null}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ol>
            </>
          ) : (
            <EmptyState title={t('sites.noDeployments')} description={canManage ? t('sites.noDeploymentsDescription') : undefined} />
          )}
        </div>
      </Card>
    </div>
  );
}

/** Stacked progress of deployments by status (live / failed / rolled back). */
function DeploymentProgress({
  data,
  t,
}: {
  data: { status: string }[];
  t: (key: string, params?: Record<string, string | number>) => string;
}) {
  const live = data.filter((d) => d.status === 'live' || d.status === 'ready').length;
  const failed = data.filter((d) => d.status === 'failed').length;
  const rolledBack = data.filter((d) => d.status === 'rolled_back').length;
  const other = data.length - live - failed - rolledBack;

  return (
    <div className="rounded-lg border border-edge bg-raised p-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">
        {t('sites.cockpit.deployProgress')}
      </p>
      <StackedBar
        items={[
          { value: live, color: 'bg-emerald-400', label: t('sites.cockpit.live') },
          { value: failed, color: 'bg-red-400', label: t('sites.cockpit.failed') },
          { value: rolledBack, color: 'bg-amber-400', label: t('sites.cockpit.rolled') },
          { value: other, color: 'bg-brand-500', label: t('sites.cockpit.provisioning') },
        ]}
        total={data.length}
        height={10}
      />
    </div>
  );
}

function CreateSiteDialog({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (site: SiteDto) => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [framework, setFramework] = useState<SiteFramework>('static');
  const [gitUrl, setGitUrl] = useState('');
  const [gitBranch, setGitBranch] = useState('main');
  const [envText, setEnvText] = useState('');
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: () =>
      api.createSite({
        name,
        framework,
        git_url: gitUrl,
        git_branch: gitBranch,
        environment_variables: parseEnvVars(envText),
      }),
    onSuccess: onCreated,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    create.mutate();
  }

  return (
    <Dialog open={open} onClose={onClose} title={t('sites.createTitle')} description={t('sites.createDescription')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('sites.name')} htmlFor="site-name">
          <Input id="site-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="marketing" required autoFocus />
        </Field>
        <Field label={t('sites.framework')} htmlFor="site-framework">
          <Select id="site-framework" value={framework} onChange={(e) => setFramework(e.target.value as SiteFramework)}>
            {FRAMEWORKS.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('sites.gitUrl')} htmlFor="site-git-url">
          <Input id="site-git-url" value={gitUrl} onChange={(e) => setGitUrl(e.target.value)} placeholder="https://github.com/acme/marketing.git" required />
        </Field>
        <Field label={t('sites.gitBranch')} htmlFor="site-git-branch">
          <Input id="site-git-branch" value={gitBranch} onChange={(e) => setGitBranch(e.target.value)} placeholder="main" />
        </Field>
        <Field label={t('sites.envVars')} htmlFor="site-env" hint={t('sites.envVarsDescription')}>
          <Textarea
            id="site-env"
            value={envText}
            onChange={(e) => setEnvText(e.target.value)}
            rows={4}
            placeholder={'APP_ENV=production\nAPI_SECRET=…'}
          />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={create.isPending} className="w-full">
          {t('sites.create')}
        </Button>
      </form>
    </Dialog>
  );
}

function EditSiteDialog({
  site,
  onClose,
  onSaved,
}: {
  site: SiteDto;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(site.name);
  const [framework, setFramework] = useState<SiteFramework>((site.framework as SiteFramework) ?? 'static');
  const [gitUrl, setGitUrl] = useState(site.git_url);
  const [gitBranch, setGitBranch] = useState(site.git_branch);
  const [envText, setEnvText] = useState('');
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () =>
      api.updateSite(site.id, {
        name,
        framework,
        git_url: gitUrl,
        git_branch: gitBranch,
        // Empty textarea means "keep existing values".
        environment_variables: envText.trim() === '' ? undefined : parseEnvVars(envText),
      }),
    onSuccess: onSaved,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    save.mutate();
  }

  return (
    <Dialog open onClose={onClose} title={t('sites.editTitle', { name: site.name })}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('sites.name')} htmlFor="site-edit-name">
          <Input id="site-edit-name" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        <Field label={t('sites.framework')} htmlFor="site-edit-framework">
          <Select id="site-edit-framework" value={framework} onChange={(e) => setFramework(e.target.value as SiteFramework)}>
            {FRAMEWORKS.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('sites.gitUrl')} htmlFor="site-edit-git-url">
          <Input id="site-edit-git-url" value={gitUrl} onChange={(e) => setGitUrl(e.target.value)} required />
        </Field>
        <Field label={t('sites.gitBranch')} htmlFor="site-edit-git-branch">
          <Input id="site-edit-git-branch" value={gitBranch} onChange={(e) => setGitBranch(e.target.value)} />
        </Field>
        <Field
          label={t('sites.envVars')}
          htmlFor="site-edit-env"
          hint={site.environment_variable_keys.length > 0 ? t('sites.envVarsKeep', { keys: site.environment_variable_keys.join(', ') }) : undefined}
        >
          <Textarea
            id="site-edit-env"
            value={envText}
            onChange={(e) => setEnvText(e.target.value)}
            rows={4}
            placeholder={t('sites.envVarsPlaceholder')}
          />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={save.isPending} className="w-full">
          {t('common.save')}
        </Button>
      </form>
    </Dialog>
  );
}

function LogsDialog({ deployment, onClose }: { deployment: SiteDeploymentDto | null; onClose: () => void }) {
  const { t } = useI18n();

  return (
    <Dialog open={!!deployment} onClose={onClose} title={t('sites.logsTitle', { n: deployment?.number ?? '' })}>
      <pre className={cn(
        'max-h-96 overflow-auto rounded-lg border border-edge bg-panel p-4 text-xs leading-relaxed',
        deployment?.status === 'failed' ? 'text-red-300' : 'text-zinc-300',
      )}>
        {deployment?.logs ?? t('sites.noLogs')}
      </pre>
    </Dialog>
  );
}
