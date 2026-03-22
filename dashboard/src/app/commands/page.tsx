'use client';

import {Suspense, useEffect, useMemo, useState} from 'react';
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
import RequestRoutesTable from '../components/RequestRoutesTable';
import {ChartTooltip, QueryTooltip} from '../components/dashboard-ui';
import {usePersistentTimeRange} from '../lib/usePersistentTimeRange';
import {
  apiBase,
  bucketForRange,
  formatBucketLabel,
  formatDurationMs,
  formatOptionalNumber,
  formatValue,
  resolveClientTimeZone,
  resolveBucket,
  resolveTimeWindow,
  timeRanges,
  toCount,
  type Bucket,
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

type DurationSummary = {
  min_ms: number | null;
  max_ms: number | null;
  avg_ms: number | null;
  p95_ms: number | null;
};

const toOptionalNumber = (value: unknown): number | null => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
};

function CommandsPageContent() {
  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = usePersistentTimeRange();
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
  const [visibleStatuses, setVisibleStatuses] = useState<Set<'1xx_3xx' | '4xx' | '5xx'>>(
    new Set(['1xx_3xx', '4xx', '5xx'])
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
    setClientTimeZone(resolveClientTimeZone());
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setRequestTrafficStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', 'command');

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
  }, [rangeQuery, timeRange]);

  useEffect(() => {
    const controller = new AbortController();
    setRequestDurationStatus('loading');

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('type', 'command');

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
  }, [rangeQuery, timeRange]);

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

  const filteredTrafficDisplay = useMemo(
    () =>
      trafficDisplay.map((point) => ({
        ...point,
        status_1xx_3xx: visibleStatuses.has('1xx_3xx') ? point.status_1xx_3xx : 0,
        status_4xx: visibleStatuses.has('4xx') ? point.status_4xx : 0,
        status_5xx: visibleStatuses.has('5xx') ? point.status_5xx : 0,
      })),
    [trafficDisplay, visibleStatuses]
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
                  Commands
                </span>
                <span className="chart-summary__value">
                  {formatOptionalNumber(trafficSummary.total)}
                </span>
              </div>
              <div className="chart-summary__stats">
                {[
                  {
                    key: '1xx_3xx',
                    label: '1/2/3xx',
                    dotClass: 'legend-dot--cool',
                    value: trafficSummary.status_1xx_3xx,
                  },
                  {
                    key: '4xx',
                    label: '4xx',
                    dotClass: 'legend-dot--gold',
                    value: trafficSummary.status_4xx,
                  },
                  {
                    key: '5xx',
                    label: '5xx',
                    dotClass: 'legend-dot--hot',
                    value: trafficSummary.status_5xx,
                  },
                ].map(({key, label, dotClass, value}) => {
                  const isVisible = visibleStatuses.has(key as '1xx_3xx' | '4xx' | '5xx');
                  return (
                    <div
                      key={key}
                      role="button"
                      tabIndex={0}
                      className={`chart-summary__stat chart-summary__stat--clickable${!isVisible ? ' chart-summary__stat--inactive' : ''}`}
                      onClick={() => {
                        const newStatuses = new Set(visibleStatuses);
                        if (newStatuses.has(key as '1xx_3xx' | '4xx' | '5xx')) {
                          newStatuses.delete(key as '1xx_3xx' | '4xx' | '5xx');
                        } else {
                          newStatuses.add(key as '1xx_3xx' | '4xx' | '5xx');
                        }
                        setVisibleStatuses(newStatuses);
                      }}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          const newStatuses = new Set(visibleStatuses);
                          if (newStatuses.has(key as '1xx_3xx' | '4xx' | '5xx')) {
                            newStatuses.delete(key as '1xx_3xx' | '4xx' | '5xx');
                          } else {
                            newStatuses.add(key as '1xx_3xx' | '4xx' | '5xx');
                          }
                          setVisibleStatuses(newStatuses);
                        }
                      }}
                    >
                      <span className={`legend-dot${dotClass ? ` ${dotClass}` : ''}`} />
                      <span>{label}</span>
                      <strong>{formatOptionalNumber(value)}</strong>
                    </div>
                  );
                })}
              </div>
            </div>
            <div className={`chart-frame${requestTrafficMessage ? ' chart-frame--empty' : ''}`}>
              {requestTrafficMessage ? (
                <p className="chart-empty">{requestTrafficMessage}</p>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={filteredTrafficDisplay}
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
                      fill="var(--accent-cool)"
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

      <RequestRoutesTable
        rangeQuery={rangeQuery}
        timeRange={timeRange}
        rangeShortLabel={rangeShortLabel}
        requestType="command"
        title="Commands"
        itemLabel="commands"
      />
    </DashboardShell>
  );
}

export default function CommandsPage() {
  return (
    <Suspense fallback={null}>
      <CommandsPageContent />
    </Suspense>
  );
}
