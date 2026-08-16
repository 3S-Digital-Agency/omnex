import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import type { ActivityItem } from './api/types';

/**
 * Real-time activity feed. Subscribes to the server-sent activity stream and
 * merges events as they arrive; a slow poll remains as a fallback if the
 * stream drops (same contract as the notification stream). A Reverb/WebSocket
 * transport could replace the hook body without changing its callers.
 */
export function useActivityFeed(enabled: boolean, limit = 100) {
  const cursorRef = useRef(0);
  const [items, setItems] = useState<ActivityItem[]>([]);

  const feed = useQuery({
    queryKey: ['activity'],
    queryFn: () => api.listActivity(cursorRef.current || undefined),
    refetchInterval: 5000,
    enabled,
  });

  // Real-time: prepend streamed events instantly; the poll is the fallback.
  useEffect(() => {
    if (!enabled) return;
    return api.subscribeActivity((item) => {
      cursorRef.current = Math.max(cursorRef.current, item.id);
      setItems((previous) => {
        if (previous.some((existing) => existing.id === item.id)) return previous;
        return [item, ...previous].slice(0, limit);
      });
    });
  }, [enabled, limit]);

  useEffect(() => {
    if (!feed.data) return;

    cursorRef.current = feed.data.latest_id;

    setItems((previous) => {
      const seen = new Set(previous.map((item) => item.id));
      const fresh = feed.data!.data.filter((item) => !seen.has(item.id));
      return [...fresh, ...previous].slice(0, limit);
    });
  }, [feed.data, limit]);

  return {
    items,
    isLoading: feed.isLoading && items.length === 0,
    error: feed.error,
  };
}
