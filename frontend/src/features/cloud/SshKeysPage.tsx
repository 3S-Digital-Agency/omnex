import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, KeyRound, Lock, LockOpen, Pencil, Plus, Server, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { SshKeyDto, SshKeyGenerateResponse } from '../../lib/api/types';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { Textarea } from '../../components/ui/Textarea';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { formatDate } from '../../lib/utils';

export function SshKeysPage() {
  const { hasPermission, activeOrganization } = useAuth();
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const canManage = hasPermission('cloud.manage');

  const [createOpen, setCreateOpen] = useState(false);
  const [generateOpen, setGenerateOpen] = useState(false);
  const [unlockFor, setUnlockFor] = useState<SshKeyDto | null>(null);
  const [installFor, setInstallFor] = useState<SshKeyDto | null>(null);
  const [editFor, setEditFor] = useState<SshKeyDto | null>(null);

  const keys = useQuery({
    queryKey: ['cloud', activeOrganization?.id, 'ssh-keys'],
    queryFn: () => api.listSshKeys(),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['cloud', activeOrganization?.id, 'ssh-keys'] });

  const remove = useMutation({
    mutationFn: (key: SshKeyDto) => api.deleteSshKey(key.id),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.cloud.sshKeyDeleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link to="/cloud">
            <Button variant="ghost" size="icon" title={t('cloud.sshKeyBack')}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-white">{t('cloud.sshKeys')}</h1>
            <p className="text-sm text-zinc-400">{t('cloud.sshKeysDescription')}</p>
          </div>
        </div>
        {canManage ? (
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => setGenerateOpen(true)}>
              <Sparkles className="h-4 w-4" /> {t('cloud.sshKeyGenerate')}
            </Button>
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> {t('cloud.newSshKey')}
            </Button>
          </div>
        ) : null}
      </header>

      <Card>
        <CardHeader title={t('cloud.sshKeys')} description={t('cloud.sshKeysDescription')} />
        <div className="p-5">
          {keys.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : keys.data && keys.data.length > 0 ? (
            <ul className="space-y-2">
              {keys.data.map((key) => (
                <li key={key.id} className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-4 py-3">
                  <KeyRound className="h-4 w-4 shrink-0 text-brand-400" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-white">{key.name}</p>
                    <p className="truncate font-mono text-xs text-zinc-500">{key.fingerprint}</p>
                  </div>
                  {key.servers_count > 0 ? (
                    <span
                      className="hidden items-center gap-1 rounded-full border border-zinc-700 bg-raised px-2 py-0.5 text-xs text-zinc-300 sm:inline-flex"
                      title={t('cloud.sshKeyInUseTooltip', { count: key.servers_count })}
                    >
                      <Server className="h-3 w-3" />
                      {key.servers_count === 1
                        ? t('cloud.sshKeyServersCount', { count: key.servers_count })
                        : t('cloud.sshKeyServersCountPlural', { count: key.servers_count })}
                    </span>
                  ) : null}
                  <span className="hidden text-xs text-zinc-600 sm:block">{formatDate(key.created_at)}</span>
                  {key.has_private_key ? (
                    <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-300">
                      <Lock className="h-3 w-3" /> {t('cloud.sshKeyVaulted')}
                    </span>
                  ) : null}
                  {canManage ? (
                    <div className="flex shrink-0 gap-1">
                      {key.has_private_key ? (
                        <Button size="sm" variant="ghost" title={t('cloud.sshKeyUnlock')} onClick={() => setUnlockFor(key)}>
                          <LockOpen className="h-3.5 w-3.5" />
                        </Button>
                      ) : null}
                      <Button size="sm" variant="ghost" title={t('cloud.sshKeyInstall')} onClick={() => setInstallFor(key)}>
                        <Server className="h-3.5 w-3.5" />
                      </Button>
                      <Button size="sm" variant="ghost" title={t('cloud.sshKeyRename')} onClick={() => setEditFor(key)}>
                        <Pencil className="h-3.5 w-3.5" />
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        title={key.servers_count > 0 ? t('cloud.sshKeyInUseTooltip', { count: key.servers_count }) : t('common.delete')}
                        disabled={key.servers_count > 0}
                        loading={remove.isPending && remove.variables?.id === key.id}
                        onClick={() => remove.mutate(key)}
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState
              title={t('cloud.sshKeysEmpty')}
              description={canManage ? t('cloud.sshKeysEmptyDescription') : undefined}
            />
          )}
        </div>
      </Card>

      <CreateKeyDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => {
          setCreateOpen(false);
          void invalidate();
          toast(t('toast.cloud.sshKeyCreated'));
        }}
      />

      <GenerateKeyDialog
        open={generateOpen}
        onClose={() => setGenerateOpen(false)}
        onGenerated={(result) => {
          setGenerateOpen(false);
          void invalidate();
          downloadPrivateKey(result);
          toast(result.data.has_private_key ? t('cloud.sshKeyGenerated') : t('cloud.sshKeyGenerated'));
        }}
      />

      {unlockFor ? (
        <UnlockKeyDialog
          key={unlockFor.id}
          sshKey={unlockFor}
          onClose={() => setUnlockFor(null)}
          onUnlocked={(privateKey) => {
            setUnlockFor(null);
            downloadPrivateKey({ data: unlockFor, private_key: privateKey });
            toast(t('cloud.sshKeyUnlocked'));
          }}
        />
      ) : null}

      {installFor ? (
        <InstallKeyDialog
          key={installFor.id}
          sshKey={installFor}
          onClose={() => setInstallFor(null)}
          onInstalled={() => {
            setInstallFor(null);
            void invalidate();
          }}
        />
      ) : null}

      {editFor ? (
        <RenameKeyDialog
          key={editFor.id}
          sshKey={editFor}
          onClose={() => setEditFor(null)}
          onSaved={() => {
            setEditFor(null);
            void invalidate();
            toast(t('toast.cloud.sshKeyUpdated'));
          }}
        />
      ) : null}
    </div>
  );
}

function CreateKeyDialog({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [publicKey, setPublicKey] = useState('');
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: () => api.createSshKey({ name, public_key: publicKey }),
    onSuccess: onCreated,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    create.mutate();
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('cloud.sshKeyCreateTitle')}
      description={t('cloud.sshKeyCreateDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.sshKeyName')} htmlFor="ssh-key-name">
          <Input id="ssh-key-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Laptop" required autoFocus />
        </Field>
        <Field label={t('cloud.sshKeyPublicKey')} htmlFor="ssh-key-public">
          <Textarea
            id="ssh-key-public"
            value={publicKey}
            onChange={(e) => setPublicKey(e.target.value)}
            rows={4}
            placeholder={t('cloud.sshKeyPublicKeyPlaceholder')}
            className="font-mono text-xs"
            required
          />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={create.isPending} className="w-full">
          {t('cloud.sshKeyCreate')}
        </Button>
      </form>
    </Dialog>
  );
}

function downloadPrivateKey(result: SshKeyGenerateResponse): void {
  const slug = result.data.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  const filename = `${slug}_id_${result.data.public_key.startsWith('ssh-rsa') ? 'rsa' : 'ed25519'}`;
  const blob = new Blob([result.private_key], { type: 'application/octet-stream' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

function GenerateKeyDialog({
  open,
  onClose,
  onGenerated,
}: {
  open: boolean;
  onClose: () => void;
  onGenerated: (result: SshKeyGenerateResponse) => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [type, setType] = useState<'ed25519' | 'rsa'>('ed25519');
  const [useVault, setUseVault] = useState(false);
  const [vaultPassword, setVaultPassword] = useState('');
  const [vaultConfirm, setVaultConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);

  const generate = useMutation({
    mutationFn: () =>
      api.generateSshKey({
        name,
        type,
        vault_password: useVault && vaultPassword !== '' ? vaultPassword : undefined,
      }),
    onSuccess: onGenerated,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if (useVault && vaultPassword !== vaultConfirm) {
      setError(t('cloud.sshKeyVaultMismatch'));
      return;
    }
    generate.mutate();
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('cloud.sshKeyGenerateTitle')}
      description={t('cloud.sshKeyGenerateDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.sshKeyName')} htmlFor="ssh-generate-name">
          <Input id="ssh-generate-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Deploy bot" required autoFocus />
        </Field>
        <Field label={t('cloud.sshKeyGenerateType')} htmlFor="ssh-generate-type">
          <Select id="ssh-generate-type" value={type} onChange={(e) => setType(e.target.value as 'ed25519' | 'rsa')}>
            <option value="ed25519">{t('cloud.sshKeyGenerateEd25519')}</option>
            <option value="rsa">{t('cloud.sshKeyGenerateRsa')}</option>
          </Select>
        </Field>
        <label className="flex items-start gap-3 rounded-lg border border-edge bg-raised p-3">
          <input
            type="checkbox"
            checked={useVault}
            onChange={(e) => setUseVault(e.target.checked)}
            className="mt-0.5 h-4 w-4 rounded border-zinc-600 bg-raised text-brand-500"
          />
          <span className="text-sm">
            <span className="font-medium text-white">{t('cloud.sshKeyVaultToggle')}</span>
            <span className="mt-0.5 block text-xs text-zinc-400">{t('cloud.sshKeyVaultDescription')}</span>
          </span>
        </label>
        {useVault ? (
          <>
            <Field label={t('cloud.sshKeyVaultPassword')} htmlFor="ssh-generate-vault-pass">
              <Input
                id="ssh-generate-vault-pass"
                type="password"
                value={vaultPassword}
                onChange={(e) => setVaultPassword(e.target.value)}
                placeholder="••••••••"
                required
                minLength={8}
              />
            </Field>
            <Field label={t('cloud.sshKeyVaultConfirm')} htmlFor="ssh-generate-vault-confirm">
              <Input
                id="ssh-generate-vault-confirm"
                type="password"
                value={vaultConfirm}
                onChange={(e) => setVaultConfirm(e.target.value)}
                placeholder="••••••••"
                required
                minLength={8}
              />
            </Field>
          </>
        ) : null}
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200">
          {t('cloud.sshKeyGeneratedWarning')}
        </div>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={generate.isPending} className="w-full">
          <Sparkles className="h-4 w-4" /> {t('cloud.sshKeyGenerateSubmit')}
        </Button>
      </form>
    </Dialog>
  );
}

function UnlockKeyDialog({
  sshKey,
  onClose,
  onUnlocked,
}: {
  sshKey: SshKeyDto;
  onClose: () => void;
  onUnlocked: (privateKey: string) => void;
}) {
  const { t } = useI18n();
  const [vaultPassword, setVaultPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const unlock = useMutation({
    mutationFn: () => api.unlockSshKey(sshKey.id, vaultPassword),
    onSuccess: (result) => onUnlocked(result.private_key),
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    unlock.mutate();
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={t('cloud.sshKeyUnlockTitle')}
      description={t('cloud.sshKeyUnlockDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <p className="break-all font-mono text-xs text-zinc-500">{sshKey.name} · {sshKey.fingerprint}</p>
        <Field label={t('cloud.sshKeyVaultPassword')} htmlFor="ssh-unlock-pass">
          <Input
            id="ssh-unlock-pass"
            type="password"
            value={vaultPassword}
            onChange={(e) => setVaultPassword(e.target.value)}
            placeholder="••••••••"
            required
            autoFocus
          />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={unlock.isPending} className="w-full">
          <LockOpen className="h-4 w-4" /> {t('cloud.sshKeyUnlockSubmit')}
        </Button>
      </form>
    </Dialog>
  );
}

function InstallKeyDialog({
  sshKey,
  onClose,
  onInstalled,
}: {
  sshKey: SshKeyDto;
  onClose: () => void;
  onInstalled: () => void;
}) {
  const { t } = useI18n();
  const { toast } = useToast();
  const { activeOrganization } = useAuth();
  const [serverId, setServerId] = useState('');
  const [error, setError] = useState<string | null>(null);

  const servers = useQuery({
    queryKey: ['cloud', activeOrganization?.id],
    queryFn: () => api.listServers(),
    enabled: !!activeOrganization?.id,
  });

  const install = useMutation({
    mutationFn: () => api.installServerSshKey(serverId, sshKey.id),
    onSuccess: (result) => {
      onInstalled();
      if (result.status === 'unsupported') {
        toast(t('cloud.sshKeyInstallUnsupported'), 'error');
      } else {
        toast(t('cloud.sshKeyInstalled'));
      }
    },
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if (!serverId) return;
    install.mutate();
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={t('cloud.sshKeyInstallTitle', { name: sshKey.name })}
      description={t('cloud.sshKeyInstallDescription')}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.server')} htmlFor="ssh-install-server">
          <Select id="ssh-install-server" value={serverId} onChange={(e) => setServerId(e.target.value)} required>
            <option value="">{t('common.select')}</option>
            {(servers.data ?? []).map((server) => (
              <option key={server.id} value={server.id}>{server.name} — {server.region}</option>
            ))}
          </Select>
        </Field>
        {(servers.data ?? []).length === 0 ? (
          <p className="text-sm text-zinc-500">{t('cloud.sshKeyInstallNoServers')}</p>
        ) : null}
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={install.isPending} disabled={(servers.data ?? []).length === 0} className="w-full">
          <Server className="h-4 w-4" /> {t('cloud.sshKeyInstallSubmit')}
        </Button>
      </form>
    </Dialog>
  );
}

function RenameKeyDialog({
  sshKey,
  onClose,
  onSaved,
}: {
  sshKey: SshKeyDto;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(sshKey.name);
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => api.updateSshKey(sshKey.id, { name }),
    onSuccess: onSaved,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    save.mutate();
  }

  return (
    <Dialog open onClose={onClose} title={t('cloud.sshKeyEditTitle', { name: sshKey.name })}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('cloud.sshKeyName')} htmlFor="ssh-key-edit-name">
          <Input id="ssh-key-edit-name" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        <p className="break-all font-mono text-xs text-zinc-500">{sshKey.fingerprint}</p>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={save.isPending} className="w-full">
          {t('common.save')}
        </Button>
      </form>
    </Dialog>
  );
}
