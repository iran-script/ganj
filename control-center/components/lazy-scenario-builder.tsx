'use client';

import dynamic from 'next/dynamic';
import { LoadingState } from '@/components/ui';

export const ScenarioBuilderScreen = dynamic(() => import('@/components/scenario-builder').then((module) => module.ScenarioBuilderScreen), {
  ssr: false,
  loading: () => <LoadingState label="در حال دریافت سازنده سناریو…" />,
});
