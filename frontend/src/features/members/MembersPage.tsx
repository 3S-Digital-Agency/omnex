import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ShieldCheck, ShieldOff, Trash2, UserPlus, Users } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Button } from '../../components/ui/Button';
import { Card, CardHeader } from '../../components/ui/Card';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useToast } from '../../components/ui/Toast';
import { DistributionDonut } from '../../components/viz/DistributionDonut';
import { KpiCard } from '../../components/viz/KpiCard';
import { ProgressBar } from '../../components/viz/ProgressBar';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { cn, formatDate, initials } from '../../lib/utils';
import type { InvitationDto, MembershipDto } from '../../lib/api/types';

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

      <MembersCockpit members={members.data ?? []} invitations={invitations.data ?? []} />

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

function MembersCockpit({
  members,
  invitations,
}: {
  members: MembershipDto[];
  invitations: InvitationDto[];
}) {
  const { t } = useI18n();

  const total = members.length;
  const pending = invitations.filter((inv) => inv.status === 'pending').length;
  const mfaEnabled = members.filter((m) => m.user?.mfa_enabled).length;
  const mfaPercent = total > 0 ? Math.round((mfaEnabled / total) * 100) : 0;

  const roleGroups = new Map<string, { name: string; count: number; color: string }>();
  const ROLE_COLORS: Record<string, string> = {
    owner: 'text-brand-400',
    admin: 'text-sky-400',
    developer: 'text-violet-400',
    viewer: 'text-zinc-400',
  };
  for (const m of members) {
    const key = m.role?.key ?? 'viewer';
    const current = roleGroups.get(key) ?? { name: m.role?.name ?? key, count: 0, color: ROLE_COLORS[key] ?? 'text-zinc-400' };
    current.count += 1;
    roleGroups.set(key, current);
  }
  const segments = Array.from(roleGroups.values()).map((g) => ({ value: g.count, color: g.color, label: g.name }));

  const events: Array<{
    id: string;
    date: string;
    kind: 'member' | 'invitation';
    title: string;
    detail: string;
  }> = [];
  for (const m of members) {
    if (!m.joined_at) continue;
    events.push({
      id: m.id,
      date: m.joined_at,
      kind: 'member',
      title: m.user?.name ?? t('members.unknown'),
      detail: `${m.role?.name ?? ''} · ${m.user?.email ?? ''}`,
    });
  }
  for (const inv of invitations) {
    if (!inv.created_at) continue;
    events.push({
      id: inv.id,
      date: inv.created_at,
      kind: 'invitation',
      title: inv.email,
      detail: `${inv.role?.name ?? ''} · ${t('members.pendingInvitation')}`,
    });
  }
  events.sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label={t('members.cockpit.total')}
          value={total}
          icon={Users}
          to="/members"
          ariaLabel={t('members.cockpit.totalAria')}
          sub={t('members.cockpit.totalSub')}
        />
        <KpiCard
          label={t('members.cockpit.pending')}
          value={pending}
          icon={UserPlus}
          to="/members"
          accent={pending > 0 ? 'bg-amber-500/15 text-amber-300' : 'bg-brand-700/15 text-brand-300'}
          ariaLabel={t('members.cockpit.pendingAria')}
          sub={pending > 0 ? t('members.cockpit.pendingSub') : t('members.cockpit.pendingNone')}
        />
        <KpiCard
          label={t('members.cockpit.mfa')}
          value={mfaEnabled}
          icon={ShieldCheck}
          to="/security"
          accent={mfaPercent >= 100 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-brand-700/15 text-brand-300'}
          ariaLabel={t('members.cockpit.mfaAria')}
          sub={t('members.cockpit.mfaSub', { count: total - mfaEnabled })}
          footer={<ProgressBar percent={mfaPercent} tone={mfaPercent >= 100 ? 'success' : 'warning'} />}
        />
        <KpiCard
          label={t('members.cockpit.unprotected')}
          value={total - mfaEnabled}
          icon={ShieldOff}
          to="/security"
          accent={(total - mfaEnabled) > 0 ? 'bg-red-500/15 text-red-300' : 'bg-emerald-500/15 text-emerald-300'}
          ariaLabel={t('members.cockpit.unprotectedAria')}
          sub={(total - mfaEnabled) > 0 ? t('members.cockpit.unprotectedSub') : t('members.cockpit.unprotectedNone')}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5">
          <h3 className="text-sm font-semibold text-white">{t('members.cockpit.rolesTitle')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('members.cockpit.rolesSub')}</p>
          <div className="mt-4 flex items-center gap-5">
            <DistributionDonut
              segments={segments}
              size={120}
              thickness={11}
              center={
                <>
                  <span className="text-2xl font-bold text-white tabular-nums">{total}</span>
                  <span className="text-[10px] uppercase tracking-wide text-zinc-500">{t('members.cockpit.members')}</span>
                </>
              }
              label={t('members.cockpit.rolesTitle')}
            />
            <ul className="flex-1 space-y-2">
              {segments.map((segment) => (
                <li key={segment.label} className="flex items-center justify-between gap-3 text-sm">
                  <span className="flex items-center gap-2 text-zinc-300">
                    <span className={cn('h-2.5 w-2.5 rounded-full', segment.color)} />
                    {segment.label}
                  </span>
                  <span className="font-medium text-white tabular-nums">{segment.value}</span>
                </li>
              ))}
            </ul>
          </div>
        </Card>

        <Card className="p-5 lg:col-span-2">
          <h3 className="text-sm font-semibold text-white">{t('members.cockpit.timelineTitle')}</h3>
          <p className="mt-1 text-xs text-zinc-500">{t('members.cockpit.timelineSub')}</p>
          <ol className="mt-4 max-h-64 space-y-0 overflow-y-auto">
            {events.map((event, index) => (
              <li key={event.id} className="relative flex gap-3 pb-4">
                {index < events.length - 1 ? (
                  <span className="absolute left-[7px] top-4 h-full w-px bg-edge" />
                ) : null}
                <span
                  className={cn(
                    'relative mt-1 h-3.5 w-3.5 shrink-0 rounded-full border-2',
                    event.kind === 'member' ? 'border-brand-400 bg-brand-500' : 'border-amber-400 bg-amber-500',
                  )}
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-white">{event.title}</p>
                  <p className="truncate text-xs text-zinc-500">{event.detail}</p>
                  <p className="mt-0.5 text-xs text-zinc-600">{formatDate(event.date)}</p>
                </div>
                {event.kind === 'invitation' ? (
                  <span className="shrink-0 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-300">
                    {t('members.pendingInvitations')}
                  </span>
                ) : null}
              </li>
            ))}
          </ol>
        </Card>
      </div>
    </div>
  );
}
