import { QueryClient } from '@tanstack/react-query';

export const queryPolicy = {
  crud: { staleTime: 120_000, gcTime: 900_000, refetchOnWindowFocus: false, refetchInterval: false as const },
  identity: { staleTime: 300_000 },
  health: { staleTime: 30_000 },
  live: { staleTime: 10_000, refetchInterval: 15_000, refetchIntervalInBackground: false },
};

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { ...queryPolicy.crud, retry: 1 } },
  });
}
