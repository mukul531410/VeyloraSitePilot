'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { OrganizationSummary, SiteSummary } from '@/lib/types';

export default function DashboardPage() {
  const { user } = useAuth();
  const [orgs, setOrgs] = useState<OrganizationSummary[]>([]);
  const [sites, setSites] = useState<SiteSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchDashboard = async () => {
    setLoading(true);
    setError(null);
    try {
      const [orgRes, siteRes] = await Promise.all([
        api.getOrganizations(),
        api.getSites(),
      ]);
      setOrgs(orgRes.data as OrganizationSummary[]);
      setSites(siteRes.data as SiteSummary[]);
    } catch (err) {
      const apiError = err as ApiError;
      setError(apiError.message || 'Failed to load data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void (async () => {
      await fetchDashboard();
    })();
  }, []);

  const retry = async () => {
    await fetchDashboard();
  };

  if (loading) {
    return (
      <div className="text-center py-12">
        <div className="text-gray-500">Loading dashboard...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center py-12">
        <p className="text-red-600">{error}</p>
        <button
          onClick={retry}
          className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900">Dashboard</h1>
        {user && (
          <p className="mt-2 text-gray-600">Welcome, {user.name}</p>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div>
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-xl font-semibold text-gray-900">Organizations</h2>
            <Link
              href="/organizations/new"
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              + New Organization
            </Link>
          </div>
          {orgs.length === 0 ? (
            <p className="text-gray-500 py-8 text-center border-2 border-dashed rounded-lg">
              No organizations yet. Create your first one to get started.
            </p>
          ) : (
            <div className="space-y-3">
              {orgs.map((org) => (
                <div key={org.id} className="p-4 bg-white rounded-lg shadow">
                  <h3 className="font-semibold text-gray-900">{org.name}</h3>
                  <p className="text-sm text-gray-500">/{org.slug}</p>
                  <span
                    className={`inline-block px-2 py-1 text-xs rounded-full ${
                      org.status === 'active'
                        ? 'bg-green-100 text-green-800'
                        : 'bg-gray-100 text-gray-800'
                    }`}
                  >
                    {org.status}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div>
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-xl font-semibold text-gray-900">Sites</h2>
            <Link
              href="/sites/new"
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              + New Site
            </Link>
          </div>
          {sites.length === 0 ? (
            <p className="text-gray-500 py-8 text-center border-2 border-dashed rounded-lg">
              No sites yet. Create your first site to get started.
            </p>
          ) : (
            <div className="space-y-3">
              {sites.map((site) => (
                <Link key={site.id} href={`/sites/${site.id}`}>
                  <div className="p-4 bg-white rounded-lg shadow cursor-pointer hover:bg-gray-50">
                    <h3 className="font-semibold text-gray-900">{site.name}</h3>
                    <a
                      href={site.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-sm text-blue-600 hover:underline"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {site.url}
                    </a>
                    <div className="mt-2 flex gap-2">
                      <span className="inline-block px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                        {site.environment}
                      </span>
                      <span
                        className={`inline-block px-2 py-1 text-xs rounded-full ${
                          site.status === 'active'
                            ? 'bg-green-100 text-green-800'
                            : 'bg-red-100 text-red-800'
                        }`}
                      >
                        {site.status}
                      </span>
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
