'use client';

import {useCallback, useEffect, useMemo, useState} from 'react';
import {usePathname, useRouter, useSearchParams} from 'next/navigation';
import {resolveTimeRange, type TimeRangeValue} from './dashboard';

export const usePersistentTimeRange = (): readonly [
  TimeRangeValue,
  (value: TimeRangeValue) => void,
] => {
  const pathname = usePathname();
  const router = useRouter();
  const searchParams = useSearchParams();
  const urlTimeRange = useMemo(
    () => resolveTimeRange(searchParams.get('window')),
    [searchParams]
  );
  const [timeRange, setTimeRange] = useState<TimeRangeValue>(urlTimeRange);

  useEffect(() => {
    setTimeRange((current) => (current === urlTimeRange ? current : urlTimeRange));
  }, [urlTimeRange]);

  const updateTimeRange = useCallback(
    (value: TimeRangeValue) => {
      setTimeRange((current) => (current === value ? current : value));

      const params = new URLSearchParams(searchParams.toString());

      if (params.get('window') === value) {
        return;
      }

      params.set('window', value);
      const query = params.toString();

      router.replace(query ? `${pathname}?${query}` : pathname, {scroll: false});
    },
    [pathname, router, searchParams]
  );

  return [timeRange, updateTimeRange] as const;
};
