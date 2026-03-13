'use client';

import {useEffect, useMemo, useState} from 'react';
import {useRouter} from 'next/navigation';
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
import {ChartTooltip, QueryTooltip, renderStatusCell} from '../components/dashboard-ui';
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
  type TimeRangeValue,
} from '../lib/dashboard';

type RequestTrafficPoint = {
  time: string;
  timestamp?: string;
  total: number;
  status_1xx_3xx: number;
  status_4xx: number;
  status_5xx: number;
};

type RequestDurationPoint = {
  time: string;
  timestamp?: string;
  count: number;
  avg_ms: number;
  p95_ms: number;
};

type RequestRouteMetric = {
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

type RequestTab = 'requests' | 'commands';

type DurationSummary = {
  min_ms: number | null;
  max_ms: number | null;
  avg_ms: number | null;
  p95_ms: number | null;
};

const routePageSize = 10;

const toOptionalNumber = (value: unknown): number | null => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
};

export default function RequestsPage() {
  const router = useRouter();
  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
  const [activeTab, setActiveTab] = useState<RequestTab>('requests');
  const [requestTraffic, setRequestTraffic] = useState<RequestTrafficPoint[]>([]);
  const [requestTrafficBucket, setRequestTrafficBucket] = useState<Bucket | null>(null);
  const [requestTrafficStatus, setRequestTrafficStatus] = useState<
    'idle' | 'loading' | 'error'
  >('idle');
  const [requestDuration, setRequestDuration] = useState<RequestDurationPoint[]>([]);
  const [requestDurationBucket, setRequestDurationBucket] = useState<Bucket | null>(null);
  const [requestDurationStatus, setRequestDurationStatus] = useState<
    'idle' | 'loading' | 'error'
  >('idle');
  const [durationSummary, setDurationSummary] = useState<DurationSummary>({
    min_ms: null,
    max_ms: null,
    avg_ms: null,
    p95_ms: null,
  });
  const [routeMetrics, setRouteMetrics] = useState<RequestRouteMetric[]>([]);
  const [routeMetricsStatus, setRouteMetricsStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [routeMetricsPage, setRouteMetricsPage] = useState<number>(1);
  const [routeMetricsTotal, setRouteMetricsTotal] = useState<number>(0);
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

  const requestType = activeTab === 'commands' ? 'command' : 'http';

  useEffect(() => {
    if (typeof Intl === 'undefined') {
      setClientTimeZone('UTC');
      return;
    }

    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    setClientTimeZone(zone || 'UTC');
  }, []);

  useEffect(() => {
    setRouteMetricsPage(1);
  }, [timeRange, activeTab]);

  useEffect(() => {
    const controller = new AbortController();
    setRequestTrafficStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', requestType);

        const response = await fetch(`${apiBase}/metrics/requests?${params.toString()}`, {
          signal: controller.signal,
          headers: {Accept: 'application/json'},
        });

        if (!response.ok) {
          setRequestTrafficStatus('error');
          return;
        }

        const payload = (await response.json()) as {
          data?: Array<{
            time: string;
            timestamp?: string;
            total?: number | string;
            status_1xx_3xx?: number | string;
            status_4xx?: number | string;
            status_5xx?: number | string;
          }>;
          meta?: {bucket?: string};
        };

        const series = Array.isArray(payload?.data) ? payload.data : [];
        const normalized = series.map((point) => ({
          ...point,
          total: toCount(point.total ?? 0),
          status_1xx_3xx: toCount(point.status_1xx_3xx ?? 0),
          status_4xx: toCount(point.status_4xx ?? 0),
          status_5xx: toCount(point.status_5xx ?? 0),
        }));
        const bucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

        setRequestTraffic(normalized as RequestTrafficPoint[]);
        setRequestTrafficBucket(bucket);
        setRequestTrafficStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setRequestTrafficStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery, requestType, timeRange]);

  useEffect(() => {
    const controller = new AbortController();
    setRequestDurationStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', requestType);

        const response = await fetch(
          `${apiBase}/metrics/requests-duration?${params.toString()}`,
          {
            signal: controller.signal,
            headers: {Accept: 'application/json'},
          }
        );

        if (!response.ok) {
          setRequestDurationStatus('error');
          return;
        }

        const payload = (await response.json()) as {
          data?: Array<{
            time: string;
            timestamp?: string;
            count?: number | string;
            avg_ms?: number | string;
            p95_ms?: number | string;
          }>;
          meta?: {
            bucket?: string;
            min_ms?: number | string;
            max_ms?: number | string;
            avg_ms?: number | string;
            p95_ms?: number | string;
          };
        };

        const series = Array.isArray(payload?.data) ? payload.data : [];
        const normalized = series.map((point) => ({
          ...point,
          count: toCount(point.count ?? 0),
          avg_ms: Number(point.avg_ms ?? 0),
          p95_ms: Number(point.p95_ms ?? 0),
        }));
        const bucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

        setRequestDuration(normalized as RequestDurationPoint[]);
        setRequestDurationBucket(bucket);
        setDurationSummary({
          min_ms: toOptionalNumber(payload?.meta?.min_ms),
          max_ms: toOptionalNumber(payload?.meta?.max_ms),
          avg_ms: toOptionalNumber(payload?.meta?.avg_ms),
          p95_ms: toOptionalNumber(payload?.meta?.p95_ms),
        });
        setRequestDurationStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setRequestDurationStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery, requestType, timeRange]);

  useEffect(() => {
    const controller = new AbortController();
    setRouteMetricsStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('page', String(routeMetricsPage));
        params.set('per_page', String(routePageSize));
        params.set('type', requestType);

        const response = await fetch(
          `${apiBase}/metrics/requests-routes?${params.toString()}`,
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
        const normalized = rows.map((row) => ({
          ...row,
          status_1xx_3xx: toCount(row.status_1xx_3xx ?? 0),
          status_4xx: toCount(row.status_4xx ?? 0),
          status_5xx: toCount(row.status_5xx ?? 0),
          total: toCount(row.total ?? 0),
          avg_ms: Number(row.avg_ms ?? 0),
          p95_ms: Number(row.p95_ms ?? 0),
        }));

        setRouteMetrics(normalized as RequestRouteMetric[]);
        setRouteMetricsTotal(toCount(payload?.meta?.total ?? normalized.length));
        setRouteMetricsStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setRouteMetricsStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery, requestType, routeMetricsPage]);

  const trafficDisplay = useMemo(
    () =>
      requestTraffic.map((point) => ({
        ...point,
        time: formatBucketLabel(
          point.timestamp,
          requestTrafficBucket,
          clientTimeZone,
          point.time
        ),
      })),
    [clientTimeZone, requestTraffic, requestTrafficBucket]
  );

  const durationDisplay = useMemo(
    () =>
      requestDuration.map((point) => ({
        ...point,
        time: formatBucketLabel(
          point.timestamp,
          requestDurationBucket,
          clientTimeZone,
          point.time
        ),
      })),
    [clientTimeZone, requestDuration, requestDurationBucket]
  );

  const trafficSummary = useMemo(() => {
    const totals = requestTraffic.reduce(
      (acc, point) => ({
        total: acc.total + (point.total ?? 0),
        status_1xx_3xx: acc.status_1xx_3xx + (point.status_1xx_3xx ?? 0),
        status_4xx: acc.status_4xx + (point.status_4xx ?? 0),
        status_5xx: acc.status_5xx + (point.status_5xx ?? 0),
      }),
      {
        total: 0,
        status_1xx_3xx: 0,
        status_4xx: 0,
        status_5xx: 0,
      }
    );

    return totals;
  }, [requestTraffic]);

  const durationRange =
    durationSummary.min_ms != null && durationSummary.max_ms != null
      ? `${formatDurationMs(durationSummary.min_ms)} - ${formatDurationMs(
          durationSummary.max_ms
        )}`
      : '--';

  const requestTrafficMessage =
    requestTrafficStatus === 'loading'
      ? 'Loading request traffic...'
      : requestTrafficStatus === 'error'
        ? 'Unable to load request traffic.'
        : requestTraffic.length === 0
          ? 'No request traffic recorded in this window.'
          : null;

  const requestDurationMessage =
    requestDurationStatus === 'loading'
      ? 'Loading duration metrics...'
      : requestDurationStatus === 'error'
        ? 'Unable to load duration metrics.'
        : requestDuration.length === 0
          ? 'No request duration data in this window.'
          : null;

  const normalizedSearch = searchQuery.trim().toLowerCase();
  const filteredRoutes = useMemo(() => {
    if (normalizedSearch === '') {
      return routeMetrics;
    }

    return routeMetrics.filter((row) => {
      const haystack = [row.method, row.route_name, row.url]
        .map((value) => `${value ?? ''}`.toLowerCase())
        .join(' ');

      return haystack.includes(normalizedSearch);
    });
  }, [normalizedSearch, routeMetrics]);

  const routeMetricsTotalPages = Math.max(1, Math.ceil(routeMetricsTotal / routePageSize));
  const currentRoutePage = Math.min(routeMetricsPage, routeMetricsTotalPages);
  const routePageRows = filteredRoutes;
  const noRoutesInWindow = routeMetrics.length === 0;
  const routeMessage =
    routeMetricsStatus === 'loading'
      ? 'Loading routes...'
      : routeMetricsStatus === 'error'
        ? 'Unable to load route metrics.'
        : noRoutesInWindow
          ? `No ${activeTab === 'commands' ? 'commands' : 'routes'} recorded in this window.`
          : filteredRoutes.length === 0
            ? 'No routes match the current filters.'
            : null;

  useEffect(() => {
    setRouteMetricsPage((prev) => Math.min(prev, routeMetricsTotalPages));
  }, [routeMetricsTotalPages]);

  const buildRouteDetailHref = (row: RequestRouteMetric): string | null => {
    if (!row.route_name && !row.url) {
      return null;
    }

    const params = new URLSearchParams();
    if (row.method) {
      params.set('method', row.method);
    }
    if (row.route_name) {
      params.set('route_name', row.route_name);
    }
    if (row.url) {
      params.set('url', row.url);
    }
    params.set('window', timeRange);
    params.set('type', requestType);

    return `/routes/detail?${params.toString()}`;
  };

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
                <span className="chart-summary__label">
                  {activeTab === 'commands' ? 'Commands' : 'Requests'}
                </span>
                <span className="chart-summary__value">
                  {formatOptionalNumber(trafficSummary.total)}
                </span>
              </div>
              <div className="chart-summary__stats">
                <div className="chart-summary__stat">
                  <span className="legend-dot" />
                  <span>1/2/3xx</span>
                  <strong>{formatOptionalNumber(trafficSummary.status_1xx_3xx)}</strong>
                </div>
                <div className="chart-summary__stat">
                  <span className="legend-dot legend-dot--gold" />
                  <span>4xx</span>
                  <strong>{formatOptionalNumber(trafficSummary.status_4xx)}</strong>
                </div>
                <div className="chart-summary__stat">
                  <span className="legend-dot legend-dot--hot" />
                  <span>5xx</span>
                  <strong>{formatOptionalNumber(trafficSummary.status_5xx)}</strong>
                </div>
              </div>
            </div>
            <div className={`chart-frame${requestTrafficMessage ? ' chart-frame--empty' : ''}`}>
              {requestTrafficMessage ? (
                <p className="chart-empty">{requestTrafficMessage}</p>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={trafficDisplay}
                    margin={{top: 10, right: 20, left: 0, bottom: 0}}
                    syncId="request-metrics"
                    syncMethod="index"
                  >
                    <CartesianGrid stroke="rgba(15, 23, 42, 0.08)" strokeDasharray="3 3" />
                    <XAxis dataKey="time" tickLine={false} axisLine={false} />
                    <YAxis tickLine={false} axisLine={false} />
                    <Tooltip content={<ChartTooltip />} />
                    <Bar
                      dataKey="status_1xx_3xx"
                      name="1/2/3xx"
                      stackId="status"
                      fill="var(--accent)"
                    />
                    <Bar
                      dataKey="status_4xx"
                      name="4xx"
                      stackId="status"
                      fill="var(--accent-gold)"
                    />
                    <Bar
                      dataKey="status_5xx"
                      name="5xx"
                      stackId="status"
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
                <span className="chart-summary__value">{durationRange}</span>
              </div>
              <div className="chart-summary__stats">
                <div className="chart-summary__stat">
                  <span className="legend-dot legend-dot--cool" />
                  <span>Avg</span>
                  <strong>{formatDurationMs(durationSummary.avg_ms)}</strong>
                </div>
                <div className="chart-summary__stat">
                  <span className="legend-dot legend-dot--gold" />
                  <span>P95</span>
                  <strong>{formatDurationMs(durationSummary.p95_ms)}</strong>
                </div>
              </div>
            </div>
            <div className={`chart-frame${requestDurationMessage ? ' chart-frame--empty' : ''}`}>
              {requestDurationMessage ? (
                <p className="chart-empty">{requestDurationMessage}</p>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart
                    data={durationDisplay}
                    margin={{top: 10, right: 20, left: 0, bottom: 0}}
                    syncId="request-metrics"
                    syncMethod="index"
                  >
                    <CartesianGrid stroke="rgba(15, 23, 42, 0.08)" strokeDasharray="3 3" />
                    <XAxis dataKey="time" tickLine={false} axisLine={false} />
                    <YAxis tickLine={false} axisLine={false} />
                    <Tooltip
                      content={<QueryTooltip timeZone={clientTimeZone} />}
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
            <p className="route-table__title">
              {activeTab === 'commands' ? 'Commands' : 'Routes'}
            </p>
            <span className="route-table__meta">
              {rangeShortLabel} window · {formatValue(routeMetricsTotal)}{' '}
              {activeTab === 'commands' ? 'commands' : 'routes'} · page {currentRoutePage} of{' '}
              {routeMetricsTotalPages}
            </span>
          </div>
          <div className="exceptions-toolbar">
            <div className="exceptions-filters">
              <button
                type="button"
                className={`exceptions-filter${activeTab === 'requests' ? ' exceptions-filter--active' : ''}`}
                onClick={() => setActiveTab('requests')}
              >
                Requests
              </button>
              <button
                type="button"
                className={`exceptions-filter${activeTab === 'commands' ? ' exceptions-filter--active' : ''}`}
                onClick={() => setActiveTab('commands')}
              >
                Commands
              </button>
            </div>
            <input
              className="exceptions-search"
              type="search"
              placeholder={`Search ${activeTab === 'commands' ? 'commands' : 'routes'}...`}
              value={searchQuery}
              onChange={(event) => setSearchQuery(event.target.value)}
              aria-label="Search routes"
            />
          </div>
        </div>

        {routeMessage ? (
          <p className="route-table__empty">{routeMessage}</p>
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
                  {routePageRows.map((row) => (
                    <tr
                      key={`route-${row.method ?? 'method'}-${row.route_name ?? row.url ?? 'unknown'}`}
                      className="route-row"
                      onClick={() => {
                        const href = buildRouteDetailHref(row);
                        if (href) {
                          router.push(href);
                        }
                      }}
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
                Showing {routePageRows.length} of {formatValue(routeMetricsTotal)}
              </span>
              <div className="table-footer__actions">
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() => setRouteMetricsPage((prev) => Math.max(1, prev - 1))}
                  disabled={routeMetricsStatus === 'loading' || currentRoutePage <= 1}
                >
                  Previous
                </button>
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() =>
                    setRouteMetricsPage((prev) => Math.min(routeMetricsTotalPages, prev + 1))
                  }
                  disabled={routeMetricsStatus === 'loading' || currentRoutePage >= routeMetricsTotalPages}
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
