'use client';

import Link from 'next/link';
import {usePathname} from 'next/navigation';
import {type ReactNode} from 'react';
import {useTheme} from './ThemeProvider';
import {timeRanges, type TimeRangeValue} from '../lib/dashboard';

const navItems: Array<{
  label: string;
  href?: string;
  badge?: string;
  tone?: 'warn';
  disabled?: boolean;
}> = [
  {label: 'Overview', href: '/overview'},
  {label: 'Retry traffic', href: '/retry-traffic', badge: 'Live'},
  {label: 'Queue health', badge: '243', disabled: true},
  {label: 'Replica lag', disabled: true},
  {label: 'Alerts', badge: '7', tone: 'warn', disabled: true},
];

const statusItems = [
  {label: 'Primary DB', value: 'Stable', tone: 'ok'},
  {label: 'Write locks', value: '3 hotspots', tone: 'warn'},
  {label: 'Auto throttle', value: 'Enabled'},
];

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
  const {theme, toggleTheme} = useTheme();

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
          <nav className="sidebar__nav">
            {navItems.map((item) => {
              const isActive = item.href
                ? pathname === item.href || pathname?.startsWith(`${item.href}/`)
                : false;
              const classes = `sidebar__item${isActive ? ' sidebar__item--active' : ''}${
                item.disabled ? ' sidebar__item--disabled' : ''
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
                        className={`sidebar__pill${
                          item.tone === 'warn' ? ' sidebar__pill--warn' : ''
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
                      className={`sidebar__pill${
                        item.tone === 'warn' ? ' sidebar__pill--warn' : ''
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
                  className={`sidebar__status-value${
                    item.tone === 'ok'
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
          <div className="dashboard-header__intro">
            <span className="eyebrow">Retry telemetry</span>
            <h1 className="dashboard-header__title">Transaction Retry Command Center</h1>
            <p className="dashboard-header__subtitle">
              Window: {rangeLabel}. Metrics update across the dashboard.
            </p>
          </div>
          <div className="date-filter" role="group" aria-label="Date range">
            <span className="date-filter__label">Date range</span>
            <div className="date-filter__options">
              {timeRanges.map((range) => (
                <button
                  key={range.value}
                  type="button"
                  className={`date-filter__button${
                    range.value === timeRange ? ' date-filter__button--active' : ''
                  }`}
                  onClick={() => onTimeRangeChange(range.value)}
                  aria-pressed={range.value === timeRange}
                >
                  {range.label}
                </button>
              ))}
            </div>
          </div>
        </header>

        {children}
      </div>
    </main>
  );
}
