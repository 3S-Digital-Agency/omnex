import type { ApiClient } from './client';
import { HttpApiClient } from './http';
import { MockApiClient } from './mock';

/**
 * VITE_USE_MOCKS=false → real Laravel API.
 * Anything else (default) → in-browser mock, so the UI runs with no backend.
 */
export const api: ApiClient =
  import.meta.env.VITE_USE_MOCKS === 'false' ? new HttpApiClient() : new MockApiClient();
