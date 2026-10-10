import { request } from './client';

export type SettingsHealthStatus = 'pass' | 'warning' | 'error' | 'unknown';
export type SettingsHealthAction = 'cron' | 'connections' | 'credentials' | 'account' | 'quota';

export type SettingsHealthCheck = {
  id: string;
  status: SettingsHealthStatus;
  title: string;
  description: string;
  action?: SettingsHealthAction;
  fix?: string[];
};

export type SettingsHealthActivityDay = {
  date: string;
  completed: number;
  failed: number;
};

export type SettingsHealthActivity = {
  completed: number;
  failed: number;
  medianSeconds: number | null;
  waiting: number;
  limited: boolean;
  days: SettingsHealthActivityDay[];
};

export type SettingsHealthReportEvent = {
  timestamp: string;
  status: string;
  step: string;
  errorCode: string;
};

export type SettingsHealthResponse = {
  checkedAt: string;
  checks: SettingsHealthCheck[];
  activity: SettingsHealthActivity;
  versions: { wordpress: string; php: string; plugin: string };
  reportEvents: SettingsHealthReportEvent[];
};

const statuses: SettingsHealthStatus[] = ['pass', 'warning', 'error', 'unknown'];
const actions: SettingsHealthAction[] = ['cron', 'connections', 'credentials', 'account', 'quota'];
const text = (value: unknown): string => (typeof value === 'string' ? value : '');
const count = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.floor(value) : 0);
const list = (value: unknown): unknown[] => (Array.isArray(value) ? value : []);
const record = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});

const normalizeCheck = (value: unknown): SettingsHealthCheck => {
  const item = record(value);
  const status = statuses.find((candidate) => candidate === item.status) ?? 'unknown';
  const action = actions.find((candidate) => candidate === item.action);
  const fix = list(item.fix).filter((step): step is string => typeof step === 'string' && step !== '');

  return {
    id: text(item.id),
    status,
    title: text(item.title),
    description: text(item.description),
    ...(action ? { action } : {}),
    ...(fix.length > 0 ? { fix } : {})
  };
};

export const normalizeSettingsHealth = (value: unknown): SettingsHealthResponse => {
  const data = record(value);
  const activity = record(data.activity);
  const versions = record(data.versions);
  const median = activity.medianSeconds;

  return {
    checkedAt: text(data.checkedAt),
    checks: list(data.checks).map(normalizeCheck).filter((check) => check.id !== ''),
    activity: {
      completed: count(activity.completed),
      failed: count(activity.failed),
      medianSeconds: typeof median === 'number' && Number.isFinite(median) && median >= 0 ? median : null,
      waiting: count(activity.waiting),
      limited: activity.limited === true,
      days: list(activity.days).map((day) => {
        const item = record(day);
        return { date: text(item.date), completed: count(item.completed), failed: count(item.failed) };
      }).filter((day) => day.date !== '')
    },
    versions: { wordpress: text(versions.wordpress), php: text(versions.php), plugin: text(versions.plugin) },
    reportEvents: list(data.reportEvents).map((event) => {
      const item = record(event);
      return { timestamp: text(item.timestamp), status: text(item.status), step: text(item.step), errorCode: text(item.errorCode) };
    })
  };
};

export const getSettingsHealth = async (): Promise<SettingsHealthResponse> => {
  return normalizeSettingsHealth(await request<unknown>('settings/health'));
};

export const requestSettingsHealthCron = (): Promise<{ requested: boolean }> => {
  return request<{ requested: boolean }>('settings/health/run-cron', { method: 'POST' });
};
