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
  reported_supported: boolean;
  reported_at: string | null;
  effective: boolean;
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

export interface NotificationItem {
  id: string;
  organization_id: string | null;
  site_id: string | null;
  source_type: string;
  source_id: string;
  type: string;
  severity: string;
  title: string;
  body: string | null;
  read_at: string | null;
  created_at: string;
}

export interface ApprovalItem {
  id: string;
  organization_id: string;
  site_id: string;
  operation_id: string;
  status: 'pending' | 'approved' | 'rejected' | 'expired';
  requested_by: string;
  reviewed_by: string | null;
  reason: string | null;
  expires_at: string;
  reviewed_at: string | null;
  created_at: string;
  updated_at: string;
  site: { id: string; name: string } | null;
  operation: { id: string; operation_type: string; status: string } | null;
}

export interface AuditLogItem {
  id: string;
  organization_id: string;
  site_id: string | null;
  user_id: string | number | null;
  action: string;
  target_type: string;
  target_id: string;
  correlation_id: string;
  policy_result: string | null;
  created_at: string;
  user: { id: string | number; name: string } | null;
  site: { id: string; name: string } | null;
}

/**
 * Operation statuses written by the backend (`Operation` model constants).
 * `unknown` means the execution outcome is uncertain; only authoritative
 * remote verification can ever set `succeeded`.
 */
export type OperationStatus =
  | 'requested'
  | 'pending_approval'
  | 'approved'
  | 'queued'
  | 'running'
  | 'verification_pending'
  | 'succeeded'
  | 'failed'
  | 'unknown'
  | 'cancelled'
  | 'dead_letter';

/** Mirrors `Operation::TERMINAL_STATUSES`. */
export const OPERATION_TERMINAL_STATUSES: OperationStatus[] = [
  'succeeded',
  'failed',
  'unknown',
  'cancelled',
  'dead_letter',
];

/** Only these pre-flight states are legal to cancel (backend rejects the rest with 409). */
export const CANCELLABLE_STATUSES: OperationStatus[] = ['requested', 'approved', 'queued'];

/** Only `action.cache_clear` is accepted by `StoreOperationRequest`. */
export const SUPPORTED_OPERATION_TYPES = ['action.cache_clear'] as const;

/**
 * `transformOperation()` projects each relation with `->only([...])`, so these
 * nested objects carry no `id` and no connector credentials.
 */
export interface OperationAttemptSummary {
  attempt_number: number;
  status: string;
  connector_job_id: string | null;
  retryable: boolean | null;
  timeout_at: string | null;
  started_at: string | null;
  finished_at: string | null;
  error_code: string | null;
}

export interface OperationResultSummary {
  result_status: string | null;
  cache_cleared_at: string | null;
  cleared_types: string[] | null;
  cache_generation: string | null;
  error_code: string | null;
  error_message: string | null;
  verification_status: string | null;
  verification_error: string | null;
  verified_at: string | null;
}

export interface OperationApprovalSummary {
  status: string;
  requested_by: string | number;
  reviewed_by: string | number | null;
  reason: string | null;
  expires_at: string | null;
  reviewed_at: string | null;
}

export interface OperationItem {
  id: string;
  site_id: string;
  operation_type: string;
  target_json: Record<string, unknown> | null;
  status: OperationStatus;
  policy_result: string | null;
  approval_required: boolean;
  idempotency_key: string;
  requested_by: string | number | null;
  started_at: string | null;
  finished_at: string | null;
  resolution: string | null;
  resolved_at: string | null;
  resolved_by: string | number | null;
  resolution_reason: string | null;
  created_at: string;
  updated_at: string;
  latest_attempt?: OperationAttemptSummary | null;
  result?: OperationResultSummary | null;
  approval?: OperationApprovalSummary | null;
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
