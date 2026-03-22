'use client';

import {useEffect, useMemo, useState} from 'react';
import {useRouter} from 'next/navigation';
import {
  apiBase,
  formatDurationMs,
  formatRouteLabel,
  formatValue,
  methodClassName,
  toCount,
  type TimeRangeValue,
} from '../lib/dashboard';
import {renderStatusCell} from './dashboard-ui';

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

type RequestRoutesTableProps = {
  rangeQuery: string;
  timeRange: TimeRangeValue;
  rangeShortLabel: string;
  requestType: 'http' | 'command';
  title: string;
  itemLabel: string;
};

const routePageSize = 10;

export default function RequestRoutesTable({
  rangeQuery,
  timeRange,
  rangeShortLabel,
  requestType,
  title,
  itemLabel,
}: RequestRoutesTableProps) {
  const router = useRouter();
  const [routeMetrics, setRouteMetrics] = useState<RequestRouteMetric[]>([]);
  const [routeMetricsStatus, setRouteMetricsStatus] = useState<'idle' | 'loading' | 'error'>(
    'idle'
  );
  const [routeMetricsPage, setRouteMetricsPage] = useState<number>(1);
  const [routeMetricsTotal, setRouteMetricsTotal] = useState<number>(0);
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [sortColumn, setSortColumn] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  useEffect(() => {
    setRouteMetricsPage(1);
  }, [timeRange]);

  useEffect(() => {
    const controller = new AbortController();
    setRouteMetricsStatus('loading');
    setRouteMetrics([]);

    const load = async () => {
      try {
        const params = new URLSearchParams(rangeQuery);
        params.set('page', String(routeMetricsPage));
        params.set('per_page', String(routePageSize));
        params.set('type', requestType);
        if (sortColumn && sortColumn !== 'p95_ms') {
          params.set('sort_by', sortColumn);
          params.set('sort_dir', sortDirection);
        }

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
  }, [rangeQuery, requestType, routeMetricsPage, sortColumn, sortDirection]);

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

  const handleSort = (column: string) => {
    const direction = sortColumn === column ? (sortDirection === 'asc' ? 'desc' : 'asc') : 'desc';
    setSortColumn(column);
    setSortDirection(direction);
    if (column !== 'p95_ms') {
      setRouteMetricsPage(1);
    }
  };

  // p95_ms is computed post-pagination, so sort it client-side within the current page only
  const sortedRoutes = useMemo(() => {
    if (sortColumn !== 'p95_ms') return filteredRoutes;
    return [...filteredRoutes].sort((a, b) =>
      sortDirection === 'asc' ? a.p95_ms - b.p95_ms : b.p95_ms - a.p95_ms
    );
  }, [filteredRoutes, sortColumn, sortDirection]);

  const routeMetricsTotalPages = Math.max(1, Math.ceil(routeMetricsTotal / routePageSize));
  const currentRoutePage = Math.min(routeMetricsPage, routeMetricsTotalPages);
  const routePageRows = sortedRoutes.slice(0, routePageSize);
  const routeTableKey = `${timeRange}-${currentRoutePage}-${sortColumn ?? 'default'}-${sortDirection}-${normalizedSearch}`;
  const noRoutesInWindow = routeMetrics.length === 0;
  const routeMessage =
    routeMetricsStatus === 'loading'
      ? `Loading ${itemLabel}...`
      : routeMetricsStatus === 'error'
        ? `Unable to load ${itemLabel}.`
        : noRoutesInWindow
          ? `No ${itemLabel} recorded in this window.`
          : filteredRoutes.length === 0
            ? `No ${itemLabel} match the current filters.`
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
    <section className="route-table route-table--compact">
      <div className="route-table__header route-table__header--exceptions">
        <div className="route-table__heading">
          <p className="route-table__title">{title}</p>
          <span className="route-table__meta">
            {rangeShortLabel} window · {formatValue(routeMetricsTotal)} {itemLabel} · page{' '}
            {currentRoutePage} of {routeMetricsTotalPages}
          </span>
        </div>
        <div className="exceptions-toolbar">
          <input
            className="exceptions-search"
            type="search"
            placeholder={`Search ${itemLabel}...`}
            value={searchQuery}
            onChange={(event) => setSearchQuery(event.target.value)}
            aria-label={`Search ${itemLabel}`}
          />
        </div>
      </div>

      {routeMessage ? (
        <p className="route-table__empty">{routeMessage}</p>
      ) : (
        <>
          <div className="route-table__scroll">
            <table key={routeTableKey}>
              <thead>
                <tr>
                  {([
                    {key: 'method', label: 'Method'},
                    {key: 'path', label: 'Path'},
                    {key: 'status_1xx_3xx', label: '1/2/3xx'},
                    {key: 'status_4xx', label: '4xx'},
                    {key: 'status_5xx', label: '5xx'},
                    {key: 'total', label: 'Total'},
                    {key: 'avg_ms', label: 'Avg'},
                    {key: 'p95_ms', label: 'P95'},
                  ] as const).map(({key, label}) => (
                    <th
                      key={key}
                      className="th--sortable"
                      onClick={() => handleSort(key)}
                      aria-sort={
                        sortColumn === key
                          ? sortDirection === 'asc'
                            ? 'ascending'
                            : 'descending'
                          : 'none'
                      }
                    >
                      {label}
                      <span className="sort-indicator" aria-hidden="true">
                        {sortColumn === key ? (sortDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}
                      </span>
                    </th>
                  ))}
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
                disabled={
                  routeMetricsStatus === 'loading' || currentRoutePage >= routeMetricsTotalPages
                }
              >
                Next
              </button>
            </div>
          </div>
        </>
      )}
    </section>
  );
}
