'use client';

import {useEffect, useMemo, useState} from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
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
    {label: 'Recovered', value: '93.8%', delta: '+1.4% success lift', down: false},
    {label: 'P95 latency', value: '482 ms', delta: '-18 ms', down: false},
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

type TimeRangeValue = (typeof timeRanges)[number]['value'];

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

export default function Home() {
    const [theme, setTheme] = useState<'light' | 'dark'>('light');
    const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
    const [totalRetries, setTotalRetries] = useState<number | null>(null);
    const [retryTraffic, setRetryTraffic] = useState<
        Array<{time: string; attempts: number; recovered: number}>
    >([]);
    const [retryTrafficStatus, setRetryTrafficStatus] = useState<
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
        document.documentElement.dataset.theme = theme;
        window.localStorage.setItem('dashboard-theme', theme);
    }, [theme]);

    useEffect(() => {
        const controller = new AbortController();
        setTotalRetries(null);

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
                    data?: { total_retries?: number | string };
                };
                const total = Number(payload?.data?.total_retries);

                if (!Number.isNaN(total)) {
                    setTotalRetries(total);
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setTotalRetries(null);
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
                    data?: Array<{time: string; attempts: number; recovered: number}>;
                };

                setRetryTraffic(Array.isArray(payload?.data) ? payload.data : []);
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

    const kpis = [
        {
            label: 'Total retries',
            value: totalRetries === null ? '--' : formatValue(totalRetries),
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

    return (
        <main className="dashboard-shell">
            <aside className="sidebar">
                <div>
                    <div className="sidebar__brand">
                        <span className="sidebar__badge">RTR</span>
                        <div>
                            <p className="sidebar__title">Retry Control</p>
                            <p className="sidebar__subtitle">nightwatch layer</p>
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
                                        className={`sidebar__pill${
                                            item.tone === 'warn' ? ' sidebar__pill--warn' : ''
                                        }`}
                                    >
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
                                    Attempts vs recovered transactions - {rangeLabel}
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
                                    <AreaChart
                                        data={retryTraffic}
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
                                            stroke="var(--accent)"
                                            strokeWidth={2}
                                            fill="url(#retryFill)"
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="recovered"
                                            stroke="var(--accent-cool)"
                                            strokeWidth={2}
                                            dot={false}
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            )}
                        </div>
                        <div className="legend">
              <span>
                <span className="legend-dot"/> Attempts
              </span>
                            <span>
                <span className="legend-dot legend-dot--cool"/> Recovered
              </span>
                        </div>
                    </div>

                    <div className="card chart-card">
                        <div className="card-header">
                            <div>
                                <p className="card-title">Latency drift</p>
                                <p className="card-subtitle">
                                    P95 and P99 retry duration (ms) - {rangeLabel}
                                </p>
                            </div>
                            <span className="card-chip">{rangeShortLabel}</span>
                        </div>
                        <div className="chart-frame">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart
                                    data={latencyTrend}
                                    margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                >
                                    <CartesianGrid
                                        stroke="rgba(15, 23, 42, 0.08)"
                                        strokeDasharray="3 3"
                                    />
                                    <XAxis dataKey="time" tickLine={false} axisLine={false}/>
                                    <YAxis tickLine={false} axisLine={false}/>
                                    <Tooltip content={<ChartTooltip/>}/>
                                    <Line
                                        type="monotone"
                                        dataKey="p95"
                                        stroke="var(--accent-cool)"
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="p99"
                                        stroke="var(--accent)"
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                        <p className="chart-footnote">
                            Latency stays within alerting bounds.
                        </p>
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
