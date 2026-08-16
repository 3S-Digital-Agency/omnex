import { Construction } from 'lucide-react';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { useI18n } from '../../lib/i18n';
import { moduleById } from '../../lib/modules';

export function ModulePage({ moduleId }: { moduleId: string }) {
  const module = moduleById(moduleId);
  const { t } = useI18n();

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header className="flex items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-edge bg-panel">
          <module.icon className="h-6 w-6 text-brand-400" />
        </div>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-white">{t(`module.${module.id}.name`)}</h1>
          <p className="truncate text-sm text-zinc-400">{t(`module.${module.id}.tagline`)}</p>
        </div>
        <Badge tone="brand" className="ml-auto shrink-0">
          {module.phase}
        </Badge>
      </header>

      <Card className="p-6">
        <p className="text-sm text-zinc-300">{t(`module.${module.id}.description`)}</p>

        <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
          {module.capabilities.map((capability, index) => (
            <div
              key={capability}
              className="flex items-center gap-2 rounded-lg border border-edge bg-raised px-3 py-2.5 text-sm text-zinc-300"
            >
              <Construction className="h-4 w-4 shrink-0 text-zinc-500" />
              {t(`module.${module.id}.cap${index + 1}`)}
            </div>
          ))}
        </div>

        <div className="mt-6 rounded-lg border border-dashed border-edge px-4 py-8 text-center">
          <p className="text-sm text-zinc-400">{t('module.underConstruction')}</p>
          <p className="mt-1 text-xs text-zinc-600">{t('module.roadmapNote')}</p>
        </div>
      </Card>
    </div>
  );
}
