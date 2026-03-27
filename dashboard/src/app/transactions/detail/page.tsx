import {Suspense} from 'react';
import TransactionDetailClient from './TransactionDetailClient';

export default function TransactionDetailPage() {
    return (
        <Suspense fallback={null}>
            <TransactionDetailClient />
        </Suspense>
    );
}
