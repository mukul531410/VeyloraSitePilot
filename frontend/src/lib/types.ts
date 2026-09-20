export interface User {
  id: number;
  name: string;
  email: string;
  status: string | null;
  last_login_at: string | null;
  organizations: OrganizationSummary[];
}

export interface OrganizationSummary {
  id: string;
  name: string;
  slug: string;
  status: string;
}

export interface Organization {
  id: string;
  name: string;
  slug: string;
  status: string;
  created_at: string;
  updated_at: string;
}

export interface SiteSummary {
  id: string;
  organization_id: string;
  name: string;
  url: string;
  environment: string;
  status: string;
  created_at?: string;
}

export interface Site {
  id: string;
  organization_id: string;
  name: string;
  url: string;
  environment: string;
  status: string;
  business_criticality: string | null;
  timezone: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface ApiResponse<T> {
  data: T;
  meta: Record<string, unknown>;
  request_id: string;
}

export interface ApiError {
  error: {
    code: string;
    message: string;
    details: Record<string, unknown>;
  };
  request_id: string;
}
