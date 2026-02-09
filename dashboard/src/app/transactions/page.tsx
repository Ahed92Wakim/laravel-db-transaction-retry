'use client';

import {useEffect, useMemo, useState} from 'react';
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
import DashboardShell from '../components/DashboardShell';
import {QueryTooltip, renderStatusCell} from '../components/dashboard-ui';
import {
    apiBase,
    bucketForRange,
    formatBucketLabel,
    formatDurationMs,
    formatOptionalNumber,
    formatRouteLabel,
    formatValue,
    methodClassName,
    resolveBucket,
    resolveTimeWindow,
    timeRanges,
    toCount,
    type Bucket,
    type QueryMetric,
    type RouteVolumeMetric,
    type TimeRangeValue,
} from '../lib/dashboard';

const routeVolumePageSize = 10;

export default function TransactionsPage() {

    const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
    const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
    const [routeVolumeMetrics, setRouteVolumeMetrics] = useState<RouteVolumeMetric[]>([]);
    const [routeVolumeStatus, setRouteVolumeStatus] = useState<'idle' | 'loading' | 'error'>(
        'idle'
    );
    const [routeVolumePage, setRouteVolumePage] = useState<number>(1);
    const [routeVolumeTotal, setRouteVolumeTotal] = useState<number>(0);
    const [routeVolumePerPage, setRouteVolumePerPage] = useState<number>(routeVolumePageSize);
    const [queryMetrics, setQueryMetrics] = useState<QueryMetric[]>([]);
    const [queryMetricsBucket, setQueryMetricsBucket] = useState<Bucket | null>(null);
    const [queryMetricsStatus, setQueryMetricsStatus] = useState<'idle' | 'loading' | 'error'>(
        'idle'
    );

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
        setRouteVolumePage(1);
    }, [timeRange]);

    useEffect(() => {
        if (typeof Intl === 'undefined') {
            setClientTimeZone('UTC');
            return;
        }

        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        setClientTimeZone(zone || 'UTC');
    }, []);

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
    }, [rangeQuery, timeRange]);

    useEffect(() => {
        const controller = new AbortController();
        setRouteVolumeStatus('loading');

        const load = async () => {
            try {
                const params = new URLSearchParams(rangeQuery);
                params.set('page', String(routeVolumePage));
                params.set('per_page', String(routeVolumePageSize));

                const response = await fetch(
                    `${apiBase}/metrics/routes-volume?${params.toString()}`,
                    {
                        signal: controller.signal,
                        headers: {Accept: 'application/json'},
                    }
                );

                if (!response.ok) {
                    setRouteVolumeStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<{
                        method?: string | null;
                        route_name?: string | null;
                        url?: string | null;
                        status_1xx_3xx?: number | string;
                        status_4xx?: number | string;
                        status_5xx?: number | string;
                        total?: number | string;
                        avg_ms?: number | string;
                        p95_ms?: number | string;
                    }>;
                    meta?: {
                        page?: number | string;
                        per_page?: number | string;
                        total?: number | string;
                    };
                };
                const rows = Array.isArray(payload?.data) ? payload.data : [];
                const normalized = rows.map((row) => {
                    const avgMs = Number(row.avg_ms ?? 0);
                    const p95Ms = Number(row.p95_ms ?? 0);

                    return {
                        ...row,
                        status_1xx_3xx: toCount(row.status_1xx_3xx ?? 0),
                        status_4xx: toCount(row.status_4xx ?? 0),
                        status_5xx: toCount(row.status_5xx ?? 0),
                        total: toCount(row.total ?? 0),
                        avg_ms: Number.isFinite(avgMs) ? avgMs : 0,
                        p95_ms: Number.isFinite(p95Ms) ? p95Ms : 0,
                    };
                });

                setRouteVolumeMetrics(normalized);
                const total = Number(payload?.meta?.total ?? 0);
                const perPage = Number(payload?.meta?.per_page ?? routeVolumePageSize);
                const page = Number(payload?.meta?.page ?? routeVolumePage);
                setRouteVolumeTotal(Number.isFinite(total) ? total : 0);
                setRouteVolumePerPage(Number.isFinite(perPage) ? perPage : routeVolumePageSize);
                if (Number.isFinite(page) && page !== routeVolumePage) {
                    setRouteVolumePage(page);
                }
                setRouteVolumeStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setRouteVolumeStatus('error');
                }
            }
        };

        load();

        return () => controller.abort();
    }, [rangeQuery, routeVolumePage]);

    const queryMetricsMessage =
        queryMetricsStatus === 'loading'
            ? 'Loading query metrics...'
            : queryMetricsStatus === 'error'
                ? 'Unable to load query metrics.'
                : queryMetrics.length === 0
                    ? 'No query records in this window.'
                    : null;
    const isRouteVolumeLoading = routeVolumeStatus === 'loading';
    const hasRouteVolumeRows = routeVolumeMetrics.length > 0;
    const routeVolumeMessage =
        !hasRouteVolumeRows && routeVolumeStatus === 'error'
            ? 'Unable to load routes.'
            : !hasRouteVolumeRows && routeVolumeTotal === 0
                ? isRouteVolumeLoading
                    ? 'Loading routes...'
                    : 'No routes recorded in this window.'
                : !hasRouteVolumeRows
                    ? isRouteVolumeLoading
                        ? 'Loading routes...'
                        : 'No routes on this page.'
                    : null;
    const routeVolumeTotalPages = Math.max(
        1,
        Math.ceil(routeVolumeTotal / Math.max(routeVolumePerPage, 1))
    );
    const currentRouteVolumePage = Math.min(routeVolumePage, routeVolumeTotalPages);
    const routeVolumePageRows = routeVolumeMetrics;
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

        const total = totals.reduce((sum, value) => sum + Number(value), 0);
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

    useEffect(() => {
        setRouteVolumePage((prev) => Math.min(prev, routeVolumeTotalPages));
    }, [routeVolumeTotalPages]);

    return (
        <DashboardShell
            timeRange={timeRange}
            onTimeRangeChange={setTimeRange}
            rangeLabel={rangeLabel}
        >
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
                                        syncId="query-metrics"
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

            <section className="route-table route-table--compact">
                <div className="route-table__header">
                    <p className="route-table__title">Routes</p>
                    <span className="route-table__meta">
            {rangeShortLabel} window · {formatValue(routeVolumeTotal)} routes · page{' '}
                        {currentRouteVolumePage} of {routeVolumeTotalPages}
          </span>
                </div>
                {routeVolumeMessage ? (
                    <p className="route-table__empty">{routeVolumeMessage}</p>
                ) : (
                    <>
                        <div className="route-table__scroll">
                            <table>
                                <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Path</th>
                                    <th>1/2/3xx</th>
                                    <th>4xx</th>
                                    <th>5xx</th>
                                    <th>Total</th>
                                    <th>Avg</th>
                                    <th>P95</th>
                                </tr>
                                </thead>
                                <tbody>
                                {routeVolumePageRows.map((row) => (
                                    <tr
                                        key={`volume-${row.method ?? 'method'}-${row.route_name ?? row.url ?? 'unknown'}`}
                                    >
                                        <td>
                        <span
                            className={`route-method route-method--text ${methodClassName(row.method)}`}
                        >
                          {row.method ? row.method.toUpperCase() : '--'}
                        </span>
                                        </td>
                                        <td>{formatRouteLabel(row)}</td>
                                        <td>{formatValue(row.status_1xx_3xx ?? 0)}</td>
                                        <td>{renderStatusCell(row.status_4xx, 'warn')}</td>
                                        <td>{renderStatusCell(row.status_5xx, 'error')}</td>
                                        <td>{formatValue(row.total ?? 0)}</td>
                                        <td>{formatDurationMs(row.avg_ms)}</td>
                                        <td>{formatDurationMs(row.p95_ms)}</td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="table-footer">
              <span className="table-footer__meta">
                Showing {routeVolumePageRows.length} of {formatValue(routeVolumeTotal)}
              </span>
                            <div className="table-footer__actions">
                                <button
                                    type="button"
                                    className="pagination-button"
                                    onClick={() => setRouteVolumePage((prev) => Math.max(1, prev - 1))}
                                    disabled={isRouteVolumeLoading || currentRouteVolumePage <= 1}
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    className="pagination-button"
                                    onClick={() =>
                                        setRouteVolumePage((prev) => Math.min(routeVolumeTotalPages, prev + 1))
                                    }
                                    disabled={isRouteVolumeLoading || currentRouteVolumePage >= routeVolumeTotalPages}
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </section>
        </DashboardShell>
    );
}
