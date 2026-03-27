'use client';

import {useEffect, useMemo, useState} from 'react';
import {useSearchParams} from 'next/navigation';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import DashboardShell from '../../components/DashboardShell';
import {usePersistentTimeRange} from '../../lib/usePersistentTimeRange';
import {
    apiBase,
    formatDashboardDateTime,
    formatDurationMs,
    formatOptionalNumber,
    formatRouteLabel,
    formatValue,
    methodClassName,
    resolveClientTimeZone,
    timeRanges,
} from '../../lib/dashboard';

type QueryRow = {
    id?: number | string;
    query_order?: number | string | null;
    sql_query?: string | null;
    raw_sql?: string | null;
    execution_time_ms?: number | string | null;
    connection_name?: string | null;
};

type TransactionMeta = {
    id?: number | string;
    completed_at?: string | null;
    http_method?: string | null;
    route_name?: string | null;
    url?: string | null;
    http_status?: number | string | null;
    elapsed_ms?: number | string | null;
    total_queries_count?: number | string | null;
};

type ChartPoint = {
    label: string;
    execution_time_ms: number;
    order: number;
};

const resolveStatusTone = (status: number | null): string => {
    if (!status) return 'status-pill--muted';
    if (status >= 500) return 'status-pill--error';
    if (status >= 400) return 'status-pill--warn';
    return 'status-pill--ok';
};

const toOptionalNumber = (value: unknown): number | null => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

export default function TransactionQueriesClient() {
    const searchParams = useSearchParams();
    const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
    const [timeRange] = usePersistentTimeRange();
    const [queries, setQueries] = useState<QueryRow[]>([]);
    const [transaction, setTransaction] = useState<TransactionMeta | null>(null);
    const [status, setStatus] = useState<'idle' | 'loading' | 'error'>('idle');

    const transactionId = searchParams.get('id');
    const selectedRange =
        timeRanges.find((r) => r.value === timeRange) ?? timeRanges[1];

    useEffect(() => {
        setClientTimeZone(resolveClientTimeZone());
    }, []);

    useEffect(() => {
        if (!transactionId) {
            setStatus('idle');
            return;
        }

        const controller = new AbortController();
        setStatus('loading');

        const load = async () => {
            try {
                const response = await fetch(
                    `${apiBase}/transaction-logs/${transactionId}/queries`,
                    {
                        signal: controller.signal,
                        headers: {Accept: 'application/json'},
                    }
                );

                if (!response.ok) {
                    setStatus('error');
                    return;
                }

                const payload = (await response.json()) as {
                    data?: Array<QueryRow>;
                    meta?: {transaction?: TransactionMeta};
                };

                setQueries(Array.isArray(payload?.data) ? payload.data : []);
                setTransaction(payload?.meta?.transaction ?? null);
                setStatus('idle');
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setStatus('error');
                }
            }
        };

        load();
        return () => controller.abort();
    }, [transactionId]);

    const chartData = useMemo<ChartPoint[]>(() => {
        return queries.map((q) => ({
            label: `#${q.query_order ?? '?'}`,
            execution_time_ms: Number(q.execution_time_ms ?? 0),
            order: Number(q.query_order ?? 0),
        }));
    }, [queries]);

    const durationSummary = useMemo(() => {
        const times = chartData.map((p) => p.execution_time_ms).filter(Number.isFinite);
        if (times.length === 0) return {total: null, avg: null, max: null};
        const total = times.reduce((s, v) => s + v, 0);
        return {
            total,
            avg: total / times.length,
            max: Math.max(...times),
        };
    }, [chartData]);

    const txStatus = toOptionalNumber(transaction?.http_status ?? null);
    const routeLabel = formatRouteLabel({
        route_name: transaction?.route_name ?? null,
        url: transaction?.url ?? null,
    });

    const message =
        !transactionId
            ? 'No transaction selected.'
            : status === 'loading'
                ? 'Loading queries...'
                : status === 'error'
                    ? 'Unable to load queries.'
                    : queries.length === 0
                        ? 'No queries recorded for this transaction.'
                        : null;

    return (
        <DashboardShell
            timeRange={timeRange}
            onTimeRangeChange={() => {}}
            rangeLabel={selectedRange.windowLabel}
            pageTitle="Transaction queries"
        >
            <section className="route-detail-page">
                <div className="route-hero">
                    <p className="route-hero__eyebrow">Transaction details</p>
                    <div className="route-hero__row">
                        <div>
                            <h1 className="route-hero__title">{routeLabel}</h1>
                            <p className="route-hero__subtitle">
                                Transaction #{transactionId} ·{' '}
                                {formatDashboardDateTime(
                                    transaction?.completed_at ?? null,
                                    clientTimeZone
                                )}
                            </p>
                        </div>
                        <span
                            className={`route-method ${methodClassName(transaction?.http_method ?? null)}`}
                        >
                            {transaction?.http_method
                                ? transaction.http_method.toUpperCase()
                                : '--'}
                        </span>
                    </div>
                    <div className="route-hero__meta">
                        <span className="route-hero__meta-item">
                            Duration: {formatDurationMs(transaction?.elapsed_ms ?? null)}
                        </span>
                        <span className="route-hero__meta-item">
                            Queries: {formatValue(transaction?.total_queries_count ?? 0)}
                        </span>
                        {txStatus != null ? (
                            <span className="route-hero__meta-item">
                                <span className={`status-pill ${resolveStatusTone(txStatus)}`}>
                                    {txStatus}
                                </span>
                            </span>
                        ) : null}
                    </div>
                </div>

                <section className="chart-pair">
                    <div className="chart-pair__grid" style={{gridTemplateColumns: '1fr'}}>
                        <div className="card chart-card">
                            <div className="chart-summary">
                                <div className="chart-summary__main">
                                    <span className="chart-summary__label">Query execution times</span>
                                    <span className="chart-summary__value">
                                        {formatOptionalNumber(queries.length)} queries
                                    </span>
                                </div>
                                <div className="chart-summary__stats">
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--cool"/>
                                        <span>Avg</span>
                                        <strong>{formatDurationMs(durationSummary.avg)}</strong>
                                    </div>
                                    <div className="chart-summary__stat">
                                        <span className="legend-dot legend-dot--hot"/>
                                        <span>Max</span>
                                        <strong>{formatDurationMs(durationSummary.max)}</strong>
                                    </div>
                                </div>
                            </div>
                            <div className={`chart-frame${message ? ' chart-frame--empty' : ''}`}>
                                {message ? (
                                    <p className="chart-empty">{message}</p>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart
                                            data={chartData}
                                            margin={{top: 10, right: 20, left: 0, bottom: 0}}
                                        >
                                            <CartesianGrid
                                                stroke="rgba(15, 23, 42, 0.08)"
                                                strokeDasharray="3 3"
                                            />
                                            <XAxis
                                                dataKey="label"
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <YAxis
                                                tickLine={false}
                                                axisLine={false}
                                                tickFormatter={(v: number) =>
                                                    formatDurationMs(v)
                                                }
                                            />
                                            <Tooltip
                                                formatter={(value: number) => [
                                                    formatDurationMs(value),
                                                    'Duration',
                                                ]}
                                                cursor={{
                                                    stroke: 'var(--border)',
                                                    strokeWidth: 1,
                                                }}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="execution_time_ms"
                                                name="Duration"
                                                stroke="var(--accent-cool)"
                                                strokeWidth={2}
                                                dot={{r: 3, fill: 'var(--accent-cool)'}}
                                                activeDot={{r: 5, strokeWidth: 2}}
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
                        <p className="route-table__title">Queries</p>
                        <span className="route-table__meta">
                            {formatValue(queries.length)} queries
                        </span>
                    </div>
                    {message ? (
                        <p className="route-table__empty">{message}</p>
                    ) : (
                        <div className="route-table__scroll">
                            <table>
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>SQL</th>
                                    <th>Duration</th>
                                    <th>Connection</th>
                                </tr>
                                </thead>
                                <tbody>
                                {queries.map((row, idx) => (
                                    <tr key={row.id ?? idx}>
                                        <td style={{whiteSpace: 'nowrap'}}>
                                            {row.query_order ?? idx + 1}
                                        </td>
                                        <td
                                            style={{
                                                fontFamily: 'monospace',
                                                fontSize: '0.8em',
                                                maxWidth: '60ch',
                                                wordBreak: 'break-all',
                                            }}
                                        >
                                            {row.raw_sql ?? row.sql_query ?? '--'}
                                        </td>
                                        <td style={{whiteSpace: 'nowrap'}}>
                                            {formatDurationMs(row.execution_time_ms)}
                                        </td>
                                        <td style={{whiteSpace: 'nowrap'}}>
                                            {row.connection_name ?? '--'}
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </section>
        </DashboardShell>
    );
}
