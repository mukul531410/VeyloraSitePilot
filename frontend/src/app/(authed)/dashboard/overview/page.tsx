'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import {
  OrganizationSummary,
  SiteHealth,
  Incident,
  NotificationItem,
} from '@/lib/types';
import {
  PageHeader,
  StatCard,
  Card,
  LoadingState,
  ErrorState,
  EmptyState,
  StatusBadge,
} from '@/components/ui';

interface OverviewSite {
  id: string;
  name: string;
  url: string;
  environment: string;
  status: string;
  connection_status: string | null;
  health: SiteHealth | null;
  healthError: string | null;
  incidents: Incident[];
}

interface OverviewData {
  organizations: OrganizationSummary[];
  sites: OverviewSite[];
  notifications: NotificationItem[];
  unreadCount: number;
  notificationsError: string | null;
}

export default function OverviewPage() {
  const { user } = useAuth();
  const router = useRouter();
  const [data, setData] = useState<OverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchOverview = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [orgRes, siteRes] = await Promise.all([
        api.getOrganizations(),
        api.getSites(),
      ]);

      const organizations = orgRes.data as OrganizationSummary[];
      const rawSites = siteRes.data as Array<{
        id: string;
        name: string;
        url: string;
        environment: string;
        status: string;
        connection_status: string | null;
      }>;

      // Notifications are ancillary to this page, so a failure here degrades
      // that panel instead of blanking the whole Overview.
      let notifications: NotificationItem[] = [];
      let unreadCount = 0;
      let notificationsError: string | null = null;
      try {
        const notifRes = await api.getUnreadNotifications({ per_page: 10 });
        notifications = (notifRes.data ?? []) as NotificationItem[];
        unreadCount =
          typeof notifRes.meta?.total === 'number'
            ? notifRes.meta.total
            : notifications.length;
      } catch (err) {
        const notifError = err as ApiError;
        if (notifError.statusCode === 401) throw notifError;
        notificationsError = notifError.message || 'Notifications unavailable';
      }

      const sites: OverviewSite[] = await Promise.all(
        rawSites.map(async (site) => {
          let health: SiteHealth | null = null;
          let healthError: string | null = null;
          let incidents: Incident[] = [];
          try {
            const healthRes = await api.getSiteHealth(site.id);
            health = healthRes.data;
            incidents = health?.open_incidents ?? [];
          } catch (err) {
            healthError = (err as ApiError).message || 'Health unavailable';
          }
          return {
            ...site,
            health,
            healthError,
            incidents,
          };
        }),
      );

      setData({
        organizations,
        sites,
        notifications,
        unreadCount,
        notificationsError,
      });
    } catch (err) {
      const apiError = err as ApiError;
      if (apiError.statusCode === 401) {
        // The stored session is no longer valid; drop it and re-authenticate.
        if (typeof window !== 'undefined') {
          localStorage.removeItem('veylora_token');
        }
        router.replace('/login');
        return;
      }
      setError(apiError.message || 'Failed to load overview');
    } finally {
      setLoading(false);
    }
  }, [router]);

  useEffect(() => {
    void (async () => {
      await fetchOverview();
    })();
  }, [fetchOverview]);

  if (loading) {
    return <LoadingState message="Loading overview..." />;
  }

  if (error) {
    return <ErrorState message={error} onRetry={() => void fetchOverview()} />;
  }

  if (!data) {
    return <EmptyState message="No overview data available." />;
  }

  const healthyCount = data.sites.filter(
    (site) => site.health?.health_state === 'healthy',
  ).length;
  const incidentCount = data.sites.reduce(
    (total, site) => total + site.incidents.length,
    0,
  );
  const connectedCount = data.sites.filter(
    (site) => site.connection_status === 'active',
  ).length;

  return (
    <div>
      <PageHeader
        title="Overview"
        subtitle={
          user ? `Welcome back, ${user.name}. Here is the current state of your estate.` : undefined
        }
        action={
          <button
            type="button"
            onClick={() => void fetchOverview()}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
          >
            Refresh
          </button>
        }
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard
          label="Sites"
          value={data.sites.length}
          hint={`${connectedCount} connected`}
          href="/sites"
        />
        <StatCard
          label="Healthy"
          value={healthyCount}
          hint="Passing all health checks"
        />
        <StatCard
          label="Open incidents"
          value={incidentCount}
          hint={incidentCount > 0 ? 'Needs attention' : 'All clear'}
        />
        <StatCard
          label="Organizations"
          value={data.organizations.length}
          href="/organizations"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-xl font-semibold text-gray-900">Site health</h2>
            <Link
              href="/sites"
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              View all sites
            </Link>
          </div>

          {data.sites.length === 0 ? (
            <EmptyState
              message="No sites yet. Add a site to start monitoring."
              actionLabel="Add your first site"
              actionHref="/sites/new"
            />
          ) : (
            <div className="space-y-3">
              {data.sites.map((site) => (
                <Link key={site.id} href={`/sites/${site.id}`}>
                  <div className="p-4 bg-white rounded-lg shadow cursor-pointer hover:bg-gray-50">
                    <div className="flex justify-between items-start gap-3">
                      <div className="min-w-0">
                        <h3 className="font-semibold text-gray-900 truncate">
                          {site.name}
                        </h3>
                        <p className="text-sm text-blue-600 truncate">
                          {site.url}
                        </p>
                      </div>
                      <div className="flex flex-col items-end gap-1">
                        <StatusBadge value={site.status} />
                        {site.health ? (
                          <StatusBadge value={site.health.health_state} />
                        ) : site.healthError ? (
                          <StatusBadge value="unknown" />
                        ) : null}
                      </div>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-2 text-xs">
                      <span className="px-2 py-1 bg-blue-100 text-blue-800 rounded-full">
                        {site.environment}
                      </span>
                      <span className="px-2 py-1 bg-gray-100 text-gray-700 rounded-full">
                        {site.connection_status ?? 'not connected'}
                      </span>
                      {site.incidents.length > 0 && (
                        <span className="px-2 py-1 bg-red-100 text-red-800 rounded-full">
                          {site.incidents.length} open incident
                          {site.incidents.length === 1 ? '' : 's'}
                        </span>
                      )}
                    </div>
                    {site.healthError && (
                      <p className="mt-2 text-xs text-amber-600">
                        {site.healthError}
                      </p>
                    )}
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>

        <div>
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-xl font-semibold text-gray-900">
              Unread notifications
            </h2>
            <span className="text-sm text-gray-500">{data.unreadCount}</span>
          </div>
          <Card>
            {data.notificationsError ? (
              <p className="text-sm text-amber-600">{data.notificationsError}</p>
            ) : data.notifications.length === 0 ? (
              <EmptyState message="You are all caught up." />
            ) : (
              <ul className="divide-y divide-gray-100">
                {data.notifications.map((notification) => (
                  <li key={notification.id} className="py-3">
                    <p className="text-sm font-medium text-gray-900">
                      {notification.title}
                    </p>
                    {notification.body && (
                      <p className="text-sm text-gray-600 mt-1">
                        {notification.body}
                      </p>
                    )}
                    <p className="text-xs text-gray-400 mt-1">
                      {notification.severity} ·{' '}
                      {new Date(notification.created_at).toLocaleString()}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
