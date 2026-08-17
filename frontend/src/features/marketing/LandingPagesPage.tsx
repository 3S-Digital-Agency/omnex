import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Megaphone, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import type { LandingPageDto, LandingPageInput, LandingPageType } from '../../lib/api/types';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { formatDate } from '../../lib/utils';

const SLUG_REGEX = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function typeLabel(t: (key: string) => string, type: string): string {
  return t(`landing.type.${type}`);
}

export function LandingPagesPage() {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [editor, setEditor] = useState<LandingPageDto | 'new' | null>(null);

  const pages = useQuery({ queryKey: ['marketing', 'landing-pages'], queryFn: () => api.listLandingPages() });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['marketing', 'landing-pages'] });
  };

  const toggleStatus = useMutation({
    mutationFn: (page: LandingPageDto) =>
      api.updateLandingPage(page.id, {
        slug: page.slug,
        type: page.type,
        status: page.status === 'published' ? 'draft' : 'published',
        content_en: page.content_en,
        content_fr: page.content_fr,
      }),
    onSuccess: () => {
      invalidate();
      toast(t('landing.toast.status'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const remove = useMutation({
    mutationFn: (id: string) => api.deleteLandingPage(id),
    onSuccess: () => {
      invalidate();
      toast(t('landing.toast.deleted'), 'success');
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  if (editor) {
    return (
      <LandingPageEditor
        page={editor === 'new' ? null : editor}
        onBack={() => setEditor(null)}
        onSaved={() => {
          setEditor(null);
          invalidate();
        }}
      />
    );
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-edge bg-panel">
          <Megaphone className="h-6 w-6 text-brand-400" />
        </div>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-white">{t('landing.title')}</h1>
          <p className="text-sm text-zinc-400">{t('landing.subtitle')}</p>
        </div>
        <Button onClick={() => setEditor('new')}>
          <Plus className="mr-1.5 h-4 w-4" />
          {t('landing.new')}
        </Button>
      </header>

      <Card>
        <CardHeader title={t('landing.title')} description={t('landing.subtitle')} />
        {pages.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : pages.data && pages.data.length > 0 ? (
          <ul className="divide-y divide-edge">
            {pages.data.map((page) => (
              <li key={page.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold text-white">/{page.slug}</p>
                    <Badge tone="brand">{typeLabel(t, page.type)}</Badge>
                    <Badge tone={page.status === 'published' ? 'success' : 'neutral'}>
                      {page.status === 'published' ? t('landing.status.published') : t('landing.status.draft')}
                    </Badge>
                  </div>
                  <p className="mt-0.5 text-xs text-zinc-500">
                    {t('landing.updated')} {formatDate(page.updated_at)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <Link to={`/landing/${page.slug}`} target="_blank" rel="noopener noreferrer">
                    <Button variant="ghost" size="sm">
                      <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                      {t('landing.view')}
                    </Button>
                  </Link>
                  <Button variant="outline" size="sm" onClick={() => setEditor(page)}>
                    <Pencil className="mr-1.5 h-3.5 w-3.5" />
                    {t('landing.edit')}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    loading={toggleStatus.isPending}
                    onClick={() => toggleStatus.mutate(page)}
                  >
                    {page.status === 'published' ? t('landing.unpublish') : t('landing.publish')}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="text-red-400 hover:text-red-300"
                    loading={remove.isPending}
                    onClick={() => remove.mutate(page.id)}
                  >
                    <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                    {t('landing.delete')}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <div className="p-5">
            <EmptyState title={t('landing.empty')} description={t('landing.emptyDescription')} />
          </div>
        )}
      </Card>
    </div>
  );
}

function LandingPageEditor({
  page,
  onBack,
  onSaved,
}: {
  page: LandingPageDto | null;
  onBack: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const { toast } = useToast();
  const [slug, setSlug] = useState(page?.slug ?? '');
  const [type, setType] = useState<LandingPageType>(page?.type ?? 'offer');
  const [status, setStatus] = useState<'draft' | 'published'>(page?.status ?? 'draft');
  const [contentEn, setContentEn] = useState(() => JSON.stringify(page?.content_en ?? [], null, 2));
  const [contentFr, setContentFr] = useState(() => JSON.stringify(page?.content_fr ?? [], null, 2));
  const [jsonError, setJsonError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: (input: LandingPageInput) =>
      page ? api.updateLandingPage(page.id, input) : api.createLandingPage(input),
    onSuccess: () => {
      toast(page ? t('landing.toast.updated') : t('landing.toast.created'), 'success');
      onSaved();
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const submit = () => {
    if (!SLUG_REGEX.test(slug)) {
      setJsonError(t('landing.slugError'));
      return;
    }
    let parsedEn: unknown;
    let parsedFr: unknown;
    try {
      parsedEn = JSON.parse(contentEn);
      parsedFr = JSON.parse(contentFr);
    } catch {
      setJsonError(t('landing.jsonError'));
      return;
    }
    if (!Array.isArray(parsedEn) || !Array.isArray(parsedFr)) {
      setJsonError(t('landing.jsonError'));
      return;
    }
    setJsonError(null);
    save.mutate({
      slug,
      type,
      status,
      content_en: parsedEn as LandingPageInput['content_en'],
      content_fr: parsedFr as LandingPageInput['content_fr'],
    });
  };

  const inputClass =
    'h-9 rounded-md border border-edge bg-panel px-2 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:ring-1 focus:ring-brand-500';

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header className="flex items-center gap-3">
        <Button variant="ghost" size="sm" onClick={onBack}>
          ← {t('landing.back')}
        </Button>
        <div>
          <h1 className="text-2xl font-bold text-white">
            {page ? t('landing.editorEdit') : t('landing.editorNew')}
          </h1>
          <p className="text-sm text-zinc-400">/landing/{slug || '…'}</p>
        </div>
      </header>

      <Card>
        <CardHeader title={t('landing.editorEdit')} description={t('landing.subtitle')} />
        <div className="grid gap-4 px-5 py-5 sm:grid-cols-3">
          <label className="flex flex-col gap-1 text-xs text-zinc-500">
            {t('landing.slug')}
            <input
              value={slug}
              onChange={(event) => setSlug(event.target.value)}
              placeholder="launch-offer"
              className={inputClass}
            />
            <span className="text-[11px] text-zinc-600">{t('landing.slugHint')}</span>
          </label>
          <label className="flex flex-col gap-1 text-xs text-zinc-500">
            {t('landing.type')}
            <select
              value={type}
              onChange={(event) => setType(event.target.value as LandingPageType)}
              className={inputClass}
            >
              <option value="offer">{t('landing.type.offer')}</option>
              <option value="promo">{t('landing.type.promo')}</option>
              <option value="comparison">{t('landing.type.comparison')}</option>
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs text-zinc-500">
            {t('landing.status')}
            <select
              value={status}
              onChange={(event) => setStatus(event.target.value as 'draft' | 'published')}
              className={inputClass}
            >
              <option value="draft">{t('landing.status.draft')}</option>
              <option value="published">{t('landing.status.published')}</option>
            </select>
          </label>
        </div>

        <div className="grid gap-4 px-5 pb-5 lg:grid-cols-2">
          <EditorJsonField
            label={t('landing.contentEn')}
            hint={t('landing.jsonHint')}
            value={contentEn}
            onChange={setContentEn}
          />
          <EditorJsonField
            label={t('landing.contentFr')}
            hint={t('landing.jsonHint')}
            value={contentFr}
            onChange={setContentFr}
          />
        </div>

        {jsonError ? (
          <p className="px-5 pb-4 text-xs font-medium text-red-400">{jsonError}</p>
        ) : null}

        <div className="flex items-center justify-end gap-2 border-t border-edge px-5 py-4">
          <Button variant="ghost" onClick={onBack}>
            {t('landing.cancel')}
          </Button>
          <Button loading={save.isPending} onClick={submit}>
            {t('landing.save')}
          </Button>
        </div>
      </Card>
    </div>
  );
}

function EditorJsonField({
  label,
  hint,
  value,
  onChange,
}: {
  label: string;
  hint: string;
  value: string;
  onChange: (value: string) => void;
}) {
  const valid = (() => {
    try {
      return Array.isArray(JSON.parse(value));
    } catch {
      return false;
    }
  })();

  return (
    <label className="flex flex-col gap-1 text-xs text-zinc-500">
      {label}
      <textarea
        value={value}
        onChange={(event) => onChange(event.target.value)}
        spellCheck={false}
        className="h-72 resize-y rounded-md border border-edge bg-panel p-2 font-mono text-xs text-zinc-200 focus:outline-none focus:ring-1 focus:ring-brand-500"
      />
      <span className={valid ? 'text-[11px] text-emerald-500' : 'text-[11px] text-zinc-600'}>
        {hint}
      </span>
    </label>
  );
}
