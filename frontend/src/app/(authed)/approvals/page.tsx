'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { api, ApiError } from '@/lib/api';
import { ApprovalItem } from '@/lib/types';
import {
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  StatusBadge,
} from '@/components/ui';

const STATUS_TONES: Record<ApprovalItem['status'], 'amber' | 'green' | 'red' | 'gray'> = {
  pending: 'amber',
  approved: 'green',
  rejected: 'red',
  expired: 'gray',
};

const FILTERS: Array<{ label: string; value: string }> = [
  { label: 'All', value: '' },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Expired', value: 'expired' },
];

const PER_PAGE = 25;

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export default function ApprovalsPage() {
  const router = useRouter();
  const [items, setItems] = useState<ApprovalItem[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: PER_PAGE,
  });
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchApprovals = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.getApprovals({
        status: status || undefined,
        per_page: PER_PAGE,
        page,
      });
      const list = (res.data ?? []) as ApprovalItem[];
      setItems(list);
      const m = (res.meta ?? {}) as Partial<PaginationMeta>;
      setMeta({
        current_page: m.current_page ?? 1,
        last_page: m.last_page ?? 1,
        total: m.total ?? list.length,
        per_page: m.per_page ?? PER_PAGE,
      });
    } catch (err) {
      const apiError = err as ApiError;
      if (apiError.statusCode === 401) {
        if (typeof window !== 'undefined') {
          localStorage.removeItem('veylora_token');
        }
        router.replace('/login');
        return;
      }
      setError(apiError.message || 'Failed to load approvals');
    } finally {
      setLoading(false);
    }
  }, [status, page, router]);

  useEffect(() => {
    void (async () => {
      await fetchApprovals();
    })();
  }, [fetchApprovals]);

  const onFilterChange = (value: string) => {
    setStatus(value);
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title="Approvals"
        subtitle="Review maintenance operations awaiting a decision."
        action={
          <button
            type="button"
            onClick={() => void fetchApprovals()}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
          >
            Refresh
          </button>
        }
      />

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <label htmlFor="approval-status" className="text-sm font-medium text-gray-700">
          Status
        </label>
        <select
          id="approval-status"
          value={status}
          onChange={(e) => onFilterChange(e.target.value)}
          className="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-blue-500"
        >
          {FILTERS.map((f) => (
            <option key={f.value} value={f.value}>
              {f.label}
            </option>
          ))}
        </select>
        {!loading && !error ? (
          <span className="text-sm text-gray-500">
            {meta.total} approval{meta.total === 1 ? '' : 's'}
          </span>
        ) : null}
      </div>

      {loading ? (
        <LoadingState message="Loading approvals..." />
      ) : error ? (
        <Card>
          <ErrorState message={error} onRetry={() => void fetchApprovals()} />
        </Card>
      ) : items.length === 0 ? (
        <EmptyState message="No approvals found." />
      ) : (
        <>
          <ul className="space-y-4">
            {items.map((approval) => (
              <li key={approval.id}>
                <Card>
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-gray-900">
                        {approval.site?.name ?? 'Site'}
                      </p>
                      <p className="text-sm text-gray-600">
                        {approval.operation?.operation_type ?? 'Operation'}
                      </p>
                    </div>
                    <StatusBadge value={approval.status} tone={STATUS_TONES[approval.status]} />
                  </div>

                  <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Requested</dt>
                      <dd className="text-gray-900">{new Date(approval.created_at).toLocaleString()}</dd>
                    </div>
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Expires</dt>
                      <dd className="text-gray-900">{new Date(approval.expires_at).toLocaleString()}</dd>
                    </div>
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Operation status</dt>
                      <dd className="text-gray-900">{approval.operation?.status ?? '—'}</dd>
                    </div>
                    {approval.reviewed_at ? (
                      <div className="flex justify-between sm:block">
                        <dt className="text-gray-500">Reviewed</dt>
                        <dd className="text-gray-900">
                          {new Date(approval.reviewed_at).toLocaleString()}
                        </dd>
                      </div>
                    ) : null}
                  </dl>

                  {approval.reason ? (
                    <p className="mt-3 text-sm text-gray-600">{approval.reason}</p>
                  ) : null}

                  <div className="mt-4">
                    <Link
                      href={`/sites/${approval.site_id}`}
                      className="text-sm text-blue-600 hover:underline"
                    >
                      View site
                    </Link>
                  </div>
                </Card>
              </li>
            ))}
          </ul>


          {meta.last_page > 1 ? (
            <nav className="mt-6 flex items-center justify-between" aria-label="Pagination">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={meta.current_page <= 1}
                className="px-3 py-2 rounded-md border border-gray-300 text-sm text-gray-700 disabled:opacity-50"
              >
                Previous
              </button>
              <span className="text-sm text-gray-500">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <button
                type="button"
                onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                disabled={meta.current_page >= meta.last_page}
                className="px-3 py-2 rounded-md border border-gray-300 text-sm text-gray-700 disabled:opacity-50"
              >
                Next
              </button>
            </nav>
          ) : null}
        </>
      )}
    </div>
  );
}

