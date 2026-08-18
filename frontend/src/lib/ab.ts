import { useEffect, useState } from 'react';
import { getDeviceId } from './device';
import { track, type AnalyticsProperties } from './analytics';

/**
 * A/B testing harness for the public marketing site.
 *
 * - `EXPERIMENTS` is the single registry: an experiment declares its variants
 *   with integer weights (sum = 100).
 * - Assignment is deterministic per (device, experiment) — a hash of the
 *   stable device id and the experiment id. The result is persisted in
 *   localStorage so a visitor keeps the same variant across sessions
 *   (sticky). No variant is ever re-assigned.
 * - `?ab_<experiment>=<variant>` overrides the assignment for QA / analysis
 *   (e.g. `?ab_hero=benefit`). `?ab_debug=1` shows a small panel listing
 *   every experiment, its current variant, and lets you force one.
 * - Exposures and conversions are recorded through the existing analytics
 *   event store (`experiment_viewed`, plus the caller's own event augmented
 *   with `ab_<experiment>` / `ab_variant` style properties), so the whole
 *   funnel — exposure → cta_clicked → signup — is attributed locally and can
 *   be forwarded to GA4 once consented.
 */

export interface AbVariant {
  id: string;
  weight: number;
}

export interface ExperimentDefinition {
  id: string;
  name: string;
  variants: AbVariant[];
}

/** The three live experiments. First variant is always the control. */
export const EXPERIMENTS: ExperimentDefinition[] = [
  {
    id: 'hero',
    name: 'Hero message',
    variants: [
      { id: 'control', weight: 60 },
      { id: 'benefit', weight: 20 },
      { id: 'sovereign', weight: 20 },
    ],
  },
  {
    id: 'cta',
    name: 'Hero CTA label',
    variants: [
      { id: 'control', weight: 60 },
      { id: 'short', weight: 20 },
      { id: 'value', weight: 20 },
    ],
  },
  {
    id: 'pricing',
    name: 'Pricing emphasis',
    variants: [
      { id: 'control', weight: 50 },
      { id: 'free', weight: 50 },
    ],
  },
];

const ASSIGNMENTS_KEY = 'omnex.ab.assignments';
const EXPOSED_KEY = 'omnex.ab.exposed';

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
    // Storage unavailable — experiments must never break the page.
  }
}

type Assignments = Record<string, string>;

function storedAssignments(): Assignments {
  return readJson<Assignments>(ASSIGNMENTS_KEY) ?? {};
}

function storeAssignment(id: string, variant: string): void {
  writeJson(ASSIGNMENTS_KEY, { ...storedAssignments(), [id]: variant });
}

/** FNV-1a 32-bit — fast, stable across sessions, no crypto needed for bucketing. */
function hashString(input: string): number {
  let hash = 0x811c9dc5;
  for (let i = 0; i < input.length; i += 1) {
    hash ^= input.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
  }
  return hash >>> 0;
}

function pickVariant(experiment: ExperimentDefinition, bucket: number): string {
  const total = experiment.variants.reduce((sum, v) => sum + v.weight, 0);
  const target = (bucket % total) + 1;
  let cursor = 0;
  for (const variant of experiment.variants) {
    cursor += variant.weight;
    if (target <= cursor) return variant.id;
  }
  return experiment.variants[0].id;
}

function experimentById(id: string): ExperimentDefinition | undefined {
  return EXPERIMENTS.find((experiment) => experiment.id === id);
}

/** Read a forced variant from the URL (`?ab_hero=benefit`). */
function forcedVariant(id: string): string | null {
  if (typeof window === 'undefined') return null;
  const param = new URLSearchParams(window.location.search).get(`ab_${id}`);
  const experiment = experimentById(id);
  if (!param || !experiment) return null;
  return experiment.variants.some((v) => v.id === param) ? param : null;
}

/**
 * Resolve the sticky variant for an experiment: forced override first, then
 * the persisted assignment, then a fresh deterministic bucketing that is
 * persisted immediately.
 */
export function getVariant(id: string): { variant: string; forced: boolean } {
  const experiment = experimentById(id);
  if (!experiment) return { variant: 'control', forced: false };

  const forced = forcedVariant(id);
  if (forced) return { variant: forced, forced: true };

  const stored = storedAssignments()[id];
  if (stored) return { variant: stored, forced: false };

  const bucket = hashString(`${getDeviceId()}:${id}`);
  const variant = pickVariant(experiment, bucket);
  storeAssignment(id, variant);
  return { variant, forced: false };
}

/**
 * Record an experiment exposure — once per experiment per assignment (so
 * repeated navigation does not spam the funnel).
 */
export function trackExposure(id: string): void {
  const { variant, forced } = getVariant(id);
  const exposed = readJson<Assignments>(EXPOSED_KEY) ?? {};
  if (exposed[id] === variant) return;

  writeJson(EXPOSED_KEY, { ...exposed, [id]: variant });
  track('experiment_viewed', {
    ab_experiment: id,
    ab_variant: variant,
    ab_forced: forced ? '1' : '0',
  });
}

/**
 * Augment conversion properties with the current assignment of every listed
 * experiment — call this inside the conversion's own `track(...)` properties.
 */
export function abProperties(experimentIds: string[] = EXPERIMENTS.map((e) => e.id)): AnalyticsProperties {
  const props: AnalyticsProperties = {};
  for (const id of experimentIds) {
    props[`ab_${id}`] = getVariant(id).variant;
  }
  return props;
}

export interface ExperimentState {
  experiment: ExperimentDefinition;
  variant: string;
  forced: boolean;
}

/**
 * React binding: returns the sticky variant for one experiment and records
 * the exposure once (only on the public site).
 */
export function useExperiment(id: string): ExperimentState {
  const experiment = experimentById(id) ?? EXPERIMENTS[0];
  const [state, setState] = useState<ExperimentState>(() => {
    const { variant, forced } = getVariant(id);
    return { experiment, variant, forced };
  });

  useEffect(() => {
    trackExposure(id);

    // Re-resolve when the URL changes (a forced `?ab_hero=...` may appear or
    // disappear between navigations) or when the operator panel forces a
    // variant for this visitor.
    const refresh = () => {
      const { variant, forced } = getVariant(id);
      setState((previous) => {
        const changed = previous.variant !== variant || previous.forced !== forced;
        if (changed) trackExposure(id);
        return changed ? { experiment, variant, forced } : previous;
      });
    };
    refresh();
    window.addEventListener('popstate', refresh);
    const unsubscribe = onAbChange(refresh);
    return () => {
      window.removeEventListener('popstate', refresh);
      unsubscribe();
    };
  }, [id, experiment]);

  return state;
}

/** Force a variant for this visitor (used by the debug panel / tests). */
export function forceVariant(id: string, variant: string): void {
  const experiment = experimentById(id);
  if (!experiment || !experiment.variants.some((v) => v.id === variant)) return;
  storeAssignment(id, variant);
  window.dispatchEvent(new CustomEvent('omnex:ab', { detail: { id, variant } }));
}

export function onAbChange(listener: (id: string, variant: string) => void): () => void {
  const handler = (event: Event) => {
    const { id, variant } = (event as CustomEvent<{ id: string; variant: string }>).detail;
    listener(id, variant);
  };
  window.addEventListener('omnex:ab', handler);
  return () => window.removeEventListener('omnex:ab', handler);
}

/** Whether the debug/operator panel is enabled (`?ab_debug=1`). */
export function isAbDebugEnabled(): boolean {
  if (typeof window === 'undefined') return false;
  return new URLSearchParams(window.location.search).get('ab_debug') === '1';
}