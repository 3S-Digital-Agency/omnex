import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import type { FeatureFlagDto } from './api/types';

/**
 * Reads the active organization's feature flags/perks (resolved server-side
 * from plan tier + organization overrides). The result is cached briefly and
 * shared across the app so navigation and controls stay consistent.
 */
export function useFeatures() {
  const query = useQuery({
    queryKey: ['features'],
    queryFn: () => api.getFeatures(),
    staleTime: 60_000,
  });

  const features: FeatureFlagDto[] = query.data ?? [];
  const byKey = new Map(features.map((flag) => [flag.key, flag]));

  const enabled = (key: string): boolean => byKey.get(key)?.enabled ?? false;

  const value = <T extends boolean | number = boolean | number>(key: string, fallback?: T): T | undefined =>
    (byKey.get(key)?.value as T | undefined) ?? fallback;

  return { features, enabled, value, isLoading: query.isLoading };
}
