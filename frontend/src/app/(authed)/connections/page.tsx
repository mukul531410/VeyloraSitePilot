'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { SiteConnection, SiteConnectionMeta } from '@/lib/types';
import {
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  StatusBadge,
} from '@/components/ui';

/**
 * Connection states written by the backend (`SiteConnection::$status`):
 * pending, active, revoked. Revoked is red and never labelled "connected" -
 * revocation destroys the connector credentials. "inactive" is a *site* state
 * (`sites.status`), not a connection state, so it is surfaced separately on the
 * site summary row.
 */
const CONNECTION_TONES: Record<string, 'amber' | 'green' | 'red' | 'gray'> = {
  pending: 'amber',
  active: 'green',
  revoked: 'red',
};

const CONNECTION_HINTS: Record<string, string> = {
  pending: 'Registered but not yet claimed by the connector. No WordPress traffic is accepted.',
  active: 'The connector authenticated and may report inventory and heartbeats.',
  revoked:
    'Credentials were destroyed. This site is NOT connected and cannot reconnect until a new registration exists.',
};

const SITES_PER_PAGE = 25;

/**
 * Explicit tones for `sites.status`. The shared StatusBadge heuristic would
 * otherwise render "inactive" as green because the string contains "active".
 */
const SITE_TONES: Record<string, 'green' | 'amber' | 'gray'> = {
  active: 'green',
  inactive: 'amber',
  deprovisioned: 'gray',
};

interface SitesMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

interface SiteRow {
  id: string;
  name: string;
  url: string;
  environment: string;
  status: string;
  connection_status: string | null;
}

function formatTimestamp(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—';
}

export default function ConnectionsPage() {
  const router = useRouter();

  const [sites, setSites] = useState<SiteRow[]>([]);
  const [sitesMeta, setSitesMeta] = useState<SitesMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: SITES_PER_PAGE,
  });
  const [sitePage, setSitePage] = useState(1);
  const [sitesLoading, setSitesLoading] = useState(true);
  const [sitesError, setSitesError] = useState<string | null>(null);

  const [selectedSiteId, setSelectedSiteId] = useState('');
  const [connections, setConnections] = useState<SiteConnection[]>([]);
  const [connectionsLoading, setConnectionsLoading] = useState(false);
  const [connectionsError, setConnectionsError] = useState<string | null>(null);

  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<SiteConnectionMeta | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);

  const [revokingId, setRevokingId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  /** Drop the dead session token and return to login. Used by every 401 path. */
  const expireSession = useCallback(() => {
    if (typeof window !== 'undefined') {
      localStorage.removeItem('veylora_token');
    }
    router.replace('/login');
  }, [router]);

  const fetchSites = useCallback(async () => {
    setSitesLoading(true);
    setSitesError(null);
    try {
      const res = await api.getSites({ page: sitePage });
      const list = (res.data ?? []) as SiteRow[];
      setSites(list);
      const m = (res.meta ?? {}) as Partial<SitesMeta>;
      setSitesMeta({
        current_page: m.current_page ?? sitePage,
        last_page: m.last_page ?? 1,
        total: m.total ?? list.length,
        per_page: m.per_page ?? SITES_PER_PAGE,
      });
    } catch (err) {
      const apiError = err as ApiError;
      if (apiError.statusCode === 401) {
        expireSession();
        return;
      }
      setSitesError(apiError.message || 'Failed to load sites');
    } finally {
      setSitesLoading(false);
    }
  }, [sitePage, expireSession]);

  const fetchConnections = useCallback(
    async (siteId: string) => {
      if (!siteId) return;
      setConnectionsLoading(true);
      setConnectionsError(null);
      try {
        const res = await api.listConnections(siteId);
        setConnections((res.data ?? []) as SiteConnection[]);
      } catch (err) {
        const apiError = err as ApiError;
        if (apiError.statusCode === 401) {
          expireSession();
          return;
        }
        setConnections([]);
        setConnectionsError(apiError.message || 'Failed to load connections');
      } finally {
        setConnectionsLoading(false);
      }
    },
    [expireSession],
  );
  useEffect(() => {
    void (async () => {
      await fetchSites();
    })();
  }, [fetchSites]);

  const selectSite = (value: string) => {
    setSelectedSiteId(value);
    setExpandedId(null);
    setDetail(null);
    setDetailError(null);
    setActionError(null);
    if (value) {
      void fetchConnections(value);
    } else {
      setConnections([]);
      setConnectionsError(null);
    }
  };

  const selectedSite = sites.find((site) => site.id === selectedSiteId) ?? null;

  const refresh = useCallback(() => {
    void fetchSites();
    if (selectedSiteId) {
      void fetchConnections(selectedSiteId);
    }
  }, [fetchSites, fetchConnections, selectedSiteId]);

  const loadDetail = useCallback(
    async (connectionId: string) => {
      if (!selectedSiteId) return;
      setDetailLoading(true);
      setDetailError(null);
      try {
        const res = await api.getConnection(selectedSiteId, connectionId);
        setDetail((res.meta ?? null) as SiteConnectionMeta | null);
      } catch (err) {
        const apiError = err as ApiError;
        if (apiError.statusCode === 401) {
          expireSession();
          return;
        }
        setDetail(null);
        setDetailError(apiError.message || 'Failed to load connection details');
      } finally {
        setDetailLoading(false);
      }
    },
    [selectedSiteId, expireSession],
  );

  const onToggleDetail = (connectionId: string) => {
    if (expandedId === connectionId) {
      setExpandedId(null);
      setDetail(null);
      setDetailError(null);
      return;
    }
    setExpandedId(connectionId);
    setDetail(null);
    setDetailError(null);
    void loadDetail(connectionId);
  };

  const onRevoke = async (connection: SiteConnection) => {
    if (!selectedSiteId) return;
    const confirmed = window.confirm(
      'Revoke this connection? The connector will no longer authenticate against SitePilot. ' +
        'A new registration is required to reconnect.',
    );
    if (!confirmed) return;

    setRevokingId(connection.id);
    setActionError(null);
    try {
      await api.revokeConnection(selectedSiteId, connection.id);
      setExpandedId(null);
      setDetail(null);
      await fetchConnections(selectedSiteId);
    } catch (err) {
      const apiError = err as ApiError;
      if (apiError.statusCode === 401) {
        expireSession();
        return;
      }
      setActionError(apiError.message || 'Failed to revoke connection');
    } finally {
      setRevokingId(null);
    }
  };

  const renderDetail = () => {
    if (detailLoading) {
      return <LoadingState message="Loading connection details..." />;
    }
    if (detailError) {
      return (
        <ErrorState
          message={detailError}
          onRetry={() => {
            if (expandedId) void loadDetail(expandedId);
          }}
        />
      );
    }
    if (!detail) {
      return (
        <p className="text-sm text-gray-500">No detail metadata returned for this registration.</p>
      );
    }

    return (
      <>
        <h3 className="text-sm font-semibold text-gray-900 mb-2">Capabilities</h3>
        {detail.capabilities.length === 0 ? (
          <p className="text-sm text-gray-500">
            The connector has not reported any capabilities yet.
          </p>
        ) : (
          <ul className="space-y-1">
            {detail.capabilities.map((cap) => (
              <li key={cap.capability_key} className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-mono text-gray-900">{cap.capability_key}</span>
                <StatusBadge
                  value={cap.effective ? 'effective' : 'not effective'}
                  tone={cap.effective ? 'green' : 'gray'}
                />
                <span className="text-xs text-gray-500">
                  enabled: {cap.enabled ? 'yes' : 'no'} · reported supported:{' '}
                  {cap.reported_supported ? 'yes' : 'no'} · reported{' '}
                  {formatTimestamp(cap.reported_at)}
                </span>
              </li>
            ))}
          </ul>
        )}

        <h3 className="mt-4 text-sm font-semibold text-gray-900 mb-2">Latest heartbeat</h3>
        {detail.latest_heartbeat ? (
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <div className="flex justify-between sm:block">
              <dt className="text-gray-500">Status</dt>
              <dd>
                <StatusBadge value={detail.latest_heartbeat.status} />
              </dd>
            </div>
            <div className="flex justify-between sm:block">
              <dt className="text-gray-500">Connector</dt>
              <dd className="text-gray-900">{detail.latest_heartbeat.connector_version}</dd>
            </div>
            <div className="flex justify-between sm:block">
              <dt className="text-gray-500">WordPress</dt>
              <dd className="text-gray-900">{detail.latest_heartbeat.wordpress_version ?? '—'}</dd>
            </div>
            <div className="flex justify-between sm:block">
              <dt className="text-gray-500">PHP</dt>
              <dd className="text-gray-900">{detail.latest_heartbeat.php_version ?? '—'}</dd>
            </div>
            <div className="flex justify-between sm:block">
              <dt className="text-gray-500">Reported at</dt>
              <dd className="text-gray-900">{formatTimestamp(detail.latest_heartbeat.reported_at)}</dd>
            </div>
          </dl>
        ) : (
          <p className="text-sm text-gray-500">
            No heartbeat has been received on this registration.
          </p>
        )}
      </>
    );
  };

  const renderConnection = (connection: SiteConnection) => {
    const tone = CONNECTION_TONES[connection.status] ?? 'gray';
    const isRevoked = connection.status === 'revoked';
    const isActive = connection.status === 'active' && connection.revoked_at === null;
    const isExpanded = expandedId === connection.id;

    return (
      <>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <StatusBadge value={connection.status} tone={tone} />
              <span className="text-sm font-medium text-gray-900">
                {isActive
                  ? 'Connected'
                  : isRevoked
                    ? 'Not connected — credentials revoked'
                    : 'Awaiting connector'}
              </span>
            </div>
            <p className="mt-1 text-xs text-gray-500">
              {CONNECTION_HINTS[connection.status] ??
                'Unrecognised connection state reported by the server.'}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => onToggleDetail(connection.id)}
              className="px-3 py-1.5 rounded-md border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
            >
              {isExpanded ? 'Hide details' : 'Details'}
            </button>
            {!isRevoked ? (
              <button
                type="button"
                onClick={() => void onRevoke(connection)}
                disabled={revokingId === connection.id}
                className="px-3 py-1.5 rounded-md border border-red-300 text-sm text-red-700 hover:bg-red-50 disabled:opacity-50"
              >
                {revokingId === connection.id ? 'Revoking...' : 'Revoke'}
              </button>
            ) : null}
          </div>
        </div>
        <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Connection ID</dt>
            <dd className="text-gray-900 break-all">{connection.id}</dd>
          </div>
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Connector version</dt>
            <dd className="text-gray-900">{connection.connector_version ?? '—'}</dd>
          </div>
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Connected at</dt>
            <dd className="text-gray-900">{formatTimestamp(connection.connected_at)}</dd>
          </div>
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Last seen at</dt>
            <dd className="text-gray-900">{formatTimestamp(connection.last_seen_at)}</dd>
          </div>
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Revoked at</dt>
            <dd className="text-gray-900">{formatTimestamp(connection.revoked_at)}</dd>
          </div>
          <div className="flex justify-between sm:block">
            <dt className="text-gray-500">Registered at</dt>
            <dd className="text-gray-900">{formatTimestamp(connection.created_at)}</dd>
          </div>
        </dl>

        {isExpanded ? <div className="mt-4 border-t pt-4">{renderDetail()}</div> : null}
      </>
    );
  };

  return (
    <div>
      <PageHeader
        title="Connections"
        subtitle="WordPress connector registrations: pending, active, and revoked."
        action={
          <button
            type="button"
            onClick={refresh}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
          >
            Refresh
          </button>
        }
      />

      <Card title="Site" className="mb-6">
        {sitesLoading ? (
          <LoadingState message="Loading sites..." />
        ) : sitesError ? (
          <ErrorState message={sitesError} onRetry={() => void fetchSites()} />
        ) : sites.length === 0 ? (
          <EmptyState
            message="No sites found. Connections are registered per site."
            actionLabel="Create your first site"
            actionHref="/sites/new"
          />
        ) : (
          <>
            <label htmlFor="connections-site" className="block text-sm font-medium text-gray-700">
              Choose a site
            </label>
            <select
              id="connections-site"
              value={selectedSiteId}
              onChange={(e) => selectSite(e.target.value)}
              className="mt-1 block w-full sm:max-w-md rounded-md border border-gray-300 px-3 py-2 text-sm"
            >
              <option value="">Select a site...</option>
              {sites.map((site) => (
                <option key={site.id} value={site.id}>
                  {site.name} ({site.environment}) — {site.connection_status ?? 'no connection'}
                </option>
              ))}
            </select>

            {sitesMeta.last_page > 1 ? (
              <nav className="mt-4 flex items-center justify-between" aria-label="Site pages">
                <button
                  type="button"
                  onClick={() => setSitePage((p) => Math.max(1, p - 1))}
                  disabled={sitesMeta.current_page <= 1}
                  className="px-3 py-2 rounded-md border border-gray-300 text-sm text-gray-700 disabled:opacity-50"
                >
                  Previous sites
                </button>
                <span className="text-sm text-gray-500">
                  Sites page {sitesMeta.current_page} of {sitesMeta.last_page} ·{' '}
                  {sitesMeta.total} total
                </span>
                <button
                  type="button"
                  onClick={() => setSitePage((p) => Math.min(sitesMeta.last_page, p + 1))}
                  disabled={sitesMeta.current_page >= sitesMeta.last_page}
                  className="px-3 py-2 rounded-md border border-gray-300 text-sm text-gray-700 disabled:opacity-50"
                >
                  Next sites
                </button>
              </nav>
            ) : null}
            {selectedSite ? (
              <div className="mt-4 border-t pt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div className="flex justify-between sm:block">
                  <dt className="text-gray-500">Site</dt>
                  <dd className="text-gray-900">{selectedSite.name}</dd>
                </div>
                <div className="flex justify-between sm:block">
                  <dt className="text-gray-500">URL</dt>
                  <dd className="text-gray-900 break-all">{selectedSite.url}</dd>
                </div>
                <div className="flex justify-between items-center sm:block">
                  <dt className="text-gray-500">Site status</dt>
                  <dd>
                    <StatusBadge
                      value={selectedSite.status}
                      tone={SITE_TONES[selectedSite.status] ?? 'gray'}
                    />
                  </dd>
                </div>
                <div className="flex justify-between items-center sm:block">
                  <dt className="text-gray-500">Latest connection state</dt>
                  <dd>
                    {selectedSite.connection_status ? (
                      <StatusBadge
                        value={selectedSite.connection_status}
                        tone={CONNECTION_TONES[selectedSite.connection_status] ?? 'gray'}
                      />
                    ) : (
                      <span className="text-gray-900">No registration</span>
                    )}
                  </dd>
                </div>
                {selectedSite.status !== 'active' ? (
                  <p className="sm:col-span-2 text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                    This site is <strong>{selectedSite.status}</strong>. An active connector on a
                    non-active site cannot drive maintenance operations.
                  </p>
                ) : null}
              </div>
            ) : (
              <p className="mt-4 text-sm text-gray-500">
                Select a site to see its connector registrations.
              </p>
            )}
          </>
        )}
      </Card>
      <Card title={selectedSite ? `Registrations for ${selectedSite.name}` : 'Registrations'}>
        {!selectedSite ? (
          <EmptyState message="Choose a site above to list its connector registrations." />
        ) : connectionsLoading ? (
          <LoadingState message="Loading connections..." />
        ) : connectionsError ? (
          <ErrorState
            message={connectionsError}
            onRetry={() => {
              if (selectedSiteId) void fetchConnections(selectedSiteId);
            }}
          />
        ) : connections.length === 0 ? (
          <EmptyState message="No connector registration exists for this site yet." />
        ) : (
          <>
            {actionError ? (
              <div
                role="alert"
                className="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"
              >
                {actionError}
              </div>
            ) : null}

            <p className="mb-4 text-sm text-gray-500">
              {connections.length} registration{connections.length === 1 ? '' : 's'} · newest
              first
            </p>

            <ul className="space-y-4">
              {connections.map((connection) => (
                <li key={connection.id} className="border border-gray-200 rounded-md p-4">
                  {renderConnection(connection)}
                </li>
              ))}
            </ul>
          </>
        )}
      </Card>

      <p className="mt-6 text-xs text-gray-500">
        Credential material, HMAC tokens, and one-time registration intents are never returned by
        this API and are not displayed here. A revoked registration cannot be restored; creating
        a replacement registration is performed outside this screen.
      </p>
    </div>
  );
}

