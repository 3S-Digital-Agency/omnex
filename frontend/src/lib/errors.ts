import { ApiError } from './api/client';

export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (error instanceof ApiError) {
    const first = error.fieldErrors ? Object.values(error.fieldErrors)[0]?.[0] : undefined;
    return first ?? error.detail ?? error.message ?? fallback;
  }
  return error instanceof Error ? error.message : fallback;
}
