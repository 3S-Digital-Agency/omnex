import { detectBrowserLocale } from '../i18n';

describe('detectBrowserLocale', () => {
  it('maps a supported browser language to its primary code', () => {
    expect(detectBrowserLocale(['fr-FR', 'fr'])).toBe('fr');
    expect(detectBrowserLocale(['fr-CA'])).toBe('fr');
    expect(detectBrowserLocale(['en-US', 'en'])).toBe('en');
  });

  it('picks the first supported language in the preference list', () => {
    expect(detectBrowserLocale(['de-DE', 'fr-FR', 'en-GB'])).toBe('fr');
  });

  it('falls back to en when no supported language matches', () => {
    expect(detectBrowserLocale(['ja-JP'])).toBe('en');
    expect(detectBrowserLocale(['de-DE', 'es-ES'])).toBe('en');
  });

  it('falls back to en for an empty list', () => {
    expect(detectBrowserLocale([])).toBe('en');
  });
});
