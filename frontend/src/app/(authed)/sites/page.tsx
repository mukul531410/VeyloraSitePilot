'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { api, ApiError } from '@/lib/api';
import { SiteSummary } from '@/lib/types';

export default function SitesPage() {
  const [sites, setSites] = useState<SiteSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await api.getSites();
        setSites(response.data as SiteSummary[]);
      } catch (err) {
        setError((err as ApiError).message || 'Failed to load sites');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  if (loading) return <div className="text-center py-12">Loading...</div>;
  if (error) return <div className="text-center py-12 text-red-600">{error}</div>;

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold text-gray-900">Sites</h1>
        <Link
          href="/sites/new"
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          + New Site
        </Link>
      </div>

      {sites.length === 0 ? (
        <div className="text-center py-12 border-2 border-dashed rounded-lg">
          <p className="text-gray-500">No sites found.</p>
          <Link
            href="/sites/new"
            className="mt-2 inline-block text-blue-600 hover:underline"
          >
            Create your first site
          </Link>
        </div>
      ) : (
        <div className="space-y-4">
          {sites.map((site) => (
            <Link key={site.id} href={`/sites/${site.id}`}>
              <div className="p-6 bg-white rounded-lg shadow cursor-pointer hover:bg-gray-50">
                <div className="flex justify-between items-start">
                  <div>
                    <h3 className="text-xl font-semibold text-gray-900">{site.name}</h3>
                    <a
                      href={site.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-sm text-blue-600 hover:underline"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {site.url}
                    </a>
                  </div>
                  <div className="flex gap-2">
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
                {site.created_at && (
                  <p className="mt-2 text-xs text-gray-400">
                    Created {new Date(site.created_at).toLocaleDateString()}
                  </p>
                )}
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
