import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { track } from '../analytics';
import {
  EXPERIMENTS,
  abProperties,
  getVariant,
  trackExposure,
} from '../ab';

vi.mock('../analytics', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics')>();
  return { ...actual, track: vi.fn() };
});

const ASSIGNMENTS_KEY = 'omnex.ab.assignments';
const EXPOSED_KEY = 'omnex.ab.exposed';
const DEVICE_ID_KEY = 'omnex_device_id';

describe('A/B testing harness', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    window.localStorage.clear();
    const url = new URL('http://localhost/');
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, search: url.search, pathname: url.pathname },
      writable: true,
    });
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, writable: true });
    vi.restoreAllMocks();
  });

  it('registers valid experiments (weights sum to 100, control first)', () => {
    for (const experiment of EXPERIMENTS) {
      expect(experiment.variants[0].id).toBe('control');
      const total = experiment.variants.reduce((sum, v) => sum + v.weight, 0);
      expect(total).toBe(100);
    }
  });

  it('is deterministic and sticky per device', () => {
    const first = getVariant('hero');
    const second = getVariant('hero');
    expect(second.variant).toBe(first.variant);

    const stored = JSON.parse(localStorage.getItem(ASSIGNMENTS_KEY) ?? '{}');
    expect(stored.hero).toBe(first.variant);
  });

  it('uses different buckets for different experiments', () => {
    const hero = getVariant('hero').variant;
    const pricing = getVariant('pricing').variant;
    // Only a sanity check — they MAY coincide, so assert on the storage
    // structure instead: two keys persisted.
    const stored = JSON.parse(localStorage.getItem(ASSIGNMENTS_KEY) ?? '{}');
    expect(Object.keys(stored)).toContain('hero');
    expect(Object.keys(stored)).toContain('pricing');
  });

  it('stays sticky when the assignment is already stored', () => {
    localStorage.setItem(ASSIGNMENTS_KEY, JSON.stringify({ hero: 'sovereign' }));
    expect(getVariant('hero').variant).toBe('sovereign');
  });

  it('respects the ?ab_<experiment>=<variant> override', () => {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, search: '?ab_hero=benefit' },
      writable: true,
    });
    const { variant, forced } = getVariant('hero');
    expect(variant).toBe('benefit');
    expect(forced).toBe(true);
  });

  it('ignores unknown forced variants', () => {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, search: '?ab_hero=nope' },
      writable: true,
    });
    const known = ['control', 'benefit', 'sovereign'];
    expect(known).toContain(getVariant('hero').variant);
  });

  it('tracks each exposure only once per assignment', () => {
    const trackMock = vi.mocked(track);
    trackMock.mockClear();
    trackExposure('hero');
    trackExposure('hero');
    expect(trackMock).toHaveBeenCalledTimes(1);
    expect(trackMock).toHaveBeenCalledWith('experiment_viewed', expect.objectContaining({ ab_experiment: 'hero' }));
  });

  it('augments conversion properties with every experiment', () => {
    localStorage.setItem(ASSIGNMENTS_KEY, JSON.stringify({ hero: 'benefit', cta: 'short', pricing: 'free' }));
    const props = abProperties();
    expect(props.ab_hero).toBe('benefit');
    expect(props.ab_cta).toBe('short');
    expect(props.ab_pricing).toBe('free');
  });
});