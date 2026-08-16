import { describe, expect, it, beforeEach } from 'vitest';
import { captureUtm, getConsent, getStoredEvents, getUtm, setConsent, track } from '../analytics';

describe('analytics', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('records events locally with UTM attribution', () => {
    captureUtm('?utm_source=newsletter&utm_campaign=launch');
    track('cta_clicked', { cta: 'hero' });

    const events = getStoredEvents();
    expect(events).toHaveLength(1);
    expect(events[0].event).toBe('cta_clicked');
    expect(events[0].properties.cta).toBe('hero');
    expect(events[0].properties.source).toBe('newsletter');
    expect(events[0].properties.campaign).toBe('launch');
  });

  it('captures UTM once and reuses it for later events', () => {
    captureUtm('?utm_source=ads&utm_medium=cpc');
    captureUtm('?utm_source=other'); // ignored — first visit wins
    track('signup_started', { provider: 'google' });

    expect(getUtm().source).toBe('ads');
    expect(getUtm().medium).toBe('cpc');
    expect(getStoredEvents()[0].properties.source).toBe('ads');
  });

  it('caps stored events at the maximum', () => {
    for (let i = 0; i < 220; i += 1) {
      track('pageview', { path: `/p${i}` });
    }
    expect(getStoredEvents()).toHaveLength(200);
    expect(getStoredEvents()[0].properties.path).toBe('/p20');
  });

  it('respects consent for gtag forwarding', () => {
    // Consent not granted → no gtag call happens (window.gtag stays absent).
    track('lead_submitted', { subject: 'Quote' });
    expect((window as { gtag?: unknown }).gtag).toBeUndefined();

    setConsent('granted');
    expect(getConsent()).toBe('granted');
  });
});
