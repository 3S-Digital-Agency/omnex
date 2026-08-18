import { useEffect, useState } from 'react';
import { FlaskConical } from 'lucide-react';
import {
  EXPERIMENTS,
  forceVariant,
  getVariant,
  isAbDebugEnabled,
  onAbChange,
} from '../../lib/ab';
import { cn } from '../../lib/utils';

/**
 * Small operator panel for the A/B harness. Only rendered when the visitor
 * (or QA) enabled it with `?ab_debug=1`. Shows every experiment and its
 * current sticky variant, and lets you force a variant for inspection —
 * forcing persists, so the funnel keeps that variant until changed.
 *
 * Completely inert for regular visitors: zero layout impact, zero tracking
 * beyond the `experiment_viewed` events recorded by `useExperiment`.
 */
export function AbDebugPanel() {
  const [enabled, setEnabled] = useState<boolean>(isAbDebugEnabled);
  const [open, setOpen] = useState<boolean>(false);
  const [assignments, setAssignments] = useState<Record<string, string>>(() =>
    Object.fromEntries(EXPERIMENTS.map((e) => [e.id, getVariant(e.id).variant])),
  );

  useEffect(() => {
    const unsubscribe = onAbChange((id, variant) => {
      setAssignments((previous) => ({ ...previous, [id]: variant }));
    });
    return unsubscribe;
  }, []);

  useEffect(() => {
    setEnabled(isAbDebugEnabled());
  }, []);

  if (!enabled) return null;

  return (
    <div className="fixed bottom-4 left-4 z-[60]">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex items-center gap-2 rounded-full border border-white/10 bg-[#121214]/95 px-3 py-1.5 text-xs font-medium text-zinc-300 shadow-lg backdrop-blur transition-colors hover:text-white"
        aria-expanded={open}
        aria-label="A/B testing panel"
      >
        <FlaskConical className="h-3.5 w-3.5 text-brand-400" aria-hidden="true" />
        A/B
      </button>

      {open && (
        <div className="mt-2 w-72 rounded-xl border border-white/10 bg-[#121214]/95 p-4 shadow-2xl backdrop-blur">
          <p className="text-xs font-semibold uppercase tracking-wider text-zinc-500">
            Experiments
          </p>
          <ul className="mt-3 space-y-3">
            {EXPERIMENTS.map((experiment) => (
              <li key={experiment.id}>
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-white">{experiment.name}</span>
                  <span className="text-xs text-zinc-500">{assignments[experiment.id]}</span>
                </div>
                <div className="mt-1.5 flex flex-wrap gap-1">
                  {experiment.variants.map((variant) => (
                    <button
                      key={variant.id}
                      type="button"
                      onClick={() => forceVariant(experiment.id, variant.id)}
                      className={cn(
                        'rounded-md border px-2 py-0.5 text-[11px] transition-colors',
                        assignments[experiment.id] === variant.id
                          ? 'border-brand-400 bg-brand-400/15 text-brand-200'
                          : 'border-white/10 bg-white/5 text-zinc-400 hover:text-white',
                      )}
                    >
                      {variant.id}
                    </button>
                  ))}
                </div>
              </li>
            ))}
          </ul>
          <p className="mt-3 border-t border-white/5 pt-2 text-[11px] leading-relaxed text-zinc-500">
            Assignments are sticky per device. Conversions are attributed via{' '}
            <code className="text-zinc-400">ab_&lt;experiment&gt;</code> event properties.
          </p>
        </div>
      )}
    </div>
  );
}