import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Download, Pencil, Plus, RotateCcw, Trash2, Upload } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { DnsRecordDto, DnsRecordInput, DomainUpdateInput } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Select } from '../../components/ui/Select';
import { Textarea } from '../../components/ui/Textarea';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { StackedBar } from '../../components/viz/StackedBar';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { DnsRecordDialog } from './DnsRecordDialog';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { formatDate } from '../../lib/utils';

const TEMPLATES = ['website', 'email'];

export function DomainDetailPage() {
  const { domainId = '' } = useParams();
  const { hasPermission } = useAuth();
  const { t } = useI18n();
  const canManage = hasPermission('domains.manage') || hasPermission('dns.manage');
  const canReadDns = hasPermission('dns.read') || hasPermission('domains.read');
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const [recordDialog, setRecordDialog] = useState<{ open: boolean; record: DnsRecordDto | null }>({
    open: false,
    record: null,
  });
  const [importOpen, setImportOpen] = useState(false);
  const [nameserversOpen, setNameserversOpen] = useState(false);

  const invalidateDomain = () => queryClient.invalidateQueries({ queryKey: ['domain', domainId] });
  const invalidateRecords = () => queryClient.invalidateQueries({ queryKey: ['dns-records', domainId] });
  const invalidateHistory = () => queryClient.invalidateQueries({ queryKey: ['dns-history', domainId] });

  const domain = useQuery({
    queryKey: ['domain', domainId],
    queryFn: () => api.getDomain(domainId),
    enabled: !!domainId,
  });

  const update = useMutation({
    mutationFn: (input: DomainUpdateInput) => api.updateDomain(domainId, input),
    onSuccess: () => {
      void invalidateDomain();
      toast(t('toast.domains.updated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const renew = useMutation({
    mutationFn: () => api.renewDomain(domainId, 1),
    onSuccess: () => {
      void invalidateDomain();
      toast(t('toast.domains.renewed'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const saveRecord = useMutation({
    mutationFn: (input: DnsRecordInput) =>
      recordDialog.record
        ? api.updateDnsRecord(domainId, recordDialog.record.id, input)
        : api.createDnsRecord(domainId, input),
    onSuccess: () => {
      setRecordDialog({ open: false, record: null });
      void invalidateRecords();
      void invalidateHistory();
      toast(t('toast.dns.saved'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const removeRecord = useMutation({
    mutationFn: (recordId: string) => api.deleteDnsRecord(domainId, recordId),
    onSuccess: () => {
      void invalidateRecords();
      void invalidateHistory();
      toast(t('toast.dns.deleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const rollback = useMutation({
    mutationFn: (historyId: string) => api.rollbackDns(domainId, historyId),
    onSuccess: () => {
      void invalidateRecords();
      void invalidateHistory();
      toast(t('toast.dns.rolledBack'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const importDns = useMutation({
    mutationFn: (zoneFile: string) => api.importDns(domainId, zoneFile),
    onSuccess: () => {
      setImportOpen(false);
      void invalidateRecords();
      void invalidateHistory();
      toast(t('toast.dns.imported'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const applyTemplate = useMutation({
    mutationFn: (template: string) => api.applyDnsTemplate(domainId, template),
    onSuccess: () => {
      void invalidateRecords();
      void invalidateHistory();
      toast(t('toast.dns.templateApplied'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const updateNameservers = useMutation({
    mutationFn: (value: string) =>
      api.updateDomain(domainId, {
        nameservers: value
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean),
      }),
    onSuccess: () => {
      setNameserversOpen(false);
      void invalidateDomain();
      toast(t('toast.domains.nameserversUpdated'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  if (domain.isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (!domain.data) {
    return (
      <div className="mx-auto max-w-3xl">
        <EmptyState title={t('domains.notFound')} />
      </div>
    );
  }

  const current = domain.data;

  function downloadZoneFile() {
    void api
      .exportDns(domainId)
      .then((zoneFile) => {
        const blob = new Blob([zoneFile], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${current.name}.zone`;
        anchor.click();
        URL.revokeObjectURL(url);
      })
      .catch((err) => toast(errorMessage(err), 'error'));
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <Link to="/domains" className="inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-white">
          <ArrowLeft className="h-4 w-4" /> {t('domains.title')}
        </Link>
        <div className="mt-2 flex items-center gap-3">
          <h1 className="font-mono text-2xl font-bold text-white">{current.name}</h1>
          <Badge tone={current.status === 'active' ? 'success' : 'neutral'}>{current.status}</Badge>
        </div>
        <p className="text-sm text-zinc-400">
          {t('domains.registeredOn', { date: formatDate(current.registered_at) })} ·{' '}
          {t('domains.expires', { date: formatDate(current.expires_at) })} · {current.provider}
        </p>
      </header>

      <Card>
        <CardHeader title={t('domains.settings')} description={t('domains.settingsDescription')} />
        <div className="grid grid-cols-1 gap-6 p-5 sm:grid-cols-2">
          <ToggleRow
            label={t('domains.autoRenew')}
            hint={t('domains.autoRenewHint')}
            checked={current.auto_renew}
            disabled={!canManage}
            onToggle={(value) => update.mutate({ auto_renew: value })}
          />
          <ToggleRow
            label={t('domains.privacy')}
            hint={t('domains.privacyHint')}
            checked={current.privacy_protection}
            disabled={!canManage}
            onToggle={(value) => update.mutate({ privacy_protection: value })}
          />
          <ToggleRow
            label={t('domains.transferLock')}
            hint={t('domains.transferLockHint')}
            checked={current.transfer_lock}
            disabled={!canManage}
            onToggle={(value) => update.mutate({ transfer_lock: value })}
          />
          <div className="flex items-center justify-between rounded-lg border border-edge bg-raised px-4 py-3">
            <div>
              <p className="text-sm font-medium text-white">{t('domains.renew')}</p>
              <p className="text-xs text-zinc-500">{t('domains.renewHint')}</p>
            </div>
            <Button size="sm" disabled={!canManage} loading={renew.isPending} onClick={() => renew.mutate()}>
              {t('domains.renew1y')}
            </Button>
          </div>
        </div>

        <div className="border-t border-edge px-5 py-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium text-white">{t('domains.nameservers')}</p>
              <p className="text-xs text-zinc-500">{t('domains.nameserversHint')}</p>
            </div>
            {canManage ? (
              <Button size="sm" variant="outline" onClick={() => setNameserversOpen(true)}>
                <Pencil className="h-3.5 w-3.5" /> {t('common.edit')}
              </Button>
            ) : null}
          </div>
          <div className="mt-2 flex flex-wrap gap-2">
            {(current.nameservers ?? []).map((ns) => (
              <span key={ns} className="rounded-md border border-edge bg-raised px-2 py-1 font-mono text-xs text-zinc-300">
                {ns}
              </span>
            ))}
          </div>
        </div>
      </Card>

      {canReadDns ? (
        <Card className="overflow-hidden">
          <CardHeader
            title={t('domains.dnsRecords')}
            description={t('domains.dnsRecordsDescription')}
            action={
              canManage ? (
                <div className="flex items-center gap-2">
                  <Select
                    aria-label={t('domains.applyTemplate')}
                    value=""
                    onChange={(e) => {
                      if (e.target.value) applyTemplate.mutate(e.target.value);
                    }}
                    className="w-36"
                  >
                    <option value="" disabled>
                      {t('domains.template')}
                    </option>
                    {TEMPLATES.map((template) => (
                      <option key={template} value={template}>
                        {template}
                      </option>
                    ))}
                  </Select>
                  <Button size="sm" variant="outline" onClick={() => setImportOpen(true)}>
                    <Upload className="h-3.5 w-3.5" /> {t('common.import')}
                  </Button>
                  <Button size="sm" variant="outline" onClick={downloadZoneFile}>
                    <Download className="h-3.5 w-3.5" /> {t('common.export')}
                  </Button>
                  <Button size="sm" onClick={() => setRecordDialog({ open: true, record: null })}>
                    <Plus className="h-3.5 w-3.5" /> {t('common.add')}
                  </Button>
                </div>
              ) : undefined
            }
          />
          <RecordsTable
            domainId={domainId}
            canManage={canManage}
            onEdit={(record) => setRecordDialog({ open: true, record })}
            onDelete={(recordId) => removeRecord.mutate(recordId)}
            deletingId={removeRecord.isPending ? removeRecord.variables : null}
          />
        </Card>
      ) : null}

      {canReadDns ? <DnssecCard domainId={domainId} canManage={canManage} /> : null}

      {canReadDns ? <PropagationCard domainId={domainId} canManage={canManage} /> : null}

      {canReadDns ? (
        <Card>
          <CardHeader title={t('domains.history')} description={t('domains.historyDescription')} />
          <HistoryList
            domainId={domainId}
            canManage={canManage}
            onRollback={(historyId) => rollback.mutate(historyId)}
            rollingBackId={rollback.isPending ? rollback.variables : null}
          />
        </Card>
      ) : null}

      <DnsRecordDialog
        open={recordDialog.open}
        initial={recordDialog.record}
        saving={saveRecord.isPending}
        onClose={() => setRecordDialog({ open: false, record: null })}
        onSave={(input) => saveRecord.mutate(input)}
      />

      <ImportDialog
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onImport={(zoneFile) => importDns.mutate(zoneFile)}
        importing={importDns.isPending}
      />

      <NameserversDialog
        open={nameserversOpen}
        initial={(current.nameservers ?? []).join('\n')}
        onClose={() => setNameserversOpen(false)}
        onSave={(value) => updateNameservers.mutate(value)}
        saving={updateNameservers.isPending}
      />
    </div>
  );
}

function ToggleRow({
  label,
  hint,
  checked,
  disabled,
  onToggle,
}: {
  label: string;
  hint: string;
  checked: boolean;
  disabled: boolean;
  onToggle: (value: boolean) => void;
}) {
  const { t } = useI18n();
  return (
    <div className="flex items-center justify-between rounded-lg border border-edge bg-raised px-4 py-3">
      <div>
        <p className="text-sm font-medium text-white">{label}</p>
        <p className="text-xs text-zinc-500">{hint}</p>
      </div>
      <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-300">
        <input
          type="checkbox"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onToggle(e.target.checked)}
          className="h-4 w-4 accent-white disabled:opacity-40"
        />
        {checked ? t('common.on') : t('common.off')}
      </label>
    </div>
  );
}

function RecordsTable({
  domainId,
  canManage,
  onEdit,
  onDelete,
  deletingId,
}: {
  domainId: string;
  canManage: boolean;
  onEdit: (record: DnsRecordDto) => void;
  onDelete: (recordId: string) => void;
  deletingId: string | null;
}) {
  const { t } = useI18n();
  const records = useQuery({
    queryKey: ['dns-records', domainId],
    queryFn: () => api.listDnsRecords(domainId),
    enabled: !!domainId,
  });

  if (records.isLoading) {
    return (
      <div className="flex justify-center p-8">
        <Spinner />
      </div>
    );
  }

  if (!records.data || records.data.length === 0) {
    return (
      <div className="p-5">
        <EmptyState title={t('domains.noRecords')} />
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-edge text-left text-xs uppercase tracking-wide text-zinc-500">
            <th className="px-5 py-3 font-medium">{t('domains.type')}</th>
            <th className="px-5 py-3 font-medium">{t('domains.name')}</th>
            <th className="px-5 py-3 font-medium">{t('domains.content')}</th>
            <th className="px-5 py-3 font-medium">{t('domains.ttl')}</th>
            {canManage ? <th className="px-5 py-3 text-right font-medium">{t('common.actions')}</th> : null}
          </tr>
        </thead>
        <tbody className="divide-y divide-edge">
          {records.data.map((record) => (
            <tr key={record.id} className="text-zinc-300">
              <td className="px-5 py-3">
                <Badge tone={record.type === 'MX' || record.type === 'TXT' ? 'brand' : 'neutral'}>{record.type}</Badge>
              </td>
              <td className="px-5 py-3 font-mono text-xs">{record.name}</td>
              <td className="max-w-xs truncate px-5 py-3 font-mono text-xs" title={record.content}>
                {record.priority != null ? `${record.priority} ` : ''}
                {record.content}
              </td>
              <td className="px-5 py-3 text-zinc-500">{record.ttl}</td>
              {canManage ? (
                <td className="px-5 py-3">
                  <div className="flex justify-end gap-1">
                    <Button variant="ghost" size="icon" aria-label={t('domains.editRecord', { name: record.name })} onClick={() => onEdit(record)}>
                      <Pencil className="h-4 w-4 text-zinc-400" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={t('domains.deleteRecord', { name: record.name })}
                      loading={deletingId === record.id}
                      onClick={() => {
                        if (window.confirm(t('domains.deleteConfirm', { type: record.type, name: record.name }))) onDelete(record.id);
                      }}
                    >
                      <Trash2 className="h-4 w-4 text-red-400" />
                    </Button>
                  </div>
                </td>
              ) : null}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DnssecCard({ domainId, canManage }: { domainId: string; canManage: boolean }) {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [confirmOpen, setConfirmOpen] = useState(false);

  const dnssec = useQuery({
    queryKey: ['dnssec', domainId],
    queryFn: () => api.getDnssec(domainId),
    enabled: !!domainId,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['dnssec', domainId] });

  const enable = useMutation({
    mutationFn: () => api.enableDnssec(domainId),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.dns.dnssecEnabled'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const disable = useMutation({
    mutationFn: () => api.disableDnssec(domainId),
    onSuccess: () => {
      setConfirmOpen(false);
      void invalidate();
      toast(t('toast.dns.dnssecDisabled'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  if (dnssec.isLoading) {
    return (
      <Card>
        <CardHeader title={t('dnssec.title')} description={t('dnssec.description')} />
        <div className="flex justify-center p-8">
          <Spinner />
        </div>
      </Card>
    );
  }

  const status = dnssec.data;

  return (
    <Card>
      <CardHeader
        title={t('dnssec.title')}
        description={t('dnssec.description')}
        action={
          canManage ? (
            status?.enabled ? (
              <Button size="sm" variant="outline" onClick={() => setConfirmOpen(true)}>
                {t('dnssec.disable')}
              </Button>
            ) : (
              <Button size="sm" loading={enable.isPending} onClick={() => enable.mutate()}>
                {t('dnssec.enable')}
              </Button>
            )
          ) : undefined
        }
      />
      <div className="space-y-4 border-t border-edge px-5 py-4">
        <div className="flex items-center gap-2">
          <Badge tone={status?.enabled ? 'success' : 'neutral'}>
            {status?.enabled ? t('dnssec.enabled') : t('dnssec.disabled')}
          </Badge>
          {status?.status ? <span className="font-mono text-xs text-zinc-500">{status.status}</span> : null}
        </div>
        <div>
          <div className="mb-1.5 flex items-center justify-between text-xs">
            <span className="text-zinc-500">{t('dnssec.progress')}</span>
            <span className={status?.enabled ? 'font-semibold text-emerald-400' : 'text-zinc-400'}>
              {status?.enabled ? '100%' : '0%'}
            </span>
          </div>
          <ProgressBar percent={status?.enabled ? 100 : 0} tone={status?.enabled ? 'success' : 'brand'} />
        </div>
      </div>
      {status?.enabled ? (
        <div className="px-5 pb-5">
          <p className="text-xs text-zinc-500">{t('dnssec.publishHint')}</p>
          {status.ds_records.length === 0 ? (
            <p className="mt-3 text-sm text-zinc-400">{t('dnssec.noDs')}</p>
          ) : (
            <div className="mt-3 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-edge text-left text-xs uppercase tracking-wide text-zinc-500">
                    <th className="py-2 pr-4 font-medium">{t('dnssec.keyTag')}</th>
                    <th className="py-2 pr-4 font-medium">{t('dnssec.algorithm')}</th>
                    <th className="py-2 pr-4 font-medium">{t('dnssec.digestType')}</th>
                    <th className="py-2 font-medium">{t('dnssec.digest')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-edge">
                  {status.ds_records.map((ds, index) => (
                    <tr key={index} className="font-mono text-xs text-zinc-300">
                      <td className="py-2 pr-4">{ds.key_tag}</td>
                      <td className="py-2 pr-4">{ds.algorithm}</td>
                      <td className="py-2 pr-4">{ds.digest_type}</td>
                      <td className="break-all py-2 text-zinc-400">{ds.digest}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : null}

      <Dialog
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        title={t('dnssec.disableConfirm')}
        description={t('dnssec.disableDescription')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={disable.isPending} onClick={() => disable.mutate()}>
              {t('dnssec.disable')}
            </Button>
          </>
        }
      >
        <div />
      </Dialog>
    </Card>
  );
}

function PropagationCard({ domainId, canManage }: { domainId: string; canManage: boolean }) {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const propagation = useQuery({
    queryKey: ['dns-propagation', domainId],
    queryFn: () => api.getDnsPropagation(domainId),
    enabled: !!domainId,
  });

  const runCheck = useMutation({
    mutationFn: () => api.checkDnsPropagation(domainId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dns-propagation', domainId] });
      toast(t('toast.dns.propagationChecked'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const status = propagation.data;

  return (
    <Card>
      <CardHeader
        title={t('propagation.title')}
        description={t('propagation.description', { name: status?.domain ?? '' })}
        action={
          canManage ? (
            <Button size="sm" loading={runCheck.isPending} onClick={() => runCheck.mutate()}>
              {t('propagation.runCheck')}
            </Button>
          ) : undefined
        }
      />
      {propagation.isLoading ? (
        <div className="flex justify-center p-8">
          <Spinner />
        </div>
      ) : !status || status.data.length === 0 ? (
        <div className="p-5">
          <EmptyState title={t('propagation.noChecks')} description={t('propagation.noChecksDescription')} />
        </div>
      ) : (
        <>
          <div className="space-y-4 border-t border-edge px-5 py-4">
            <StackedBar
              items={
                status.summary.total > 0
                  ? [
                      { value: status.summary.synced, color: 'bg-emerald-400', label: t('propagation.synced') },
                      { value: status.summary.pending, color: 'bg-amber-400', label: t('propagation.pending') },
                      { value: status.summary.outdated, color: 'bg-red-400', label: t('propagation.outdated') },
                    ]
                  : []
              }
              total={status.summary.total}
              height={10}
            />
            <div className="flex flex-wrap items-center gap-3">
              <Badge tone="success">
                {t('propagation.synced')} · {status.summary.synced}
              </Badge>
              <Badge tone="warning">
                {t('propagation.pending')} · {status.summary.pending}
              </Badge>
              <Badge tone="danger">
                {t('propagation.outdated')} · {status.summary.outdated}
              </Badge>
              <Badge tone="neutral">
                {t('propagation.total')} · {status.summary.total}
              </Badge>
              {status.checked_at ? (
                <span className="text-xs text-zinc-500">
                  {t('propagation.checkedAt', { date: formatDate(status.checked_at) })}
                </span>
              ) : null}
            </div>
          </div>
          <div className="overflow-x-auto border-t border-edge">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-edge text-left text-xs uppercase tracking-wide text-zinc-500">
                  <th className="px-5 py-3 font-medium">{t('propagation.nameserver')}</th>
                  <th className="px-5 py-3 font-medium">{t('domains.type')}</th>
                  <th className="px-5 py-3 font-medium">{t('domains.name')}</th>
                  <th className="px-5 py-3 font-medium">{t('propagation.status')}</th>
                  <th className="px-5 py-3 font-medium">{t('propagation.observed')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-edge">
                {status.data.map((check) => (
                  <tr key={check.id} className="text-zinc-300">
                    <td className="px-5 py-3 font-mono text-xs">{check.nameserver}</td>
                    <td className="px-5 py-3">
                      <Badge tone="neutral">{check.record_type}</Badge>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs">{check.record_name}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={check.status} />
                    </td>
                    <td
                      className="max-w-xs truncate px-5 py-3 font-mono text-xs text-zinc-500"
                      title={(check.observed ?? []).join(', ')}
                    >
                      {(check.observed ?? []).join(', ') || '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Card>
  );
}

function StatusBadge({ status }: { status: string }) {
  const { t } = useI18n();
  const tone =
    status === 'synced' ? 'success' : status === 'pending' ? 'warning' : status === 'outdated' ? 'danger' : 'neutral';
  const label =
    status === 'synced'
      ? t('propagation.synced')
      : status === 'pending'
        ? t('propagation.pending')
        : status === 'outdated'
          ? t('propagation.outdated')
          : t('propagation.error');
  return <Badge tone={tone}>{label}</Badge>;
}

function HistoryList({
  domainId,
  canManage,
  onRollback,
  rollingBackId,
}: {
  domainId: string;
  canManage: boolean;
  onRollback: (historyId: string) => void;
  rollingBackId: string | null;
}) {
  const { t } = useI18n();
  const history = useQuery({
    queryKey: ['dns-history', domainId],
    queryFn: () => api.listDnsHistory(domainId),
    enabled: !!domainId,
  });

  if (history.isLoading) {
    return (
      <div className="flex justify-center p-8">
        <Spinner />
      </div>
    );
  }

  if (!history.data || history.data.length === 0) {
    return (
      <div className="p-5">
        <EmptyState title={t('domains.noChanges')} description={t('domains.noChangesDescription')} />
      </div>
    );
  }

  return (
    <ul className="divide-y divide-edge">
      {history.data.map((entry) => (
        <li key={entry.id} className="flex items-center gap-3 px-5 py-3">
          <Badge tone={entry.action === 'deleted' ? 'danger' : entry.action === 'updated' ? 'warning' : 'success'}>
            {entry.action}
          </Badge>
          <div className="min-w-0 flex-1">
            <p className="truncate font-mono text-xs text-zinc-300">{summarize(entry)}</p>
            <p className="text-xs text-zinc-600">
              {formatDate(entry.created_at)}
              {entry.user ? ` · ${entry.user.name}` : ''}
            </p>
          </div>
          {canManage ? (
            <Button
              variant="ghost"
              size="sm"
              loading={rollingBackId === entry.id}
              onClick={() => onRollback(entry.id)}
            >
              <RotateCcw className="h-3.5 w-3.5" /> {t('domains.rollback')}
            </Button>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

function summarize(entry: { action: string; before?: unknown; after?: unknown }): string {
  const snapshot = (entry.after ?? entry.before) as { type?: string; name?: string; content?: string } | null;
  if (!snapshot?.type) return entry.action;
  return `${snapshot.type} ${snapshot.name ?? '@'} → ${snapshot.content ?? ''}`;
}

function ImportDialog({
  open,
  onClose,
  onImport,
  importing,
}: {
  open: boolean;
  onClose: () => void;
  onImport: (zoneFile: string) => void;
  importing: boolean;
}) {
  const { t } = useI18n();
  const [zoneFile, setZoneFile] = useState('');

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('domains.importTitle')}
      description={t('domains.importDescription')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button loading={importing} onClick={() => onImport(zoneFile)} disabled={zoneFile.trim() === ''}>
            {t('common.import')}
          </Button>
        </>
      }
    >
      <Field label={t('domains.bindZone')} htmlFor="zone-file">
        <Textarea
          id="zone-file"
          rows={10}
          value={zoneFile}
          onChange={(e) => setZoneFile(e.target.value)}
          placeholder={'$ORIGIN example.com.\n$TTL 3600\n@ IN A 192.0.2.1'}
          className="font-mono text-xs"
        />
      </Field>
    </Dialog>
  );
}

function NameserversDialog({
  open,
  initial,
  onClose,
  onSave,
  saving,
}: {
  open: boolean;
  initial: string;
  onClose: () => void;
  onSave: (value: string) => void;
  saving: boolean;
}) {
  const { t } = useI18n();
  const [value, setValue] = useState(initial);

  useEffect(() => {
    if (open) setValue(initial);
  }, [open, initial]);

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('domains.nameserversTitle')}
      description={t('domains.nameserversDescription')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button loading={saving} onClick={() => onSave(value)} disabled={value.trim() === ''}>
            {t('common.save')}
          </Button>
        </>
      }
    >
      <Textarea
        rows={4}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        className="font-mono text-xs"
        aria-label={t('domains.nameserversTitle')}
      />
    </Dialog>
  );
}
