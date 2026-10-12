'use client';

import { useParams } from 'next/navigation';
import { TeamDetailPage } from '@/components/teams-module';

export default function TeamDetailRoute() {
  const { id } = useParams<{ id: string }>();
  return <TeamDetailPage id={id} />;
}
