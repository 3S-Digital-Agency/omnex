import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../../app/AuthProvider';
import { api } from '../../lib/api';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';
import { useI18n } from '../../lib/i18n';
import { formatDate } from '../../lib/utils';

export function AuditPage() {
  const { activeOrganization } = useAuth();
  const { t } = useI18n();
  const orgId = activeOrganization?.id;

  const audit = useQuery({
    queryKey: ['audit', orgId],
    queryFn: () => api.listAudit(100),
    enabled: !!orgId,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-white">{t('audit.title')}</h1>
        <p className="text-sm text-zinc-400">
          {t('audit.subtitle', { name: activeOrganization?.name ?? '' })}
        </p>
      </header>

      <Card className="overflow-hidden">
        {audit.isLoading ? (
          <div className="flex justify-center p-10">
            <Spinner />
          </div>
        ) : audit.data && audit.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-edge text-left text-xs uppercase tracking-wide text-zinc-500">
                  <th className="px-5 py-3 font-medium">{t('audit.action')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.user')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.ip')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.result')}</th>
                  <th className="px-5 py-3 font-medium">{t('audit.time')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-edge">
                {audit.data.data.map((log) => (
                  <tr key={log.id} className="text-zinc-300">
                    <td className="px-5 py-3 font-mono text-xs">{log.action}</td>
                    <td className="px-5 py-3">{log.user?.name ?? '—'}</td>
                    <td className="px-5 py-3 font-mono text-xs text-zinc-500">{log.ip_address ?? '—'}</td>
                    <td className="px-5 py-3">
                      <Badge tone={log.result === 'success' ? 'success' : 'danger'}>{log.result}</Badge>
                    </td>
                    <td className="px-5 py-3 text-zinc-500">{formatDate(log.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="p-5">
            <EmptyState title={t('audit.empty')} />
          </div>
        )}
      </Card>
    </div>
  );
}
