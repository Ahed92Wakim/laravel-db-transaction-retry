'use client';

import {useEffect, useMemo, useState} from 'react';
import {useSearchParams} from 'next/navigation';
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
import {ChartTooltip, QueryTooltip} from '../../components/dashboard-ui';
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
} from '../../lib/dashboard';

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

type RequestLogRow = {
  id: number | string;
  completed_at?: string | null;
  http_method?: string | null;
  route_name?: string | null;
  url?: string | null;
  http_status?: number | string | null;
  elapsed_ms?: number | string | null;
};

type DurationSummary = {
  count: number | null;
  min_ms: number | null;
  max_ms: number | null;
  avg_ms: number | null;
  p95_ms: number | null;
};

const requestPageSize = 20;

const isValidTimeRange = (value: string | null): value is TimeRangeValue => {
  if (!value) {
    return false;
  }

  return timeRanges.some((range) => range.value === value);
};

const normalizeParam = (value: string | null): string | null => {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
};

const toOptionalNumber = (value: unknown): number | null => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
};

const formatRequestDate = (value?: string | null, timeZone?: string | null): string => {
  if (!value) {
    return '--';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '--';
  }

  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: timeZone ?? undefined,
  }).format(date);
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

export default function RouteDetailClient() {
  const searchParams = useSearchParams();
  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
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
    count: null,
    min_ms: null,
    max_ms: null,
    avg_ms: null,
    p95_ms: null,
  });
  const [requestRows, setRequestRows] = useState<RequestLogRow[]>([]);
  const [requestStatus, setRequestStatus] = useState<'idle' | 'loading' | 'error'>('idle');
  const [requestPage, setRequestPage] = useState<number>(1);
  const [requestTotal, setRequestTotal] = useState<number>(0);
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

  const windowParam = searchParams.get('window');
  const routeNameParam = normalizeParam(searchParams.get('route_name'));
  const urlParam = normalizeParam(searchParams.get('url'));
  const methodParam = normalizeParam(searchParams.get('method'));
  const requestType = normalizeParam(searchParams.get('type')) === 'command' ? 'command' : 'http';
  const hasRouteFilter = Boolean(routeNameParam || urlParam);
  const routeLabel = formatRouteLabel({route_name: routeNameParam, url: urlParam});
  const routeKey = `${requestType}-${methodParam ?? ''}-${routeNameParam ?? ''}-${urlParam ?? ''}`;

  useEffect(() => {
    if (typeof Intl === 'undefined') {
      setClientTimeZone('UTC');
      return;
    }

    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    setClientTimeZone(zone || 'UTC');
  }, []);

  useEffect(() => {
    if (!isValidTimeRange(windowParam)) {
      return;
    }

    setTimeRange((prev) => (prev === windowParam ? prev : windowParam));
  }, [windowParam]);

  useEffect(() => {
    setRequestPage(1);
  }, [routeKey, timeRange]);

  useEffect(() => {
    if (!hasRouteFilter) {
      setRequestTraffic([]);
      setRequestTrafficBucket(null);
      setRequestTrafficStatus('idle');
      return;
    }

    const controller = new AbortController();
    setRequestTrafficStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', requestType);
        if (methodParam) {
          params.set('method', methodParam);
        }
        if (routeNameParam) {
          params.set('route_name', routeNameParam);
        } else if (urlParam) {
          params.set('url', urlParam);
        }

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
  }, [rangeQuery, requestType, routeKey, timeRange, hasRouteFilter, methodParam, routeNameParam, urlParam]);

  useEffect(() => {
    if (!hasRouteFilter) {
      setRequestDuration([]);
      setRequestDurationBucket(null);
      setRequestDurationStatus('idle');
      setDurationSummary({
        count: null,
        min_ms: null,
        max_ms: null,
        avg_ms: null,
        p95_ms: null,
      });
      return;
    }

    const controller = new AbortController();
    setRequestDurationStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', requestType);
        if (methodParam) {
          params.set('method', methodParam);
        }
        if (routeNameParam) {
          params.set('route_name', routeNameParam);
        } else if (urlParam) {
          params.set('url', urlParam);
        }

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
            count?: number | string;
            avg_ms?: number | string;
            min_ms?: number | string;
            max_ms?: number | string;
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
          count: toOptionalNumber(payload?.meta?.count),
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
  }, [rangeQuery, requestType, routeKey, timeRange, hasRouteFilter, methodParam, routeNameParam, urlParam]);

  useEffect(() => {
    if (!hasRouteFilter) {
      setRequestRows([]);
      setRequestTotal(0);
      setRequestStatus('idle');
      return;
    }

    const controller = new AbortController();
    setRequestStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', requestType);
        params.set('page', String(requestPage));
        params.set('per_page', String(requestPageSize));
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

        const response = await fetch(`${apiBase}/requests?${params.toString()}`, {
          signal: controller.signal,
          headers: {Accept: 'application/json'},
        });

        if (!response.ok) {
          setRequestStatus('error');
          return;
        }

        const payload = (await response.json()) as {
          data?: Array<RequestLogRow>;
          meta?: {total?: number | string; page?: number | string};
        };

        const rows = Array.isArray(payload?.data) ? payload.data : [];
        const total = Number(payload?.meta?.total ?? 0);
        const page = Number(payload?.meta?.page ?? requestPage);

        setRequestRows(rows);
        setRequestTotal(Number.isFinite(total) ? total : 0);
        if (Number.isFinite(page) && page !== requestPage) {
          setRequestPage(page);
        }
        setRequestStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setRequestStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery, requestType, routeKey, requestPage, searchQuery, hasRouteFilter, methodParam, routeNameParam, urlParam]);

  const requestTrafficDisplay = useMemo(
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

  const requestDurationDisplay = useMemo(
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
    return requestTraffic.reduce(
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
  }, [requestTraffic]);

  const durationRange =
    durationSummary.min_ms != null && durationSummary.max_ms != null
      ? `${formatDurationMs(durationSummary.min_ms)} - ${formatDurationMs(
          durationSummary.max_ms
        )}`
      : '--';

  const requestTrafficMessage =
    !hasRouteFilter
      ? 'Select a route to load request traffic.'
      : requestTrafficStatus === 'loading'
        ? 'Loading request traffic...'
        : requestTrafficStatus === 'error'
          ? 'Unable to load request traffic.'
          : requestTraffic.length === 0
            ? 'No request traffic recorded in this window.'
            : null;

  const requestDurationMessage =
    !hasRouteFilter
      ? 'Select a route to load duration metrics.'
      : requestDurationStatus === 'loading'
        ? 'Loading duration metrics...'
        : requestDurationStatus === 'error'
          ? 'Unable to load duration metrics.'
          : requestDuration.length === 0
            ? 'No request duration data in this window.'
            : null;

  const requestTotalPages = Math.max(1, Math.ceil(requestTotal / requestPageSize));
  const currentRequestPage = Math.min(requestPage, requestTotalPages);
  const requestMessage =
    !hasRouteFilter
      ? 'Select a route to view requests.'
      : requestStatus === 'loading'
        ? 'Loading requests...'
        : requestStatus === 'error'
          ? 'Unable to load requests.'
          : requestRows.length === 0
            ? 'No requests recorded for this route in this window.'
            : null;

  useEffect(() => {
    setRequestPage((prev) => Math.min(prev, requestTotalPages));
  }, [requestTotalPages]);

  return (
    <DashboardShell
      timeRange={timeRange}
      onTimeRangeChange={setTimeRange}
      rangeLabel={rangeLabel}
      pageTitle="Route details"
    >
      <section className="route-detail-page">
        <div className="route-hero">
          <p className="route-hero__eyebrow">Route information</p>
          <div className="route-hero__row">
            <div>
              <h1 className="route-hero__title">{routeLabel}</h1>
              <p className="route-hero__subtitle">
                {requestType === 'command' ? 'Command' : 'HTTP'} · {rangeLabel}
              </p>
            </div>
            <span className={`route-method ${methodClassName(methodParam)}`}>
              {methodParam ? methodParam.toUpperCase() : '--'}
            </span>
          </div>
          <div className="route-hero__meta">
            <span className="route-hero__meta-item">
              Type: {requestType === 'command' ? 'Command' : 'HTTP'}
            </span>
            {routeNameParam ? (
              <span className="route-hero__meta-item">Route name: {routeNameParam}</span>
            ) : null}
            {urlParam ? (
              <span className="route-hero__meta-item">URL: {urlParam}</span>
            ) : null}
            <span className="route-hero__meta-item">
              {rangeShortLabel} window
            </span>
          </div>
        </div>

        <section className="chart-pair">
          <div className="chart-pair__grid">
            <div className="card chart-card">
              <div className="chart-summary">
                <div className="chart-summary__main">
                  <span className="chart-summary__label">Requests</span>
                  <span className="chart-summary__value">
                    {formatOptionalNumber(durationSummary.count)}
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
                      data={requestTrafficDisplay}
                      margin={{top: 10, right: 20, left: 0, bottom: 0}}
                      syncId="route-metrics"
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
                      data={requestDurationDisplay}
                      margin={{top: 10, right: 20, left: 0, bottom: 0}}
                      syncId="route-metrics"
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
              <p className="route-table__title">Requests</p>
              <span className="route-table__meta">
                {rangeShortLabel} window · {formatValue(requestTotal)} requests · page{' '}
                {currentRequestPage} of {requestTotalPages}
              </span>
            </div>
            <div className="exceptions-toolbar">
              <input
                className="exceptions-search"
                type="search"
                placeholder="Search requests..."
                value={searchQuery}
                onChange={(event) => {
                  setSearchQuery(event.target.value);
                  setRequestPage(1);
                }}
                aria-label="Search requests"
              />
            </div>
          </div>
          {requestMessage ? (
            <p className="route-table__empty">{requestMessage}</p>
          ) : (
            <>
              <div className="route-table__scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Method</th>
                      <th>Path</th>
                      <th>Status</th>
                      <th>Duration</th>
                    </tr>
                  </thead>
                  <tbody>
                    {requestRows.map((row) => {
                      const statusValue = toOptionalNumber(row.http_status);
                      const statusLabel = statusValue != null ? String(statusValue) : '--';

                      return (
                        <tr key={row.id}>
                          <td>{formatRequestDate(row.completed_at, clientTimeZone)}</td>
                          <td>
                            <span
                              className={`route-method route-method--text ${methodClassName(row.http_method)}`}
                            >
                              {row.http_method ? row.http_method.toUpperCase() : '--'}
                            </span>
                          </td>
                          <td>{formatRouteLabel(row)}</td>
                          <td>
                            <span className={`status-pill ${resolveStatusTone(statusValue)}`}>
                              {statusLabel}
                            </span>
                          </td>
                          <td>{formatDurationMs(row.elapsed_ms)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="table-footer">
                <span className="table-footer__meta">
                  Showing {requestRows.length} of {formatValue(requestTotal)}
                </span>
                <div className="table-footer__actions">
                  <button
                    type="button"
                    className="pagination-button"
                    onClick={() => setRequestPage((prev) => Math.max(1, prev - 1))}
                    disabled={requestStatus === 'loading' || currentRequestPage <= 1}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="pagination-button"
                    onClick={() =>
                      setRequestPage((prev) => Math.min(requestTotalPages, prev + 1))
                    }
                    disabled={
                      requestStatus === 'loading' || currentRequestPage >= requestTotalPages
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
