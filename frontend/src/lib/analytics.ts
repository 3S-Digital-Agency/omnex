import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Privacy-conscious analytics for the public marketing site.
 *
 * - Every event is recorded locally (localStorage, capped) so conversion
 *   funnels work even with no third-party script loaded.
 * - When `VITE_GA4_MEASUREMENT_ID` is set, the gtag script is loaded lazily
 *   and events are forwarded — but only after the visitor consents
 *   (`analytics.consent === 'granted'`). No consent → local-only.
 * - UTM parameters are captured on first visit and persisted, so a campaign
 *   click can be attributed to a later signup.
 */

export const ANALYTICS_EVENTS = {
  pageview: 'pageview',
  cta_clicked: 'cta_clicked',
  signup_started: 'signup_started',
  signup_completed: 'signup_completed',
  trial_started: 'trial_started',
  lead_submitted: 'lead_submitted',
  quote_requested: 'quote_requested',
  demo_requested: 'demo_requested',
  experiment_viewed: 'experiment_viewed',
} as const;

export type AnalyticsEvent = (typeof ANALYTICS_EVENTS)[keyof typeof ANALYTICS_EVENTS];

export interface AnalyticsProperties {
  [key: string]: string | number | boolean | undefined;
}

const STORAGE_EVENTS_KEY = 'omnex.analytics.events';
const UTM_KEY = 'omnex.analytics.utm';
const CONSENT_KEY = 'omnex.analytics.consent';
const MAX_STORED_EVENTS = 200;

const GA4_MEASUREMENT_ID = import.meta.env.VITE_GA4_MEASUREMENT_ID;

interface GtagEvent {
  event: string;
  properties: AnalyticsProperties;
  timestamp: string;
}

function readJson<T>(key: string): T | null {
  if (typeof localStorage === 'undefined') return null;
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

function writeJson(key: string, value: unknown): void {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage full or unavailable — analytics must never break the app.
  }
}

export interface UtmParams {
  source?: string;
  medium?: string;
  campaign?: string;
  term?: string;
  content?: string;
}

/** Capture UTM params from the URL on first visit (persisted for attribution). */
export function captureUtm(search: string): UtmParams {
  const params = new URLSearchParams(search);
  const utm: UtmParams = {
    source: params.get('utm_source') ?? undefined,
    medium: params.get('utm_medium') ?? undefined,
    campaign: params.get('utm_campaign') ?? undefined,
    term: params.get('utm_term') ?? undefined,
    content: params.get('utm_content') ?? undefined,
  };

  if (Object.values(utm).some((value) => value !== undefined) && !readJson<UtmParams>(UTM_KEY)) {
    writeJson(UTM_KEY, utm);
  }

  return utm;
}

export function getUtm(): UtmParams {
  return readJson<UtmParams>(UTM_KEY) ?? {};
}

export type ConsentValue = 'granted' | 'denied';

export type ConsentStatus = ConsentValue | null;

const CONSENT_EVENT = 'omnex:consent';

export function getConsent(): ConsentStatus {
  if (typeof localStorage === 'undefined') return null;
  const value = localStorage.getItem(CONSENT_KEY);
  return value === 'granted' || value === 'denied' ? value : null;
}

/** Consent Mode v2: keep gtag in sync with the stored choice. */
function applyGtagConsent(value: ConsentValue): void {
  if (!window.gtag) return;
  window.gtag('consent', 'update', {
    ad_storage: value,
    analytics_storage: value,
    functionality_storage: value,
    personalization_storage: value,
    security_storage: 'granted',
  });
  if (value === 'granted' && GA4_MEASUREMENT_ID) {
    window.gtag('config', GA4_MEASUREMENT_ID);
  }
}

/**
 * Persist the visitor's consent choice, apply it to gtag (Consent Mode v2)
 * and notify subscribers via the `omnex:consent` event.
 */
export function setConsent(value: ConsentValue): void {
  localStorage.setItem(CONSENT_KEY, value);
  if (value === 'granted') {
    // Load the tag now — it initializes with denied defaults, then we upgrade.
    ensureGtag();
  }
  applyGtagConsent(value);
  window.dispatchEvent(new CustomEvent<ConsentValue>(CONSENT_EVENT, { detail: value }));
}

/** Subscribe to consent changes. Returns an unsubscribe function. */
export function onConsentChange(listener: (value: ConsentValue) => void): () => void {
  const handler = (event: Event) => {
    listener((event as CustomEvent<ConsentValue>).detail);
  };
  window.addEventListener(CONSENT_EVENT, handler);
  return () => window.removeEventListener(CONSENT_EVENT, handler);
}

/** Locally recorded events (for testing, debugging, or self-hosted export). */
export function getStoredEvents(): GtagEvent[] {
  return readJson<GtagEvent[]>(STORAGE_EVENTS_KEY) ?? [];
}

type GtagCommand = 'js' | 'config' | 'event' | 'consent' | 'set' | 'get';
type Gtag = (command: GtagCommand, ...args: unknown[]) => void;

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: Gtag;
  }
}

/**
 * Lazily inject the gtag script. Only called when a measurement id exists and
 * the visitor has granted consent.
 */
function ensureGtag(): void {
  if (window.gtag || !GA4_MEASUREMENT_ID) return;
  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_MEASUREMENT_ID}`;
  document.head.appendChild(script);

  window.dataLayer = window.dataLayer ?? [];
  window.gtag = function gtag(...args: unknown[]) {
    window.dataLayer?.push(args);
  };
  // Consent Mode v2: default everything to denied until the visitor opts in.
  window.gtag('consent', 'default', {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
  });
  window.gtag('js', new Date());
  window.gtag('config', GA4_MEASUREMENT_ID);
}

/**
 * Record an analytics event. Always stored locally; forwarded to GA4 only
 * when configured AND consented. Returns the stored event.
 */
export function track(event: AnalyticsEvent, properties: AnalyticsProperties = {}): GtagEvent {
  const recorded: GtagEvent = {
    event,
    properties: { ...getUtm(), ...properties },
    timestamp: new Date().toISOString(),
  };

  const events = getStoredEvents();
  events.push(recorded);
  writeJson(STORAGE_EVENTS_KEY, events.slice(-MAX_STORED_EVENTS));

  if (GA4_MEASUREMENT_ID && getConsent() === 'granted') {
    ensureGtag();
    window.gtag?.('event', event, recorded.properties);
  }

  return recorded;
}

/** Track a pageview whenever the public route changes. */
export function usePageviewTracking(): void {
  const location = useLocation();

  useEffect(() => {
    captureUtm(location.search);
    track('pageview', { path: location.pathname });
  }, [location.pathname, location.search]);
}

export const analyticsConfig = {
  ga4MeasurementId: GA4_MEASUREMENT_ID,
};
