import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ArrowLeft, Download, FileText, Folder, FolderPlus, History, RotateCcw, Trash2, UploadCloud } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import type { DriveFileDto } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Textarea } from '../../components/ui/Textarea';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatBytes } from '../../lib/utils';

interface Crumb {
  id: string;
  name: string;
}

export function StoragePage() {
  const { hasPermission, activeOrganization } = useAuth();
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const canManage = hasPermission('storage.manage');

  const [path, setPath] = useState<Crumb[]>([]);
  const [folderOpen, setFolderOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [trashOpen, setTrashOpen] = useState(false);
  const [versionsFor, setVersionsFor] = useState<DriveFileDto | null>(null);

  const currentFolderId = path.length > 0 ? path[path.length - 1].id : undefined;

  const listing = useQuery({
    queryKey: ['drive', activeOrganization?.id, currentFolderId],
    queryFn: () => api.listDrive(currentFolderId),
    enabled: !!activeOrganization?.id,
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['drive', activeOrganization?.id] });

  const download = useMutation({
    mutationFn: (file: DriveFileDto) => api.downloadFile(file.id),
    onSuccess: (data) => window.open(data.url, '_blank', 'noopener,noreferrer'),
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const trashFile = useMutation({
    mutationFn: (file: DriveFileDto) => api.trashFile(file.id),
    onSuccess: () => {
      void invalidate();
      toast(t('toast.storage.trashed'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const used = listing.data?.quota.used ?? 0;
  const limit = listing.data?.quota.limit ?? 0;
  const quotaPercent = limit > 0 ? Math.min(100, (used / limit) * 100) : 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('storage.title')}</h1>
          <p className="text-sm text-zinc-400">{t('storage.subtitle', { name: activeOrganization?.name ?? '' })}</p>
        </div>
        {canManage ? (
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => setFolderOpen(true)}>
              <FolderPlus className="h-4 w-4" /> {t('storage.newFolder')}
            </Button>
            <Button onClick={() => setUploadOpen(true)}>
              <UploadCloud className="h-4 w-4" /> {t('storage.upload')}
            </Button>
            <Button variant="ghost" onClick={() => setTrashOpen(true)}>
              <Trash2 className="h-4 w-4" /> {t('storage.trash')}
            </Button>
          </div>
        ) : null}
      </header>

      <Card>
        <CardHeader title={t('storage.quota')} description={t('storage.quotaDescription')} />
        <div className="px-5 pb-5">
          <div className="h-2 w-full overflow-hidden rounded-full bg-raised">
            <div
              className="h-full rounded-full bg-brand-500 transition-all"
              style={{ width: `${quotaPercent}%` }}
            />
          </div>
          <p className="mt-2 text-xs text-zinc-500">
            {formatBytes(used)} / {limit > 0 ? formatBytes(limit) : '∞'}
          </p>
        </div>
      </Card>

      <Card>
        <div className="flex items-center gap-2 border-b border-edge px-5 py-3">
          <button
            onClick={() => setPath([])}
            className={cn(
              'text-sm font-medium transition-colors hover:text-white',
              path.length === 0 ? 'text-white' : 'text-zinc-400',
            )}
          >
            {t('storage.root')}
          </button>
          {path.map((crumb, index) => (
            <span key={crumb.id} className="flex items-center gap-2">
              <span className="text-zinc-600">/</span>
              <button
                onClick={() => setPath(path.slice(0, index + 1))}
                className={cn(
                  'text-sm font-medium transition-colors hover:text-white',
                  index === path.length - 1 ? 'text-white' : 'text-zinc-400',
                )}
              >
                {crumb.name}
              </button>
            </span>
          ))}
          {path.length > 0 ? (
            <button
              onClick={() => setPath(path.slice(0, -1))}
              className="ml-auto flex items-center gap-1 text-xs text-zinc-400 hover:text-white"
            >
              <ArrowLeft className="h-3.5 w-3.5" /> {t('storage.back')}
            </button>
          ) : null}
        </div>

        <div className="p-5">
          {listing.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : listing.data && (listing.data.folders.length > 0 || listing.data.files.length > 0) ? (
            <div className="space-y-4">
              {listing.data.folders.length > 0 ? (
                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">{t('storage.folders')}</p>
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {listing.data.folders.map((folder) => (
                      <button
                        key={folder.id}
                        onClick={() => setPath([...path, { id: folder.id, name: folder.name }])}
                        className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5 text-left transition-colors hover:border-brand-700"
                      >
                        <Folder className="h-4 w-4 shrink-0 text-brand-400" />
                        <span className="truncate text-sm text-white">{folder.name}</span>
                      </button>
                    ))}
                  </div>
                </div>
              ) : null}

              {listing.data.files.length > 0 ? (
                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">{t('storage.files')}</p>
                  <ul className="space-y-2">
                    {listing.data.files.map((file) => (
                      <li
                        key={file.id}
                        className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5"
                      >
                        <FileText className="h-4 w-4 shrink-0 text-zinc-400" />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium text-white">{file.name}</p>
                          <p className="text-xs text-zinc-500">
                            {formatBytes(file.size)} · {t('storage.versionN', { n: file.version })}
                          </p>
                        </div>
                        {canManage ? (
                          <div className="flex items-center gap-1">
                            <Button size="sm" variant="ghost" onClick={() => setVersionsFor(file)} title={t('storage.versions')}>
                              <History className="h-4 w-4" />
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => download.mutate(file)} title={t('storage.download')}>
                              <Download className="h-4 w-4" />
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => trashFile.mutate(file)} title={t('storage.delete')}>
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        ) : (
                          <Button size="sm" variant="ghost" onClick={() => download.mutate(file)} title={t('storage.download')}>
                            <Download className="h-4 w-4" />
                          </Button>
                        )}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </div>
          ) : (
            <EmptyState
              title={t('storage.empty')}
              description={canManage ? t('storage.emptyDescription') : undefined}
            />
          )}
        </div>
      </Card>

      <CreateFolderDialog
        open={folderOpen}
        onClose={() => setFolderOpen(false)}
        parentId={currentFolderId ?? null}
        onCreated={() => {
          setFolderOpen(false);
          void invalidate();
          toast(t('toast.storage.folderCreated'));
        }}
      />

      <UploadDialog
        open={uploadOpen}
        onClose={() => setUploadOpen(false)}
        folderId={currentFolderId ?? null}
        onUploaded={() => {
          setUploadOpen(false);
          void invalidate();
          toast(t('toast.storage.uploaded'));
        }}
      />

      <VersionsDialog
        file={versionsFor}
        onClose={() => setVersionsFor(null)}
        onRestored={() => {
          void invalidate();
          toast(t('toast.storage.versionRestored'));
        }}
      />

      <TrashDialog
        open={trashOpen}
        onClose={() => setTrashOpen(false)}
        onChanged={() => {
          void invalidate();
        }}
      />
    </div>
  );
}

function CreateFolderDialog({
  open,
  onClose,
  parentId,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  parentId: string | null;
  onCreated: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: () => api.createFolder(parentId, name),
    onSuccess: onCreated,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    create.mutate();
  }

  return (
    <Dialog open={open} onClose={onClose} title={t('storage.folderTitle')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('storage.folderName')} htmlFor="folder-name">
          <Input id="folder-name" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={create.isPending} className="w-full">
          {t('storage.create')}
        </Button>
      </form>
    </Dialog>
  );
}

function UploadDialog({
  open,
  onClose,
  folderId,
  onUploaded,
}: {
  open: boolean;
  onClose: () => void;
  folderId: string | null;
  onUploaded: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState('');
  const [content, setContent] = useState('');
  const [error, setError] = useState<string | null>(null);

  const upload = useMutation({
    mutationFn: () => api.uploadFile(folderId, name, content, 'text/plain'),
    onSuccess: onUploaded,
    onError: (err) => setError(errorMessage(err)),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    upload.mutate();
  }

  return (
    <Dialog open={open} onClose={onClose} title={t('storage.uploadTitle')} description={t('storage.uploadDescription')}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t('storage.fileName')} htmlFor="file-name">
          <Input id="file-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="notes.txt" required autoFocus />
        </Field>
        <Field label={t('storage.content')} htmlFor="file-content">
          <Textarea id="file-content" value={content} onChange={(e) => setContent(e.target.value)} rows={5} required />
        </Field>
        {error ? <p className="text-sm text-red-400">{error}</p> : null}
        <Button type="submit" loading={upload.isPending} className="w-full">
          <UploadCloud className="h-4 w-4" /> {t('storage.upload')}
        </Button>
      </form>
    </Dialog>
  );
}

function VersionsDialog({
  file,
  onClose,
  onRestored,
}: {
  file: DriveFileDto | null;
  onClose: () => void;
  onRestored: () => void;
}) {
  const { t } = useI18n();
  const { toast } = useToast();

  const versions = useQuery({
    queryKey: ['drive-versions', file?.id],
    queryFn: () => api.listFileVersions(file!.id),
    enabled: !!file,
  });

  const restore = useMutation({
    mutationFn: (versionId: string) => api.restoreFileVersion(file!.id, versionId),
    onSuccess: onRestored,
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <Dialog
      open={!!file}
      onClose={onClose}
      title={t('storage.versionTitle', { name: file?.name ?? '' })}
    >
      {versions.isLoading ? (
        <div className="flex justify-center py-6">
          <Spinner />
        </div>
      ) : versions.data && versions.data.length > 0 ? (
        <ul className="space-y-2">
          {versions.data.map((version) => (
            <li key={version.id} className="flex items-center justify-between gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5">
              <div>
                <p className="text-sm font-medium text-white">{t('storage.versionN', { n: version.version })}</p>
                <p className="text-xs text-zinc-500">{formatBytes(version.size)}</p>
              </div>
              {version.version !== file?.version ? (
                <Button size="sm" variant="outline" loading={restore.isPending && restore.variables === version.id} onClick={() => restore.mutate(version.id)}>
                  <RotateCcw className="h-3.5 w-3.5" /> {t('storage.restore')}
                </Button>
              ) : (
                <Badge tone="success">{t('storage.current')}</Badge>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-zinc-500">{t('storage.noVersions')}</p>
      )}
    </Dialog>
  );
}

function TrashDialog({ open, onClose, onChanged }: { open: boolean; onClose: () => void; onChanged: () => void }) {
  const { t } = useI18n();
  const { toast } = useToast();

  const trash = useQuery({
    queryKey: ['drive-trash'],
    queryFn: () => api.listDriveTrash(),
    enabled: open,
  });

  const restore = useMutation({
    mutationFn: (fileId: string) => api.restoreFile(fileId),
    onSuccess: () => {
      onChanged();
      toast(t('toast.storage.restored'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const remove = useMutation({
    mutationFn: (fileId: string) => api.deleteFile(fileId),
    onSuccess: () => {
      onChanged();
      toast(t('toast.storage.deleted'));
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  return (
    <Dialog open={open} onClose={onClose} title={t('storage.trashTitle')}>
      {trash.isLoading ? (
        <div className="flex justify-center py-6">
          <Spinner />
        </div>
      ) : trash.data && trash.data.length > 0 ? (
        <ul className="space-y-2">
          {trash.data.map((file) => (
            <li key={file.id} className="flex items-center gap-3 rounded-lg border border-edge bg-raised px-3 py-2.5">
              <FileText className="h-4 w-4 shrink-0 text-zinc-400" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-white">{file.name}</p>
                <p className="text-xs text-zinc-500">{formatBytes(file.size)}</p>
              </div>
              <Button size="sm" variant="outline" onClick={() => restore.mutate(file.id)}>
                {t('storage.restore')}
              </Button>
              <Button size="sm" variant="danger" onClick={() => remove.mutate(file.id)}>
                <Trash2 className="h-3.5 w-3.5" />
              </Button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-zinc-500">{t('storage.trashEmpty')}</p>
      )}
    </Dialog>
  );
}
