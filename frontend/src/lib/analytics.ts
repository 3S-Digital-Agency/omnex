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

export function getConsent(): 'granted' | 'denied' | null {
  if (typeof localStorage === 'undefined') return null;
  const value = localStorage.getItem(CONSENT_KEY);
  return value === 'granted' || value === 'denied' ? value : null;
}

export function setConsent(value: 'granted' | 'denied'): void {
  localStorage.setItem(CONSENT_KEY, value);
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
