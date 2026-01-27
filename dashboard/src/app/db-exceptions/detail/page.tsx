import {Suspense} from 'react';
import DbExceptionDetailClient from './DbExceptionDetailClient';

export default function DbExceptionDetailPage() {
  return (
    <Suspense fallback={null}>
      <DbExceptionDetailClient />
    </Suspense>
  );
}
