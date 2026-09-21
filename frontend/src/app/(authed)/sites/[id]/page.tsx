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
  const [editing, setEditing] = useState(false);
  const [saveLoading, setSaveLoading] = useState(false);
  const [editData, setEditData] = useState({
    name: '',
    url: '',
    environment: '',
    status: '',
    business_criticality: '',
    timezone: '',
    notes: '',
  });

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
        const siteData = response.data as Site;
        setSite(siteData);
        setEditData({
          name: siteData.name,
          url: siteData.url,
          environment: siteData.environment,
          status: siteData.status,
          business_criticality: siteData.business_criticality ?? '',
          timezone: siteData.timezone ?? '',
          notes: siteData.notes ?? '',
        });
      } catch (err) {
        setError((err as ApiError).message || 'Failed to load site');
      } finally {
        setLoading(false);
      }
    })();
  }, [id]);

  const fetchSite = async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      const response = await api.getSite(id);
      const siteData = response.data as Site;
      setSite(siteData);
      setEditData({
        name: siteData.name,
        url: siteData.url,
        environment: siteData.environment,
        status: siteData.status,
        business_criticality: siteData.business_criticality ?? '',
        timezone: siteData.timezone ?? '',
        notes: siteData.notes ?? '',
      });
    } catch (err) {
      setError((err as ApiError).message || 'Failed to load site');
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this site?')) return;
    try {
      await api.deleteSite(id);
      router.replace('/sites');
    } catch (err) {
      setError((err as ApiError).message || 'Failed to delete site');
    }
  };

  const handleSave = async () => {
    setSaveLoading(true);
    setError(null);
    try {
      await api.updateSite(id, {
        name: editData.name,
        url: editData.url,
        environment: editData.environment,
        status: editData.status,
        business_criticality: editData.business_criticality || null,
        timezone: editData.timezone || null,
        notes: editData.notes,
      });
      await fetchSite();
      setEditing(false);
    } catch (err) {
      setError((err as ApiError).message || 'Failed to update site');
    } finally {
      setSaveLoading(false);
    }
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setEditData((prev) => ({ ...prev, [name]: value }));
  };

  if (loading) return <div className="text-center py-12">Loading...</div>;
  if (error) return <div className="text-center py-12 text-red-600">{error}</div>;
  if (!site) return null;

  return (
    <div className="max-w-4xl mx-auto">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold text-gray-900">{site.name}</h1>
        <div className="flex gap-2">
          {!editing ? (
            <button
              onClick={() => setEditing(true)}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Edit
            </button>
          ) : (
            <button
              onClick={() => setEditing(false)}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
          )}
          <button
            onClick={handleDelete}
            className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
          >
            Delete Site
          </button>
        </div>
      </div>

      {editing && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-semibold text-gray-900 mb-4">Edit Site</h2>
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Name</label>
              <input
                type="text"
                name="name"
                value={editData.name}
                onChange={handleChange}
                className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">URL</label>
              <input
                type="url"
                name="url"
                value={editData.url}
                onChange={handleChange}
                className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Environment</label>
                <select
                  name="environment"
                  value={editData.environment}
                  onChange={handleChange}
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                >
                  <option value="development">Development</option>
                  <option value="staging">Staging</option>
                  <option value="production">Production</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Status</label>
                <select
                  name="status"
                  value={editData.status}
                  onChange={handleChange}
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="deprovisioned">Deprovisioned</option>
                </select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Business Criticality</label>
                <select
                  name="business_criticality"
                  value={editData.business_criticality}
                  onChange={handleChange}
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                >
                  <option value="">None</option>
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Timezone</label>
                <input
                  type="text"
                  name="timezone"
                  value={editData.timezone}
                  onChange={handleChange}
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                  placeholder="America/New_York"
                />
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Notes</label>
              <textarea
                name="notes"
                value={editData.notes}
                onChange={handleChange}
                rows={3}
                className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              />
            </div>
          </div>
          <div className="mt-4 flex gap-2">
            <button
              onClick={handleSave}
              disabled={saveLoading}
              className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {saveLoading ? 'Saving...' : 'Save Changes'}
            </button>
            <button
              onClick={() => setEditing(false)}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
          </div>
        </div>
      )}

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
