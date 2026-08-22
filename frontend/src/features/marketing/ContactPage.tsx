import { useState } from 'react';
import type { FormEvent } from 'react';
import { Mail, Send } from 'lucide-react';
import { api } from '../../lib/api';
import { errorMessage } from '../../lib/errors';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Reveal } from '../../components/Reveal';
import { Field } from '../../components/ui/Field';
import { Input } from '../../components/ui/Input';
import { Textarea } from '../../components/ui/Textarea';
import { useToast } from '../../components/ui/Toast';
import { track } from '../../lib/analytics';
import { useDocumentMeta } from './useDocumentMeta';
import { useHreflang } from './seo';

export function ContactPage() {
  const { locale, t } = useI18n();
  const { toast } = useToast();

  useHreflang('/contact', locale);
  useDocumentMeta(
    locale === 'fr' ? 'Contactez OMNEX — soumission & démo' : 'Contact OMNEX — quote & demo',
    locale === 'fr'
      ? 'Parlons de votre infrastructure. Demandez une soumission, réservez une démo ou écrivez-nous — réponse sous un jour ouvrable.'
      : 'Tell us about your infrastructure. Request a quote, book a demo or write to us — we reply within one business day.',
    '/contact',
  );

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [company, setCompany] = useState('');
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  // Honeypot — hidden from real visitors, must stay empty.
  const [website, setWebsite] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  /**
   * Optional reCAPTCHA v3. Only active when VITE_RECAPTCHA_SITE_KEY is set
   * (the backend secret must be configured to match). The script is loaded
   * lazily on first submit, then the token is fetched per submission.
   */
  const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;

  async function getRecaptchaToken(): Promise<string | undefined> {
    if (!recaptchaSiteKey) return undefined;
    if (!window.grecaptcha) {
      await new Promise<void>((resolve) => {
        const script = document.createElement('script');
        script.src = `https://www.google.com/recaptcha/api.js?render=${recaptchaSiteKey}`;
        script.onload = () => resolve();
        document.head.appendChild(script);
      });
    }
    return new Promise<string>((resolve) => {
      window.grecaptcha?.ready(() => {
        void window.grecaptcha?.execute(recaptchaSiteKey, { action: 'submit' }).then(resolve);
      });
    });
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setErrors({});
    setBusy(true);
    try {
      const recaptchaToken = await getRecaptchaToken();
      await api.submitContactLead({
        name,
        email,
        company,
        subject,
        message,
        website,
        recaptcha_token: recaptchaToken,
        source: 'marketing-site',
      });
      track('lead_submitted', { subject: subject || undefined, company: company || undefined });
      toast(t('contact.success'));
      setName('');
      setEmail('');
      setCompany('');
      setSubject('');
      setMessage('');
    } catch (err) {
      const apiError = err as { fields?: Record<string, string[]> };
      if (apiError.fields) {
        const fieldErrors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(apiError.fields)) {
          fieldErrors[field] = messages[0] ?? t('contact.error');
        }
        setErrors(fieldErrors);
      } else {
        toast(t('contact.error'), 'error');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.06),transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 py-20 text-center sm:px-6 sm:py-24 lg:px-8">
          <Reveal>
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/5">
              <Mail className="h-7 w-7 text-white" />
            </div>
          </Reveal>
          <Reveal delay={80}>
            <h1 className="mx-auto mt-6 max-w-3xl text-4xl font-bold tracking-tight text-white sm:text-5xl">
              {t('contact.title')}
            </h1>
          </Reveal>
          <Reveal delay={160}>
            <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400">{t('contact.subtitle')}</p>
          </Reveal>
        </div>
      </section>

      <section className="border-t border-white/5 bg-[#0d0d10]">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-5 lg:px-8">
          {/* Form */}
          <form onSubmit={submit} className="lg:col-span-3" noValidate>
            <div className="rounded-2xl border border-white/5 bg-[#121214] p-6 sm:p-8">
              <div className="grid gap-5 sm:grid-cols-2">
                <Field label={t('contact.name')} htmlFor="contact-name" error={errors.name}>
                  <Input
                    id="contact-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    autoComplete="name"
                  />
                </Field>
                <Field label={t('contact.email')} htmlFor="contact-email" error={errors.email}>
                  <Input
                    id="contact-email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    autoComplete="email"
                  />
                </Field>
              </div>

              <div className="mt-5 grid gap-5 sm:grid-cols-2">
                <Field label={t('contact.company')} htmlFor="contact-company">
                  <Input
                    id="contact-company"
                    value={company}
                    onChange={(e) => setCompany(e.target.value)}
                    autoComplete="organization"
                  />
                </Field>
                <Field label={t('contact.subject')} htmlFor="contact-subject" error={errors.subject}>
                  <Input
                    id="contact-subject"
                    value={subject}
                    onChange={(e) => setSubject(e.target.value)}
                    required
                  />
                </Field>
              </div>

              {/* Honeypot — invisible to humans, catches bots. */}
              <div className="hidden" aria-hidden="true">
                <label htmlFor="contact-website">Website</label>
                <input
                  id="contact-website"
                  tabIndex={-1}
                  autoComplete="off"
                  value={website}
                  onChange={(e) => setWebsite(e.target.value)}
                />
              </div>

              <div className="mt-5">
                <Field label={t('contact.message')} htmlFor="contact-message" error={errors.message} hint={t('contact.messageHint')}>
                  <Textarea
                    id="contact-message"
                    rows={6}
                    value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    required
                  />
                </Field>
              </div>

              <div className="mt-6">
                <Button type="submit" size="lg" loading={busy} className="w-full sm:w-auto">
                  {busy ? t('contact.submitting') : t('contact.submit')}
                  <Send className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </form>

          {/* Alternatives */}
          <aside className="lg:col-span-2">
            <div className="space-y-4">
              <div className="rounded-2xl border border-white/5 bg-[#121214] p-6 transition-all duration-300 hover:-translate-y-1 hover:border-white/15 hover:bg-[#16161a] hover:shadow-xl hover:shadow-black/40">
                <h2 className="text-lg font-semibold text-white">{t('contact.direct')}</h2>
                <p className="mt-2 text-sm text-zinc-400">
                  {t('contact.directHint')}{' '}
                  <a href="mailto:hello@omnex.cloud" className="text-brand-200 hover:underline">
                    hello@omnex.cloud
                  </a>
                </p>
              </div>
              <div className="rounded-2xl border border-white/5 bg-[#121214] p-6 transition-all duration-300 hover:-translate-y-1 hover:border-white/15 hover:bg-[#16161a] hover:shadow-xl hover:shadow-black/40">
                <h2 className="text-lg font-semibold text-white">{t('contact.alt.quote')}</h2>
                <p className="mt-2 text-sm text-zinc-400">{t('marketing.pricing.business.desc')}</p>
                <div className="mt-4">
                  <Button variant="outline" onClick={() => setSubject(t('contact.alt.quote'))}>
                    {t('contact.alt.quote')}
                  </Button>
                </div>
              </div>
              <div className="rounded-2xl border border-white/5 bg-[#121214] p-6 transition-all duration-300 hover:-translate-y-1 hover:border-white/15 hover:bg-[#16161a] hover:shadow-xl hover:shadow-black/40">
                <h2 className="text-lg font-semibold text-white">{t('contact.alt.demo')}</h2>
                <p className="mt-2 text-sm text-zinc-400">{t('marketing.hero.subtitle')}</p>
                <div className="mt-4">
                  <Button variant="outline" onClick={() => setSubject(t('contact.alt.demo'))}>
                    {t('contact.alt.demo')}
                  </Button>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </section>
    </div>
  );
}
