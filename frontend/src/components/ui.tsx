import type { ReactNode } from 'react';

export function PageHeader({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
  return (
    <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-3xl font-bold text-gray-900">{title}</h1>
        {subtitle ? <p className="mt-2 text-gray-600">{subtitle}</p> : null}
      </div>
      {action ? <div className="flex-shrink-0">{action}</div> : null}
    </div>
  );
}

export function Card({ title, children, className = '' }: { title?: string; children: ReactNode; className?: string }) {
  return (
    <div className={`bg-white rounded-lg shadow p-6 ${className}`}>
      {title ? <h2 className="text-xl font-semibold text-gray-900 mb-4">{title}</h2> : null}
      {children}
    </div>
  );
}

export function StatCard({
  label,
  value,
  hint,
  href,
}: {
  label: string;
  value: string | number;
  hint?: string;
  href?: string;
}) {
  const inner = (
    <>
      <p className="text-sm font-medium text-gray-500">{label}</p>
      <p className="mt-1 text-3xl font-bold text-gray-900">{value}</p>
      {hint ? <p className="mt-1 text-xs text-gray-400">{hint}</p> : null}
    </>
  );
  if (href) {
    return (
      <a href={href} className="block bg-white rounded-lg shadow p-6 hover:bg-gray-50">
        {inner}
      </a>
    );
  }
  return <div className="bg-white rounded-lg shadow p-6">{inner}</div>;
}

export function StatusBadge({ value, tone }: { value: string; tone?: 'green' | 'red' | 'blue' | 'gray' | 'amber' }) {
  const tones: Record<string, string> = {
    green: 'bg-green-100 text-green-800',
    red: 'bg-red-100 text-red-800',
    blue: 'bg-blue-100 text-blue-800',
    amber: 'bg-amber-100 text-amber-800',
    gray: 'bg-gray-100 text-gray-800',
  };
  const resolved = tone ?? (/active|healthy|connected|completed|succeeded|resolved/i.test(value) ? 'green'
    : /fail|error|critical|revoked|cancelled/i.test(value) ? 'red'
    : /pending|warn|attention|degraded|unknown/i.test(value) ? 'amber'
    : 'gray');
  return (
    <span className={`inline-block px-2 py-1 text-xs rounded-full ${tones[resolved]}`}>
      {value}
    </span>
  );
}

export function LoadingState({ message = 'Loading...' }: { message?: string }) {
  return (
    <div className="text-center py-12" role="status" aria-live="polite">
      <div className="text-gray-500">{message}</div>
    </div>
  );
}

export function EmptyState({
  message,
  actionLabel,
  actionHref,
}: {
  message: string;
  actionLabel?: string;
  actionHref?: string;
}) {
  return (
    <div className="text-center py-12 border-2 border-dashed rounded-lg">
      <p className="text-gray-500">{message}</p>
      {actionLabel && actionHref ? (
        <a href={actionHref} className="mt-2 inline-block text-blue-600 hover:underline">
          {actionLabel}
        </a>
      ) : null}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="text-center py-12" role="alert">
      <p className="text-red-600">{message}</p>
      {onRetry ? (
        <button
          onClick={onRetry}
          className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          Retry
        </button>
      ) : null}
    </div>
  );
}
