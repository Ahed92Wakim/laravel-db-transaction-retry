export const apiBase = '/api/transaction-retry';

export const timeRanges = [
  {label: '1H', value: '1h', windowLabel: 'Last hour'},
  {label: '24H', value: '24h', windowLabel: 'Last 24 hours'},
  {label: '7D', value: '7d', windowLabel: 'Last 7 days'},
  {label: '14D', value: '14d', windowLabel: 'Last 14 days'},
  {label: '30D', value: '30d', windowLabel: 'Last 30 days'},
] as const;

export const routeMetricsLimit = 50;

export type TimeRangeValue = (typeof timeRanges)[number]['value'];

export type Bucket =
  | 'minute'
  | '15minute'
  | 'hour'
  | '2hour'
  | '4hour'
  | '8hour'
  | 'day';

export type RouteMetric = {
  route_hash?: string | null;
  method?: string | null;
  route_name?: string | null;
  url?: string | null;
  attempts: number;
  success: number;
  failure: number;
  last_seen?: string | null;
};

export type RouteVolumeMetric = {
  method?: string | null;
  route_name?: string | null;
  url?: string | null;
  status_1xx_3xx: number;
  status_4xx: number;
  status_5xx: number;
  total: number;
  avg_ms: number;
  p95_ms: number;
};

export type QueryMetric = {
  time: string;
  timestamp?: string;
  count: number;
  transaction_count: number;
  transaction_volume: number;
  avg_ms: number;
  p95_ms: number;
  under_2s: number;
  over_2s: number;
};

export type TransactionVolumeMetric = {
  time: string;
  timestamp?: string;
  transaction_count: number;
  transaction_volume: number;
  under_2s: number;
  over_2s: number;
};

export type QueryDurationMetric = {
  time: string;
  timestamp?: string;
  count: number;
  avg_ms: number;
  p95_ms: number;
};

export const resolveBucket = (value?: string | null): Bucket | null => {
  const normalized = value?.toLowerCase();

  switch (normalized) {
    case 'minute':
    case '15minute':
    case 'hour':
    case '2hour':
    case '4hour':
    case '8hour':
    case 'day':
      return normalized;
    default:
      return null;
  }
};

export const bucketForRange = (range: TimeRangeValue): Bucket => {
  switch (range) {
    case '1h':
      return 'minute';
    case '24h':
      return '15minute';
    case '7d':
      return '2hour';
    case '14d':
      return '4hour';
    case '30d':
      return '8hour';
    default:
      return 'day';
  }
};

export const formatBucketLabel = (
  timestamp: string | undefined,
  bucket: Bucket | null,
  timeZone: string | null,
  fallback: string
): string => {
  if (!timestamp || !bucket || !timeZone) {
    return fallback;
  }

  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return fallback;
  }

  const options: Intl.DateTimeFormatOptions =
    bucket === 'day'
      ? {month: 'short', day: '2-digit'}
      : {hour: '2-digit', minute: '2-digit', hour12: false};

  return new Intl.DateTimeFormat(undefined, {...options, timeZone}).format(date);
};

export const resolveTimeWindow = (range: TimeRangeValue) => {
  const now = new Date();
  const from = new Date(now);

  switch (range) {
    case '1h':
      from.setHours(now.getHours() - 1);
      break;
    case '24h':
      from.setHours(now.getHours() - 24);
      break;
    case '7d':
      from.setDate(now.getDate() - 7);
      break;
    case '14d':
      from.setDate(now.getDate() - 14);
      break;
    case '30d':
      from.setDate(now.getDate() - 30);
      break;
    default:
      from.setHours(now.getHours() - 24);
      break;
  }

  return {from, to: now};
};

export const formatValue = (
  value: number | string | Array<number | string> | null | undefined
): string => {
  if (value == null) {
    return '0';
  }

  if (Array.isArray(value)) {
    return value.map((item) => formatValue(item)).join(', ');
  }

  if (typeof value === 'number') {
    return value.toLocaleString();
  }

  const parsed = Number(value);
  return Number.isNaN(parsed) ? value : parsed.toLocaleString();
};

export const toCount = (value: unknown): number => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

export const formatOptionalNumber = (
  value: number | null | undefined,
  options?: Intl.NumberFormatOptions
): string => {
  if (value == null || !Number.isFinite(value)) {
    return '--';
  }

  return value.toLocaleString(undefined, options);
};

const formatDurationValue = (value: number): string => {
  const absValue = Math.abs(value);
  const formatted = value.toLocaleString(undefined, {
    maximumFractionDigits: absValue >= 100 ? 0 : absValue >= 10 ? 1 : 2,
  });

  return formatted;
};

export const formatDurationMs = (value: number | string | null | undefined): string => {
  const numeric = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(numeric)) {
    return '--';
  }

  if (Math.abs(numeric) >= 1000) {
    return `${formatDurationValue(numeric / 1000)}s`;
  }

  return `${formatDurationValue(numeric)}ms`;
};

export const formatTooltipTimestamp = (
  timestamp: string | undefined,
  timeZone: string | null | undefined,
  fallback: string
): string => {
  if (!timestamp || !timeZone) {
    return fallback;
  }

  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return fallback;
  }

  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone,
    timeZoneName: 'short',
  }).format(date);
};

export const methodClassName = (method?: string | null): string => {
  const normalized = method?.toUpperCase();

  if (!normalized) {
    return 'route-method--default';
  }

  if (normalized.includes('DELETE')) {
    return 'route-method--delete';
  }
  if (normalized.includes('PATCH')) {
    return 'route-method--patch';
  }
  if (normalized.includes('PUT')) {
    return 'route-method--put';
  }
  if (normalized.includes('POST')) {
    return 'route-method--post';
  }
  if (normalized.includes('GET') || normalized.includes('HEAD')) {
    return 'route-method--get';
  }
  if (normalized.includes('OPTIONS')) {
    return 'route-method--options';
  }
  if (normalized.includes('TRACE')) {
    return 'route-method--trace';
  }

  return 'route-method--default';
};

export const formatRouteLabel = (row: {
  route_name?: string | null;
  url?: string | null;
}): string => {
  const name = row.route_name?.trim();
  if (name) {
    return name;
  }

  const url = row.url?.trim();
  if (url) {
    return url;
  }

  return 'Unknown route';
};
