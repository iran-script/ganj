'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { contentApi } from '@/lib/content-api';
import { ErrorState, LoadingState } from '@/components/ui';

export function LocationStageReferences({ locationId }: { locationId: string }) {
  const query = useQuery({ queryKey: ['location-stage-references', locationId], queryFn: () => contentApi.locationStages(locationId) });
  if (query.isPending) return <LoadingState label="در حال بررسی مرحله‌های مرتبط..." />;
  if (query.isError) return <ErrorState message="فهرست مرحله‌های مرتبط بارگذاری نشد." onRetry={() => void query.refetch()} />;
  return <section className="nexo-surface mt-5 rounded-xl p-5" aria-labelledby="location-stage-references-title">
    <h2 id="location-stage-references-title" className="font-bold">مرحله‌های استفاده‌کننده از این موقعیت</h2>
    {query.data.length === 0 ? <p className="mt-3 text-sm text-[var(--nexo-muted)]">هنوز مرحله‌ای به این موقعیت متصل نیست.</p> : <ul className="mt-3 divide-y divide-[var(--nexo-border)]">
      {query.data.map((stage) => <li key={`${stage.version_id}-${stage.node_key}`} className="py-3">
        <Link className="font-semibold text-[var(--nexo-amber)] hover:underline" href={`/control/scenarios/${stage.scenario_id}/builder?version=${stage.version_id}`}>{stage.stage_title || stage.node_type} · {stage.scenario_title}</Link>
        <p className="mt-1 text-xs text-[var(--nexo-muted)]">نسخه {stage.version_no} · {stage.version_status} · {stage.node_type}</p>
      </li>)}
    </ul>}
  </section>;
}
