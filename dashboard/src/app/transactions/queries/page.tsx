import {Suspense} from 'react';
import TransactionQueriesClient from './TransactionQueriesClient';

export default function TransactionQueriesPage() {
    return (
        <Suspense fallback={null}>
            <TransactionQueriesClient />
        </Suspense>
    );
}
