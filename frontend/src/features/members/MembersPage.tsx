import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Trash2, UserPlus } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { formatDate, initials } from '../../lib/utils';

export function MembersPage() {
  const { activeOrganization, hasPermission } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const canManage = hasPermission('members.manage');
  const canInvite = hasPermission('organizations.invite');

  const members = useQuery({
    queryKey: ['members', orgId],
    queryFn: () => api.listMembers(orgId!),
    enabled: !!orgId,
  });
  const invitations = useQuery({
    queryKey: ['invitations', orgId],
    queryFn: () => api.listInvitations(orgId!),
    enabled: !!orgId && canInvite,
  });
  const roles = useQuery({
    queryKey: ['roles'],
    queryFn: () => api.listRoles(),
    enabled: !!orgId,
  });

  const [email, setEmail] = useState('');
  const [roleId, setRoleId] = useState('role-viewer');
  const [inviteError, setInviteError] = useState<string | null>(null);

  const updateRole = useMutation({
    mutationFn: ({ membershipId, newRoleId }: { membershipId: string; newRoleId: string }) =>
      api.updateMemberRole(orgId!, membershipId, newRoleId),
    onSuccess: () => {
      toast(t('toast.members.roleUpdated'));
      void queryClient.invalidateQueries({ queryKey: ['members', orgId] });
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const remove = useMutation({
    mutationFn: (membershipId: string) => api.removeMember(orgId!, membershipId),
    onSuccess: () => {
      toast(t('toast.members.memberRemoved'));
      void queryClient.invalidateQueries({ queryKey: ['members', orgId] });
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  const invite = useMutation({
    mutationFn: () => api.createInvitation(orgId!, email, roleId),
    onSuccess: () => {
      toast(t('toast.members.invitationSent'));
      setEmail('');
      void queryClient.invalidateQueries({ queryKey: ['invitations', orgId] });
    },
    onError: (err) => setInviteError(errorMessage(err)),
  });

  const cancelInvitation = useMutation({
    mutationFn: (invitationId: string) => api.cancelInvitation(orgId!, invitationId),
    onSuccess: () => {
      toast(t('toast.members.invitationCancelled'));
      void queryClient.invalidateQueries({ queryKey: ['invitations', orgId] });
    },
    onError: (err) => toast(errorMessage(err), 'error'),
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setInviteError(null);
    void invite.mutateAsync();
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('members.title')}</h1>
        <p className="text-sm text-zinc-400">{t('members.subtitle', { name: activeOrganization?.name ?? '' })}</p>
      </header>

      {canInvite ? (
        <Card>
          <CardHeader title={t('members.inviteMember')} description={t('members.inviteDescription')} />
          <form onSubmit={onSubmit} className="flex items-end gap-3 p-5">
            <div className="flex-1">
              <Field label={t('members.email')} htmlFor="invite-email" error={inviteError}>
                <Input
                  id="invite-email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="person@example.com"
                  required
                />
              </Field>
            </div>
            <div className="w-40">
              <Field label={t('members.role')} htmlFor="invite-role">
                <Select id="invite-role" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
                  {(roles.data ?? []).map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.name}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>
            <Button type="submit" loading={invite.isPending}>
              <UserPlus className="h-4 w-4" /> {t('common.invite')}
            </Button>
          </form>
        </Card>
      ) : null}

      <Card>
        <CardHeader title={t('members.title')} />
        <div className="p-5">
          {members.isLoading ? (
            <Spinner />
          ) : members.data && members.data.length > 0 ? (
            <ul className="divide-y divide-edge">
              {members.data.map((membership) => (
                <li key={membership.id} className="flex items-center gap-3 py-3">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-700 text-xs font-bold text-zinc-950">
                    {initials(membership.user?.name ?? '?')}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-white">{membership.user?.name}</p>
                    <p className="truncate text-xs text-zinc-500">{membership.user?.email}</p>
                  </div>
                  <div className="text-xs text-zinc-500">
                    {formatDate(membership.joined_at)}
                  </div>
                  {canManage ? (
                    <div className="w-36">
                      <Select
                        aria-label={t('members.roleFor', { name: membership.user?.name ?? '' })}
                        value={membership.role?.id ?? ''}
                        onChange={(e) =>
                          updateRole.mutate({ membershipId: membership.id, newRoleId: e.target.value })
                        }
                      >
                        {(roles.data ?? []).map((role) => (
                          <option key={role.id} value={role.id}>
                            {role.name}
                          </option>
                        ))}
                      </Select>
                    </div>
                  ) : (
                    <span className="w-36 text-right text-xs text-zinc-400">{membership.role?.name}</span>
                  )}
                  {canManage ? (
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={t('members.remove', { name: membership.user?.name ?? '' })}
                      onClick={() => {
                        if (window.confirm(t('members.removeConfirm', { name: membership.user?.name ?? '' }))) {
                          remove.mutate(membership.id);
                        }
                      }}
                    >
                      <Trash2 className="h-4 w-4 text-red-400" />
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState title={t('members.noMembers')} />
          )}
        </div>
      </Card>

      {canInvite && invitations.data && invitations.data.length > 0 ? (
        <Card>
          <CardHeader title={t('members.pendingInvitations')} />
          <ul className="divide-y divide-edge">
            {invitations.data.map((invitation) => (
              <li key={invitation.id} className="flex items-center justify-between px-5 py-3">
                <div>
                  <p className="text-sm text-white">{invitation.email}</p>
                  <p className="text-xs text-zinc-500">
                    {invitation.role?.name} · {t('members.expires', { date: formatDate(invitation.expires_at) })}
                  </p>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => cancelInvitation.mutate(invitation.id)}
                >
                  {t('common.cancel')}
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}
