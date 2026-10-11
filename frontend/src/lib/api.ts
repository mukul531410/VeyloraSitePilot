import { ApprovalItem, AuditLogItem, NotificationItem, OperationItem, SiteConnection, SiteConnectionMeta, SiteHealth, SiteMetricsResponse, Incident } from './types';

export class ApiError extends Error {
  public statusCode: number;
  public code: string;
  public details: Record<string, unknown>;

  constructor(message: string, statusCode: number, code: string, details: Record<string, unknown> = {}) {
    super(message);
    this.name = 'ApiError';
    this.statusCode = statusCode;
    this.code = code;
    this.details = details;
  }
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1';

function getToken(): string | null {
  if (typeof window !== 'undefined') {
    return localStorage.getItem('veylora_token');
  }
  return null;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE_URL}${path}`;
  const token = getToken();

  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(url, {
    ...options,
    headers: {
      ...headers,
      ...options.headers,
    },
  });

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    const error = body as {
      error?: { code?: string; message?: string; details?: Record<string, unknown> };
      message?: string;
    };
    const message = error?.error?.message || error?.message || response.statusText;
    const code = error?.error?.code || 'http_error';
    const details = error?.error?.details || {};

    throw new ApiError(message, response.status, code, details);
  }

  return body as T;
}

export interface ApiResponse<T> {
  data: T;
  meta: Record<string, unknown>;
  request_id: string;
}

export const api = {
  login: (credentials: { email: string; password: string }): Promise<ApiResponse<{ user: unknown; token: string }>> =>
    request('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    }),

  logout: (): Promise<ApiResponse<null>> =>
    request('/auth/logout', {
      method: 'POST',
    }),

  me: (): Promise<ApiResponse<{ user: unknown }>> =>
    request('/auth/me', {
      method: 'GET',
    }),

  getOrganizations: (): Promise<ApiResponse<unknown[]>> =>
    request('/organizations', { method: 'GET' }),

  createOrganization: (data: { name: string; slug: string; status?: string }): Promise<ApiResponse<unknown>> =>
    request('/organizations', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  getOrganization: (id: string): Promise<ApiResponse<unknown>> =>
    request(`/organizations/${id}`, { method: 'GET' }),

  updateOrganization: (id: string, data: Partial<{ name: string; slug: string; status: string }>): Promise<ApiResponse<unknown>> =>
    request(`/organizations/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  getSites: (params?: { page?: number }): Promise<ApiResponse<unknown[]>> => {
    const qs = new URLSearchParams();
    if (params?.page) qs.append('page', String(params.page));
    const query = qs.toString();
    return request(`/sites${query ? `?${query}` : ''}`, { method: 'GET' });
  },

  createSite: (data: {
    organization_id: string;
    name: string;
    url: string;
    environment?: string;
    status?: string;
    business_criticality?: string | null;
    timezone?: string | null;
    notes?: string | null;
  }): Promise<ApiResponse<unknown>> =>
    request('/sites', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  getSite: (id: string): Promise<ApiResponse<unknown>> =>
    request(`/sites/${id}`, { method: 'GET' }),

  updateSite: (id: string, data: Record<string, unknown>): Promise<ApiResponse<unknown>> =>
    request(`/sites/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  deleteSite: (id: string): Promise<ApiResponse<null>> =>
    request(`/sites/${id}`, { method: 'DELETE' }),

  createConnection: (siteId: string, data?: { connector_version?: string }): Promise<ApiResponse<SiteConnection>> =>
    request(`/sites/${siteId}/connections`, {
      method: 'POST',
      body: JSON.stringify(data || {}),
    }),

  getConnection: (
    siteId: string,
    connectionId: string,
  ): Promise<ApiResponse<SiteConnection> & { meta: SiteConnectionMeta }> =>
    request(`/sites/${siteId}/connections/${connectionId}`, { method: 'GET' }),

  revokeConnection: (siteId: string, connectionId: string): Promise<ApiResponse<null>> =>
    request(`/sites/${siteId}/connections/${connectionId}`, { method: 'DELETE' }),

  listConnections: (siteId: string): Promise<ApiResponse<SiteConnection[]>> =>
    request(`/sites/${siteId}/connections`, { method: 'GET' }),

  getSiteHealth: (siteId: string): Promise<ApiResponse<SiteHealth>> =>
    request(`/sites/${siteId}/health`, { method: 'GET' }),

  getSiteMetrics: (siteId: string): Promise<ApiResponse<SiteMetricsResponse>> =>
    request(`/sites/${siteId}/metrics`, { method: 'GET' }),

  getSiteIncidents: (siteId: string, params?: { status?: string; per_page?: number }): Promise<ApiResponse<Incident[]>> => {
    const qs = new URLSearchParams();
    if (params?.status) qs.append('status', params.status);
    if (params?.per_page) qs.append('per_page', String(params.per_page));
    const query = qs.toString();
    return request(`/sites/${siteId}/incidents${query ? `?${query}` : ''}`, { method: 'GET' });
  },

  getUnreadNotifications: (params?: {
    per_page?: number;
  }): Promise<ApiResponse<NotificationItem[]>> => {
    const qs = new URLSearchParams({ status: 'unread' });
    if (params?.per_page) qs.append('per_page', String(params.per_page));
    return request(`/notifications?${qs.toString()}`, { method: 'GET' });
  },

  markNotificationRead: (id: string): Promise<ApiResponse<NotificationItem>> =>
    request(`/notifications/${id}/read`, {
      method: 'POST',
    }),

  getApprovals: (params?: {
    status?: string;
    site_id?: string;
    per_page?: number;
    page?: number;
  }): Promise<ApiResponse<ApprovalItem[]>> => {
    const qs = new URLSearchParams();
    if (params?.status) qs.append('status', params.status);
    if (params?.site_id) qs.append('site_id', params.site_id);
    if (params?.per_page) qs.append('per_page', String(params.per_page));
    if (params?.page) qs.append('page', String(params.page));
    const query = qs.toString();
    return request(`/approvals${query ? `?${query}` : ''}`, { method: 'GET' });
  },

  getAuditLogs: (params?: {
    action?: string;
    target_type?: string;
    site_id?: string;
    user_id?: string;
    per_page?: number;
    page?: number;
  }): Promise<ApiResponse<AuditLogItem[]>> => {
    const qs = new URLSearchParams();
    if (params?.action) qs.append('action', params.action);
    if (params?.target_type) qs.append('target_type', params.target_type);
    if (params?.site_id) qs.append('site_id', params.site_id);
    if (params?.user_id) qs.append('user_id', params.user_id);
    if (params?.per_page) qs.append('per_page', String(params.per_page));
    if (params?.page) qs.append('page', String(params.page));
    const query = qs.toString();
    return request(`/audit-logs${query ? `?${query}` : ''}`, { method: 'GET' });
  },

  getOperations: (params?: {
    site_id?: string;
    status?: string;
    per_page?: number;
    page?: number;
  }): Promise<ApiResponse<OperationItem[]>> => {
    const qs = new URLSearchParams();
    if (params?.site_id) qs.append('site_id', params.site_id);
    if (params?.status) qs.append('status', params.status);
    if (params?.per_page) qs.append('per_page', String(params.per_page));
    if (params?.page) qs.append('page', String(params.page));
    const query = qs.toString();
    return request(`/operations${query ? `?${query}` : ''}`, { method: 'GET' });
  },

  getOperation: (operationId: string): Promise<ApiResponse<OperationItem>> =>
    request(`/operations/${operationId}`, { method: 'GET' }),

  createOperation: (
    siteId: string,
    data: { operation_type: string; target_json: Record<string, unknown>; idempotency_key: string },
  ): Promise<ApiResponse<OperationItem>> =>
    request(`/sites/${siteId}/operations`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  cancelOperation: (operationId: string, reason: string): Promise<ApiResponse<OperationItem>> =>
    request(`/operations/${operationId}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
};
