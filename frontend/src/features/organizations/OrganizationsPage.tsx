import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Building2, Check, Plus, SwitchCamera } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { Card, CardHeader } from '../../components/ui/Card';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';

export function OrganizationsPage() {
  const { memberships, activeOrganization, switchOrganization, acceptInvitation, pendingInvitations, refresh } =
    useAuth();
  const { t } = useI18n();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const create = useMutation({
    mutationFn: () => api.createOrganization(name),
    onSuccess: async () => {
      toast(t('toast.org.created'));
      setName('');
      await refresh();
      await queryClient.invalidateQueries();
    },
    onError: (err) => setError(errorMessage(err)),
  });

  const accept = useMutation({
    mutationFn: (token: string) => acceptInvitation(token),
    onSuccess: () => {
      toast(t('toast.org.invitationAccepted'));
      void queryClient.invalidateQueries();
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setCreating(true);
    try {
      await create.mutateAsync();
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('org.title')}</h1>
        <p className="text-sm text-zinc-400">{t('org.subtitle')}</p>
      </header>

      <Card>
        <CardHeader title={t('org.create')} description={t('org.createDescription')} />
        <form onSubmit={onSubmit} className="flex items-end gap-3 p-5">
          <div className="flex-1">
            <Field label={t('org.name')} htmlFor="org-name" error={error}>
              <Input
                id="org-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Acme Inc"
                required
              />
            </Field>
          </div>
          <Button type="submit" loading={creating}>
            <Plus className="h-4 w-4" /> {t('org.createBtn')}
          </Button>
        </form>
      </Card>

      <Card>
        <CardHeader title={t('org.yourOrganizations')} description={t('org.switchDescription')} />
        <ul className="divide-y divide-edge">
          {memberships.map((membership) => {
            const org = membership.organization;
            const active = org?.id === activeOrganization?.id;
            return (
              <li key={membership.id} className="flex items-center justify-between px-5 py-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-md bg-raised">
                    <Building2 className="h-4 w-4 text-brand-400" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-white">{org?.name}</p>
                    <p className="text-xs text-zinc-500">
                      {org?.slug} · {org?.plan_tier}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={active ? 'brand' : 'neutral'}>{membership.role?.name}</Badge>
                  {active ? (
                    <Badge tone="success">
                      <Check className="mr-1 h-3 w-3" /> {t('org.active')}
                    </Badge>
                  ) : (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => void switchOrganization(org?.id ?? '')}
                    >
                      <SwitchCamera className="h-3.5 w-3.5" /> {t('org.switch')}
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      </Card>

      {pendingInvitations.length > 0 ? (
        <Card>
          <CardHeader title={t('org.pendingInvitations')} description="" />
          <ul className="divide-y divide-edge">
            {pendingInvitations.map((invitation) => (
              <li key={invitation.id} className="flex items-center justify-between px-5 py-4">
                <div>
                  <p className="text-sm font-medium text-white">{invitation.organization?.name}</p>
                  <p className="text-xs text-zinc-500">
                    {t('org.invitedAs', { role: invitation.role?.name ?? '' })}
                  </p>
                </div>
                <Button
                  size="sm"
                  onClick={() => void accept.mutate(invitation.id)}
                  loading={accept.isPending}
                >
                  {t('org.accept')}
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}
