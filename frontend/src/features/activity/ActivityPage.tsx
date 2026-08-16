import { useAuth } from '../../app/AuthProvider';
import { useI18n } from '../../lib/i18n';
import { useActivityFeed } from '../../lib/useActivityFeed';
import { ActivityFeedList } from '../../components/activity/ActivityFeedList';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState, Spinner } from '../../components/ui/Spinner';

export function ActivityPage() {
  const { activeOrganization } = useAuth();
  const { t } = useI18n();
  const { items, isLoading } = useActivityFeed(!!activeOrganization);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">{t('activity.title')}</h1>
          <p className="text-sm text-zinc-400">{t('activity.subtitle', { name: activeOrganization?.name ?? '' })}</p>
        </div>
        <Badge tone="success">{t('common.live')}</Badge>
      </header>

      <Card className="p-5">
        {isLoading ? (
          <div className="flex justify-center py-10">
            <Spinner />
          </div>
        ) : items.length > 0 ? (
          <ActivityFeedList items={items} />
        ) : (
          <EmptyState title={t('activity.noActivity')} />
        )}
      </Card>
    </div>
  );
}
