import type {TooltipProps} from 'recharts';
import {
  formatDurationMs,
  formatOptionalNumber,
  formatTooltipTimestamp,
  formatValue,
  toCount,
} from '../lib/dashboard';

export const WarningIcon = () => (
  <svg
    className="route-status__icon"
    viewBox="0 0 20 20"
    fill="none"
    aria-hidden="true"
  >
    <path
      d="M10 3.5L17 15.7H3L10 3.5Z"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinejoin="round"
    />
    <path
      d="M10 7.2V11.4"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
    />
    <circle cx="10" cy="14.1" r="1" fill="currentColor" />
  </svg>
);

export const ErrorIcon = () => (
  <svg
    className="route-status__icon"
    viewBox="0 0 20 20"
    fill="none"
    aria-hidden="true"
  >
    <circle cx="10" cy="10" r="7.5" stroke="currentColor" strokeWidth="1.6" />
    <path
      d="M10 6.4V11"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
    />
    <circle cx="10" cy="13.6" r="1" fill="currentColor" />
  </svg>
);

export const renderStatusCell = (
  value: number | null | undefined,
  tone: 'warn' | 'error'
) => {
  const count = toCount(value);
  const formatted = formatValue(count);

  if (count <= 0) {
    return <span className="route-status route-status--muted">{formatted}</span>;
  }

  return (
    <span className={`route-status route-status--${tone}`}>
      {tone === 'warn' ? <WarningIcon /> : <ErrorIcon />}
      <span className="route-status__value">{formatted}</span>
    </span>
  );
};

export function ChartTooltip({active, payload, label}: TooltipProps<number, string>) {
  if (!active || !payload || payload.length === 0) {
    return null;
  }

  const title = label ?? payload[0]?.name ?? 'Snapshot';

  return (
    <div className="tooltip">
      <strong>{title}</strong>
      {payload.map((entry) => (
        <div key={`${entry.name}-${entry.value}`}>
          {entry.name}: {formatValue(entry.value ?? 0)}
        </div>
      ))}
    </div>
  );
}

type QueryTooltipProps = TooltipProps<number, string> & {
  timeZone?: string | null;
};

const durationBucketKeys = new Set(['under_2s', 'over_2s']);

export function QueryTooltip({active, payload, label, timeZone}: QueryTooltipProps) {
  if (!active || !payload || payload.length === 0) {
    return null;
  }

  const timestamp = payload[0]?.payload?.timestamp as string | undefined;
  const fallbackLabel = label ?? payload[0]?.name ?? 'Snapshot';
  const title = formatTooltipTimestamp(timestamp, timeZone, String(fallbackLabel));
  const durationTotal = payload.reduce((sum, entry) => {
    const key = String(entry.dataKey ?? '');
    if (!durationBucketKeys.has(key)) {
      return sum;
    }

    const numeric = typeof entry.value === 'number' ? entry.value : Number(entry.value);
    return Number.isFinite(numeric) ? sum + numeric : sum;
  }, 0);
  const hasDurationBuckets = payload.some((entry) =>
    durationBucketKeys.has(String(entry.dataKey ?? ''))
  );

  return (
    <div className="tooltip">
      <strong>{title}</strong>
      {hasDurationBuckets ? (
        <div className="tooltip__total">Total: {formatOptionalNumber(durationTotal)}</div>
      ) : null}
      {payload.map((entry) => {
        const key = `${entry.name ?? entry.dataKey ?? 'value'}-${entry.value}`;
        const labelText = entry.name ?? String(entry.dataKey ?? 'Value');
        const dataKey = String(entry.dataKey ?? entry.name ?? '');
        const valueText = dataKey.includes('ms')
          ? formatDurationMs(entry.value)
          : formatOptionalNumber(
              typeof entry.value === 'number' ? entry.value : Number(entry.value)
            );
        const dotColor = entry.color ?? 'var(--accent)';

        return (
          <div key={key} className="tooltip__item">
            <span className="tooltip__dot" style={{backgroundColor: dotColor}} />
            <span className="tooltip__label">{labelText}</span>
            <span className="tooltip__value">{valueText}</span>
          </div>
        );
      })}
    </div>
  );
}
