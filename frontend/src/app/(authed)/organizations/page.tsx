'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { api, ApiError } from '@/lib/api';

interface Organization {
  id: string;
  name: string;
  slug: string;
  status: string;
  created_at: string;
  updated_at: string;
}

export default function OrganizationsPage() {
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await api.getOrganizations();
        setOrganizations(response.data as Organization[]);
      } catch (err) {
        setError((err as ApiError).message || 'Failed to load organizations');
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
        <h1 className="text-3xl font-bold text-gray-900">Organizations</h1>
        <Link
          href="/organizations/new"
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          + New Organization
        </Link>
      </div>

      {organizations.length === 0 ? (
        <div className="text-center py-12 border-2 border-dashed rounded-lg">
          <p className="text-gray-500">No organizations found.</p>
          <Link
            href="/organizations/new"
            className="mt-2 inline-block text-blue-600 hover:underline"
          >
            Create your first organization
          </Link>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {organizations.map((org) => (
            <div key={org.id} className="p-6 bg-white rounded-lg shadow">
              <h3 className="text-xl font-semibold text-gray-900">{org.name}</h3>
              <p className="text-sm text-gray-500">/{org.slug}</p>
              <span
                className={`inline-block mt-2 px-2 py-1 text-xs rounded-full ${
                  org.status === 'active'
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                }`}
              >
                {org.status}
              </span>
              <p className="mt-2 text-xs text-gray-400">
                Created {new Date(org.created_at).toLocaleDateString()}
              </p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
