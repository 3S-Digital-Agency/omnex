import { useEffect, useState } from 'react';
import { Cookie } from 'lucide-react';
import { getConsent, onConsentChange, setConsent, type ConsentValue } from '../../lib/analytics';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';

/**
 * Cookie / analytics consent banner shown on the public marketing site until
 * the visitor makes a choice. Opting in loads GA4 (Consent Mode v2) and
 * forwards events; declining keeps analytics strictly local.
 */
export function ConsentBanner() {
  const { t } = useI18n();
  const [visible, setVisible] = useState<boolean>(() => getConsent() === null);

  useEffect(() => {
    const unsubscribe = onConsentChange(() => setVisible(false));
    return unsubscribe;
  }, []);

  if (!visible) return null;

  const choose = (value: ConsentValue) => {
    setConsent(value);
    setVisible(false);
  };

  return (
    <div
      role="region"
      aria-label={t('marketing.consent.title')}
      className="fixed inset-x-0 bottom-0 z-50 border-t border-white/10 bg-[#121214]/95 shadow-[0_-8px_30px_rgba(0,0,0,0.35)] backdrop-blur"
    >
      <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <div className="flex items-start gap-3 sm:items-center">
          <Cookie className="mt-0.5 h-5 w-5 shrink-0 text-zinc-400 sm:mt-0" aria-hidden="true" />
          <div>
            <p className="text-sm font-medium text-white">{t('marketing.consent.title')}</p>
            <p className="mt-1 text-sm leading-relaxed text-zinc-400">{t('marketing.consent.text')}</p>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          <Button variant="ghost" size="sm" onClick={() => choose('denied')}>
            {t('marketing.consent.decline')}
          </Button>
          <Button size="sm" onClick={() => choose('granted')}>
            {t('marketing.consent.accept')}
          </Button>
        </div>
      </div>
    </div>
  );
}
