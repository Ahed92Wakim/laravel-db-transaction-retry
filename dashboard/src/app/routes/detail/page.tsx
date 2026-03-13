import {Suspense} from 'react';
import RouteDetailClient from './RouteDetailClient';

export default function RouteDetailPage() {
  return (
    <Suspense fallback={null}>
      <RouteDetailClient />
    </Suspense>
  );
}
