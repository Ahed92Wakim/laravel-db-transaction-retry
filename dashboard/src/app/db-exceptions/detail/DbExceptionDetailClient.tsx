'use client';

import Link from 'next/link';
import {useEffect, useMemo, useState} from 'react';
import {useSearchParams} from 'next/navigation';
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import DashboardShell from '../../components/DashboardShell';
import {ChartTooltip} from '../../components/dashboard-ui';
import {
  apiBase,
  bucketForRange,
  formatBucketLabel,
  formatValue,
  resolveBucket,
  resolveTimeWindow,
  timeRanges,
  toCount,
  type Bucket,
  type TimeRangeValue,
} from '../../lib/dashboard';

type ExceptionGroupDetail = {
  exception_class?: string | null;
  error_message?: string | null;
  sql_state?: string | null;
  driver_code?: number | string | null;
  connection?: string | null;
  sql?: string | null;
  occurrences?: number | string;
  last_seen?: string | null;
};

type ExceptionOccurrence = {
  id: number | string;
  occurred_at?: string | null;
  sql?: string | null;
  raw_sql?: string | null;
  error_message?: string | null;
  method?: string | null;
  route_name?: string | null;
  url?: string | null;
  user_type?: string | null;
  user_id?: string | null;
  connection?: string | null;
  sql_state?: string | null;
  driver_code?: number | string | null;
  event_hash?: string | null;
};

type ExceptionSeriesPoint = {
  time: string;
  timestamp?: string;
  count: number;
};

type ImpactWindow = '30d' | '7d' | '24h';

const occurrencePageSize = 20;
const impactWindows: ImpactWindow[] = ['30d', '7d', '24h'];

const isValidTimeRange = (value: string | null): value is TimeRangeValue => {
  if (!value) {
    return false;
  }

  return timeRanges.some((range) => range.value === value);
};

const formatExceptionTitle = (exceptionClass?: string | null): string => {
  const trimmed = exceptionClass?.trim();
  if (!trimmed) {
    return 'Unknown exception';
  }

  const parts = trimmed.split('\\');
  const last = parts[parts.length - 1];

  return last && last.length > 0 ? last : trimmed;
};

const formatLastSeen = (value?: string | null, timeZone?: string | null): string => {
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

const formatHumanDate = (value?: string | null): string => {
  if (!value) {
    return '--';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '--';
  }

  const now = Date.now();
  const diff = date.getTime() - now;
  const absDiff = Math.abs(diff);
  const rtf = new Intl.RelativeTimeFormat(undefined, {numeric: 'auto'});

  const units: Array<{unit: Intl.RelativeTimeFormatUnit; ms: number}> = [
    {unit: 'year', ms: 1000 * 60 * 60 * 24 * 365},
    {unit: 'month', ms: 1000 * 60 * 60 * 24 * 30},
    {unit: 'week', ms: 1000 * 60 * 60 * 24 * 7},
    {unit: 'day', ms: 1000 * 60 * 60 * 24},
    {unit: 'hour', ms: 1000 * 60 * 60},
    {unit: 'minute', ms: 1000 * 60},
  ];

  const match = units.find((item) => absDiff >= item.ms) ?? units[units.length - 1];
  const valueForUnit = Math.round(diff / match.ms);

  return rtf.format(valueForUnit, match.unit);
};

const buildRangeQuery = (range: TimeRangeValue): string => {
  const window = resolveTimeWindow(range);

  return new URLSearchParams({
    from: window.from.toISOString(),
    to: window.to.toISOString(),
    window: range,
  }).toString();
};

export default function DbExceptionDetailClient() {
  const searchParams = useSearchParams();
  const eventHash = searchParams.get('eventHash');

  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
  const [groupDetail, setGroupDetail] = useState<ExceptionGroupDetail | null>(null);
  const [occurrences, setOccurrences] = useState<ExceptionOccurrence[]>([]);
  const [series, setSeries] = useState<ExceptionSeriesPoint[]>([]);
  const [seriesBucket, setSeriesBucket] = useState<Bucket | null>(null);
  const [detailStatus, setDetailStatus] = useState<'idle' | 'loading' | 'error'>('idle');
  const [occurrencePage, setOccurrencePage] = useState<number>(1);
  const [occurrenceTotal, setOccurrenceTotal] = useState<number>(0);
  const [impactCounts, setImpactCounts] = useState<Record<ImpactWindow, number>>({
    '30d': 0,
    '7d': 0,
    '24h': 0,
  });
  const [impactStatus, setImpactStatus] = useState<'idle' | 'loading' | 'error'>('idle');

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
    const windowParam = searchParams.get('window');

    if (!isValidTimeRange(windowParam)) {
      return;
    }

    setTimeRange((prev) => (prev === windowParam ? prev : windowParam));
  }, [searchParams]);

  useEffect(() => {
    setOccurrencePage(1);
  }, [eventHash, timeRange]);

  useEffect(() => {
    if (!eventHash) {
      setGroupDetail(null);
      setOccurrences([]);
      setSeries([]);
      setSeriesBucket(null);
      setOccurrenceTotal(0);
      setImpactCounts({'30d': 0, '7d': 0, '24h': 0});
      setImpactStatus('idle');
      setDetailStatus('idle');
      return;
    }

    const controller = new AbortController();
    setDetailStatus('loading');
    setGroupDetail(null);
    setOccurrences([]);
    setSeries([]);
    setSeriesBucket(null);
    setOccurrenceTotal(0);

    const load = async () => {
      try {
        const response = await fetch(
          `${apiBase}/metrics/exceptions/${encodeURIComponent(
            eventHash
          )}?${rangeQuery}&page=${occurrencePage}&per_page=${occurrencePageSize}`,
          {
            signal: controller.signal,
            headers: {Accept: 'application/json'},
          }
        );

        if (!response.ok) {
          setDetailStatus('error');
          return;
        }

        const payload = (await response.json()) as {
          data?: {
            group?: ExceptionGroupDetail | null;
            occurrences?: ExceptionOccurrence[];
            series?: Array<{
              time: string;
              timestamp?: string;
              count?: number | string;
            }>;
          };
          meta?: {
            bucket?: string;
            total?: number | string;
            page?: number | string;
          };
        };

        const seriesRows = Array.isArray(payload?.data?.series)
          ? payload.data.series
          : [];
        const normalizedSeries = seriesRows.map((point) => ({
          ...point,
          count: toCount(point.count ?? 0),
        }));
        const bucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

        setGroupDetail(payload?.data?.group ?? null);
        setOccurrences(
          Array.isArray(payload?.data?.occurrences) ? payload.data.occurrences : []
        );
        setSeries(normalizedSeries);
        setSeriesBucket(bucket);
        setOccurrenceTotal(toCount(payload?.meta?.total ?? 0));
        setDetailStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setDetailStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [eventHash, occurrencePage, rangeQuery, timeRange]);

  useEffect(() => {
    if (!eventHash) {
      return;
    }

    const controller = new AbortController();
    setImpactStatus('loading');
    setImpactCounts({'30d': 0, '7d': 0, '24h': 0});

    const loadImpact = async () => {
      try {
        const results = await Promise.all(
          impactWindows.map(async (range) => {
            const response = await fetch(
              `${apiBase}/metrics/exceptions/${encodeURIComponent(
                eventHash
              )}?${buildRangeQuery(range)}&page=1&per_page=1`,
              {
                signal: controller.signal,
                headers: {Accept: 'application/json'},
              }
            );

            if (!response.ok) {
              return [range, 0] as const;
            }

            const payload = (await response.json()) as {meta?: {total?: number | string}};
            return [range, toCount(payload?.meta?.total ?? 0)] as const;
          })
        );

        const nextCounts = results.reduce<Record<ImpactWindow, number>>(
          (carry, [range, value]) => {
            carry[range] = value;
            return carry;
          },
          {'30d': 0, '7d': 0, '24h': 0}
        );

        setImpactCounts(nextCounts);
        setImpactStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setImpactStatus('error');
          setImpactCounts({'30d': 0, '7d': 0, '24h': 0});
        }
      }
    };

    loadImpact();

    return () => controller.abort();
  }, [eventHash]);

  const detailMessage =
    !eventHash
      ? 'Select an exception from the DB exceptions list.'
      : detailStatus === 'loading'
        ? 'Loading exception detail...'
        : detailStatus === 'error'
          ? 'Unable to load exception detail.'
          : occurrences.length === 0
            ? 'No occurrences in this window.'
            : null;

  const totalPages = Math.max(1, Math.ceil(occurrenceTotal / occurrencePageSize));
  const displaySeries = useMemo(
    () =>
      series.map((point) => ({
        ...point,
        time: formatBucketLabel(
          point.timestamp,
          seriesBucket,
          clientTimeZone,
          point.time
        ),
      })),
    [clientTimeZone, series, seriesBucket]
  );

  const exceptionTitle = formatExceptionTitle(groupDetail?.exception_class ?? null);
  const backHref = `/db-exceptions?window=${timeRange}`;
  const eventLabel = eventHash ? eventHash.slice(0, 12) : '--';
  const lastSeenLabel = formatHumanDate(groupDetail?.last_seen ?? null);
  const windowStartLabel = formatLastSeen(timeWindow.from.toISOString(), clientTimeZone);
  const firstSeenLabel = useMemo(() => {
    const firstPoint = series
      .filter((point) => point.count > 0 && point.timestamp)
      .sort((a, b) => {
        const aTime = new Date(a.timestamp ?? '').getTime();
        const bTime = new Date(b.timestamp ?? '').getTime();
        return aTime - bTime;
      })[0];

    if (firstPoint?.timestamp) {
      return formatLastSeen(firstPoint.timestamp, clientTimeZone);
    }

    return windowStartLabel;
  }, [clientTimeZone, series, windowStartLabel]);
  const errorMessage = groupDetail?.error_message?.trim() || 'No message recorded.';
  const impactSummaryLabel =
    impactStatus === 'loading' ? 'Refreshing impact...' : 'Impact over fixed windows';

  return (
    <DashboardShell
      timeRange={timeRange}
      onTimeRangeChange={setTimeRange}
      rangeLabel={rangeLabel}
    >
      <div className="exception-detail-page">
        <header className="exception-hero">
          <p className="exception-hero__eyebrow">Exceptions</p>
          <div className="exception-hero__row">
            <div className="exception-hero__main">
              <h1 className="exception-hero__title">{exceptionTitle}</h1>
              <p className="exception-hero__message">{errorMessage}</p>
              <p className="exception-hero__meta">
                {rangeShortLabel} window · event {eventLabel} · last seen {lastSeenLabel}
              </p>
            </div>
            <div className="exception-hero__actions">
              <div className="exception-hero__pill">
                {formatValue(occurrenceTotal)} issue{occurrenceTotal === 1 ? '' : 's'}
              </div>
              <Link className="exceptions-filter exceptions-filter--active" href={backHref}>
                Back to DB exceptions
              </Link>
            </div>
          </div>
        </header>

        <section className="exception-top-grid">
          <div className="card exception-summary">
            <div className="exception-summary__section">
              <p className="exception-summary__title">Info</p>
              <div className="exception-summary__rows">
                <div className="exception-summary__row">
                  <span>Last seen</span>
                  <strong>{lastSeenLabel}</strong>
                </div>
                <div className="exception-summary__row">
                  <span>First seen</span>
                  <strong>{firstSeenLabel}</strong>
                </div>
                <div className="exception-summary__row">
                  <span>Window start</span>
                  <strong>{windowStartLabel}</strong>
                </div>
                <div className="exception-summary__row">
                  <span>Occurrences</span>
                  <strong>{formatValue(occurrenceTotal)}</strong>
                </div>
              </div>
            </div>
            <div className="exception-summary__divider" />
            <div className="exception-summary__section">
              <p className="exception-summary__title">Impact</p>
              <p className="exception-summary__subtitle">{impactSummaryLabel}</p>
              <div className="exception-summary__rows">
                <div className="exception-summary__row">
                  <span>Events (30 days)</span>
                  <strong>{formatValue(impactCounts['30d'])}</strong>
                </div>
                <div className="exception-summary__row">
                  <span>Events (7 days)</span>
                  <strong>{formatValue(impactCounts['7d'])}</strong>
                </div>
                <div className="exception-summary__row">
                  <span>Events (24 hours)</span>
                  <strong>{formatValue(impactCounts['24h'])}</strong>
                </div>
              </div>
            </div>
          </div>

          <div className="card exception-trend">
            <div className="exception-trend__header">
              <div>
                <p className="card-title">Occurrences</p>
                <p className="card-subtitle">{rangeLabel}</p>
              </div>
              <div className="exception-trend__totals">
                <span className="exception-trend__count">{formatValue(occurrenceTotal)}</span>
                <span className="card-chip">{rangeShortLabel}</span>
              </div>
            </div>
            <div
              className={`chart-frame exception-trend__chart${
                detailMessage ? ' chart-frame--empty' : ''
              }`}
            >
              {detailMessage ? (
                <p className="chart-empty">{detailMessage}</p>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={displaySeries}
                    margin={{top: 8, right: 8, left: -12, bottom: 0}}
                  >
                    <CartesianGrid
                      stroke="var(--grid)"
                      strokeDasharray="3 3"
                      vertical={false}
                    />
                    <XAxis
                      dataKey="time"
                      tickLine={false}
                      axisLine={false}
                      minTickGap={24}
                    />
                    <YAxis
                      tickLine={false}
                      axisLine={false}
                      allowDecimals={false}
                    />
                    <Tooltip content={<ChartTooltip />} />
                    <Bar
                      dataKey="count"
                      name="Occurrences"
                      fill="var(--accent-strong)"
                      radius={[6, 6, 0, 0]}
                    />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>
        </section>

        <section className="exception-bottom-grid">
          <section className="route-table exception-occurrences exception-occurrences--nightwatch">
            <div className="route-table__header">
              <p className="route-table__title">Occurrences</p>
              <span className="route-table__meta">
                {formatValue(occurrenceTotal)} total · page {occurrencePage} of {totalPages}
              </span>
            </div>
            {detailMessage ? (
              <p className="route-table__empty">{detailMessage}</p>
            ) : (
              <>
                <div className="route-table__scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>Occurred</th>
                        <th>User</th>
                        <th>SQL</th>
                        {/*<th>Message</th>*/}
                      </tr>
                    </thead>
                    <tbody>
                      {occurrences.map((row) => (
                        <tr key={`${row.event_hash ?? 'event'}-${row.id}`}>
                          <td>{formatLastSeen(row.occurred_at, clientTimeZone)}</td>
                          <td>
                            {row.user_type ? `${row.user_type}` : '--'}
                            {row.user_id ? ` #${row.user_id}` : ''}
                          </td>
                          <td className="exception-sql">
                            {row.raw_sql?.trim() || row.sql?.trim() || '--'}
                          </td>
                          {/*<td className="exception-message">*/}
                          {/*  {row.error_message?.trim() || '--'}*/}
                          {/*</td>*/}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="table-footer">
                  <span className="table-footer__meta">
                    Showing {occurrences.length} of {formatValue(occurrenceTotal)}
                  </span>
                  <div className="table-footer__actions">
                    <button
                      type="button"
                      className="pagination-button"
                      onClick={() => setOccurrencePage((prev) => Math.max(1, prev - 1))}
                      disabled={occurrencePage <= 1}
                    >
                      Previous
                    </button>
                    <button
                      type="button"
                      className="pagination-button"
                      onClick={() => setOccurrencePage((prev) => Math.min(totalPages, prev + 1))}
                      disabled={occurrencePage >= totalPages}
                    >
                      Next
                    </button>
                  </div>
                </div>
              </>
            )}
          </section>
        </section>
      </div>
    </DashboardShell>
  );
}
