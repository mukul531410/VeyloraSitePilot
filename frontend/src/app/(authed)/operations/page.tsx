'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { CANCELLABLE_STATUSES, OperationItem, OperationStatus, SUPPORTED_OPERATION_TYPES } from '@/lib/types';
import {
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  StatusBadge,
} from '@/components/ui';

/**
 * Phase 5 — Maintenance Operations.
 *
 * Scope is deliberately limited to what the backend genuinely implements
 * end-to-end: `action.cache_clear` plus its verification lifecycle. Plugin,
 * theme and core operations are rejected by `StoreOperationRequest`, and no
 * backup operation type exists at all, so neither is offered here.
 *
 * This page never authorizes anything. Policy evaluation, permission and
 * capability checks, approval gating, tenant isolation and verification all
 * happen server-side; every denial is surfaced exactly as the API returns it.
 */
const STATUS_TONES: Record<string, 'green' | 'red' | 'blue' | 'gray' | 'amber'> = {
  requested: 'blue',
  pending_approval: 'amber',
  approved: 'blue',
  queued: 'blue',
  running: 'blue',
  verification_pending: 'amber',
  succeeded: 'green',
  failed: 'red',
  unknown: 'amber',
  cancelled: 'gray',
  dead_letter: 'red',
};

const STATUS_HINTS: Record<string, string> = {
  requested: 'Recorded. The server policy evaluation decides whether it queues or waits for approval.',
  pending_approval: 'Awaiting a review decision. Approve or reject it on the Approvals page.',
  approved: 'Approved and waiting to be dispatched to the connector.',
  queued: 'Queued for dispatch. No connector has claimed the job yet.',
  running: 'A connector has claimed the job and is executing it. Remote work may already be in flight.',
  verification_pending:
    'The connector reported a result. That claim is not proof — only authoritative remote verification can succeed this operation.',
  succeeded: 'Succeeded only after authoritative connector state verified the outcome.',
  failed: 'The operation or its verification failed.',
  unknown:
    'Execution outcome is uncertain. It is never retried automatically and must be resolved by an organization owner or admin.',
  cancelled: 'Cancelled before any remote work started.',
  dead_letter: 'Retries were exhausted. The operation is parked for manual recovery.',
};

const STATUS_FILTERS: Array<{ label: string; value: string }> = [
  { label: 'All', value: '' },
  { label: 'Requested', value: 'requested' },
  { label: 'Pending approval', value: 'pending_approval' },
  { label: 'Approved', value: 'approved' },
  { label: 'Queued', value: 'queued' },
  { label: 'Running', value: 'running' },
  { label: 'Verification pending', value: 'verification_pending' },
  { label: 'Succeeded', value: 'succeeded' },
  { label: 'Failed', value: 'failed' },
  { label: 'Unknown', value: 'unknown' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'Dead letter', value: 'dead_letter' },
];

const PER_PAGE = 25;

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

interface SiteRow {
  id: string;
  name: string;
  url: string;
  status: string;
  connection_status: string | null;
}

function formatTimestamp(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—';
}

function isCancellable(status: OperationStatus): boolean {
  return CANCELLABLE_STATUSES.includes(status);
}

export default function OperationsPage() {
  const router = useRouter();

  const [items, setItems] = useState<OperationItem[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: PER_PAGE,
  });
  const [status, setStatus] = useState('');
  const [siteId, setSiteId] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [sites, setSites] = useState<SiteRow[]>([]);
  const [sitesLoading, setSitesLoading] = useState(true);
  const [sitesError, setSitesError] = useState<string | null>(null);

  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<OperationItem | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);

  const [requestSiteId, setRequestSiteId] = useState('');
  const [requesting, setRequesting] = useState(false);
  const [requestError, setRequestError] = useState<string | null>(null);
  const [requestNotice, setRequestNotice] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState('');

  const [cancellingId, setCancellingId] = useState<string | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  /** Drop the dead session token and return to login. Used by every 401 path. */
  const expireSession = useCallback(() => {
    if (typeof window !== 'undefined') {
      localStorage.removeItem('veylora_token');
    }
    router.replace('/login');
  }, [router]);

  const handleApiError = useCallback(
    (err: unknown): string => {
      const apiError = err as ApiError;
      if (apiError.statusCode === 401) {
        expireSession();
        return 'Session expired';
      }
      return apiError.message || 'Request failed';
    },
    [expireSession],
  );

  const fetchOperations = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.getOperations({
        site_id: siteId || undefined,
        status: status || undefined,
        per_page: PER_PAGE,
        page,
      });
      const list = (res.data ?? []) as OperationItem[];
      setItems(list);
      const m = (res.meta ?? {}) as Partial<PaginationMeta>;
      setMeta({
        current_page: m.current_page ?? 1,
        last_page: m.last_page ?? 1,
        total: m.total ?? list.length,
        per_page: m.per_page ?? PER_PAGE,
      });
    } catch (err) {
      const message = handleApiError(err);
      if ((err as ApiError).statusCode === 401) return;
      setItems([]);
      setError(message);
    } finally {
      setLoading(false);
    }
  }, [siteId, status, page, handleApiError]);

  const fetchSites = useCallback(async () => {
    setSitesLoading(true);
    setSitesError(null);
    try {
      const res = await api.getSites();
      setSites((res.data ?? []) as SiteRow[]);
    } catch (err) {
      const message = handleApiError(err);
      if ((err as ApiError).statusCode === 401) return;
      setSitesError(message);
    } finally {
      setSitesLoading(false);
    }
  }, [handleApiError]);

  useEffect(() => {
    void (async () => {
      await fetchOperations();
    })();
  }, [fetchOperations]);

  useEffect(() => {
    void (async () => {
      await fetchSites();
    })();
  }, [fetchSites]);

  const onFilterChange = (nextStatus: string, nextSite: string) => {
    setStatus(nextStatus);
    setSiteId(nextSite);
    setPage(1);
    setExpandedId(null);
    setDetail(null);
    setDetailError(null);
  };

  const siteNameById = useMemo(() => {
    const map = new Map<string, string>();
    sites.forEach((site) => map.set(site.id, site.name));
    return map;
  }, [sites]);

  const loadDetail = useCallback(
    async (operationId: string) => {
      setDetailLoading(true);
      setDetailError(null);
      try {
        const res = await api.getOperation(operationId);
        setDetail(res.data ?? null);
      } catch (err) {
        const message = handleApiError(err);
        if ((err as ApiError).statusCode === 401) return;
        setDetail(null);
        setDetailError(message);
      } finally {
        setDetailLoading(false);
      }
    },
    [handleApiError],
  );

  const onToggleDetail = (operationId: string) => {
    if (expandedId === operationId) {
      setExpandedId(null);
      setDetail(null);
      setDetailError(null);
      return;
    }
    setExpandedId(operationId);
    setDetail(null);
    setDetailError(null);
    void loadDetail(operationId);
  };

  /**
   * The frontend only *submits* a request. Whether it is queued or routed to
   * approval — and whether it is refused at all — is decided exclusively by
   * the server-side policy engine, permission checks and capability state.
   */
  const onRequestCacheClear = async () => {
    if (!requestSiteId) {
      setRequestError('Choose a site first.');
      return;
    }
    const key = idempotencyKey || `ui:${crypto.randomUUID()}`;
    setIdempotencyKey(key);
    setRequesting(true);
    setRequestError(null);
    setRequestNotice(null);
    try {
      const res = await api.createOperation(requestSiteId, {
        operation_type: SUPPORTED_OPERATION_TYPES[0],
        target_json: { cache_type: 'wordpress' },
        idempotency_key: key,
      });
      const created = res.data;
      setRequestNotice(
        created.status === 'pending_approval'
          ? `Submitted. The server required approval, so this operation is ${created.status} — review it on the Approvals page.`
          : `Submitted. The server accepted it as ${created.status}.`,
      );
      // A new key is required for the next, logically distinct, request.
      setIdempotencyKey('');
      setRequestSiteId('');
      await fetchOperations();
    } catch (err) {
      const apiError = err as ApiError;
      const message = handleApiError(err);
      if (apiError.statusCode === 401) return;
      setRequestError(
        apiError.code === 'policy_denied'
          ? `${message}. The server refused this operation; nothing was queued.`
          : message,
      );
    } finally {
      setRequesting(false);
    }
  };

  const onCancelOperation = async (operationId: string) => {
    const reason = cancelReason.trim();
    if (!reason) {
      setActionError('A non-empty reason is required to cancel.');
      return;
    }
    setCancellingId(operationId);
    setActionError(null);
    try {
      await api.cancelOperation(operationId, reason);
      setCancelReason('');
      setExpandedId(null);
      setDetail(null);
      await fetchOperations();
    } catch (err) {
      const apiError = err as ApiError;
      const message = handleApiError(err);
      if (apiError.statusCode === 401) return;
      setActionError(message);
    } finally {
      setCancellingId(null);
    }
  };
  const renderDetail = () => {
    if (expandedId === null) return null;

    return (
      <div className="border-t border-gray-200 bg-gray-50 px-4 py-4">
        {detailLoading ? <LoadingState message="Loading operation detail..." /> : null}

        {detailError ? <ErrorState message={detailError} onRetry={() => void loadDetail(expandedId)} /> : null}

        {!detailLoading && detail ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <h3 className="text-sm font-semibold text-gray-900 mb-2">Request</h3>
              <dl className="space-y-1 text-sm">
                <DetailRow label="Operation type" value={detail.operation_type} />
                <DetailRow label="Target" value={JSON.stringify(detail.target_json ?? {})} />
                <DetailRow label="Policy result" value={detail.policy_result ?? '—'} />
                <DetailRow
                  label="Approval required"
                  value={detail.approval_required ? 'Yes' : 'No'}
                />
                <DetailRow label="Idempotency key" value={detail.idempotency_key} />
                <DetailRow label="Requested by" value={String(detail.requested_by ?? '—')} />
                <DetailRow label="Created" value={formatTimestamp(detail.created_at)} mono={false} />
                <DetailRow label="Started" value={formatTimestamp(detail.started_at)} mono={false} />
                <DetailRow label="Finished" value={formatTimestamp(detail.finished_at)} mono={false} />
              </dl>
            </div>

            <div>
              <h3 className="text-sm font-semibold text-gray-900 mb-2">Connector attempt</h3>
              {detail.latest_attempt ? (
                <dl className="space-y-1 text-sm">
                  <DetailRow label="Attempt" value={`#${detail.latest_attempt.attempt_number}`} />
                  <DetailRow label="Attempt status" value={detail.latest_attempt.status} />
                  <DetailRow
                    label="Connector job"
                    value={detail.latest_attempt.connector_job_id ?? '—'}
                  />
                  <DetailRow
                    label="Retryable"
                    value={
                      detail.latest_attempt.retryable === null
                        ? '—'
                        : detail.latest_attempt.retryable
                          ? 'Yes'
                          : 'No'
                    }
                  />
                  <DetailRow
                    label="Timeout at"
                    value={formatTimestamp(detail.latest_attempt.timeout_at)}
                    mono={false}
                  />
                  <DetailRow
                    label="Attempt error"
                    value={detail.latest_attempt.error_code ?? '—'}
                  />
                </dl>
              ) : (
                <p className="text-sm text-gray-500">No attempt has been dispatched yet.</p>
              )}

              <h3 className="text-sm font-semibold text-gray-900 mt-4 mb-2">Reported result</h3>
              {detail.result ? (
                <dl className="space-y-1 text-sm">
                  <DetailRow label="Connector claim" value={detail.result.result_status ?? '—'} />
                  <DetailRow
                    label="Cache cleared at"
                    value={formatTimestamp(detail.result.cache_cleared_at)}
                    mono={false}
                  />
                  <DetailRow
                    label="Cleared types"
                    value={
                      detail.result.cleared_types && detail.result.cleared_types.length
                        ? detail.result.cleared_types.join(', ')
                        : '—'
                    }
                  />
                  <DetailRow
                    label="Cache generation"
                    value={detail.result.cache_generation ?? '—'}
                  />
                  <DetailRow label="Error code" value={detail.result.error_code ?? '—'} />
                </dl>
              ) : (
                <p className="text-sm text-gray-500">
                  No connector result has been submitted. This is not evidence of failure.
                </p>
              )}
              <h3 className="text-sm font-semibold text-gray-900 mt-4 mb-2">Verification</h3>
              {detail.result ? (
                <dl className="space-y-1 text-sm">
                  <DetailRow
                    label="Verification status"
                    value={detail.result.verification_status ?? '—'}
                  />
                  <DetailRow
                    label="Verified at"
                    value={formatTimestamp(detail.result.verified_at)}
                    mono={false}
                  />
                  <DetailRow
                    label="Verification error"
                    value={detail.result.verification_error ?? '—'}
                    mono={false}
                  />
                </dl>
              ) : (
                <p className="text-sm text-gray-500">Not applicable until a result is submitted.</p>
              )}
              <p className="mt-2 text-xs text-gray-500">
                A connector result is only a claim. This operation reaches
                <span className="font-medium text-gray-700"> succeeded </span>
                solely through authoritative remote verification.
              </p>
            </div>

            {(detail.resolution || detail.status === 'unknown' || detail.status === 'dead_letter') && (
              <div className="md:col-span-2">
                <h3 className="text-sm font-semibold text-gray-900 mb-2">Operator resolution</h3>
                <dl className="space-y-1 text-sm">
                  <DetailRow label="Resolution" value={detail.resolution ?? 'Not resolved'} />
                  <DetailRow label="Resolved by" value={String(detail.resolved_by ?? '—')} />
                  <DetailRow
                    label="Resolved at"
                    value={formatTimestamp(detail.resolved_at)}
                    mono={false}
                  />
                  <DetailRow
                    label="Resolution reason"
                    value={detail.resolution_reason ?? '—'}
                    mono={false}
                  />
                </dl>
              </div>
            )}
          </div>
        ) : null}
      </div>
    );
  };

  const DetailRow = ({
    label,
    value,
    mono = true,
  }: {
    label: string;
    value: string | number;
    mono?: boolean;
  }) => (
    <div className="flex gap-2 text-xs">
      <dt className="min-w-[110px] text-gray-500">{label}</dt>
      <dd className={`${mono ? 'font-mono text-gray-900' : 'text-gray-900'}`}>{value}</dd>
    </div>
  );

  const renderOperationRow = (operation: OperationItem) => {
    const detailOpen = expandedId === operation.id;
    const cancelAble = isCancellable(operation.status);

    return (
      <div
        key={operation.id}
        className="border-b border-gray-200 px-4 py-3 hover:bg-gray-50 last:border-b-0"
      >
        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            className="min-w-[180px] font-medium text-left"
            onClick={() => onToggleDetail(operation.id)}
          >
            <span className="block text-sm text-gray-900">{operation.id}</span>
            <span className="block text-xs text-gray-500">
              {operation.operation_type} · cache
            </span>
          </button>

          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge value={operation.status} tone={STATUS_TONES[operation.status]} />
            <span className="text-xs text-gray-500">
              {siteNameById.get(operation.site_id) ?? operation.site_id}
            </span>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-gray-600">
              Requested {formatTimestamp(operation.created_at)}
            </span>
            <span className="text-xs text-gray-400">
              {operation.policy_result === 'allowed'
                ? 'Policy allowed'
                : operation.policy_result ?? '—'}
            </span>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {operation.status === 'unknown' || operation.status === 'dead_letter' ? (
              <span className="text-xs text-gray-500">Requires manual resolution</span>
            ) : null}
            {operation.approval_required ? (
              <span className="text-xs text-amber-700">Approval required</span>
            ) : null}
            {operation.status === 'running' || operation.status === 'verification_pending' ? (
              <span className="text-xs text-gray-400">Not cancellable (in flight)</span>
            ) : null}
            {cancelAble ? (
              <button
                type="button"
                className="text-xs text-red-700 hover:underline"
                onClick={() => {
                  setCancelReason('');
                  setActionError(null);
                  setExpandedId(null);
                  setDetail(null);
                  setCancellingId(operation.id);
                }}
              >
                Cancel
              </button>
            ) : null}
          </div>
        </div>

        {detailOpen ? (
          <div className="ml-7 mb-2 border-l border-gray-200 pl-4">
            <button
              type="button"
              className="text-xs text-gray-500 hover:text-gray-700"
              onClick={() => onToggleDetail(operation.id)}
            >
              Hide detail ▴
            </button>
          </div>
        ) : null}
      </div>
    );
  };

  return (
    <div>
      <PageHeader
        title="Maintenance Operations"
        subtitle="Request and track authorized cache-clear operations. Policy, approval, and verification are all decided on the server."
        action={
          <button type="button" onClick={() => void fetchOperations()} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            Refresh
          </button>
        }
      />

      <Card title="Request a cache clear" className="mb-6">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="request-site" className="block text-sm font-medium text-gray-700 mb-1">Site</label>
            <select id="request-site" value={requestSiteId} onChange={(e) => setRequestSiteId(e.target.value)} disabled={sitesLoading} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <option value="">{sitesLoading ? 'Loading sites…' : 'Select a site…'}</option>
              {sites.map((site) => (<option key={site.id} value={site.id}>{site.name}</option>))}
            </select>
          </div>
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="idempotency-key" className="block text-sm font-medium text-gray-700 mb-1">Idempotency key <span className="font-normal text-gray-400">(optional)</span></label>
            <input id="idempotency-key" type="text" value={idempotencyKey} onChange={(e) => setIdempotencyKey(e.target.value)} placeholder="Auto-generated if blank" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
          </div>
          <button type="button" onClick={() => void onRequestCacheClear()} disabled={requesting || !requestSiteId} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
            {requesting ? 'Submitting…' : 'Request cache clear'}
          </button>
        </div>
        {requestError ? <p className="mt-3 text-sm text-red-600">{requestError}</p> : null}
        {requestNotice ? <p className="mt-3 text-sm text-gray-700">{requestNotice}</p> : null}
        <p className="mt-3 text-xs text-gray-500">Submitting does not authorize it. The server decides whether it queues, waits for approval, or is refused by policy.</p>
      </Card>

      <div className="mb-6 flex flex-wrap items-center gap-4">
        <div>
          <label htmlFor="filter-status" className="mr-2 text-sm font-medium text-gray-700">Status</label>
          <select id="filter-status" value={status} onChange={(e) => onFilterChange(e.target.value, siteId)} className="rounded-md border border-gray-300 px-3 py-2 text-sm">
            {STATUS_FILTERS.map((f) => (<option key={f.value || 'all'} value={f.value}>{f.label}</option>))}
          </select>
        </div>
        <div>
          <label htmlFor="filter-site" className="mr-2 text-sm font-medium text-gray-700">Site</label>
          <select id="filter-site" value={siteId} onChange={(e) => onFilterChange(status, e.target.value)} className="rounded-md border border-gray-300 px-3 py-2 text-sm">
            <option value="">All sites</option>
            {sites.map((site) => (<option key={site.id} value={site.id}>{site.name}</option>))}
          </select>
        </div>
        {sitesError ? <span className="text-xs text-red-600">Could not load sites: {sitesError}</span> : null}
      </div>

      {actionError ? (
        <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</div>
      ) : null}

      {cancellingId ? (
        <Card title="Cancel operation" className="mb-6">
          <p className="mb-3 text-sm text-gray-600">Cancelling is only permitted before any remote work starts. A non-empty reason is required.</p>
          <textarea value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} rows={2} placeholder="Reason for cancellation" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
          <div className="mt-3 flex gap-2">
            <button type="button" onClick={() => void onCancelOperation(cancellingId)} className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">Confirm cancel</button>
            <button type="button" onClick={() => { setCancellingId(null); setCancelReason(''); setActionError(null); }} className="px-4 py-2 border border-gray-300 text-gray-700 rounded-md text-sm">Keep operation</button>
          </div>
        </Card>
      ) : null}

      {loading ? (
        <LoadingState message="Loading operations..." />
      ) : error ? (
        <ErrorState message={error} onRetry={() => void fetchOperations()} />
      ) : items.length === 0 ? (
        <EmptyState
          message="No operations match these filters yet."
        />
      ) : (
        <Card className="p-0 overflow-hidden">
          <div className="divide-y divide-gray-200">
            {items.map((operation) => (
              <div key={operation.id}>
                {renderOperationRow(operation)}
                {expandedId === operation.id ? (
                  <div className="px-4 pb-4">
                    {STATUS_HINTS[operation.status] ? (
                      <p className="mb-2 text-xs text-gray-600">{STATUS_HINTS[operation.status]}</p>
                    ) : null}
                    {renderDetail()}
                  </div>
                ) : null}
              </div>
            ))}
          </div>
        </Card>
      )}

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
            Page {meta.current_page} of {meta.last_page} · {meta.total} total
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
    </div>
  );
}

