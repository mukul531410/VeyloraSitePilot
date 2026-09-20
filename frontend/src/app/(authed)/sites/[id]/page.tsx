'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { Site } from '@/lib/types';

export default function SiteDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const router = useRouter();
  const [site, setSite] = useState<Site | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [id, setId] = useState<string>('');

  useEffect(() => {
    void (async () => {
      const resolvedParams = await params;
      setId(resolvedParams.id);
    })();
  }, [params]);

  useEffect(() => {
    if (!id) return;

    void (async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await api.getSite(id);
        setSite(response.data as Site);
      } catch (err) {
        setError((err as ApiError).message || 'Failed to load site');
      } finally {
        setLoading(false);
      }
    })();
  }, [id]);

  const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this site?')) return;
    try {
      await api.deleteSite(id);
      router.replace('/sites');
    } catch (err) {
      setError((err as ApiError).message || 'Failed to delete site');
    }
  };

  if (loading) return <div className="text-center py-12">Loading...</div>;
  if (error) return <div className="text-center py-12 text-red-600">{error}</div>;
  if (!site) return null;

  return (
    <div className="max-w-4xl mx-auto">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold text-gray-900">{site.name}</h1>
        <button
          onClick={handleDelete}
          className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
        >
          Delete Site
        </button>
      </div>

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-500">URL</label>
          <a
            href={site.url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-blue-600 hover:underline"
          >
            {site.url}
          </a>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-500">Environment</label>
            <p className="mt-1">{site.environment}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-500">Status</label>
            <p className="mt-1">{site.status}</p>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-500">Business Criticality</label>
            <p className="mt-1">{site.business_criticality || '—'}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-500">Timezone</label>
            <p className="mt-1">{site.timezone || '—'}</p>
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-500">Notes</label>
          <p className="mt-1 text-gray-700">{site.notes || '—'}</p>
        </div>
        <div className="text-xs text-gray-400 pt-4 border-t">
          <p>Created: {new Date(site.created_at).toLocaleString()}</p>
          <p>Updated: {new Date(site.updated_at).toLocaleString()}</p>
        </div>
      </div>
    </div>
  );
}
