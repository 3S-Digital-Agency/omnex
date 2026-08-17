import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import {
  ArrowLeft,
  Camera,
  KeyRound,
  Pencil,
  Play,
  Plus,
  RefreshCw,
  RotateCw,
  Server,
  Square,
  Trash2,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { ServerDto, ServerMetricsDto, SnapshotFrequency } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { AreaChart } from '../../components/viz/AreaChart';
import { PeriodSelector } from '../../components/viz/PeriodSelector';
import type { PeriodDays } from '../../components/viz/PeriodSelector';
import { Donut } from '../../components/viz/Donut';
import { Sparkline } from '../../components/viz/Sparkline';
import { StackedBar } from '../../components/viz/StackedBar';
import { useAnimatedNumber } from '../../components/viz/useMotion';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatBytes } from '../../lib/utils';

const REGIONS = ['fsn1', 'nbg1', 'hel1', 'nyc1', 'sfo3', 'ams3'];
const PLANS = ['cpx11', 'cpx21', 'cpx31', 'cpx41'];
const IMAGES = ['ubuntu-24.04', 'debian-12', 'rocky-9'];

function parseTags(text: string): string[] {
  return text
    .split(',')
    .map((tag) => tag.trim())
    .filter((tag) => tag !== '');
}

function metricTone(percent: number): 'text-emerald-400' | 'text-amber-400' | 'text-red-400' {
  if (percent >= 95) return 'text-red-400';
  if (percent >= 80) return 'text-amber-400';
  return 'text-emerald-400';
}

function MetricsRow({ label, percent, detail }: { label: string; percent: number; detail: string }) {
  const clamped = Math.max(0, Math.min(100, percent));

  return (
    <div>
      <div className="flex items-center justify-between text-sm">
        <span className="text-zinc-300">{label}</span>
        <span className="text-xs text-zinc-500">{detail}</span>
      </div>
      <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-raised">
        <div
          className={cn(
            'h-full rounded-full bg-brand-500 transition-all duration-500',
            clamped >= 95 && 'bg-red-500',
            clamped >= 80 && clamped < 95 && 'bg-amber-500',
          )}
          style={{ width: `${clamped}%` }}
        />
      </div>
    </div>
  );
}

/** Compact area chart of one metric series (shared AreaChart, fluid width). */
function MiniMetricTimeline({ label, values, tone }: { label: string; values: number[]; tone: string }) {
  return (
    <div className="rounded-lg border border-edge bg-raised p-3">
      <p className="text-xs font-medium text-zinc-400">{label}</p>
      <div className={cn('mt-1', tone)}>
        <AreaChart values={values} height={64} showLastPoint={false} min={0} max={100} label={label} />
      </div>
    </div>
  );
}

/** One animated metric donut (CPU / RAM / disk) with the live % in the center. */
function MetricDonut({ label, percent, detail }: { label: string; percent: number; detail: string }) {
  const clamped = Math.max(0, Math.min(100, percent));
  const animated = Math.round(useAnimatedNumber(clamped));

  return (
    <div className="flex flex-col items-center gap-2 rounded-xl border border-edge bg-raised p-4 text-center">
      <Donut
        value={clamped}
        size={104}
        thickness={9}
        className={metricTone(clamped)}
        trackClass="text-white/5"
        label={`${label}: ${clamped}%`}
      >
        <span className={cn('text-xl font-bold', metricTone(clamped))}>{animated}%</span>
      </Donut>
      <span className="text-xs font-medium text-zinc-400">{label}</span>
      <span className="text-[11px] text-zinc-500">{detail}</span>
    </div>
  );
}

function statusTone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'running':
      return 'success';
    case 'stopped':
      return 'neutral';
    case 'failed':
      return 'danger';
    default:
      return 'warning';
  }
}

const OPERATION_LABEL_KEYS: Record<string, string> = {
  provision: 'cloud.op.provision',
  start: 'cloud.op.start',
  stop: 'cloud.op.stop',
  reboot: 'cloud.op.reboot',
  rebuild: 'cloud.op.rebuild',
  delete: 'cloud.op.delete',
  snapshot: 'cloud.op.snapshot',
  install_key: 'cloud.op.install_key',
};

export function CloudPage() {
  const { hasPermission, activeOrganization } = useAuth();
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const canManage = hasPermission('cloud.manage');

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [editFor, setEditFor] = useState<ServerDto | null>(null);
  const [rebuildFor, setRebuildFor] = useState<ServerDto | null>(null);

  const list = useQuery({
    queryKey: ['cloud', activeOrganization?.id],
    queryFn: () => api.listServers(),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['cloud', activeOrganization?.id] });

  const selected = list.data?.find((server) => server.id === selectedId) ?? null;

  const remove = useMutation({
    mutationFn: (server: ServerDto) => api.deleteServer(server.id),
    onSuccess: () => {
      setSelectedId(null);
      void invalidate();
      toast(t('toast.cloud.deleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  if (selectedId && selected) {
    return (
      <>
        <ServerDetail
          server={selected}
          canManage={canManage}
          onBack={() => setSelectedId(null)}
          onEdit={() => setEditFor(selected)}
          onRebuild={() => setRebuildFor(selected)}
          onDelete={() => remove.mutate(selected)}
          onChanged={() => void invalidate()}
        />
        {editFor ? (
          <EditServerDialog
            server={editFor}
            onClose={() => setEditFor(null)}
            onSaved={() => {
              setEditFor(null);
              void invalidate();
              toast(t('toast.cloud.updated'));
            }}
          />
        ) : null}
        <RebuildDialog
          server={rebuildFor}
          onClose={() => setRebuildFor(null)}
          onDone={(failed) => {
            setRebuildFor(null);
            void invalidate();
            if (failed) {
              toast(t('toast.cloud.operationFailed'), 'error');
            } else {
              toast(t('toast.cloud.rebuilt'));
            }
          }}
        />
      </>
    );
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('cloud.title')}</h1>
          <p className="text-sm text-zinc-400">{t('cloud.subtitle')}</p>
        </div>
        {canManage ? (
          <div className="flex gap-2">
            <Link to="/cloud/ssh-keys">
              <Button variant="outline">
                <KeyRound className="h-4 w-4" /> {t('cloud.sshKeyManage')}
              </Button>
            </Link>
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> {t('cloud.newServer')}
            </Button>
          </div>
        ) : null}
      </header>

      <FleetOverview servers={list.data ?? []} t={t} isLoading={list.isLoading} />

      <Card>
        <CardHeader title={t('cloud.servers')} description={t('cloud.serversDescription')} />
        <div className="p-5">
          {list.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : list.data && list.data.length > 0 ? (
            <ul className="space-y-2">
              {list.data.map((server) => (
                <li key={server.id}>
                  <button
                    onClick={() => setSelectedId(server.id)}
                    className="flex w-full items-center gap-3 rounded-lg border border-edge bg-raised px-4 py-3 text-left transition-colors hover:border-brand-700"
                  >
                    <Server className="h-4 w-4 shrink-0 text-brand-400" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-white">{server.name}</p>
                      <p className="truncate text-xs text-zinc-500">
                        {server.region} · {server.plan} · {server.image} · {server.ipv4 ?? '—'}
                      </p>
                    </div>
                    {server.tags.length > 0 ? (
                      <span className="hidden text-xs text-zinc-500 sm:block">{server.tags.join(', ')}</span>
                    ) : null}
                    <Badge tone={statusTone(server.status)}>{server.status}</Badge>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState
              title={t('cloud.empty')}
              description={canManage ? t('cloud.emptyDescription') : undefined}
            />
          )}
        </div>
      </Card>

      <CreateServerDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(server) => {
          setCreateOpen(false);
          void invalidate();
          setSelectedId(server.id);
          toast(t('toast.cloud.created'));
        }}
      />

      {editFor ? (
        <EditServerDialog
          server={editFor}
          onClose={() => setEditFor(null)}
          onSaved={() => {
            setEditFor(null);
            void invalidate();
            toast(t('toast.cloud.updated'));
          }}
        />
      ) : null}

      <RebuildDialog
        server={rebuildFor}
        onClose={() => setRebuildFor(null)}
        onDone={(failed) => {
          setRebuildFor(null);
          void invalidate();
          if (failed) {
            toast(t('toast.cloud.operationFailed'), 'error');
          } else {
            toast(t('toast.cloud.rebuilt'));
          }
        }}
      />
    </div>
  );
}

function ServerDetail({
  server,
  canManage,
  onBack,
  onEdit,
  onRebuild,
  onDelete,
  onChanged,
}: {
  server: ServerDto;
  canManage: boolean;
  onBack: () => void;
  onEdit: () => void;
  onRebuild: () => void;
  onDelete: () => void;
  onChanged: () => void;
}) {
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { activeOrganization } = useAuth();

  const operations = useQuery({
    queryKey: ['cloud', activeOrganization?.id, 'operations', server.id],
    queryFn: () => api.listServerOperations(server.id),
  });

  const [metrics, setMetrics] = useState<ServerMetricsDto | null>(null);
  const [historyValues, setHistoryValues] = useState<ServerMetricsDto[]>([]);
  const [period, setPeriod] = useState<PeriodDays>(30);

  const filteredHistory = useMemo(() => {
    if (period >= 90) return historyValues;
    const cutoff = Date.now() - period * 86400000;
    return historyValues.filter((sample) => new Date(sample.sampled_at).getTime() >= cutoff);
  }, [historyValues, period]);

  const history = useQuery({
    queryKey: ['cloud', activeOrganization?.id, 'metrics-history', server.id],
    queryFn: () => api.listServerMetricsHistory(server.id, 90),
    enabled: !!activeOrganization?.id,
  });

  // Seed the sparkline from the persisted history, then append live samples.
  useEffect(() => {
    if (history.data && history.data.length > 0) {
      setHistoryValues(history.data);
    }
  }, [history.data]);

  useEffect(() => api.subscribeServerMetrics(server.id, (sample) => {
    setMetrics(sample);
    setHistoryValues((previous) => [...previous, sample].slice(-90));
  }), [server.id]);

  const invalidate = () => {
    onChanged();
    void queryClient.invalidateQueries({ queryKey: ['cloud', activeOrganization?.id, 'operations', server.id] });
  };

  const run = useMutation({
    mutationFn: (type: 'start' | 'stop' | 'reboot') => {
      if (type === 'start') return api.startServer(server.id);
      if (type === 'stop') return api.stopServer(server.id);
      return api.rebootServer(server.id);
    },
    onSuccess: (operation) => {
      void invalidate();
      if (operation.status === 'failed') {
        toast(t('toast.cloud.operationFailed'), 'error');
      } else {
        const key =
          operation.type === 'start' ? 'toast.cloud.started'
          : operation.type === 'stop' ? 'toast.cloud.stopped'
          : 'toast.cloud.rebooted';
        toast(t(key));
      }
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="icon" onClick={onBack} title={t('cloud.back')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold text-white">{server.name}</h1>
              <Badge tone={statusTone(server.status)}>{server.status}</Badge>
            </div>
            <p className="text-sm text-zinc-400">{server.ipv4 ?? '—'}</p>
          </div>
        </div>
        {canManage ? (
          <div className="flex flex-wrap gap-2">
            {server.status === 'stopped' ? (
              <Button size="sm" variant="outline" loading={run.isPending} onClick={() => run.mutate('start')}>
                <Play className="h-3.5 w-3.5" /> {t('cloud.start')}
              </Button>
            ) : (
              <Button size="sm" variant="outline" loading={run.isPending} onClick={() => run.mutate('stop')}>
                <Square className="h-3.5 w-3.5" /> {t('cloud.stop')}
              </Button>
            )}
            <Button size="sm" variant="outline" loading={run.isPending} onClick={() => run.mutate('reboot')}>
              <RefreshCw className="h-3.5 w-3.5" /> {t('cloud.reboot')}
            </Button>
            <Button size="sm" variant="outline" onClick={onRebuild}>
              <RotateCw className="h-3.5 w-3.5" /> {t('cloud.rebuild')}
            </Button>
            <Button size="sm" variant="ghost" onClick={onEdit} title={t('common.edit')}>
              <Pencil className="h-3.5 w-3.5" />
            </Button>
            <Button size="sm" variant="danger" onClick={onDelete}>
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        ) : null}
      </header>

      <Card>
        <CardHeader title={t('cloud.configuration')} />
        <dl className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.region')}</dt>
            <dd className="mt-1 text-sm text-white">{server.region}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.plan')}</dt>
            <dd className="mt-1 text-sm text-white">{server.plan}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.image')}</dt>
            <dd className="mt-1 text-sm text-white">{server.image}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.provider')}</dt>
            <dd className="mt-1 text-sm text-white">{server.provider}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.ipv4')}</dt>
            <dd className="mt-1 text-sm text-white">{server.ipv4 ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.ipv6')}</dt>
            <dd className="mt-1 text-sm text-white">{server.ipv6 ?? '—'}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.sshKey')}</dt>
            <dd className="mt-1 break-all text-sm text-zinc-300">{server.ssh_key ?? t('cloud.noSshKey')}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-xs font-medium uppercase tracking-wide text-zinc-500">{t('cloud.tags')}</dt>
            <dd className="mt-1 text-sm text-zinc-300">
              {server.tags.length > 0 ? server.tags.join(', ') : '—'}
            </dd>
          </div>
        </dl>
      </Card>      <SnapshotsCard server={server} canManage={canManage} />

      <Card>
        <CardHeader title={t('cloud.metrics')}
          description={t('cloud.metricsDescription')}
          action={
            <span className="flex items-center gap-2">
              <PeriodSelector value={period} onChange={setPeriod} />
              <Badge tone="success">{t('common.live')}</Badge>
            </span>
          }
        />
        <div className="space-y-5 p-5">
          {metrics ? (
            <>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <MetricDonut
                  label={t('cloud.cpu')}
                  percent={metrics.cpu}
                  detail={`${metrics.cpu}%`}
                />
                <MetricDonut
                  label={t('cloud.ram')}
                  percent={(metrics.memory_used / metrics.memory_total) * 100}
                  detail={`${formatBytes(metrics.memory_used)} / ${formatBytes(metrics.memory_total)}`}
                />
                <MetricDonut
                  label={t('cloud.disk')}
                  percent={(metrics.disk_used / metrics.disk_total) * 100}
                  detail={`${formatBytes(metrics.disk_used)} / ${formatBytes(metrics.disk_total)}`}
                />
              </div>

              <div className="pt-1">
                <div className="flex items-center justify-between text-xs text-zinc-500">
                  <span>{t('cloud.metricsHistory')}</span>
                  <span>{filteredHistory.length} {t('cloud.metricsSamples')}</span>
                </div>
                <div className="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <MiniMetricTimeline label={t('cloud.cpu')} values={filteredHistory.map((s) => s.cpu)} tone="text-brand-400" />
                  <MiniMetricTimeline
                    label={t('cloud.ram')}
                    values={filteredHistory.map((s) => (s.memory_total > 0 ? (s.memory_used / s.memory_total) * 100 : 0))}
                    tone="text-violet-400"
                  />
                  <MiniMetricTimeline
                    label={t('cloud.disk')}
                    values={filteredHistory.map((s) => (s.disk_total > 0 ? (s.disk_used / s.disk_total) * 100 : 0))}
                    tone="text-cyan-400"
                  />
                </div>
              </div>
              <p className="text-xs text-zinc-600">
                {t('cloud.metricsSampledAt', { time: new Date(metrics.sampled_at).toLocaleTimeString() })}
              </p>
            </>
          ) : (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          )}
        </div>
      </Card>

      <Card>
        <CardHeader title={t('cloud.operations')} description={t('cloud.operationsDescription')} />
        <div className="space-y-4 p-5">
          {operations.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : operations.data && operations.data.length > 0 ? (
            <>
              <OperationProgress data={operations.data} t={t} />
              <ul className="space-y-2">
                {operations.data.map((operation) => (
                  <li key={operation.id} className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-white">
                          {t(OPERATION_LABEL_KEYS[operation.type] ?? operation.type)}
                        </p>
                        <Badge tone={operation.status === 'succeeded' ? 'success' : operation.status === 'failed' ? 'danger' : 'warning'}>
                          {operation.status}
                        </Badge>
                      </div>
                      <p className="truncate text-xs text-zinc-500">
                        {operation.started_at ?? '—'}
                        {operation.error ? ` · ${operation.error}` : ''}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <EmptyState title={t('cloud.noOperations')} />
          )}
        </div>
      </Card>
    </div>
  );
}

/** Fleet cockpit: animated KPIs + status distribution for every server. */
function FleetOverview({
  servers,
  t,
  isLoading,
}: {
  servers: ServerDto[];
  t: (key: string, params?: Record<string, string | number>) => string;
  isLoading: boolean;
}) {
  const total = servers.length;
  const running = servers.filter((server) => server.status === 'running').length;
  const stopped = servers.filter((server) => server.status === 'stopped').length;
  const provisioning = servers.filter((server) => server.status === 'provisioning').length;
  const failed = servers.filter((server) => server.status === 'failed').length;

  const kpi = [
    { label: t('cloud.fleet.total'), value: total, tone: 'text-white', spark: 'text-brand-400' },
    { label: t('cloud.fleet.running'), value: running, tone: 'text-emerald-400', spark: 'text-emerald-400' },
    { label: t('cloud.fleet.stopped'), value: stopped, tone: 'text-zinc-300', spark: 'text-zinc-400' },
    { label: t('cloud.fleet.provisioning'), value: provisioning, tone: 'text-amber-400', spark: 'text-amber-400' },
    { label: t('cloud.fleet.failed'), value: failed, tone: failed > 0 ? 'text-red-400' : 'text-zinc-400', spark: 'text-red-400' },
  ];

  return (
    <Card>
      <CardHeader title={t('cloud.fleet.title')} description={t('cloud.fleet.description')} />
      <div className="space-y-4 p-5">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {kpi.map((item) => (
            <div key={item.label} className="rounded-xl border border-edge bg-raised p-3">
              <p className={cn('text-2xl font-bold', item.tone)}>{isLoading ? '—' : item.value}</p>
              <p className="mt-0.5 text-xs text-zinc-500">{item.label}</p>
            </div>
          ))}
        </div>
        {!isLoading && total > 0 ? (
          <StackedBar
            items={[
              { value: running, color: 'bg-emerald-400', label: t('cloud.fleet.running') },
              { value: stopped, color: 'bg-zinc-500', label: t('cloud.fleet.stopped') },
              { value: provisioning, color: 'bg-amber-400', label: t('cloud.fleet.provisioning') },
              { value: failed, color: 'bg-red-400', label: t('cloud.fleet.failed') },
            ]}
            total={total}
            height={10}
          />
        ) : null}
      </div>
    </Card>
  );
}

/** Stacked progress of server operations by status (succeeded / pending / failed). */
function OperationProgress({
  data,
  t,
}: {
  data: { status: string }[];
  t: (key: string, params?: Record<string, string | number>) => string;
}) {
  const succeeded = data.filter((operation) => operation.status === 'succeeded').length;
  const pending = data.filter((operation) => operation.status === 'pending' || operation.status === 'running').length;
  const failed = data.filter((operation) => operation.status === 'failed').length;

  return (
    <div className="rounded-lg border border-edge bg-raised p-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">
        {t('cloud.opProgress')}
      </p>
      <StackedBar
        items={[
          { value: succeeded, color: 'bg-emerald-400', label: t('cloud.opSucceeded') },
          { value: pending, color: 'bg-amber-400', label: t('cloud.opPending') },
          { value: failed, color: 'bg-red-400', label: t('cloud.opFailed') },
        ]}
        height={10}
      />
    </div>
  );
}

function SnapshotsCard({ server, canManage }: { server: ServerDto; canManage: boolean }) {
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { activeOrganization } = useAuth();
  const [label, setLabel] = useState('');

  const list = useQuery({
    queryKey: ['cloud', activeOrganization?.id, 'snapshots', server.id],
    queryFn: () => api.listServerSnapshots(server.id),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () =>
    void queryClient.invalidateQueries({ queryKey: ['cloud', activeOrganization?.id, 'snapshots', server.id] });

  const create = useMutation({
    mutationFn: (value: string) => api.createServerSnapshot(server.id, value.trim() || undefined),
    onSuccess: () => {
      setLabel('');
      invalidate();
      toast(t('cloud.snapshotCreated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const remove = useMutation({
    mutationFn: (snapshotId: string) => api.deleteServerSnapshot(server.id, snapshotId),
    onSuccess: () => {
      invalidate();
      toast(t('cloud.snapshotDeleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const schedule = useMutation({
    mutationFn: (input: { snapshot_frequency: SnapshotFrequency; snapshot_retention_days: number }) =>
      api.updateServer(server.id, input),
    onSuccess: () => {
      invalidate();
      void queryClient.invalidateQueries({ queryKey: ['cloud', activeOrganization?.id] });
      toast(t('toast.cloud.updated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <Card>
      <CardHeader title={t('cloud.snapshots')} description={t('cloud.snapshotsDescription')} />
      <div className="space-y-5 p-5">
        {canManage ? (
          <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              create.mutate(label);
            }}
          >
            <div className="min-w-52 flex-1">
              <Field label={t('cloud.snapshotLabel')} htmlFor="snapshot-label">
                <Input
                  id="snapshot-label"
                  value={label}
                  onChange={(e) => setLabel(e.target.value)}
                  placeholder={t('cloud.snapshotNew')}
                />
              </Field>
            </div>
            <Button type="submit" loading={create.isPending}>
              <Camera className="h-4 w-4" /> {t('cloud.snapshotNew')}
            </Button>
          </form>
        ) : null}

        <div className="flex flex-wrap items-end gap-3 rounded-lg border border-edge bg-raised p-3">
          <Field label={t('cloud.snapshotFrequency')} htmlFor="snap-frequency">
            <Select
              id="snap-frequency"
              value={server.snapshot_frequency ?? 'disabled'}
              disabled={!canManage}
              onChange={(e) =>
                schedule.mutate({
                  snapshot_frequency: e.target.value as SnapshotFrequency,
                  snapshot_retention_days: server.snapshot_retention_days ?? 7,
                })
              }
            >
              <option value="disabled">{t('cloud.snapshotFrequencyDisabled')}</option>
              <option value="daily">{t('cloud.snapshotFrequencyDaily')}</option>
              <option value="weekly">{t('cloud.snapshotFrequencyWeekly')}</option>
            </Select>
          </Field>
          <Field label={t('cloud.snapshotRetention')} htmlFor="snap-retention">
            <Input
              id="snap-retention"
              type="number"
              min={1}
              max={365}
              value={server.snapshot_retention_days ?? 7}
              disabled={!canManage}
              onChange={(e) => {
                const value = Math.max(1, Math.min(365, Number(e.target.value) || 1));
                schedule.mutate({
                  snapshot_frequency: server.snapshot_frequency ?? 'disabled',
                  snapshot_retention_days: value,
                });
              }}
            />
          </Field>
          <div className="pb-1 text-xs text-zinc-500">
            {t('cloud.snapshotLast')}: {server.last_snapshot_at ? new Date(server.last_snapshot_at).toLocaleString() : t('cloud.snapshotNever')}
          </div>
        </div>

        <div>
          {list.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : list.data && list.data.length > 0 ? (
            <ul className="space-y-2">
              {list.data.map((snapshot) => (
                <li key={snapshot.id} className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5">
                  <Camera className="h-4 w-4 shrink-0 text-brand-400" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-white">{snapshot.label}</p>
                    <p className="truncate text-xs text-zinc-500">
                      {snapshot.created_at ? new Date(snapshot.created_at).toLocaleString() : '—'}
                      {snapshot.size_bytes ? ` · ${formatBytes(snapshot.size_bytes)}` : ''}
                    </p>
                  </div>
                  <Badge tone={snapshot.status === 'available' ? 'success' : snapshot.status === 'creating' ? 'warning' : 'danger'}>
                    {snapshot.status}
                  </Badge>
                  {canManage ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      title={t('common.delete')}
                      loading={remove.isPending}
                      onClick={() => remove.mutate(snapshot.id)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState title={t('cloud.snapshotNone')} />
          )}
        </div>
      </div>
    </Card>
  );
}

function CreateServerDialog({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (server: ServerDto) => void;
}) {
  const { t } = useI18n();
  const { activeOrganization } = useAuth();
  const [name, setName] = useState('');
  const [provider, setProvider] = useState('');
  const [region, setRegion] = useState(REGIONS[0]);
  const [plan, setPlan] = useState(PLANS[0]);
  const [image, setImage] = useState(IMAGES[0]);
  const [selectedKeyId, setSelectedKeyId] = useState('');
  const [sshKey, setSshKey] = useState('');
  const [tagsText, setTagsText] = useState('');
  const [error, setError] = useState<string | null>(null);

  const providers = useQuery({
    queryKey: ['cloud', activeOrganization?.id, 'providers'],
    queryFn: () => api.listCloudProviders(),
    enabled: !!activeOrganization?.id,
  });

  const savedKeys = useQuery({
    queryKey: ['cloud', 'ssh-keys'],
    queryFn: () => api.listSshKeys(),
  });

  const configuredProviders = (providers.data ?? []).filter((p) => p.configured);
  const activeProvider = provider !== '' ? provider : (configuredProviders[0]?.name ?? 'sandbox');

  const create = useMutation({
    mutationFn: () =>
      api.createServer({
        name,
        provider: activeProvider,
        region,
        plan,
        image,
        ssh_key_id: selectedKeyId || undefined,
        ssh_key: selectedKeyId ? undefined : sshKey,
        tags: parseTags(tagsText),
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
    <Dialog open={open} onClose={onClose} title={t('cloud.createTitle')} description={t('cloud.createDescription')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.name')} htmlFor="server-name">
          <Input id="server-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="web-01" required autoFocus />
        </Field>
        <Field label={t('cloud.provider')} htmlFor="server-provider" hint={t('cloud.providerHint')}>
          <Select id="server-provider" value={activeProvider} onChange={(e) => setProvider(e.target.value)}>
            {configuredProviders.map((option) => (
              <option key={option.name} value={option.name}>{option.label}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('cloud.region')} htmlFor="server-region">
          <Select id="server-region" value={region} onChange={(e) => setRegion(e.target.value)}>
            {REGIONS.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('cloud.plan')} htmlFor="server-plan">
          <Select id="server-plan" value={plan} onChange={(e) => setPlan(e.target.value)}>
            {PLANS.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('cloud.image')} htmlFor="server-image">
          <Select id="server-image" value={image} onChange={(e) => setImage(e.target.value)}>
            {IMAGES.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('cloud.sshKeyAssociate')} htmlFor="server-saved-key">
          <Select id="server-saved-key" value={selectedKeyId} onChange={(e) => setSelectedKeyId(e.target.value)}>
            <option value="">{t('cloud.sshKeyAssociateNone')}</option>
            {(savedKeys.data ?? []).map((key) => (
              <option key={key.id} value={key.id}>{key.name} — {key.fingerprint}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('cloud.sshKey')} htmlFor="server-ssh" hint={t('cloud.sshKeyDescription')}>
          <Input id="server-ssh" value={sshKey} onChange={(e) => setSshKey(e.target.value)} placeholder="ssh-ed25519 AAAA…" disabled={selectedKeyId !== ''} />
        </Field>
        <Field label={t('cloud.tags')} htmlFor="server-tags" hint={t('cloud.tagsDescription')}>
          <Input id="server-tags" value={tagsText} onChange={(e) => setTagsText(e.target.value)} placeholder="web, staging" />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={create.isPending} className="w-full">
          {t('cloud.create')}
        </Button>
      </form>
    </Dialog>
  );
}

function EditServerDialog({
  server,
  onClose,
  onSaved,
}: {
  server: ServerDto;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(server.name);
  const [sshKey, setSshKey] = useState(server.ssh_key ?? '');
  const [tagsText, setTagsText] = useState(server.tags.join(', '));
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () =>
      api.updateServer(server.id, {
        name,
        ssh_key: sshKey.trim() === '' ? null : sshKey.trim(),
        tags: parseTags(tagsText),
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
    <Dialog open onClose={onClose} title={t('cloud.editTitle', { name: server.name })}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.name')} htmlFor="server-edit-name">
          <Input id="server-edit-name" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        <Field label={t('cloud.sshKey')} htmlFor="server-edit-ssh" hint={t('cloud.sshKeyDescription')}>
          <Input id="server-edit-ssh" value={sshKey} onChange={(e) => setSshKey(e.target.value)} />
        </Field>
        <Field label={t('cloud.tags')} htmlFor="server-edit-tags" hint={t('cloud.tagsDescription')}>
          <Input id="server-edit-tags" value={tagsText} onChange={(e) => setTagsText(e.target.value)} />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={save.isPending} className="w-full">
          {t('common.save')}
        </Button>
      </form>
    </Dialog>
  );
}

function RebuildDialog({
  server,
  onClose,
  onDone,
}: {
  server: ServerDto | null;
  onClose: () => void;
  onDone: (failed: boolean) => void;
}) {
  const { t } = useI18n();
  const [image, setImage] = useState(server?.image ?? IMAGES[0]);
  const [error, setError] = useState<string | null>(null);

  const rebuild = useMutation({
    mutationFn: () => api.rebuildServer(server!.id, image),
    onSuccess: (operation) => onDone(operation.status === 'failed'),
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    rebuild.mutate();
  }

  return (
    <Dialog
      open={!!server}
      onClose={onClose}
      title={t('cloud.rebuildTitle', { name: server?.name ?? '' })}
      description={t('cloud.rebuildDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.image')} htmlFor="server-rebuild-image">
          <Select id="server-rebuild-image" value={image} onChange={(e) => setImage(e.target.value)}>
            {IMAGES.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </Select>
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={rebuild.isPending} className="w-full">
          {t('cloud.rebuildConfirm')}
        </Button>
      </form>
    </Dialog>
  );
}
