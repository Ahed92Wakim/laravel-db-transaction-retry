import {Suspense} from 'react';
import DbExceptionsClient from './DbExceptionsClient';

export default function DbExceptionsPage() {
  return (
    <Suspense fallback={null}>
      <DbExceptionsClient />
    </Suspense>
  );
}
