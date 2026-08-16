import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Badge } from '../../components/ui/Badge';
import { Card, CardHeader } from '../../components/ui/Card';
import { useI18n } from '../../lib/i18n';
import { cn } from '../../lib/utils';

interface Finding {
  severity: 'high' | 'medium' | 'low';
  titleKey: string;
  impactKey: string;
  remediationKey: string;
  action: { labelKey: string; to: string };
  fixed: boolean;
}

export function SecurityPage() {
  const { user, activeOrganization } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;

  const members = useQuery({
    queryKey: ['members', orgId],
    queryFn: () => api.listMembers(orgId!),
    enabled: !!orgId,
  });

  const findings: Finding[] = [
    {
      severity: 'high',
      titleKey: 'security.finding.mfa',
      impactKey: 'security.finding.mfaImpact',
      remediationKey: 'security.finding.mfaRemediation',
      action: { labelKey: 'settings.enableMfa', to: '/settings' },
      fixed: !!user?.mfa_enabled,
    },
    {
      severity: 'medium',
      titleKey: 'security.finding.singleMember',
      impactKey: 'security.finding.singleMemberImpact',
      remediationKey: 'security.finding.singleMemberRemediation',
      action: { labelKey: 'security.inviteMembers', to: '/members' },
      fixed: (members.data?.length ?? 1) > 1,
    },
    {
      severity: 'low',
      titleKey: 'security.finding.email',
      impactKey: 'security.finding.emailImpact',
      remediationKey: 'security.finding.emailRemediation',
      action: { labelKey: 'nav.settings', to: '/settings' },
      fixed: !!user?.email_verified_at,
    },
  ];

  const penalty = findings
    .filter((finding) => !finding.fixed)
    .reduce((sum, finding) => sum + (finding.severity === 'high' ? 25 : finding.severity === 'medium' ? 15 : 10), 0);
  const score = Math.max(0, 100 - penalty);

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('security.title')}</h1>
        <p className="text-sm text-zinc-400">
          {t('security.subtitle', { name: activeOrganization?.name ?? '' })}
        </p>
      </header>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="flex flex-col items-center justify-center p-6 text-center">
          <p className="text-sm text-zinc-400">{t('security.score')}</p>
          <p
            className={cn(
              'mt-2 text-5xl font-bold',
              score >= 90 ? 'text-emerald-400' : score >= 70 ? 'text-amber-400' : 'text-red-400',
            )}
          >
            {score}
          </p>
          <p className="text-xs text-zinc-500">/ 100</p>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('security.findings')} description={t('security.findingsDescription')} />
          <ul className="divide-y divide-edge">
            {findings.map((finding) => (
              <li key={finding.titleKey} className="flex items-start gap-3 px-5 py-4">
                <Badge
                  tone={
                    finding.fixed
                      ? 'success'
                      : finding.severity === 'high'
                        ? 'danger'
                        : finding.severity === 'medium'
                          ? 'warning'
                          : 'neutral'
                  }
                >
                  {finding.fixed ? 'OK' : finding.severity}
                </Badge>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-white">{t(finding.titleKey)}</p>
                  <p className="mt-0.5 text-xs text-zinc-500">{t(finding.impactKey)}</p>
                  {!finding.fixed ? (
                    <p className="mt-1 text-xs text-zinc-400">{t('security.fix', { remediation: t(finding.remediationKey) })}</p>
                  ) : null}
                </div>
                {!finding.fixed ? (
                  <Link to={finding.action.to} className="shrink-0 text-sm text-brand-400 hover:underline">
                    {t(finding.action.labelKey)}
                  </Link>
                ) : null}
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  );
}
