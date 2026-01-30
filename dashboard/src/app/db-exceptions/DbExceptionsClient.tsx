'use client';

import {useEffect, useMemo, useState} from 'react';
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {useRouter, useSearchParams} from 'next/navigation';
import DashboardShell from '../components/DashboardShell';
import {ChartTooltip} from '../components/dashboard-ui';
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
} from '../lib/dashboard';

type ExceptionMetric = {
  event_hash?: string | null;
  exception_class?: string | null;
  error_message?: string | null;
  sql_state?: string | null;
  driver_code?: number | string | null;
  connection?: string | null;
  method?: string | null;
  route_name?: string | null;
  url?: string | null;
  users: number;
  occurrences: number;
  last_seen?: string | null;
};

type ExceptionSeriesPoint = {
  time: string;
  timestamp?: string;
  count: number;
};

type ExceptionSummaryTotals = {
  unique: number;
  users: number;
  totalOccurrences: number;
  lastSeen: string | null;
};

const exceptionRowLimit = 50;
const exceptionPageSize = 10;

const emptySummaryTotals: ExceptionSummaryTotals = {
  unique: 0,
  users: 0,
  totalOccurrences: 0,
  lastSeen: null,
};

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

const formatErrorCode = (
  sqlState?: string | null,
  driverCode?: number | string | null
): string => {
  const sql = sqlState?.trim();
  const driver = driverCode != null && `${driverCode}` !== '' ? `${driverCode}` : null;

  if (!sql && !driver) {
    return '--';
  }

  if (sql && driver) {
    return `${sql} / ${driver}`;
  }

  return sql ?? driver ?? '--';
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

export default function DbExceptionsClient() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
  const [exceptions, setExceptions] = useState<ExceptionMetric[]>([]);
  const [exceptionsStatus, setExceptionsStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [summarySeries, setSummarySeries] = useState<ExceptionSeriesPoint[]>([]);
  const [summaryBucket, setSummaryBucket] = useState<Bucket | null>(null);
  const [summaryTotals, setSummaryTotals] = useState<ExceptionSummaryTotals>(emptySummaryTotals);
  const [exceptionPage, setExceptionPage] = useState<number>(1);
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
    const controller = new AbortController();
    setExceptionsStatus('loading');
    setSummarySeries([]);
    setSummaryBucket(null);
    setSummaryTotals(emptySummaryTotals);

    const load = async () => {
      try {
        const response = await fetch(
          `${apiBase}/metrics/exceptions?${rangeQuery}&limit=${exceptionRowLimit}`,
          {
            signal: controller.signal,
            headers: {Accept: 'application/json'},
          }
        );

        if (!response.ok) {
          setExceptionsStatus('error');
          return;
        }

        const payload = (await response.json()) as {
          data?: Array<{
            event_hash?: string | null;
            exception_class?: string | null;
            error_message?: string | null;
            sql_state?: string | null;
            driver_code?: number | string | null;
            connection?: string | null;
            method?: string | null;
            route_name?: string | null;
            url?: string | null;
            users?: number | string;
            occurrences?: number | string;
            last_seen?: string | null;
          }>;
          meta?: {
            bucket?: string | null;
            unique?: number | string;
            users?: number | string;
            total_occurrences?: number | string;
            last_seen?: string | null;
            series?: Array<{
              time: string;
              timestamp?: string;
              count?: number | string;
            }>;
          };
        };

        const rows = Array.isArray(payload?.data) ? payload.data : [];
        const normalized = rows.map((row) => ({
          ...row,
          users: toCount(row.users ?? 0),
          occurrences: toCount(row.occurrences ?? 0),
        }));
        const normalizedTotalOccurrences = normalized.reduce(
          (sum, row) => sum + row.occurrences,
          0
        );

        const summarySeriesRows = Array.isArray(payload?.meta?.series)
          ? payload.meta.series
          : [];
        const normalizedSummarySeries = summarySeriesRows.map((point) => ({
          ...point,
          count: toCount(point.count ?? 0),
        }));
        const metaBucket = resolveBucket(payload?.meta?.bucket) ?? bucketForRange(timeRange);

        setExceptions(normalized);
        setSummarySeries(normalizedSummarySeries);
        setSummaryBucket(metaBucket);
        setSummaryTotals({
          unique: toCount(payload?.meta?.unique ?? normalized.length),
          users: toCount(payload?.meta?.users ?? 0),
          totalOccurrences: toCount(
            payload?.meta?.total_occurrences ?? normalizedTotalOccurrences
          ),
          lastSeen: payload?.meta?.last_seen ?? null,
        });
        setExceptionPage(1);
        setExceptionsStatus('idle');
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setExceptionsStatus('error');
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery]);

  const normalizedSearch = searchQuery.trim().toLowerCase();
  const filteredExceptions = useMemo(() => {
    if (normalizedSearch === '') {
      return exceptions;
    }

    return exceptions.filter((row) => {
      const haystack = [
        row.exception_class,
        row.error_message,
        row.sql_state,
        row.driver_code,
        row.connection,
        row.method,
        row.route_name,
        row.url,
      ]
        .map((value) => `${value ?? ''}`.toLowerCase())
        .join(' ');

      return haystack.includes(normalizedSearch);
    });
  }, [exceptions, normalizedSearch]);

  const fallbackTotalOccurrences = exceptions.reduce(
    (sum, row) => sum + toCount(row.occurrences),
    0
  );
  const summaryTotalOccurrences =
    summaryTotals.totalOccurrences > 0 ? summaryTotals.totalOccurrences : fallbackTotalOccurrences;
  const summaryUnique = summaryTotals.unique > 0 ? summaryTotals.unique : exceptions.length;
  const summaryBucketResolved = summaryBucket ?? bucketForRange(timeRange);
  const summaryDisplaySeries = useMemo(
    () =>
      summarySeries.map((point) => ({
        ...point,
        time: formatBucketLabel(
          point.timestamp,
          summaryBucketResolved,
          clientTimeZone,
          point.time
        ),
      })),
    [clientTimeZone, summaryBucketResolved, summarySeries]
  );

  const filteredOccurrences = filteredExceptions.reduce(
    (sum, row) => sum + row.occurrences,
    0
  );
  const exceptionTotalPages = Math.max(1, Math.ceil(filteredExceptions.length / exceptionPageSize));
  const currentExceptionPage = Math.min(exceptionPage, exceptionTotalPages);
  const exceptionStartIndex = (currentExceptionPage - 1) * exceptionPageSize;
  const exceptionsPageRows = filteredExceptions.slice(
    exceptionStartIndex,
    exceptionStartIndex + exceptionPageSize
  );
  const noExceptionsInWindow = exceptions.length === 0 && summaryTotalOccurrences === 0;
  const exceptionMessage =
    exceptionsStatus === 'loading'
      ? 'Loading exceptions...'
      : exceptionsStatus === 'error'
        ? 'Unable to load exception data.'
        : noExceptionsInWindow
          ? 'No exceptions logged in this window.'
          : filteredExceptions.length === 0
            ? 'No exceptions match the current filters.'
            : null;
  const summaryMessage =
    exceptionsStatus === 'loading'
      ? 'Loading exception metrics...'
      : exceptionsStatus === 'error'
        ? 'Unable to load exception metrics.'
        : noExceptionsInWindow
          ? 'No exceptions logged in this window.'
          : null;
  const summaryLastSeenLabel = formatLastSeen(summaryTotals.lastSeen, clientTimeZone);
  useEffect(() => {
    setExceptionPage((prev) => Math.min(prev, exceptionTotalPages));
  }, [exceptionTotalPages]);

  return (
    <DashboardShell timeRange={timeRange} onTimeRangeChange={setTimeRange} rangeLabel={rangeLabel}>
      <section className="card chart-card chart-card--wide exceptions-summary">
        <div className="card-header">
          <div>
            <p className="card-title">Exceptions</p>
            <p className="card-subtitle">{rangeLabel}</p>
          </div>
          <span className="card-chip">{rangeShortLabel}</span>
        </div>
          <div className="chart-summary exceptions-summary__summary">
          <div className="chart-summary__main">
            <span className="chart-summary__label">Occurrences</span>
            <span className="chart-summary__value">{formatValue(summaryTotalOccurrences)}</span>
            <span className="chart-summary__meta">
              {formatValue(summaryUnique)} unique · {formatValue(summaryTotals.users)} users · last
              seen {summaryLastSeenLabel}
            </span>
          </div>
        </div>
        <div className={`chart-frame${summaryMessage ? ' chart-frame--empty' : ''}`}>
          {summaryMessage ? (
            <p className="chart-empty">{summaryMessage}</p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={summaryDisplaySeries} margin={{top: 10, right: 20, left: 0, bottom: 0}}>
                <CartesianGrid stroke="rgba(15, 23, 42, 0.08)" strokeDasharray="3 3" />
                <XAxis dataKey="time" tickLine={false} axisLine={false} />
                <YAxis tickLine={false} axisLine={false} />
                <Tooltip content={<ChartTooltip />} />
                <Line
                  type="monotone"
                  dataKey="count"
                  name="Occurrences"
                  stroke="var(--accent)"
                  strokeWidth={2}
                  dot={false}
                  activeDot={{r: 4, strokeWidth: 2}}
                />
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>
      </section>

      <section className="route-table route-table--compact">
        <div className="route-table__header route-table__header--exceptions">
          <div className="route-table__heading">
            <p className="route-table__title">DB exceptions</p>
            <span className="route-table__meta">
              {rangeShortLabel} window · {formatValue(filteredExceptions.length)} shown of{' '}
              {formatValue(summaryUnique)} unique · {formatValue(filteredOccurrences)} occurrences ·
              page {currentExceptionPage} of {exceptionTotalPages}
            </span>
          </div>
          <div className="exceptions-toolbar">
            <input
              type="search"
              className="exceptions-search"
              placeholder="Search exceptions"
              value={searchQuery}
              onChange={(event) => {
                setSearchQuery(event.target.value);
                setExceptionPage(1);
              }}
            />
          </div>
        </div>
        {exceptionMessage ? (
          <p className="route-table__empty">{exceptionMessage}</p>
        ) : (
          <>
            <div className="route-table__scroll">
              <table>
                <thead>
                  <tr>
                    <th>Exception</th>
                    <th>Error code</th>
                    <th>Occurrences</th>
                    <th>Users</th>
                    <th>Last seen</th>
                  </tr>
                </thead>
                <tbody>
                  {exceptionsPageRows.map((row, index) => {
                    const title = formatExceptionTitle(row.exception_class);
                    const meta = row.exception_class?.trim();
                    const message = row.error_message?.trim();
                    const globalIndex = exceptionStartIndex + index;

                    return (
                      <tr
                        key={row.event_hash ?? `${title}-${globalIndex}`}
                        className="exception-row"
                        onClick={() => {
                          if (row.event_hash) {
                            const href = `/db-exceptions/detail?eventHash=${encodeURIComponent(
                              row.event_hash
                            )}&window=${timeRange}`;
                            router.push(href);
                          }
                        }}
                      >
                        <td>
                          <div className="exception-cell">
                            <span className="exception-title">{title}</span>
                            {meta && meta !== title ? (
                              <span className="exception-meta">{meta}</span>
                            ) : null}
                            {message ? (
                              <span className="exception-meta">{message}</span>
                            ) : null}
                          </div>
                        </td>
                        <td className="exception-code">
                          {formatErrorCode(row.sql_state, row.driver_code)}
                        </td>
                        <td>{formatValue(row.occurrences)}</td>
                        <td>{formatValue(row.users)}</td>
                        <td>{formatHumanDate(row.last_seen)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="table-footer">
              <span className="table-footer__meta">
                Showing {exceptionsPageRows.length} of {formatValue(filteredExceptions.length)}
              </span>
              <div className="table-footer__actions">
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() => setExceptionPage((prev) => Math.max(1, prev - 1))}
                  disabled={currentExceptionPage <= 1}
                >
                  Previous
                </button>
                <button
                  type="button"
                  className="pagination-button"
                  onClick={() => setExceptionPage((prev) => Math.min(exceptionTotalPages, prev + 1))}
                  disabled={currentExceptionPage >= exceptionTotalPages}
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
