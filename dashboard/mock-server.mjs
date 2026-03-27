/**
 * Mock API server for dashboard development.
 *
 * Serves realistic dummy data at all /api/transaction-retry/* endpoints.
 * Run with: node mock-server.mjs
 * Then start Next.js dev: npm run dev
 */

import http from 'http';
import {URL} from 'url';

const PORT = 3001;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function now() {
  return new Date();
}

/** Generate an array of ISO timestamps going back `count` buckets of `stepMs` each. */
function timeSeries(count, stepMs) {
  const base = now();
  const result = [];
  for (let i = count - 1; i >= 0; i--) {
    result.push(new Date(base.getTime() - i * stepMs).toISOString());
  }
  return result;
}

function rand(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function randFloat(min, max, decimals = 2) {
  return parseFloat((Math.random() * (max - min) + min).toFixed(decimals));
}

function json(res, data, status = 200) {
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': '*',
  });
  res.end(JSON.stringify(data));
}

// ---------------------------------------------------------------------------
// Dummy data generators
// ---------------------------------------------------------------------------

const ROUTES = [
  {method: 'GET',    route_name: 'users.index',    url: '/api/users'},
  {method: 'POST',   route_name: 'orders.store',   url: '/api/orders'},
  {method: 'GET',    route_name: 'products.index', url: '/api/products'},
  {method: 'PUT',    route_name: 'orders.update',  url: '/api/orders/{id}'},
  {method: 'DELETE', route_name: 'users.destroy',  url: '/api/users/{id}'},
  {method: 'GET',    route_name: 'reports.show',   url: '/api/reports/{id}'},
  {method: 'POST',   route_name: 'payments.store', url: '/api/payments'},
];

const EXCEPTION_CLASSES = [
  'Illuminate\\Database\\QueryException',
  'PDOException',
  'Illuminate\\Database\\DeadlockException',
  'Illuminate\\Database\\LockTimeoutException',
];

const SQL_STATES = ['40001', '23000', 'HY000', '42S02'];
const DRIVER_CODES = [1213, 1205, 1062, 1146];

const HASHES = [
  'a1b2c3d4e5f6a1b2',
  'b2c3d4e5f6a1b2c3',
  'c3d4e5f6a1b2c3d4',
  'd4e5f6a1b2c3d4e5',
];

/** 96 × 15-minute buckets = 24 hours */
function trafficSeries() {
  const timestamps = timeSeries(96, 15 * 60 * 1000);
  return timestamps.map((ts) => {
    const attempts = rand(0, 30);
    const success  = rand(0, attempts);
    const failure  = attempts - success;
    return {
      time:      ts,
      timestamp: ts,
      attempts,
      success,
      failure,
      recovered: success,
    };
  });
}

function queriesVolumeSeries() {
  const timestamps = timeSeries(96, 15 * 60 * 1000);
  return timestamps.map((ts) => {
    const count              = rand(20, 500);
    const transaction_count  = rand(1, Math.max(1, Math.floor(count / 10)));
    const transaction_volume = rand(1, transaction_count);
    const under_2s           = rand(Math.floor(count * 0.7), count);
    const over_2s            = count - under_2s;
    return {
      time:             ts,
      timestamp:        ts,
      count,
      transaction_count,
      transaction_volume,
      avg_ms:           rand(5, 800),
      p95_ms:           rand(200, 3000),
      under_2s,
      over_2s,
    };
  });
}

function requestSeries() {
  const timestamps = timeSeries(96, 15 * 60 * 1000);
  return timestamps.map((ts) => {
    const total         = rand(10, 300);
    const status_4xx    = rand(0, Math.floor(total * 0.05));
    const status_5xx    = rand(0, Math.floor(total * 0.03));
    const status_1xx_3xx = total - status_4xx - status_5xx;
    return {
      time:          ts,
      timestamp:     ts,
      total,
      status_1xx_3xx,
      status_4xx,
      status_5xx,
    };
  });
}

function requestDurationSeries() {
  const timestamps = timeSeries(96, 15 * 60 * 1000);
  return timestamps.map((ts) => ({
    time:      ts,
    timestamp: ts,
    count:     rand(10, 300),
    avg_ms:    rand(20, 1200),
    p95_ms:    rand(500, 5000),
  }));
}

function exceptionSeries() {
  const timestamps = timeSeries(96, 15 * 60 * 1000);
  return timestamps.map((ts) => ({
    time:      ts,
    timestamp: ts,
    count:     rand(0, 8),
  }));
}

function routeMetrics() {
  return ROUTES.map((r, i) => {
    const attempts = rand(5, 200);
    const success  = rand(0, attempts);
    return {
      route_hash: HASHES[i % HASHES.length],
      method:     r.method,
      route_name: r.route_name,
      url:        r.url,
      attempts,
      success,
      failure: attempts - success,
      last_seen: new Date(now().getTime() - rand(0, 3600) * 1000).toISOString(),
    };
  });
}

function routesVolume() {
  return ROUTES.map((r) => {
    const total      = rand(50, 2000);
    const status_4xx = rand(0, Math.floor(total * 0.05));
    const status_5xx = rand(0, Math.floor(total * 0.03));
    return {
      method:        r.method,
      route_name:    r.route_name,
      url:           r.url,
      status_1xx_3xx: total - status_4xx - status_5xx,
      status_4xx,
      status_5xx,
      total,
      avg_ms:        rand(20, 800),
      p95_ms:        rand(200, 3000),
    };
  });
}

function exceptions() {
  return EXCEPTION_CLASSES.map((cls, i) => ({
    event_hash:      HASHES[i % HASHES.length],
    exception_class: cls,
    error_message:   `SQLSTATE[${SQL_STATES[i % SQL_STATES.length]}]: ${cls.split('\\').pop()}`,
    sql_state:       SQL_STATES[i % SQL_STATES.length],
    driver_code:     DRIVER_CODES[i % DRIVER_CODES.length],
    connection:      'mysql',
    method:          ROUTES[i % ROUTES.length].method,
    route_name:      ROUTES[i % ROUTES.length].route_name,
    url:             ROUTES[i % ROUTES.length].url,
    users:           rand(1, 20),
    occurrences:     rand(1, 150),
    last_seen:       new Date(now().getTime() - rand(0, 7200) * 1000).toISOString(),
  }));
}

function transactionLogs(page = 1, perPage = 15) {
  const total = 80;
  const items = Array.from({length: perPage}, (_, i) => {
    const id         = (page - 1) * perPage + i + 1;
    const route      = ROUTES[id % ROUTES.length];
    const elapsed_ms = rand(10, 8000);
    return {
      id,
      completed_at:       new Date(now().getTime() - rand(0, 86400) * 1000).toISOString(),
      http_method:        route.method,
      route_name:         route.route_name,
      url:                route.url,
      http_status:        [200, 200, 200, 201, 422, 500][rand(0, 5)],
      elapsed_ms,
      total_queries_count: rand(1, 50),
      slow_queries_count:  rand(0, 5),
    };
  });
  return {data: items, meta: {total, page, per_page: perPage}};
}

function transactionQueries(id) {
  const route    = ROUTES[id % ROUTES.length];
  const count    = rand(3, 20);
  const queries  = Array.from({length: count}, (_, i) => ({
    id:               i + 1,
    query_order:      i + 1,
    sql_query:        SAMPLE_QUERIES[i % SAMPLE_QUERIES.length],
    raw_sql:          SAMPLE_QUERIES[i % SAMPLE_QUERIES.length],
    execution_time_ms: rand(1, 2000),
    connection_name:  'mysql',
  }));
  return {
    data: queries,
    meta: {
      transaction: {
        id,
        completed_at:        new Date(now().getTime() - rand(0, 3600) * 1000).toISOString(),
        http_method:         route.method,
        route_name:          route.route_name,
        url:                 route.url,
        http_status:         200,
        elapsed_ms:          rand(100, 5000),
        total_queries_count: count,
      },
    },
  };
}

const SAMPLE_QUERIES = [
  "select * from `users` where `id` = ? limit 1",
  "select `id`, `name`, `email` from `users` where `active` = ? order by `created_at` desc limit ? offset ?",
  "insert into `orders` (`user_id`, `total`, `status`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?)",
  "update `orders` set `status` = ?, `updated_at` = ? where `id` = ?",
  "select count(*) as aggregate from `orders` where `status` = ? and `created_at` > ?",
  "delete from `sessions` where `last_activity` < ?",
  "select `products`.*, `categories`.`name` as `category_name` from `products` left join `categories` on `categories`.`id` = `products`.`category_id` where `products`.`active` = ?",
  "update `users` set `last_login_at` = ?, `updated_at` = ? where `id` = ?",
];

function requestLogs(page = 1, perPage = 15) {
  const total = 120;
  const items = Array.from({length: perPage}, (_, i) => {
    const id    = (page - 1) * perPage + i + 1;
    const route = ROUTES[id % ROUTES.length];
    return {
      id,
      completed_at: new Date(now().getTime() - rand(0, 86400) * 1000).toISOString(),
      http_method:  route.method,
      route_name:   route.route_name,
      url:          route.url,
      http_status:  [200, 200, 201, 422, 500][rand(0, 4)],
      elapsed_ms:   rand(5, 3000),
    };
  });
  return {data: items, meta: {total, page, per_page: perPage}};
}

function requestRoutes() {
  return ROUTES.map((r) => {
    const total      = rand(100, 5000);
    const status_4xx = rand(0, Math.floor(total * 0.04));
    const status_5xx = rand(0, Math.floor(total * 0.02));
    return {
      method:         r.method,
      route_name:     r.route_name,
      url:            r.url,
      status_1xx_3xx: total - status_4xx - status_5xx,
      status_4xx,
      status_5xx,
      total,
      avg_ms:         rand(20, 600),
      p95_ms:         rand(200, 2500),
    };
  });
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

const PREFIX = '/api/transaction-retry';

function handle(pathname, searchParams, res) {
  const path = pathname.startsWith(PREFIX)
    ? pathname.slice(PREFIX.length)
    : pathname;

  const page    = parseInt(searchParams.get('page') || '1', 10);
  const perPage = parseInt(searchParams.get('per_page') || '15', 10);

  // GET /metrics/today
  if (path === '/metrics/today') {
    return json(res, {
      data: {
        attempt_records: rand(50, 500),
        success_records: rand(30, 400),
        failure_records: rand(0, 50),
      },
    });
  }

  // GET /metrics/traffic
  if (path === '/metrics/traffic') {
    const series = trafficSeries();
    return json(res, {data: series, meta: {bucket: '15minute'}});
  }

  // GET /metrics/routes
  if (path === '/metrics/routes') {
    const data  = routeMetrics();
    return json(res, {
      data,
      meta: {page, per_page: perPage, total: data.length},
    });
  }

  // GET /metrics/routes-volume
  if (path === '/metrics/routes-volume') {
    const data = routesVolume();
    return json(res, {
      data,
      meta: {page, per_page: perPage, total: data.length},
    });
  }

  // GET /metrics/exceptions
  if (path === '/metrics/exceptions') {
    const data   = exceptions();
    const series = exceptionSeries();
    const total  = data.reduce((s, e) => s + Number(e.occurrences), 0);
    return json(res, {
      data,
      meta: {
        unique:            data.length,
        users:             rand(5, 100),
        total_occurrences: total,
        last_seen:         data[0]?.last_seen ?? null,
        page,
        per_page:          perPage,
        total:             data.length,
        series,
        bucket:            '15minute',
      },
    });
  }

  // GET /metrics/exceptions/:hash
  const exceptionMatch = path.match(/^\/metrics\/exceptions\/(.+)$/);
  if (exceptionMatch) {
    const hash  = decodeURIComponent(exceptionMatch[1]);
    const idx   = HASHES.indexOf(hash);
    const cls   = EXCEPTION_CLASSES[idx >= 0 ? idx : 0];
    const sqlState = SQL_STATES[idx >= 0 ? idx : 0];
    const occurrences = Array.from({length: rand(5, 20)}, (_, i) => ({
      id:           i + 1,
      occurred_at:  new Date(now().getTime() - rand(0, 86400) * 1000).toISOString(),
      sql_query:    SAMPLE_QUERIES[i % SAMPLE_QUERIES.length],
      raw_sql:      SAMPLE_QUERIES[i % SAMPLE_QUERIES.length],
      error_message: `SQLSTATE[${sqlState}]: ${cls.split('\\').pop()}`,
      method:       ROUTES[i % ROUTES.length].method,
      route_name:   ROUTES[i % ROUTES.length].route_name,
      url:          ROUTES[i % ROUTES.length].url,
      user_type:    'App\\Models\\User',
      user_id:      String(rand(1, 200)),
      connection:   'mysql',
      sql_state:    sqlState,
      driver_code:  DRIVER_CODES[idx >= 0 ? idx : 0],
      event_hash:   hash,
    }));
    return json(res, {
      data: {
        group: {
          exception_class: cls,
          error_message:   `SQLSTATE[${sqlState}]: ${cls.split('\\').pop()}`,
          sql_state:       sqlState,
          driver_code:     DRIVER_CODES[idx >= 0 ? idx : 0],
          connection:      'mysql',
          sql:             SAMPLE_QUERIES[0],
          occurrences:     occurrences.length,
          last_seen:       new Date(now().getTime() - rand(0, 3600) * 1000).toISOString(),
        },
        occurrences,
        series: exceptionSeries(),
      },
      meta: {bucket: '15minute', total: occurrences.length, page},
    });
  }

  // GET /metrics/queries-volume
  if (path === '/metrics/queries-volume') {
    const series = queriesVolumeSeries();
    return json(res, {data: series, meta: {bucket: '15minute'}});
  }

  // GET /metrics/queries-duration
  if (path === '/metrics/queries-duration') {
    const series = requestDurationSeries();
    const counts = series.map((s) => Number(s.count));
    const avgs   = series.map((s) => Number(s.avg_ms));
    return json(res, {
      data: series,
      meta: {
        bucket:  '15minute',
        count:   counts.reduce((a, b) => a + b, 0),
        min_ms:  Math.min(...avgs),
        max_ms:  Math.max(...avgs) * 2,
        avg_ms:  Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length),
        p95_ms:  rand(500, 3000),
      },
    });
  }

  // GET /metrics/queries
  if (path === '/metrics/queries') {
    const series = queriesVolumeSeries();
    return json(res, {data: series, meta: {bucket: '15minute'}});
  }

  // GET /metrics/requests
  if (path === '/metrics/requests') {
    const series = requestSeries();
    return json(res, {data: series, meta: {bucket: '15minute'}});
  }

  // GET /metrics/requests-duration
  if (path === '/metrics/requests-duration') {
    const series = requestDurationSeries();
    const avgs   = series.map((s) => Number(s.avg_ms));
    return json(res, {
      data: series,
      meta: {
        bucket:  '15minute',
        count:   series.reduce((s, r) => s + Number(r.count), 0),
        min_ms:  Math.min(...avgs),
        max_ms:  Math.max(...avgs) * 2,
        avg_ms:  Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length),
        p95_ms:  rand(500, 3000),
      },
    });
  }

  // GET /metrics/requests-routes
  if (path === '/metrics/requests-routes') {
    const data = requestRoutes();
    return json(res, {
      data,
      meta: {total: data.length},
    });
  }

  // GET /requests
  if (path === '/requests') {
    return json(res, requestLogs(page, perPage));
  }

  // GET /transaction-logs
  if (path === '/transaction-logs') {
    return json(res, transactionLogs(page, perPage));
  }

  // GET /transaction-logs/:id/queries
  const txQueriesMatch = path.match(/^\/transaction-logs\/(\d+)\/queries$/);
  if (txQueriesMatch) {
    return json(res, transactionQueries(parseInt(txQueriesMatch[1], 10)));
  }

  // 404
  return json(res, {error: 'Not found', path}, 404);
}

// ---------------------------------------------------------------------------
// Server
// ---------------------------------------------------------------------------

const server = http.createServer((req, res) => {
  // CORS pre-flight
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': '*',
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
    });
    return res.end();
  }

  const url            = new URL(req.url, `http://localhost:${PORT}`);
  const {pathname, searchParams} = url;

  console.log(`${req.method} ${pathname}`);
  handle(pathname, searchParams, res);
});

server.listen(PORT, () => {
  console.log(`Mock API server running at http://localhost:${PORT}`);
  console.log(`Serving ${PREFIX}/* endpoints`);
});
