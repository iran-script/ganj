'use client';

import Link from 'next/link';
import { useQueryClient } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import type { ComponentProps } from 'react';

export function ResourceLink({ resource, resourceId, ...props }: ComponentProps<typeof Link> & { resource: 'game' | 'session' | 'scenario'; resourceId: string }) {
  const cache = useQueryClient();
  const prefetch = () => {
    if (resource === 'game') void cache.prefetchQuery(queries.game(resourceId));
    if (resource === 'session') void cache.prefetchQuery(queries.session(resourceId));
    if (resource === 'scenario') void cache.prefetchQuery(queries.scenario(resourceId));
  };
  return <Link {...props} onMouseEnter={prefetch} onFocus={prefetch} onPointerDown={prefetch} />;
}
