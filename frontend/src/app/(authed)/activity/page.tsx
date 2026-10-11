'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { AuditLogItem } from '@/lib/types';
import {
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  StatusBadge,
} from '@/components/ui';

// Only target types that existing audit writers actually record.
const TARGET_TYPES: Array<{ label: string; value: string }> = [
  { label: 'All targets', value: '' },
  { label: 'Operation', value: 'operation' },
  { label: 'Automation rule', value: 'automation_rule' },
  { label: 'Automation run', value: 'automation_run' },
  { label: 'Connector capability', value: 'connector_capability' },
  { label: 'Connector credential', value: 'connector_credential' },
];

const PER_PAGE = 25;

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export default function ActivityPage() {
  const router = useRouter();
  const [items, setItems] = useState<AuditLogItem[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: PER_PAGE,
  });
  const [targetType, setTargetType] = useState('');
  const [action, setAction] = useState('');
  const [appliedAction, setAppliedAction] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchActivity = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.getAuditLogs({
        target_type: targetType || undefined,
        action: appliedAction || undefined,
        per_page: PER_PAGE,
        page,
      });
      const list = (res.data ?? []) as AuditLogItem[];
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
      setError(apiError.message || 'Failed to load activity');
    } finally {
      setLoading(false);
    }
  }, [targetType, appliedAction, page, router]);

  useEffect(() => {
    void (async () => {
      await fetchActivity();
    })();
  }, [fetchActivity]);

  const onTargetChange = (value: string) => {
    setTargetType(value);
    setPage(1);
  };

  const applyActionFilter = () => {
    setAppliedAction(action.trim());
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title="Activity"
        subtitle="Organization audit trail of recorded platform events."
        action={
          <button
            type="button"
            onClick={() => void fetchActivity()}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
          >
            Refresh
          </button>
        }
      />
      <div className="mb-6 flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor="activity-target" className="block text-sm font-medium text-gray-700">
            Target type
          </label>
          <select
            id="activity-target"
            value={targetType}
            onChange={(e) => onTargetChange(e.target.value)}
            className="mt-1 block w-full sm:w-56 rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            {TARGET_TYPES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="activity-action" className="block text-sm font-medium text-gray-700">
            Action
          </label>
          <input
            id="activity-action"
            type="text"
            value={action}
            onChange={(e) => setAction(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') applyActionFilter();
            }}
            placeholder="e.g. operation_requested"
            className="mt-1 block w-full sm:w-64 rounded-md border border-gray-300 px-3 py-2 text-sm"
          />
        </div>

        <button
          type="button"
          onClick={applyActionFilter}
          className="px-4 py-2 rounded-md border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
        >
          Apply
        </button>

        {appliedAction ? (
          <button
            type="button"
            onClick={() => {
              setAction('');
              setAppliedAction('');
              setPage(1);
            }}
            className="px-4 py-2 rounded-md text-sm text-blue-600 hover:underline"
          >
            Clear
          </button>
        ) : null}
      </div>

      {loading ? (
        <LoadingState message="Loading activity..." />
      ) : error ? (
        <ErrorState message={error} onRetry={() => void fetchActivity()} />
      ) : items.length === 0 ? (
        <EmptyState message="No recorded activity matches these filters." />
      ) : (
        <>
          <p className="mb-4 text-sm text-gray-500">{meta.total} recorded events</p>

          <ul className="space-y-4">
            {items.map((entry) => (
              <li key={entry.id}>
                <Card className="mb-0">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-gray-900 break-all">{entry.action}</p>
                      <p className="text-sm text-gray-600">
                        {entry.user?.name ?? 'System'} · {entry.site?.name ?? 'Organization-wide'}
                      </p>
                    </div>
                    <span className="text-xs text-gray-500 whitespace-nowrap">
                      {new Date(entry.created_at).toLocaleString()}
                    </span>
                  </div>

                  <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Target</dt>
                      <dd className="text-gray-900">{entry.target_type}</dd>
                    </div>
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Target ID</dt>
                      <dd className="text-gray-900 break-all">{entry.target_id}</dd>
                    </div>
                    <div className="flex justify-between sm:block">
                      <dt className="text-gray-500">Correlation ID</dt>
                      <dd className="text-gray-900 break-all">{entry.correlation_id}</dd>
                    </div>
                    <div className="flex justify-between items-center sm:block">
                      <dt className="text-gray-500">Policy result</dt>
                      <dd>
                        {entry.policy_result ? (
                          <StatusBadge value={entry.policy_result} />
                        ) : (
                          <span className="text-gray-900">—</span>
                        )}
                      </dd>
                    </div>
                  </dl>
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
