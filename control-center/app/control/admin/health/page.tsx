'use client';

import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { EmptyState, ErrorState, HealthIndicator, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function AdminHealthPage() {
  const health = useQuery(queries.health());
  if (health.isPending) return <LoadingState label="در حال دریافت سلامت سیستم..." />;
  if (health.isError) return <ErrorState message="وضعیت سلامت سیستم در دسترس نیست." onRetry={() => void health.refetch()} />;
  const checks = health.data.checks ?? {};
  return <><PageHeader eyebrow="ADMIN / SYSTEM HEALTH" title="سلامت سیستم" description="فقط وضعیت‌هایی که endpoint سلامت API ارائه می‌کند نمایش داده می‌شوند." /><section className="nexo-surface rounded-xl p-5"><div className="mb-5 flex items-center justify-between"><h2 className="font-bold">وضعیت کلی API</h2><StatusBadge status={health.data.status === 'ok' ? 'ACTIVE' : 'PAUSED'} /></div>{Object.keys(checks).length === 0 ? <EmptyState title="جزئیات سلامت در دسترس نیست" description="API فعلی فقط وضعیت کلی را برگردانده است؛ وضعیت DB، Redis، queue و scheduler قابل استنتاج نیست." /> : <div className="grid gap-3 md:grid-cols-2">{Object.entries(checks).map(([key, value]) => <HealthIndicator key={key} label={key} status={value === 'ok' ? 'ok' : 'degraded'} />)}</div>}</section></>;
}
