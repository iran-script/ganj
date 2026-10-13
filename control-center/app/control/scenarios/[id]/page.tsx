'use client';
import { use } from 'react';
import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { Button, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
export default function ScenarioDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const scenario = useQuery(queries.scenario(id));
  if (scenario.isPending) return <LoadingState />;
  if (scenario.isError) return <ErrorState onRetry={() => void scenario.refetch()} />;
  return <><PageHeader title={scenario.data.title} description={scenario.data.description ?? undefined} actions={<><Button href={`/control/scenarios/${id}/builder`}>باز کردن Builder</Button><Button href={`/control/scenarios/${id}/versions`} variant="secondary">تاریخچه نسخه‌ها</Button></>} /><section className="nexo-surface rounded-xl p-6"><StatusBadge status={scenario.data.status} /></section></>;
}
