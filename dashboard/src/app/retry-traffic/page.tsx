'use client';

import {useEffect, useMemo, useState} from 'react';
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import DashboardShell from '../components/DashboardShell';
import {ChartTooltip} from '../components/dashboard-ui';
import {
  apiBase,
  bucketForRange,
  formatBucketLabel,
  formatRouteLabel,
  formatValue,
  methodClassName,
  resolveBucket,
  resolveTimeWindow,
  timeRanges,
  toCount,
  type Bucket,
  type RouteMetric,
  type TimeRangeValue,
} from '../lib/dashboard';

const kpiBase = [
  {label: 'Escalations', value: '7', delta: '+2 incidents', down: true},
];
const routeMetricsPageSize = 10;

export default function RetryTrafficPage() {
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
  const [retryTrafficStatus, setRetryTrafficStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [routeMetrics, setRouteMetrics] = useState<RouteMetric[]>([]);
  const [routeMetricsStatus, setRouteMetricsStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [routeMetricsPage, setRouteMetricsPage] = useState<number>(1);
  const [routeMetricsTotal, setRouteMetricsTotal] = useState<number>(0);
  const [routeMetricsPerPage, setRouteMetricsPerPage] = useState<number>(routeMetricsPageSize);

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
    if (typeof Intl === 'undefined') {
      setClientTimeZone('UTC');
      return;
    }

    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    setClientTimeZone(zone || 'UTC');
  }, []);

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
          meta?: {bucket?: string};
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
  }, [rangeQuery, timeRange]);

  useEffect(() => {
    const controller = new AbortController();
    setRouteMetricsStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('page', String(routeMetricsPage));
        params.set('per_page', String(routeMetricsPageSize));

        const response = await fetch(
          `${apiBase}/metrics/routes?${params.toString()}`,
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
          meta?: {
            page?: number | string;
            per_page?: number | string;
            total?: number | string;
          };
        };
        const rows = Array.isArray(payload?.data) ? payload.data : [];
        const normalized = rows.map((row) => ({
          ...row,
          attempts: toCount(row.attempts ?? 0),
          success: toCount(row.success ?? 0),
          failure: toCount(row.failure ?? 0),
        }));

        setRouteMetrics(normalized);
        const total = Number(payload?.meta?.total ?? 0);
        const perPage = Number(payload?.meta?.per_page ?? routeMetricsPageSize);
        const page = Number(payload?.meta?.page ?? routeMetricsPage);
        setRouteMetricsTotal(Number.isFinite(total) ? total : 0);
        setRouteMetricsPerPage(Number.isFinite(perPage) ? perPage : routeMetricsPageSize);
        if (Number.isFinite(page) && page !== routeMetricsPage) {
          setRouteMetricsPage(page);
        }
        setRouteMetricsStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setRouteMetricsStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery, routeMetricsPage]);

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
        : routeMetrics.length === 0 && routeMetricsTotal === 0
          ? 'No route retries in this window.'
          : routeMetrics.length === 0
            ? 'No routes on this page.'
          : null;
  const routeMetricsTotalPages = Math.max(
    1,
    Math.ceil(routeMetricsTotal / Math.max(routeMetricsPerPage, 1))
  );
  const currentRouteMetricsPage = Math.min(routeMetricsPage, routeMetricsTotalPages);
  const routeMetricsPageRows = routeMetrics;

  useEffect(() => {
    setRouteMetricsPage(1);
  }, [timeRange]);

  return (
    <DashboardShell
      timeRange={timeRange}
      onTimeRangeChange={setTimeRange}
      rangeLabel={rangeLabel}
    >
      <section className="grid metrics">
        {kpis.map((kpi) => (
          <div className="card metric-card" key={kpi.label}>
            <span className="metric-card__label">{kpi.label}</span>
            <span className="metric-card__value">{kpi.value}</span>
            <span
              className={`metric-card__delta${kpi.down ? ' metric-card__delta--down' : ''}`}
            >
              {kpi.delta}
            </span>
          </div>
        ))}
      </section>

      <section className="grid charts charts--pair">
        <div className="card chart-card chart-card--wide">
          <div className="card-header">
            <div>
              <p className="card-title">Retry traffic</p>
              <p className="card-subtitle">Attempts, success, and failure - {rangeLabel}</p>
            </div>
            <span className="card-chip">{rangeShortLabel}</span>
          </div>
          <div className={`chart-frame${retryTrafficMessage ? ' chart-frame--empty' : ''}`}>
            {retryTrafficMessage ? (
              <p className="chart-empty">{retryTrafficMessage}</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={retryTrafficDisplay} margin={{top: 10, right: 20, left: 0, bottom: 0}}>
                  <defs>
                    <linearGradient id="retryFill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="var(--accent)" stopOpacity={0.4} />
                      <stop offset="95%" stopColor="var(--accent)" stopOpacity={0.05} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid stroke="rgba(15, 23, 42, 0.08)" strokeDasharray="3 3" />
                  <XAxis dataKey="time" tickLine={false} axisLine={false} />
                  <YAxis tickLine={false} axisLine={false} />
                  <Tooltip content={<ChartTooltip />} />
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
              <span className="legend-dot" /> Attempts
            </span>
            <span>
              <span className="legend-dot legend-dot--cool" /> Success
            </span>
            <span>
              <span className="legend-dot legend-dot--gold" /> Failure
            </span>
          </div>
        </div>
      </section>

      <section className="route-table route-table--compact">
        <div className="route-table__header">
          <p className="route-table__title">Routes with retries</p>
          <span className="route-table__meta">
            {rangeShortLabel} window · {formatValue(routeMetricsTotal)} routes · page{' '}
            {currentRouteMetricsPage} of {routeMetricsTotalPages}
          </span>
        </div>
        {routeMetricsMessage ? (
          <p className="route-table__empty">{routeMetricsMessage}</p>
        ) : (
          <>
            <div className="route-table__scroll">
              <table>
                <thead>
                  <tr>
                    <th>Method</th>
                    <th>Path</th>
                    <th>Attempts</th>
                    <th>Success</th>
                    <th>Failure</th>
                  </tr>
                </thead>
                <tbody>
                  {routeMetricsPageRows.map((row) => (
                    <tr
                      key={`${row.route_hash ?? 'route'}-${row.method ?? 'method'}-${row.route_name ?? row.url ?? 'unknown'}`}
                    >
                      <td>
                        <span
                          className={`route-method route-method--text ${methodClassName(row.method)}`}
                        >
                          {row.method ? row.method.toUpperCase() : '--'}
                        </span>
                      </td>
                      <td>{formatRouteLabel(row)}</td>
                      <td>{formatValue(row.attempts)}</td>
                      <td>{formatValue(row.success)}</td>
                      <td>{formatValue(row.failure)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="table-footer">
              <span className="table-footer__meta">
                Showing {routeMetricsPageRows.length} of {formatValue(routeMetricsTotal)}
              </span>
              <div className="table-footer__actions">
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() => setRouteMetricsPage((prev) => Math.max(1, prev - 1))}
                  disabled={currentRouteMetricsPage <= 1}
                >
                  Previous
                </button>
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() =>
                    setRouteMetricsPage((prev) => Math.min(routeMetricsTotalPages, prev + 1))
                  }
                  disabled={currentRouteMetricsPage >= routeMetricsTotalPages}
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
