'use client';

import {useEffect, useMemo, useState} from 'react';
import {useRouter, useSearchParams} from 'next/navigation';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import DashboardShell from '../../components/DashboardShell';
import {QueryTooltip} from '../../components/dashboard-ui';
import {usePersistentTimeRange} from '../../lib/usePersistentTimeRange';
import {
    apiBase,
    bucketForRange,
    formatBucketLabel,
    formatDashboardDateTime,
    formatDurationMs,
    formatOptionalNumber,
    formatRouteLabel,
    formatValue,
    methodClassName,
    resolveClientTimeZone,
    resolveBucket,
    resolveTimeWindow,
    timeRanges,
    toCount,
    type Bucket,
    type QueryMetric,
} from '../../lib/dashboard';

type TransactionLogRow = {
    id: number | string;
    completed_at?: string | null;
    http_method?: string | null;
    route_name?: string | null;
    url?: string | null;
    http_status?: number | string | null;
    elapsed_ms?: number | string | null;
    total_queries_count?: number | string | null;
    slow_queries_count?: number | string | null;
};

const transactionPageSize = 20;

const normalizeParam = (value: string | null): string | null => {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
};

const toOptionalNumber = (value: unknown): number | null => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const resolveStatusTone = (status: number | null): string => {
    if (!status) {
        return 'status-pill--muted';
    }
    if (status >= 500) {
        return 'status-pill--error';
    }
    if (status >= 400) {
        return 'status-pill--warn';
    }
    return 'status-pill--ok';
};

export default function TransactionDetailClient() {
    const router = useRouter();
    const searchParams = useSearchParams();
    const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
    const [timeRange, setTimeRange] = usePersistentTimeRange();
    const [queryMetrics, setQueryMetrics] = useState<QueryMetric[]>([]);
    const [queryMetricsBucket, setQueryMetricsBucket] = useState<Bucket | null>(null);
    const [queryMetricsStatus, setQueryMetricsStatus] = useState<'idle' | 'loading' | 'error'>(
        'idle'
    );
    const [transactionRows, setTransactionRows] = useState<TransactionLogRow[]>([]);
    const [transactionStatus, setTransactionStatus] = useState<'idle' | 'loading' | 'error'>(
        'idle'
    );
    const [transactionPage, setTransactionPage] = useState<number>(1);
    const [transactionTotal, setTransactionTotal] = useState<number>(0);
    const [searchQuery, setSearchQuery] = useState<string>('');

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

    const routeNameParam = normalizeParam(searchParams.get('route_name'));
    const urlParam = normalizeParam(searchParams.get('url'));
    const methodParam = normalizeParam(searchParams.get('method'));
    const hasRouteFilter = Boolean(routeNameParam || urlParam);
    const routeLabel = formatRouteLabel({route_name: routeNameParam, url: urlParam});
    const routeKey = `${methodParam ?? ''}-${routeNameParam ?? ''}-${urlParam ?? ''}`;

    useEffect(() => {
        setClientTimeZone(resolveClientTimeZone());
    }, []);

    useEffect(() => {
        setTransactionPage(1);
    }, [routeKey, timeRange]);

    useEffect(() => {
        if (!hasRouteFilter) {
            setQueryMetrics([]);
            setQueryMetricsBucket(null);
            setQueryMetricsStatus('idle');
            return;
        }

        const controller = new AbortController();
        setQueryMetricsStatus('loading');

        const load = async () => {
            try {
                const params = new URLSearchParams(rangeQuery);
                if (methodParam) {
                    params.set('method', methodParam);
                }
                if (routeNameParam) {
                    params.set('route_name', routeNameParam);
                } else if (urlParam) {
                    params.set('url', urlParam);
                }

                const response = await fetch(`${apiBase}/metrics/queries?${params.toString()}`, {
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
                    meta?: {bucket?: string};
                };

                const series = Array.isArray(payload?.data) ? payload.data : [];
                const normalized = series.map((point) => {
                    const avgMs = Number(point.avg_ms ?? 0);
                    const p95Ms = Number(point.p95_ms ?? 0);
                    const queryCount = toCount(point.count ?? 0);
                    const transactionCount = toCount(point.transaction_count ?? queryCount);
                    const under2s = toCount(point.under_2s ?? 0);
                    const over2s = toCount(point.over_2s ?? 0);
                    const bucketTotal = under2s + over2s;
                    const hasDurationBuckets = point.under_2s != null || point.over_2s != null;
                    const hasTransactionVolume = point.transaction_volume != null;
                    const transactionVolumeFallback = toCount(point.transaction_volume ?? 0);
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
    }, [rangeQuery, routeKey, timeRange, hasRouteFilter, methodParam, routeNameParam, urlParam]);

    useEffect(() => {
        if (!hasRouteFilter) {
            setTransactionRows([]);
            setTransactionTotal(0);
            setTransactionStatus('idle');
            return;
        }

        const controller = new AbortController();
        setTransactionStatus('loading');

        const load = async () => {
            try {
                const params = new URLSearchParams(rangeQuery);
                params.set('page', String(transactionPage));
                params.set('per_page', String(transactionPageSize));
                if (searchQuery.trim() !== '') {
                    params.set('search', searchQuery.trim());
                }
                if (methodParam) {
                    params.set('method', methodParam);
                }
                if (routeNameParam) {
                    params.set('route_name', routeNameParam);
                } else if (urlParam) {
                    params.set('url', urlParam);
                }

                const response = await fetch(
                    `${apiBase}/transaction-logs?${params.toString()}`,
                    {
                        signal: controller.signal,
                        headers: {Accept: 'application/json'},
                    }
                );

                if (!response.ok) {
                    setTransactionStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<TransactionLogRow>;
                    meta?: {total?: number | string; page?: number | string};
                };

                const rows = Array.isArray(payload?.data) ? payload.data : [];
                const total = Number(payload?.meta?.total ?? 0);
                const page = Number(payload?.meta?.page ?? transactionPage);

                setTransactionRows(rows);
                setTransactionTotal(Number.isFinite(total) ? total : 0);
                if (Number.isFinite(page) && page !== transactionPage) {
                    setTransactionPage(page);
                }
                setTransactionStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setTransactionStatus('error');
                }
            }
        };

        load();
        return () => controller.abort();
    }, [
        rangeQuery,
        routeKey,
        transactionPage,
        searchQuery,
        hasRouteFilter,
        methodParam,
        routeNameParam,
        urlParam,
    ]);

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
        const totals = queryMetrics.map((p) => p.transaction_volume ?? p.transaction_count);
        if (totals.length === 0) {
            return {total: null, under_2s: null, over_2s: null};
        }
        const total = totals.reduce((sum, v) => sum + Number(v), 0);
        const under2s = queryMetrics.reduce((sum, p) => sum + (p.under_2s ?? 0), 0);
        const over2s = queryMetrics.reduce((sum, p) => sum + (p.over_2s ?? 0), 0);
        return {total, under_2s: under2s, over_2s: over2s};
    }, [queryMetrics]);

    const queryDurationSummary = useMemo(() => {
        const avgValues = queryMetrics
            .map((p) => p.avg_ms)
            .filter((v) => Number.isFinite(v) && v > 0);
        const p95Values = queryMetrics
            .map((p) => p.p95_ms)
            .filter((v) => Number.isFinite(v) && v > 0);
        return {
            minAvg: avgValues.length > 0 ? Math.min(...avgValues) : null,
            maxP95: p95Values.length > 0 ? Math.max(...p95Values) : null,
            avgAvg:
                avgValues.length > 0
                    ? avgValues.reduce((s, v) => s + v, 0) / avgValues.length
                    : null,
            avgP95:
                p95Values.length > 0
                    ? p95Values.reduce((s, v) => s + v, 0) / p95Values.length
                    : null,
        };
    }, [queryMetrics]);
    const queryDurationRange =
        queryDurationSummary.minAvg != null && queryDurationSummary.maxP95 != null
            ? `${formatDurationMs(queryDurationSummary.minAvg)} – ${formatDurationMs(
                queryDurationSummary.maxP95
            )}`
            : '--';

    const queryMetricsMessage =
        !hasRouteFilter
            ? 'Select a route to load metrics.'
            : queryMetricsStatus === 'loading'
                ? 'Loading metrics...'
                : queryMetricsStatus === 'error'
                    ? 'Unable to load metrics.'
                    : queryMetrics.length === 0
                        ? 'No data in this window.'
                        : null;

    const transactionTotalPages = Math.max(1, Math.ceil(transactionTotal / transactionPageSize));
    const currentTransactionPage = Math.min(transactionPage, transactionTotalPages);
    const transactionMessage =
        !hasRouteFilter
            ? 'Select a route to view transactions.'
            : transactionStatus === 'loading'
                ? 'Loading transactions...'
                : transactionStatus === 'error'
                    ? 'Unable to load transactions.'
                    : transactionRows.length === 0
                        ? 'No transactions recorded for this route in this window.'
                        : null;

    useEffect(() => {
        setTransactionPage((prev) => Math.min(prev, transactionTotalPages));
    }, [transactionTotalPages]);

    return (
        <DashboardShell
            timeRange={timeRange}
            onTimeRangeChange={setTimeRange}
            rangeLabel={rangeLabel}
            pageTitle="Transaction details"
        >
            <section className="route-detail-page">
                <div className="route-hero">
                    <p className="route-hero__eyebrow">Route information</p>
                    <div className="route-hero__row">
                        <div>
                            <h1 className="route-hero__title">{routeLabel}</h1>
                            <p className="route-hero__subtitle">Transaction Log · {rangeLabel}</p>
                        </div>
                        <span className={`route-method ${methodClassName(methodParam)}`}>
                            {methodParam ? methodParam.toUpperCase() : '--'}
                        </span>
                    </div>
                    <div className="route-hero__meta">
                        {routeNameParam ? (
                            <span className="route-hero__meta-item">Route name: {routeNameParam}</span>
                        ) : null}
                        {urlParam ? (
                            <span className="route-hero__meta-item">URL: {urlParam}</span>
                        ) : null}
                        <span className="route-hero__meta-item">{rangeShortLabel} window</span>
                    </div>
                </div>

                <section className="chart-pair">
                    <div className="chart-pair__grid">
                        <div className="card chart-card">
                            <div className="chart-summary">
                                <div className="chart-summary__main">
                                    <span className="chart-summary__label">Total transactions</span>
                                    <span className="chart-summary__value">
                                        {formatOptionalNumber(transactionVolumeSummary.total)}
                                    </span>
                                </div>
                                <div className="chart-summary__stats">
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--cool"/>
                                        <span>&lt; 2s</span>
                                        <strong>{formatOptionalNumber(transactionVolumeSummary.under_2s)}</strong>
                                    </div>
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--hot"/>
                                        <span>&gt; 2s</span>
                                        <strong>{formatOptionalNumber(transactionVolumeSummary.over_2s)}</strong>
                                    </div>
                                </div>
                            </div>
                            <div className={`chart-frame${queryMetricsMessage ? ' chart-frame--empty' : ''}`}>
                                {queryMetricsMessage ? (
                                    <p className="chart-empty">{queryMetricsMessage}</p>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={queryMetricsDisplay}
                                            margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                            syncId="tx-detail"
                                            syncMethod="index"
                                        >
                                            <CartesianGrid
                                                stroke="rgba(15, 23, 42, 0.08)"
                                                strokeDasharray="3 3"
                                            />
                                            <XAxis dataKey="time" tickLine={false} axisLine={false}/>
                                            <YAxis tickLine={false} axisLine={false}/>
                                            <Tooltip content={<QueryTooltip timeZone={clientTimeZone}/>}/>
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
                            <div className="chart-summary">
                                <div className="chart-summary__main">
                                    <span className="chart-summary__label">Duration range</span>
                                    <span className="chart-summary__value">{queryDurationRange}</span>
                                </div>
                                <div className="chart-summary__stats">
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--cool"/>
                                        <span>Avg</span>
                                        <strong>{formatDurationMs(queryDurationSummary.avgAvg)}</strong>
                                    </div>
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--gold"/>
                                        <span>P95</span>
                                        <strong>{formatDurationMs(queryDurationSummary.avgP95)}</strong>
                                    </div>
                                </div>
                            </div>
                            <div className={`chart-frame${queryMetricsMessage ? ' chart-frame--empty' : ''}`}>
                                {queryMetricsMessage ? (
                                    <p className="chart-empty">{queryMetricsMessage}</p>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart
                                            data={queryMetricsDisplay}
                                            margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                            syncId="tx-detail"
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

                <section className="route-table route-table--compact">
                    <div className="route-table__header route-table__header--exceptions">
                        <div className="route-table__heading">
                            <p className="route-table__title">Transactions</p>
                            <span className="route-table__meta">
                                {rangeShortLabel} window · {formatValue(transactionTotal)} transactions · page{' '}
                                {currentTransactionPage} of {transactionTotalPages}
                            </span>
                        </div>
                        <div className="exceptions-toolbar">
                            <input
                                className="exceptions-search"
                                type="search"
                                placeholder="Search transactions..."
                                value={searchQuery}
                                onChange={(event) => {
                                    setSearchQuery(event.target.value);
                                    setTransactionPage(1);
                                }}
                                aria-label="Search transactions"
                            />
                        </div>
                    </div>
                    {transactionMessage ? (
                        <p className="route-table__empty">{transactionMessage}</p>
                    ) : (
                        <>
                            <div className="route-table__scroll">
                                <table>
                                    <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Duration</th>
                                        <th>Queries</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    {transactionRows.map((row) => {
                                        const statusValue = toOptionalNumber(row.http_status);
                                        const statusLabel =
                                            statusValue != null ? String(statusValue) : '--';

                                        return (
                                            <tr
                                                key={row.id}
                                                className="route-row"
                                                onClick={() =>
                                                    router.push(
                                                        `/transactions/queries?id=${row.id}`
                                                    )
                                                }
                                            >
                                                <td>
                                                    {formatDashboardDateTime(
                                                        row.completed_at,
                                                        clientTimeZone
                                                    )}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`route-method route-method--text ${methodClassName(row.http_method)}`}
                                                    >
                                                        {row.http_method
                                                            ? row.http_method.toUpperCase()
                                                            : '--'}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span
                                                        className={`status-pill ${resolveStatusTone(statusValue)}`}
                                                    >
                                                        {statusLabel}
                                                    </span>
                                                </td>
                                                <td>{formatDurationMs(row.elapsed_ms)}</td>
                                                <td>{formatValue(row.total_queries_count ?? 0)}</td>
                                            </tr>
                                        );
                                    })}
                                    </tbody>
                                </table>
                            </div>
                            <div className="table-footer">
                                <span className="table-footer__meta">
                                    Showing {transactionRows.length} of{' '}
                                    {formatValue(transactionTotal)}
                                </span>
                                <div className="table-footer__actions">
                                    <button
                                        type="button"
                                        className="pagination-button"
                                        onClick={() =>
                                            setTransactionPage((prev) => Math.max(1, prev - 1))
                                        }
                                        disabled={
                                            transactionStatus === 'loading' ||
                                            currentTransactionPage <= 1
                                        }
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        className="pagination-button"
                                        onClick={() =>
                                            setTransactionPage((prev) =>
                                                Math.min(transactionTotalPages, prev + 1)
                                            )
                                        }
                                        disabled={
                                            transactionStatus === 'loading' ||
                                            currentTransactionPage >= transactionTotalPages
                                        }
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </section>
            </section>
        </DashboardShell>
    );
}
