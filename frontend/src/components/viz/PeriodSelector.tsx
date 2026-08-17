import { cn } from '../../lib/utils';
import { useI18n } from '../../lib/i18n';

export type PeriodDays = 7 | 30 | 90;

interface PeriodSelectorProps {
  value: PeriodDays;
  onChange: (days: PeriodDays) => void;
  className?: string;
}

/**
 * Cockpit period selector (7 / 30 / 90 days) used to explore persisted
 * history across the security, cloud, activity and audit cockpits.
 */
export function PeriodSelector({ value, onChange, className }: PeriodSelectorProps) {
  const { t } = useI18n();
  const periods: PeriodDays[] = [7, 30, 90];

  return (
    <div
      role="group"
      aria-label={t('cockpit.period.label')}
      className={cn('inline-flex items-center gap-0.5 rounded-lg border border-edge bg-raised p-0.5', className)}
    >
      {periods.map((days) => (
        <button
          key={days}
          type="button"
          onClick={() => onChange(days)}
          aria-pressed={value === days}
          className={cn(
            'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
            value === days ? 'bg-brand-600/30 text-white' : 'text-zinc-500 hover:text-white',
          )}
        >
          {t(`cockpit.period.days`, { days })}
        </button>
      ))}
    </div>
  );
}
