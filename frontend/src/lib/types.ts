export interface User {
  id: number;
  name: string;
  email: string;
  status: string;
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
  connection_status: string | null;
  created_at: string;
  updated_at: string;
}

export interface SiteConnection {
  id: string;
  site_id: string;
  status: string;
  connector_version: string | null;
  connected_at: string | null;
  last_seen_at: string | null;
  revoked_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface SiteConnectionMeta {
  capabilities: ConnectorCapabilitySummary[];
  latest_heartbeat: ConnectorHeartbeat | null;
}

export interface ConnectorCapabilitySummary {
  capability_key: string;
  enabled: boolean;
  discovered_at: string | null;
}

export interface ConnectorHeartbeat {
  id: string;
  connector_version: string;
  wordpress_version: string | null;
  php_version: string | null;
  status: string;
  reported_at: string;
  created_at: string;
}

export interface ConnectionIntent {
  connection_intent: string;
  intent_expires_at: string;
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

export type HealthState = 'healthy' | 'attention' | 'degraded' | 'critical' | 'unknown';
export type IncidentStatus = 'detected' | 'acknowledged' | 'investigating' | 'resolved';
export type IncidentSeverity = 'critical' | 'high' | 'medium' | 'low';
export type IncidentType = 'heartbeat_loss' | 'site_unreachable' | 'ssl_expiring' | 'critical_findings' | 'health_degraded';
export type CheckType = 'reachability' | 'http_response' | 'ssl' | 'heartbeat' | 'uptime' | 'wordpress_state' | 'critical_findings';
export type CheckStatus = 'pass' | 'warn' | 'fail';

export interface HealthCheckRecord {
  id: string;
  site_id: string;
  check_type: CheckType;
  status: CheckStatus;
  value_json: Record<string, unknown> | null;
  checked_at: string;
  created_at: string;
}

export interface SiteMetricRecord {
  site_id: string;
  metric_type: string;
  value: number | null;
  unit: string | null;
  observed_at: string;
}

export interface Incident {
  id: string;
  site_id: string;
  type: IncidentType;
  severity: IncidentSeverity;
  status: IncidentStatus;
  title: string;
  description: string | null;
  first_detected_at: string;
  last_detected_at: string | null;
  resolved_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface SiteHealth {
  site_id: string;
  health_state: HealthState;
  last_checked_at: string | null;
  open_incidents: Incident[];
  checks: HealthCheckRecord[];
}

export interface SiteMetricsResponse {
  [metricType: string]: Array<{
    metric_type: string;
    value: number | null;
    unit: string | null;
    observed_at: string;
  }>;
}
