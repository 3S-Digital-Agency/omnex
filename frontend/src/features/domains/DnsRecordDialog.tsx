import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { DNS_RECORD_TYPES } from '../../lib/api/types';
import type { DnsRecordDto, DnsRecordInput } from '../../lib/api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { useI18n } from '../../lib/i18n';

export function DnsRecordDialog({
  open,
  onClose,
  initial,
  onSave,
  saving,
}: {
  open: boolean;
  onClose: () => void;
  initial: DnsRecordDto | null;
  onSave: (input: DnsRecordInput) => void;
  saving: boolean;
}) {
  const { t } = useI18n();
  const [type, setType] = useState('A');
  const [name, setName] = useState('@');
  const [content, setContent] = useState('');
  const [ttl, setTtl] = useState('3600');
  const [priority, setPriority] = useState('10');
  const [proxied, setProxied] = useState(false);

  useEffect(() => {
    if (open) {
      setType(initial?.type ?? 'A');
      setName(initial?.name ?? '@');
      setContent(initial?.content ?? '');
      setTtl(String(initial?.ttl ?? 3600));
      setPriority(initial?.priority != null ? String(initial.priority) : '10');
      setProxied(initial?.proxied ?? false);
    }
  }, [open, initial]);

  const needsPriority = type === 'MX' || type === 'SRV';
  const supportsProxy = type === 'A' || type === 'AAAA' || type === 'CNAME';

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    onSave({
      type,
      name: name.trim() === '' ? '@' : name.trim(),
      content: content.trim(),
      ttl: Number(ttl) || 3600,
      priority: needsPriority ? Number(priority) || null : null,
      proxied: supportsProxy ? proxied : false,
    });
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={initial ? t('domains.editRecordTitle') : t('domains.addRecordTitle')}
      description={initial ? undefined : t('domains.addRecordDescription')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" form="dns-record-form" loading={saving}>
            {initial ? t('common.save') : t('common.add')}
          </Button>
        </>
      }
    >
      <form id="dns-record-form" onSubmit={onSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('domains.type')} htmlFor="rr-type">
            <Select id="rr-type" value={type} onChange={(e) => setType(e.target.value)}>
              {DNS_RECORD_TYPES.map((recordType) => (
                <option key={recordType} value={recordType}>
                  {recordType}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('domains.name')} htmlFor="rr-name" hint={t('domains.hintApex')}>
            <Input id="rr-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="@" />
          </Field>
        </div>

        <Field label={t('domains.content')} htmlFor="rr-content">
          <Input
            id="rr-content"
            value={content}
            onChange={(e) => setContent(e.target.value)}
            placeholder={type === 'A' ? '192.0.2.1' : type === 'TXT' ? 'v=spf1 …' : 'value'}
            required
          />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label={t('domains.ttl')} htmlFor="rr-ttl">
            <Input id="rr-ttl" type="number" min={0} value={ttl} onChange={(e) => setTtl(e.target.value)} />
          </Field>
          {needsPriority ? (
            <Field label={t('domains.priority')} htmlFor="rr-priority">
              <Input id="rr-priority" type="number" min={0} value={priority} onChange={(e) => setPriority(e.target.value)} />
            </Field>
          ) : (
            <Field label={t('domains.proxied')} htmlFor="rr-proxied" hint={supportsProxy ? t('domains.proxiedHint') : t('domains.proxiedNotSupported')}>
              <label className="flex h-9 items-center gap-2 text-sm text-zinc-300">
                <input
                  id="rr-proxied"
                  type="checkbox"
                  checked={proxied}
                  disabled={!supportsProxy}
                  onChange={(e) => setProxied(e.target.checked)}
                  className="h-4 w-4 accent-white"
                />
                {supportsProxy ? t('domains.enabled') : t('domains.na')}
              </label>
            </Field>
          )}
        </div>
      </form>
    </Dialog>
  );
}
