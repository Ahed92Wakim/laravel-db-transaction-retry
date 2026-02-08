'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { useTheme } from './ThemeProvider';
import {
  apiBase,
  formatOptionalNumber,
  resolveTimeWindow,
  timeRanges,
  toCount,
  type TimeRangeValue,
} from '../lib/dashboard';

const navItems: Array<{
  label: string;
  href?: string;
  badge?: string;
  tone?: 'warn';
  disabled?: boolean;
}> = [
    { label: 'Transactions', href: '/transactions' },
    { label: 'Retry traffic', href: '/retry-traffic' },
    { label: 'DB exceptions', href: '/db-exceptions' },
  ];

type StatusItem = {
  label: string;
  value: string;
  tone?: 'ok' | 'warn';
};

type DashboardShellProps = {
  timeRange: TimeRangeValue;
  onTimeRangeChange: (value: TimeRangeValue) => void;
  rangeLabel: string;
  children: ReactNode;
};

export default function DashboardShell({
  timeRange,
  onTimeRangeChange,
  rangeLabel,
  children,
}: DashboardShellProps) {
  const pathname = usePathname();
  const { theme, toggleTheme } = useTheme();
  const [issuesCount, setIssuesCount] = useState<number | null>(null);
  const [retryAttemptCount, setRetryAttemptCount] = useState<number | null>(null);
  const [retryFailureCount, setRetryFailureCount] = useState<number | null>(null);
  const timeWindow = useMemo(() => resolveTimeWindow(timeRange), [timeRange]);
  const rangeQuery = useMemo(() => {
    const params = new URLSearchParams({
      from: timeWindow.from.toISOString(),
      to: timeWindow.to.toISOString(),
      window: timeRange,
    });

    return params.toString();
  }, [timeRange, timeWindow]);
  const statusItems: StatusItem[] = useMemo(() => {
    const issuesValue = formatOptionalNumber(issuesCount);
    const issuesTone =
      issuesCount == null ? undefined : issuesCount > 0 ? 'warn' : 'ok';
    const retryAttemptsValue = formatOptionalNumber(retryAttemptCount);
    const retryFailuresValue = formatOptionalNumber(retryFailureCount);
    const retryFailuresTone =
      retryFailureCount == null ? undefined : retryFailureCount > 0 ? 'warn' : 'ok';

    return [
      { label: 'DB Exceptions', value: issuesValue, tone: issuesTone },
      { label: 'Retry failures', value: retryFailuresValue, tone: retryFailuresTone },
      { label: 'Retry attempts', value: retryAttemptsValue },
    ];
  }, [issuesCount, retryAttemptCount, retryFailureCount]);

  useEffect(() => {
    const controller = new AbortController();

    const load = async () => {
      setIssuesCount(null);
      setRetryAttemptCount(null);
      setRetryFailureCount(null);

      try {
        const [exceptionsResult, todayResult] = await Promise.allSettled([
          fetch(`${apiBase}/metrics/exceptions?${rangeQuery}&limit=1`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
          }),
          fetch(`${apiBase}/metrics/today?${rangeQuery}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
          }),
        ]);

        if (exceptionsResult.status === 'fulfilled' && exceptionsResult.value.ok) {
          const payload = (await exceptionsResult.value.json()) as {
            data?: unknown[];
            meta?: { unique?: number | string };
          };
          const unique =
            payload?.meta?.unique ?? (Array.isArray(payload?.data) ? payload.data.length : null);
          setIssuesCount(unique == null ? null : toCount(unique));
        }

        if (todayResult.status === 'fulfilled' && todayResult.value.ok) {
          const payload = (await todayResult.value.json()) as {
            data?: {
              attempt_records?: number | string;
              failure_records?: number | string;
            };
          };
          setRetryAttemptCount(toCount(payload?.data?.attempt_records ?? 0));
          setRetryFailureCount(toCount(payload?.data?.failure_records ?? 0));
        }
      } catch (error) {
        if ((error as Error).name !== 'AbortError') {
          setIssuesCount(null);
          setRetryAttemptCount(null);
          setRetryFailureCount(null);
        }
      }
    };

    load();

    return () => controller.abort();
  }, [rangeQuery]);

  const activeNavItem = useMemo(() => {
    return navItems.find((item) => {
      if (!item.href) return false;
      if (!pathname) return false;

      // Normalize pathname to remove trailing slash for exact match check
      const normalizedPath = pathname.endsWith('/') && pathname.length > 1
        ? pathname.slice(0, -1)
        : pathname;

      return normalizedPath === item.href || normalizedPath.startsWith(`${item.href}/`);
    });
  }, [pathname]);

  const pageTitle = useMemo(() => {
    if (!activeNavItem) return 'Transaction Retry Dashboard';

    switch (activeNavItem.href) {
      case '/transactions':
        return 'Transaction Logs';
      case '/retry-traffic':
        return 'Retry Traffic Analysis';
      case '/db-exceptions':
        return 'Database Exception Reports';
      default:
        return activeNavItem.label;
    }
  }, [activeNavItem]);

  return (
    <main className="dashboard-shell">
      <aside className="sidebar">
        <div>
          <div className="sidebar__brand">
            <img
              className="sidebar__logo"
              src="/transaction-retry/logo-cropped.svg"
              alt="Database Transaction Retry"
            />
            <div>
              <p className="sidebar__title">Retry Control</p>
            </div>
          </div>
          <div className="sidebar__separator"></div>
          <nav className="sidebar__nav">
            {navItems.map((item) => {
              const isActive = item === activeNavItem;
              const classes = `sidebar__item${isActive ? ' sidebar__item--active' : ''}${item.disabled ? ' sidebar__item--disabled' : ''
                }`;

              if (item.href && !item.disabled) {
                return (
                  <Link
                    key={item.label}
                    href={item.href}
                    className={classes}
                    aria-current={isActive ? 'page' : undefined}
                  >
                    <span>{item.label}</span>
                    {item.badge ? (
                      <span
                        className={`sidebar__pill${item.tone === 'warn' ? ' sidebar__pill--warn' : ''
                          }`}
                      >
                        {item.badge}
                      </span>
                    ) : null}
                  </Link>
                );
              }

              return (
                <span key={item.label} className={classes} aria-disabled="true">
                  <span>{item.label}</span>
                  {item.badge ? (
                    <span
                      className={`sidebar__pill${item.tone === 'warn' ? ' sidebar__pill--warn' : ''
                        }`}
                    >
                      {item.badge}
                    </span>
                  ) : null}
                </span>
              );
            })}
          </nav>
        </div>
        <div className="sidebar__panel">
          <div className="sidebar__panel-header">
            <span>System status</span>
            <button
              type="button"
              className="theme-toggle"
              onClick={toggleTheme}
              aria-pressed={theme === 'dark'}
            >
              {theme === 'dark' ? 'Light mode' : 'Dark mode'}
            </button>
          </div>
          <div className="sidebar__panel-body">
            {statusItems.map((item) => (
              <div className="sidebar__status" key={item.label}>
                <span className="sidebar__status-label">{item.label}</span>
                <span
                  className={`sidebar__status-value${item.tone === 'ok'
                      ? ' sidebar__status-value--ok'
                      : item.tone === 'warn'
                        ? ' sidebar__status-value--warn'
                        : ''
                    }`}
                >
                  {item.value}
                </span>
              </div>
            ))}
          </div>
        </div>
      </aside>

      <div className="dashboard">
        <header className="dashboard-header">
          {/*<div className="dashboard-header__content">*/}
          <div className="dashboard-header__intro">
            <span className="eyebrow">
              {pageTitle}
            </span>
            {/*<h1 className="dashboard-header__title">Transaction Retry Command Center</h1>*/}
            {/*<p className="dashboard-header__subtitle">*/}
            {/*  Window: {rangeLabel}. Metrics update across the dashboard.*/}
            {/*</p>*/}
          </div>
          <div className="date-filter" role="group" aria-label="Date range">
            {/*<span className="date-filter__label">Date range</span>*/}
            <div className="date-filter__options">
              {timeRanges.map((range) => (
                <button
                  key={range.value}
                  type="button"
                  className={`date-filter__button${range.value === timeRange ? ' date-filter__button--active' : ''
                    }`}
                  onClick={() => onTimeRangeChange(range.value)}
                  aria-pressed={range.value === timeRange}
                >
                  {range.label}
                </button>
              ))}
              {/*</div>*/}
            </div>
          </div>
        </header>

        {children}
      </div>
    </main>
  );
}
