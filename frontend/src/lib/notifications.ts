import { AlertCircle, AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import type { NotificationSeverity } from './api/types';

export const NOTIFICATION_SEVERITY_META: Record<
  NotificationSeverity,
  { icon: typeof Info; className: string }
> = {
  info: { icon: Info, className: 'text-brand-300' },
  success: { icon: CheckCircle2, className: 'text-emerald-400' },
  warning: { icon: AlertTriangle, className: 'text-amber-400' },
  danger: { icon: AlertCircle, className: 'text-red-400' },
};

export const NOTIFICATION_TYPES = [
  'system',
  'security',
  'domain',
  'deployment',
  'billing',
  'member',
  'welcome',
];

export const NOTIFICATION_SEVERITIES: NotificationSeverity[] = [
  'info',
  'success',
  'warning',
  'danger',
];
