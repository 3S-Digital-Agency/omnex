/**
 * Stable per-browser device id used by the unknown-device detection: a random
 * id generated once and persisted in localStorage. The backend only ever
 * receives a salted hash of it (never a hardware fingerprint).
 */
const DEVICE_ID_KEY = 'omnex_device_id';

let cached: string | null = null;

export function getDeviceId(): string {
  if (cached) return cached;
  if (typeof window !== 'undefined') {
    const existing = window.localStorage.getItem(DEVICE_ID_KEY);
    if (existing) {
      cached = existing;
      return existing;
    }
  }
  const fresh = `dev-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(DEVICE_ID_KEY, fresh);
  }
  cached = fresh;
  return fresh;
}

/** Rough platform label for audit/UI purposes. */
export function getDevicePlatform(): string {
  if (typeof navigator === 'undefined') return 'browser';
  const ua = navigator.userAgent;
  if (/iPhone|iPad|iPod/i.test(ua)) return 'iphone';
  if (/Android/i.test(ua)) return 'android';
  if (/Mac/i.test(ua)) return 'mac';
  if (/Windows/i.test(ua)) return 'windows';
  if (/Linux/i.test(ua)) return 'linux';
  return 'browser';
}
