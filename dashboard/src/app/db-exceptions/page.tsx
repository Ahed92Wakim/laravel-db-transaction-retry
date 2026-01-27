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
  occurrences: number;
  last_seen?: string | null;
};

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

const exceptionRowLimit = 50;
const occurrencePageSize = 20;

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

export default function DbExceptionsPage() {
  const [clientTimeZone, setClientTimeZone] = useState<string | null>(null);
  const [timeRange, setTimeRange] = useState<TimeRangeValue>('24h');
  const [exceptions, setExceptions] = useState<ExceptionMetric[]>([]);
  const [exceptionsStatus, setExceptionsStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [selectedEventHash, setSelectedEventHash] = useState<string | null>(null);
  const [groupDetail, setGroupDetail] = useState<ExceptionGroupDetail | null>(null);
  const [occurrences, setOccurrences] = useState<ExceptionOccurrence[]>([]);
  const [series, setSeries] = useState<ExceptionSeriesPoint[]>([]);
  const [seriesBucket, setSeriesBucket] = useState<Bucket | null>(null);
  const [detailStatus, setDetailStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [occurrencePage, setOccurrencePage] = useState<number>(1);
  const [occurrenceTotal, setOccurrenceTotal] = useState<number>(0);

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
    setExceptionsStatus('loading');

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
            occurrences?: number | string;
            last_seen?: string | null;
          }>;
        };

        const rows = Array.isArray(payload?.data) ? payload.data : [];
        const normalized = rows.map((row) => ({
          ...row,
          occurrences: toCount(row.occurrences ?? 0),
        }));

        setExceptions(normalized);
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

  useEffect(() => {
    if (exceptions.length === 0) {
      setSelectedEventHash(null);
      return;
    }

    const hasSelection = selectedEventHash
      ? exceptions.some((row) => row.event_hash === selectedEventHash)
      : false;

    if (!hasSelection) {
      setSelectedEventHash(exceptions[0].event_hash ?? null);
      setOccurrencePage(1);
    }
  }, [exceptions, selectedEventHash]);

  useEffect(() => {
    if (!selectedEventHash) {
      setGroupDetail(null);
      setOccurrences([]);
      setSeries([]);
      setSeriesBucket(null);
      setOccurrenceTotal(0);
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
            selectedEventHash
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
  }, [occurrencePage, rangeQuery, selectedEventHash, timeRange]);

  const totalOccurrences = exceptions.reduce((sum, row) => sum + toCount(row.occurrences), 0);
  const exceptionMessage =
    exceptionsStatus === 'loading'
      ? 'Loading exceptions...'
      : exceptionsStatus === 'error'
        ? 'Unable to load exception data.'
        : exceptions.length === 0
          ? 'No exceptions logged in this window.'
          : null;

  const detailMessage =
    detailStatus === 'loading'
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

  return (
    <DashboardShell timeRange={timeRange} onTimeRangeChange={setTimeRange} rangeLabel={rangeLabel}>
      <section className="route-table route-table--compact">
        <div className="route-table__header">
          <p className="route-table__title">DB exceptions</p>
          <span className="route-table__meta">
            {rangeShortLabel} window · {formatValue(exceptions.length)} unique ·{' '}
            {formatValue(totalOccurrences)} total
          </span>
        </div>
        {exceptionMessage ? (
          <p className="route-table__empty">{exceptionMessage}</p>
        ) : (
          <div className="route-table__scroll">
            <table>
              <thead>
                <tr>
                  <th>Exception</th>
                  <th>Error code</th>
                  <th>Connection</th>
                  <th>Route</th>
                  <th>Occurrences</th>
                  <th>Last seen</th>
                </tr>
              </thead>
              <tbody>
                {exceptions.map((row, index) => {
                  const title = formatExceptionTitle(row.exception_class);
                  const meta = row.exception_class?.trim();
                  const message = row.error_message?.trim();
                  const isActive = row.event_hash && row.event_hash === selectedEventHash;

                  return (
                    <tr
                      key={row.event_hash ?? `${title}-${index}`}
                      className={`exception-row${isActive ? ' exception-row--active' : ''}`}
                      onClick={() => {
                        if (row.event_hash) {
                          setSelectedEventHash(row.event_hash);
                          setOccurrencePage(1);
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
                      <td>{row.connection?.trim() || '--'}</td>
                      <td>
                        <div className="route-cell">
                          <span
                            className={`route-method route-method--text ${methodClassName(
                              row.method
                            )}`}
                          >
                            {row.method ? row.method.toUpperCase() : '--'}
                          </span>
                          <span>{formatRouteLabel(row)}</span>
                        </div>
                      </td>
                      <td>{formatValue(row.occurrences)}</td>
                      <td>{formatLastSeen(row.last_seen, clientTimeZone)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {selectedEventHash ? (
        <>
          <section className="grid charts charts--pair">
            <div className="card chart-card">
              <div className="card-header">
                <div>
                  <p className="card-title">Occurrences over time</p>
                  <p className="card-subtitle">{rangeLabel}</p>
                </div>
                <span className="card-chip">{rangeShortLabel}</span>
              </div>
              <div className={`chart-frame${detailMessage ? ' chart-frame--empty' : ''}`}>
                {detailMessage ? (
                  <p className="chart-empty">{detailMessage}</p>
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={displaySeries} margin={{top: 10, right: 20, left: 0, bottom: 0}}>
                      <CartesianGrid
                        stroke="rgba(15, 23, 42, 0.08)"
                        strokeDasharray="3 3"
                      />
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
            </div>

            <div className="card exception-detail">
              <div className="card-header">
                <div>
                  <p className="card-title">Exception details</p>
                  <p className="card-subtitle">Grouped fingerprint</p>
                </div>
                <span className="card-chip">{formatValue(occurrenceTotal)} hits</span>
              </div>
              <div className="exception-detail__body">
                <div className="exception-detail__row">
                  <span>Class</span>
                  <strong>{groupDetail?.exception_class?.trim() || '--'}</strong>
                </div>
                <div className="exception-detail__row">
                  <span>Message</span>
                  <strong>{groupDetail?.error_message?.trim() || '--'}</strong>
                </div>
                <div className="exception-detail__row">
                  <span>Error code</span>
                  <strong>
                    {formatErrorCode(groupDetail?.sql_state ?? null, groupDetail?.driver_code ?? null)}
                  </strong>
                </div>
                <div className="exception-detail__row">
                  <span>Connection</span>
                  <strong>{groupDetail?.connection?.trim() || '--'}</strong>
                </div>
                <div className="exception-detail__row">
                  <span>Last seen</span>
                  <strong>{formatLastSeen(groupDetail?.last_seen ?? null, clientTimeZone)}</strong>
                </div>
                <div className="exception-detail__sql">
                  <span>SQL</span>
                  <code>{groupDetail?.sql?.trim() || '--'}</code>
                </div>
              </div>
            </div>
          </section>

          <section className="route-table exception-occurrences">
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
                        <th>Route</th>
                        <th>User</th>
                        <th>SQL</th>
                        <th>Message</th>
                      </tr>
                    </thead>
                    <tbody>
                      {occurrences.map((row) => (
                        <tr key={`${row.event_hash ?? 'event'}-${row.id}`}>
                          <td>{formatLastSeen(row.occurred_at, clientTimeZone)}</td>
                          <td>
                            <div className="route-cell">
                              <span
                                className={`route-method route-method--text ${methodClassName(
                                  row.method
                                )}`}
                              >
                                {row.method ? row.method.toUpperCase() : '--'}
                              </span>
                              <span>{formatRouteLabel(row)}</span>
                            </div>
                          </td>
                          <td>
                            {row.user_type ? `${row.user_type}` : '--'}
                            {row.user_id ? ` #${row.user_id}` : ''}
                          </td>
                          <td className="exception-sql">
                            {row.sql?.trim() || row.raw_sql?.trim() || '--'}
                          </td>
                          <td className="exception-message">
                            {row.error_message?.trim() || '--'}
                          </td>
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
        </>
      ) : null}
    </DashboardShell>
  );
}
