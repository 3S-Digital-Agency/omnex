import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import type { ActivityItem } from './api/types';

/**
 * Incrementally polls the activity endpoint (every 5s) and merges new events
 * into a bounded in-memory list. Near-real-time without a WebSocket dependency;
 * a Reverb/WebSocket transport can replace this hook's body later.
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
