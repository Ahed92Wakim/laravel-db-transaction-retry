'use client';

import {useEffect, useMemo, useState} from 'react';
import {
    Area,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type {TooltipProps} from 'recharts';

const latencyTrend = [
    {time: '00:00', p95: 420, p99: 610},
    {time: '04:00', p95: 460, p99: 650},
    {time: '08:00', p95: 510, p99: 700},
    {time: '12:00', p95: 480, p99: 670},
    {time: '16:00', p95: 530, p99: 720},
    {time: '20:00', p95: 455, p99: 640},
];

const errorCodes = [
    {code: '400', count: 36},
    {code: '401', count: 14},
    {code: '409', count: 42},
    {code: '429', count: 58},
    {code: '500', count: 21},
    {code: '503', count: 27},
];

const retryReasons = [
    {name: 'Deadlock', value: 38},
    {name: 'Lock timeout', value: 26},
    {name: 'Network jitter', value: 18},
    {name: 'Replica lag', value: 12},
    {name: 'Unknown', value: 6},
];

const kpiBase = [
    {label: 'Escalations', value: '7', delta: '+2 incidents', down: true},
];

const metaCards = [
    {
        label: 'Window',
        value: 'Last 24 hours',
        meta: 'Auto-refresh every 60s',
    },
    {
        label: 'Nodes monitored',
        value: '18 active',
        meta: 'EU-West + US-East',
    },
    {
        label: 'Queue depth',
        value: '243 jobs',
        meta: 'Peak at 10:40',
    },
];

const donutColors = [
    'var(--accent)',
    'var(--accent-cool)',
    'var(--accent-gold)',
    '#4d7c8a',
    '#5f6b89',
];

const timeRanges = [
    {label: '1H', value: '1h', windowLabel: 'Last hour'},
    {label: '24H', value: '24h', windowLabel: 'Last 24 hours'},
    {label: '7D', value: '7d', windowLabel: 'Last 7 days'},
    {label: '14D', value: '14d', windowLabel: 'Last 14 days'},
    {label: '30D', value: '30d', windowLabel: 'Last 30 days'},
] as const;

const routeMetricsLimit = 8;

type TimeRangeValue = (typeof timeRanges)[number]['value'];

type Bucket =
    | 'minute'
    | '15minute'
    | 'hour'
    | '2hour'
    | '4hour'
    | '8hour'
    | 'day';

type RouteMetric = {
    route_hash?: string | null;
    method?: string | null;
    route_name?: string | null;
    url?: string | null;
    attempts: number;
    success: number;
    failure: number;
    last_seen?: string | null;
};

type QueryMetric = {
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

const resolveBucket = (value?: string | null): Bucket | null => {
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

const bucketForRange = (range: TimeRangeValue): Bucket => {
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

const formatBucketLabel = (
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

const resolveTimeWindow = (range: TimeRangeValue) => {
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

const apiBase = '/api/transaction-retry';

const navItems = [
    {label: 'Overview', active: true},
    {label: 'Retry traffic', badge: 'Live'},
    {label: 'Queue health', badge: '243'},
    {label: 'Replica lag'},
    {label: 'Alerts', badge: '7', tone: 'warn'},
];

const statusItems = [
    {label: 'Primary DB', value: 'Stable', tone: 'ok'},
    {label: 'Write locks', value: '3 hotspots', tone: 'warn'},
    {label: 'Auto throttle', value: 'Enabled'},
];

const formatValue = (
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

const toCount = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const formatOptionalNumber = (
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

const formatDurationMs = (value: number | string | null | undefined): string => {
    const numeric = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(numeric)) {
        return '--';
    }

    if (Math.abs(numeric) >= 1000) {
        return `${formatDurationValue(numeric / 1000)}s`;
    }

    return `${formatDurationValue(numeric)}ms`;
};

const formatTooltipTimestamp = (
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

function ChartTooltip({active, payload, label}: TooltipProps<number, string>) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    const title = label ?? payload[0]?.name ?? 'Snapshot';

    return (
        <div className="tooltip">
            <strong>{title}</strong>
            {payload.map((entry) => (
                <div key={`${entry.name}-${entry.value}`}>
                    {entry.name}: {formatValue(entry.value ?? 0)}
                </div>
            ))}
        </div>
    );
}

type QueryTooltipProps = TooltipProps<number, string> & {
    timeZone?: string | null;
};

const durationBucketKeys = new Set(['under_2s', 'over_2s']);

function QueryTooltip({active, payload, label, timeZone}: QueryTooltipProps) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    const timestamp = payload[0]?.payload?.timestamp as string | undefined;
    const fallbackLabel = label ?? payload[0]?.name ?? 'Snapshot';
    const title = formatTooltipTimestamp(
        timestamp,
        timeZone,
        String(fallbackLabel)
    );
    const durationTotal = payload.reduce((sum, entry) => {
        const key = String(entry.dataKey ?? '');
        if (!durationBucketKeys.has(key)) {
            return sum;
        }

        const numeric =
            typeof entry.value === 'number' ? entry.value : Number(entry.value);
        return Number.isFinite(numeric) ? sum + numeric : sum;
    }, 0);
    const hasDurationBuckets = payload.some((entry) =>
        durationBucketKeys.has(String(entry.dataKey ?? ''))
    );

    return (
        <div className="tooltip">
            <strong>{title}</strong>
            {hasDurationBuckets ? (
                <div className="tooltip__total">
                    Total: {formatOptionalNumber(durationTotal)}
                </div>
            ) : null}
            {payload.map((entry) => {
                const key = `${entry.name ?? entry.dataKey ?? 'value'}-${entry.value}`;
                const labelText = entry.name ?? String(entry.dataKey ?? 'Value');
                const dataKey = String(entry.dataKey ?? entry.name ?? '');
                const valueText = dataKey.includes('ms')
                    ? formatDurationMs(entry.value)
                    : formatOptionalNumber(
                        typeof entry.value === 'number'
                            ? entry.value
                            : Number(entry.value)
                    );
                const dotColor = entry.color ?? 'var(--accent)';

                return (
                    <div key={key} className="tooltip__item">
                        <span
                            className="tooltip__dot"
                            style={{backgroundColor: dotColor}}
                        />
                        <span className="tooltip__label">{labelText}</span>
                        <span className="tooltip__value">{valueText}</span>
                    </div>
                );
            })}
        </div>
    );
}

export default function Home() {
    const [theme, setTheme] = useState<'light' | 'dark'>('light');
    const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
    const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
    const [attemptRecords, setAttemptRecords] = useState<number | null>(null);
    const [successRecords, setSuccessRecords] = useState<number | null>(null);
    const [failureRecords, setFailureRecords] = useState<number | null>(null);
    const [retryTraffic, setRetryTraffic] = useState<
        Array<{
            time: string;
            timestamp?: string;
            attempts: number;
            success: number;
            failure: number;
        }>
    >([]);
    const [retryTrafficBucket, setRetryTrafficBucket] = useState<Bucket | null>(null);
    const [retryTrafficStatus, setRetryTrafficStatus] = useState<
        'idle' | 'loading' | 'error'
    >('idle');
    const [routeMetrics, setRouteMetrics] = useState<RouteMetric[]>([]);
    const [routeMetricsStatus, setRouteMetricsStatus] = useState<
        'idle' | 'loading' | 'error'
    >('idle');
    const [queryMetrics, setQueryMetrics] = useState<QueryMetric[]>([]);
    const [queryMetricsBucket, setQueryMetricsBucket] = useState<Bucket | null>(null);
    const [queryMetricsStatus, setQueryMetricsStatus] = useState<
        'idle' | 'loading' | 'error'
    >('idle');

    const selectedRange =
        timeRanges.find((range) => range.value === timeRange) ?? timeRanges[1];
    const timeWindow = useMemo(() => resolveTimeWindow(timeRange), [timeRange]);
    const rangeQuery = useMemo(() => {
        const params = new URLSearchParams({
            from: timeWindow.from.toISOString(),
            to: timeWindow.to.toISOString(),
            window: timeRange,
        });

        return params.toString();
    }, [timeRange, timeWindow]);
    const rangeLabel = selectedRange.windowLabel;
    const rangeShortLabel = selectedRange.label;

    useEffect(() => {
        const stored = window.localStorage.getItem('dashboard-theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const nextTheme =
            stored === 'light' || stored === 'dark'
                ? stored
                : prefersDark
                    ? 'dark'
                    : 'light';
        setTheme(nextTheme);
    }, []);

    useEffect(() => {
        if (typeof Intl === 'undefined') {
            setClientTimeZone('UTC');
            return;
        }

        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        setClientTimeZone(zone || 'UTC');
    }, []);

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        window.localStorage.setItem('dashboard-theme', theme);
    }, [theme]);

    useEffect(() => {
        const controller = new AbortController();
        setAttemptRecords(null);
        setSuccessRecords(null);
        setFailureRecords(null);

        const load = async () => {
            try {
                const response = await fetch(`${apiBase}/metrics/today?${rangeQuery}`, {
                    signal: controller.signal,
                    headers: {Accept: 'application/json'},
                });

                if (!response.ok) {
                    return;
                }

                const payload = (await response.json()) as {
                    data?: {
                        attempt_records?: number | string;
                        success_records?: number | string;
                        failure_records?: number | string;
                    };
                };
                const attempt = Number(payload?.data?.attempt_records);
                const success = Number(payload?.data?.success_records);
                const failure = Number(payload?.data?.failure_records);

                if (!Number.isNaN(attempt)) {
                    setAttemptRecords(attempt);
                }
                if (!Number.isNaN(success)) {
                    setSuccessRecords(success);
                }
                if (!Number.isNaN(failure)) {
                    setFailureRecords(failure);
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setAttemptRecords(null);
                    setSuccessRecords(null);
                    setFailureRecords(null);
                }
            }
        };

        load();

        return () => controller.abort();
    }, [rangeQuery]);

    useEffect(() => {
        const controller = new AbortController();
        setRetryTrafficStatus('loading');

        const load = async () => {
            try {
                const response = await fetch(`${apiBase}/metrics/traffic?${rangeQuery}`, {
                    signal: controller.signal,
                    headers: {Accept: 'application/json'},
                });

                if (!response.ok) {
                    setRetryTrafficStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<{
                        time: string;
                        timestamp?: string;
                        attempts?: number | string;
                        success?: number | string;
                        failure?: number | string;
                        recovered?: number | string;
                    }>;
                    meta?: { bucket?: string };
                };
                const series = Array.isArray(payload?.data) ? payload.data : [];
                const normalized = series.map((point) => ({
                    ...point,
                    attempts: toCount(point.attempts ?? 0),
                    success: toCount(point.success ?? point.recovered ?? 0),
                    failure: toCount(point.failure ?? 0),
                }));
                const bucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

                setRetryTraffic(normalized);
                setRetryTrafficBucket(bucket);
                setRetryTrafficStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setRetryTrafficStatus('error');
                }
            }
        };

        load();

        return () => controller.abort();
    }, [rangeQuery]);

    useEffect(() => {
        const controller = new AbortController();
        setRouteMetricsStatus('loading');

        const load = async () => {
            try {
                const response = await fetch(
                    `${apiBase}/metrics/routes?${rangeQuery}&limit=${routeMetricsLimit}`,
                    {
                        signal: controller.signal,
                        headers: {Accept: 'application/json'},
                    }
                );

                if (!response.ok) {
                    setRouteMetricsStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<{
                        route_hash?: string | null;
                        method?: string | null;
                        route_name?: string | null;
                        url?: string | null;
                        attempts?: number | string;
                        success?: number | string;
                        failure?: number | string;
                        last_seen?: string | null;
                    }>;
                };
                const rows = Array.isArray(payload?.data) ? payload.data : [];
                const normalized = rows.map((row) => ({
                    ...row,
                    attempts: toCount(row.attempts ?? 0),
                    success: toCount(row.success ?? 0),
                    failure: toCount(row.failure ?? 0),
                }));

                setRouteMetrics(normalized);
                setRouteMetricsStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setRouteMetricsStatus('error');
                }
            }
        };

        load();

        return () => controller.abort();
    }, [rangeQuery]);

    useEffect(() => {
        const controller = new AbortController();
        setQueryMetricsStatus('loading');

        const load = async () => {
            try {
                const response = await fetch(`${apiBase}/metrics/queries?${rangeQuery}`, {
                    signal: controller.signal,
                    headers: {Accept: 'application/json'},
                });

                if (!response.ok) {
                    setQueryMetricsStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<{
                        time: string;
                        timestamp?: string;
                        count?: number | string;
                        transaction_count?: number | string;
                        transaction_volume?: number | string;
                        avg_ms?: number | string;
                        p95_ms?: number | string;
                        under_2s?: number | string;
                        over_2s?: number | string;
                    }>;
                    meta?: { bucket?: string };
                };
                const series = Array.isArray(payload?.data) ? payload.data : [];
                const normalized = series.map((point) => {
                    const avgMs = Number(point.avg_ms ?? 0);
                    const p95Ms = Number(point.p95_ms ?? 0);
                    const queryCount = toCount(point.count ?? 0);
                    const transactionCount = toCount(
                        point.transaction_count ?? queryCount
                    );
                    const under2s = toCount(point.under_2s ?? 0);
                    const over2s = toCount(point.over_2s ?? 0);
                    const bucketTotal = under2s + over2s;
                    const hasDurationBuckets =
                        point.under_2s != null || point.over_2s != null;
                    const hasTransactionVolume = point.transaction_volume != null;
                    const transactionVolumeFallback = toCount(
                        point.transaction_volume ?? 0
                    );
                    const transactionVolume = hasDurationBuckets
                        ? bucketTotal
                        : hasTransactionVolume
                            ? transactionVolumeFallback
                            : transactionCount;

                    return {
                        ...point,
                        count: queryCount,
                        transaction_count: transactionCount,
                        transaction_volume: transactionVolume,
                        avg_ms: Number.isFinite(avgMs) ? avgMs : 0,
                        p95_ms: Number.isFinite(p95Ms) ? p95Ms : 0,
                        under_2s: under2s,
                        over_2s: over2s,
                    };
                });
                const bucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

                setQueryMetrics(normalized);
                setQueryMetricsBucket(bucket);
                setQueryMetricsStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setQueryMetricsStatus('error');
                }
            }
        };

        load();

        return () => controller.abort();
    }, [rangeQuery]);

    const kpis = [
        {
            label: 'Attempts',
            value: attemptRecords === null ? '--' : formatValue(attemptRecords),
            delta: rangeLabel,
            down: false,
        },
        {
            label: 'Success',
            value: successRecords === null ? '--' : formatValue(successRecords),
            delta: rangeLabel,
            down: false,
        },
        {
            label: 'Failure',
            value: failureRecords === null ? '--' : formatValue(failureRecords),
            delta: rangeLabel,
            down: false,
        },
        ...kpiBase,
    ];
    const retryTrafficMessage =
        retryTrafficStatus === 'loading'
            ? 'Loading traffic...'
            : retryTrafficStatus === 'error'
                ? 'Unable to load traffic.'
                : retryTraffic.length === 0
                    ? 'No retry events in this window.'
                    : null;
    const retryTrafficDisplay = useMemo(
        () =>
            retryTraffic.map((point) => ({
                ...point,
                time: formatBucketLabel(
                    point.timestamp,
                    retryTrafficBucket,
                    clientTimeZone,
                    point.time
                ),
            })),
        [clientTimeZone, retryTraffic, retryTrafficBucket]
    );
    const routeMetricsMessage =
        routeMetricsStatus === 'loading'
            ? 'Loading routes...'
            : routeMetricsStatus === 'error'
                ? 'Unable to load routes.'
                : routeMetrics.length === 0
                    ? 'No route retries in this window.'
                    : null;
    const queryMetricsMessage =
        queryMetricsStatus === 'loading'
            ? 'Loading query metrics...'
            : queryMetricsStatus === 'error'
                ? 'Unable to load query metrics.'
                : queryMetrics.length === 0
                    ? 'No query records in this window.'
                    : null;
    const queryMetricsDisplay = useMemo(
        () =>
            queryMetrics.map((point) => ({
                ...point,
                time: formatBucketLabel(
                    point.timestamp,
                    queryMetricsBucket,
                    clientTimeZone,
                    point.time
                ),
            })),
        [clientTimeZone, queryMetrics, queryMetricsBucket]
    );
    const transactionVolumeSummary = useMemo(() => {
        const totals = queryMetrics
            .map((point) => point.transaction_volume ?? point.transaction_count)
            .filter((value) => Number.isFinite(value));

        if (totals.length === 0) {
            return {
                total: null,
                peak: null,
                average: null,
                under_2s: null,
                over_2s: null,
            };
        }

        const total = totals.reduce((sum, value) => sum + value, 0);
        const peak = Math.max(...totals);
        const average = total / totals.length;
        const bucketTotals = queryMetrics.reduce(
            (acc, point) => ({
                under_2s: acc.under_2s + (point.under_2s ?? 0),
                over_2s: acc.over_2s + (point.over_2s ?? 0),
            }),
            {
                under_2s: 0,
                over_2s: 0,
            }
        );

        return {
            total,
            peak,
            average,
            ...bucketTotals,
        };
    }, [queryMetrics]);
    const queryDurationSummary = useMemo(() => {
        const avgValues = queryMetrics
            .map((point) => point.avg_ms)
            .filter((value) => Number.isFinite(value));
        const p95Values = queryMetrics
            .map((point) => point.p95_ms)
            .filter((value) => Number.isFinite(value));
        const avgAvg =
            avgValues.length > 0
                ? avgValues.reduce((sum, value) => sum + value, 0) / avgValues.length
                : null;
        const avgP95 =
            p95Values.length > 0
                ? p95Values.reduce((sum, value) => sum + value, 0) / p95Values.length
                : null;

        return {
            minAvg: avgValues.length > 0 ? Math.min(...avgValues) : null,
            maxP95: p95Values.length > 0 ? Math.max(...p95Values) : null,
            avgAvg,
            avgP95,
        };
    }, [queryMetrics]);
    const queryDurationRange =
        queryDurationSummary.minAvg != null && queryDurationSummary.maxP95 != null
            ? `${formatDurationMs(queryDurationSummary.minAvg)} - ${formatDurationMs(
                queryDurationSummary.maxP95
            )}`
            : '--';
    const formatRouteLabel = (row: RouteMetric): string => {
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

    return (
        <main className="dashboard-shell">
            <aside className="sidebar">
                <div>
                    <div className="sidebar__brand">
                        <img
                            className="sidebar__logo"
                            src="/transaction-retry/logo-cropped.svg"
                            alt="Database Transaction Retry"
                        />
                        {/*<span className="sidebar__badge">RTR</span>*/}
                        <div>
                            <p className="sidebar__title">Retry Control</p>
                            {/*<p className="sidebar__subtitle">nightwatch layer</p>*/}
                        </div>
                    </div>
                    <nav className="sidebar__nav">
                        {navItems.map((item) => (
                            <button
                                key={item.label}
                                type="button"
                                className={`sidebar__item${item.active ? ' sidebar__item--active' : ''}`}
                            >
                                <span>{item.label}</span>
                                {item.badge ? (
                                    <span
                                        className={`sidebar__pill${item.tone === 'warn' ? ' sidebar__pill--warn' : ''}`}>
                                        {item.badge}
                                    </span>
                                ) : null}
                            </button>
                        ))}
                    </nav>
                </div>
                <div className="sidebar__panel">
                    <div className="sidebar__panel-header">
                        <span>System status</span>
                        <button
                            type="button"
                            className="theme-toggle"
                            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                            aria-pressed={theme === 'dark'}
                        >
                            {theme === 'dark' ? 'Light mode' : 'Dark mode'}
                        </button>
                    </div>
                    <div className="sidebar__panel-body">
                        {statusItems.map((item) => (
                            <div className="sidebar__status" key={item.label}>
                                <span className="sidebar__status-label">{item.label}</span>
                                <span
                                    className={`sidebar__status-value${
                                        item.tone === 'ok'
                                            ? ' sidebar__status-value--ok'
                                            : item.tone === 'warn'
                                                ? ' sidebar__status-value--warn'
                                                : ''
                                    }`}
                                >
                                    {item.value}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </aside>

            <div className="dashboard">
                <header className="dashboard-header">
                    <div className="dashboard-header__intro">
                        <span className="eyebrow">Retry telemetry</span>
                        <h1 className="dashboard-header__title">
                            Transaction Retry Command Center
                        </h1>
                        <p className="dashboard-header__subtitle">
                            Window: {rangeLabel}. Metrics update across the dashboard.
                        </p>
                    </div>
                    <div className="date-filter" role="group" aria-label="Date range">
                        <span className="date-filter__label">Date range</span>
                        <div className="date-filter__options">
                            {timeRanges.map((range) => (
                                <button
                                    key={range.value}
                                    type="button"
                                    className={`date-filter__button${
                                        range.value === timeRange
                                            ? ' date-filter__button--active'
                                            : ''
                                    }`}
                                    onClick={() => setTimeRange(range.value)}
                                    aria-pressed={range.value === timeRange}
                                >
                                    {range.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </header>

                <section className="chart-pair">
                    {/*<div className="card-header chart-pair__header">*/}
                    {/*    <div>*/}
                    {/*        <p className="card-title">DB query insights</p>*/}
                    {/*        <p className="card-subtitle">*/}
                    {/*            Volume and duration trends - {rangeLabel}*/}
                    {/*        </p>*/}
                    {/*    </div>*/}
                    {/*    <span className="card-chip">{rangeShortLabel}</span>*/}
                    {/*</div>*/}
                    <div className="chart-pair__grid">
                        <div className="card chart-card">
                            <div className="card-header">
                                <div>
                                    <p className="card-title">DB transaction volume</p>
                                    <p className="card-subtitle">
                                        Logged transactions by duration - {rangeLabel}
                                    </p>
                                </div>
                                <span className="card-chip">{rangeShortLabel}</span>
                            </div>
                            <div className="chart-summary">
                                <div className="chart-summary__main">
                                    <span className="chart-summary__label">
                                        Total transactions
                                    </span>
                                    <span className="chart-summary__value">
                                        {formatOptionalNumber(
                                            transactionVolumeSummary.total
                                        )}
                                    </span>
                                    <span className="chart-summary__meta">{rangeLabel}</span>
                                </div>
                                <div className="chart-summary__stats">
                                    <div className="chart-summary__stat">
                                        {/*<span className="legend-dot"/>*/}
                                        {/*<span>Peak</span>*/}
                                        {/*<strong>*/}
                                        {/*    {formatOptionalNumber(*/}
                                        {/*        transactionVolumeSummary.peak*/}
                                        {/*    )}*/}
                                        {/*</strong>*/}
                                        <span className="legend-dot legend-dot--cool"/>
                                        <span>&lt; 2s</span>
                                        <strong>
                                            {formatOptionalNumber(
                                                transactionVolumeSummary.under_2s
                                            )}
                                        </strong>
                                    </div>
                                    <div className="chart-summary__stat">
                                        {/*<span className="legend-dot legend-dot--cool"/>*/}
                                        {/*<span>Avg / bucket</span>*/}
                                        {/*<strong>*/}
                                        {/*    {formatOptionalNumber(*/}
                                        {/*        transactionVolumeSummary.average,*/}
                                        {/*        {*/}
                                        {/*            maximumFractionDigits: 1,*/}
                                        {/*        }*/}
                                        {/*    )}*/}
                                        {/*</strong>*/}
                                        <span className="legend-dot legend-dot--hot"/>
                                        <span>&gt; 2s</span>
                                        <strong>
                                            {formatOptionalNumber(
                                                transactionVolumeSummary.over_2s
                                            )}
                                        </strong>
                                    </div>
                                </div>
                                {/*<div className="chart-summary__ranges">*/}
                                {/*    <div className="chart-summary__range">*/}
                                {/*        <span className="legend-dot legend-dot--cool"/>*/}
                                {/*        <span>&lt; 2s</span>*/}
                                {/*        <strong>*/}
                                {/*            {formatOptionalNumber(*/}
                                {/*                transactionVolumeSummary.under_2s*/}
                                {/*            )}*/}
                                {/*        </strong>*/}
                                {/*    </div>*/}
                                {/*    <div className="chart-summary__range">*/}
                                {/*        <span className="legend-dot legend-dot--hot"/>*/}
                                {/*        <span>&gt; 2s</span>*/}
                                {/*        <strong>*/}
                                {/*            {formatOptionalNumber(*/}
                                {/*                transactionVolumeSummary.over_2s*/}
                                {/*            )}*/}
                                {/*        </strong>*/}
                                {/*    </div>*/}
                                {/*</div>*/}
                            </div>
                            <div
                                className={`chart-frame${
                                    queryMetricsMessage ? ' chart-frame--empty' : ''
                                }`}
                            >
                                {queryMetricsMessage ? (
                                    <p className="chart-empty">{queryMetricsMessage}</p>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={queryMetricsDisplay}
                                            margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                            syncId="query-metrics"
                                            syncMethod="index"
                                        >
                                            <CartesianGrid
                                                stroke="rgba(15, 23, 42, 0.08)"
                                                strokeDasharray="3 3"
                                            />
                                            <XAxis dataKey="time" tickLine={false} axisLine={false}/>
                                            <YAxis tickLine={false} axisLine={false}/>
                                            <Tooltip
                                                content={<QueryTooltip timeZone={clientTimeZone}/>}
                                                cursor={{
                                                    fill: 'var(--grid)',
                                                    stroke: 'var(--border)',
                                                }}
                                            />
                                            <Bar
                                                dataKey="under_2s"
                                                name="<2s"
                                                stackId="duration"
                                                fill="var(--accent-cool)"
                                            />
                                            <Bar
                                                dataKey="over_2s"
                                                name=">2s"
                                                stackId="duration"
                                                fill="var(--accent-hot)"
                                                radius={[10, 10, 4, 4]}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                )}
                            </div>
                        </div>

                        <div className="card chart-card">
                            <div className="card-header">
                                <div>
                                    <p className="card-title">Query duration</p>
                                    <p className="card-subtitle">
                                        Avg vs p95 execution time (ms) - {rangeLabel}
                                    </p>
                                </div>
                                <span className="card-chip">{rangeShortLabel}</span>
                            </div>
                            <div className="chart-summary">
                                <div className="chart-summary__main">
                                    <span className="chart-summary__label">Duration range</span>
                                    <span className="chart-summary__value">
                                        {queryDurationRange}
                                    </span>
                                    <span className="chart-summary__meta">{rangeLabel}</span>
                                </div>
                                <div className="chart-summary__stats">
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--cool"/>
                                        <span>Avg</span>
                                        <strong>
                                            {formatDurationMs(queryDurationSummary.avgAvg)}
                                        </strong>
                                    </div>
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--gold"/>
                                        <span>P95</span>
                                        <strong>
                                            {formatDurationMs(queryDurationSummary.avgP95)}
                                        </strong>
                                    </div>
                                </div>
                            </div>
                            <div
                                className={`chart-frame${
                                    queryMetricsMessage ? ' chart-frame--empty' : ''
                                }`}
                            >
                                {queryMetricsMessage ? (
                                    <p className="chart-empty">{queryMetricsMessage}</p>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart
                                            data={queryMetricsDisplay}
                                            margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                            syncId="query-metrics"
                                            syncMethod="index"
                                        >
                                            <CartesianGrid
                                                stroke="rgba(15, 23, 42, 0.08)"
                                                strokeDasharray="3 3"
                                            />
                                            <XAxis dataKey="time" tickLine={false} axisLine={false}/>
                                            <YAxis tickLine={false} axisLine={false}/>
                                            <Tooltip
                                                content={<QueryTooltip timeZone={clientTimeZone}/>}
                                                cursor={{stroke: 'var(--border)', strokeWidth: 1}}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="avg_ms"
                                                name="Avg ms"
                                                stroke="var(--accent-cool)"
                                                strokeWidth={2}
                                                dot={false}
                                                activeDot={{r: 4, strokeWidth: 2}}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="p95_ms"
                                                name="P95 ms"
                                                stroke="var(--accent-gold)"
                                                strokeWidth={2}
                                                dot={false}
                                                activeDot={{r: 4, strokeWidth: 2}}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid metrics">
                    {kpis.map((kpi) => (
                        <div className="card metric-card" key={kpi.label}>
                            <span className="metric-card__label">{kpi.label}</span>
                            <span className="metric-card__value">{kpi.value}</span>
                            <span
                                className={`metric-card__delta${
                                    kpi.down ? ' metric-card__delta--down' : ''
                                }`}
                            >
                {kpi.delta}
              </span>
                        </div>
                    ))}
                </section>

                <section className="grid charts">
                    <div className="card chart-card chart-card--wide">
                        <div className="card-header">
                            <div>
                                <p className="card-title">Retry traffic</p>
                                <p className="card-subtitle">
                                    Attempts, success, and failure - {rangeLabel}
                                </p>
                            </div>
                            <span className="card-chip">{rangeShortLabel}</span>
                        </div>
                        <div
                            className={`chart-frame${
                                retryTrafficMessage ? ' chart-frame--empty' : ''
                            }`}
                        >
                            {retryTrafficMessage ? (
                                <p className="chart-empty">{retryTrafficMessage}</p>
                            ) : (
                                <ResponsiveContainer width="100%" height="100%">
                                    <ComposedChart
                                        data={retryTrafficDisplay}
                                        margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                    >
                                        <defs>
                                            <linearGradient
                                                id="retryFill"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="5%"
                                                    stopColor="var(--accent)"
                                                    stopOpacity={0.4}
                                                />
                                                <stop
                                                    offset="95%"
                                                    stopColor="var(--accent)"
                                                    stopOpacity={0.05}
                                                />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid
                                            stroke="rgba(15, 23, 42, 0.08)"
                                            strokeDasharray="3 3"
                                        />
                                        <XAxis dataKey="time" tickLine={false} axisLine={false}/>
                                        <YAxis tickLine={false} axisLine={false}/>
                                        <Tooltip content={<ChartTooltip/>}/>
                                        <Area
                                            type="monotone"
                                            dataKey="attempts"
                                            name="Attempts"
                                            stroke="var(--accent)"
                                            strokeWidth={2}
                                            fill="url(#retryFill)"
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="success"
                                            name="Success"
                                            stroke="var(--accent-cool)"
                                            strokeWidth={2}
                                            dot={false}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="failure"
                                            name="Failure"
                                            stroke="var(--accent-gold)"
                                            strokeWidth={2}
                                            dot={false}
                                        />
                                    </ComposedChart>
                                </ResponsiveContainer>
                            )}
                        </div>
                        <div className="legend">
              <span>
                <span className="legend-dot"/> Attempts
              </span>
                            <span>
                <span className="legend-dot legend-dot--cool"/> Success
              </span>
                            <span>
                <span className="legend-dot legend-dot--gold"/> Failure
              </span>
                        </div>
                    </div>
                    <div className="route-table chart-card--wide">
                        <div className="route-table__header">
                            <p className="route-table__title">Routes with retries</p>
                            <span className="route-table__meta">
                                Top {routeMetricsLimit}
                            </span>
                        </div>
                        {routeMetricsMessage ? (
                            <p className="route-table__empty">{routeMetricsMessage}</p>
                        ) : (
                            <div className="route-table__scroll">
                                <table>
                                    <thead>
                                    <tr>
                                        <th>Route</th>
                                        <th>Attempts</th>
                                        <th>Success</th>
                                        <th>Failure</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    {routeMetrics.map((row) => (
                                        <tr
                                            key={`${row.route_hash ?? 'route'}-${row.method ?? 'method'}-${row.route_name ?? row.url ?? 'unknown'}`}
                                        >
                                            <td>
                                                <div className="route-cell">
                                                    <span className="route-method">
                                                        {row.method
                                                            ? row.method.toUpperCase()
                                                            : '--'}
                                                    </span>
                                                    <span
                                                        className="route-name"
                                                        title={formatRouteLabel(row)}
                                                    >
                                                        {formatRouteLabel(row)}
                                                    </span>
                                                </div>
                                            </td>
                                            <td>{formatValue(row.attempts)}</td>
                                            <td>{formatValue(row.success)}</td>
                                            <td>{formatValue(row.failure)}</td>
                                        </tr>
                                    ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <div className="card chart-card">
                        <div className="card-header">
                            <div>
                                <p className="card-title">Failure codes</p>
                                <p className="card-subtitle">
                                    Distribution of error responses - {rangeLabel}
                                </p>
                            </div>
                            <span className="card-chip">{rangeShortLabel}</span>
                        </div>
                        <div className="chart-frame">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={errorCodes}
                                    margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                >
                                    <CartesianGrid
                                        stroke="rgba(15, 23, 42, 0.08)"
                                        strokeDasharray="3 3"
                                    />
                                    <XAxis dataKey="code" tickLine={false} axisLine={false}/>
                                    <YAxis tickLine={false} axisLine={false}/>
                                    <Tooltip content={<ChartTooltip/>}/>
                                    <Bar
                                        dataKey="count"
                                        radius={[10, 10, 4, 4]}
                                        fill="var(--accent-gold)"
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                        <p className="chart-footnote">429 and 409 are trending up.</p>
                    </div>

                    <div className="card chart-card">
                        <div className="card-header">
                            <div>
                                <p className="card-title">Retry reasons</p>
                                <p className="card-subtitle">
                                    What triggers the backoff - {rangeLabel}
                                </p>
                            </div>
                            <span className="card-chip">{rangeShortLabel}</span>
                        </div>
                        <div className="chart-frame">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Tooltip content={<ChartTooltip/>}/>
                                    <Pie
                                        data={retryReasons}
                                        dataKey="value"
                                        nameKey="name"
                                        innerRadius={55}
                                        outerRadius={90}
                                        paddingAngle={4}
                                    >
                                        {retryReasons.map((entry, index) => (
                                            <Cell
                                                key={entry.name}
                                                fill={donutColors[index % donutColors.length]}
                                            />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="legend">
                            {retryReasons.map((reason, index) => (
                                <span key={reason.name}>
                  <span
                      className="legend-dot"
                      style={{
                          background: donutColors[index % donutColors.length],
                      }}
                  />
                                    {reason.name}
                </span>
                            ))}
                        </div>
                    </div>
                </section>


            </div>
        </main>
    );
}
