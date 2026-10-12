import type { Team } from '@/types/api';

export function filterTeams(teams: Team[], search: string, status: string): Team[] {
  const normalizedSearch = search.trim().toLowerCase();
  return teams.filter((team) => `${team.name ?? ''} ${team.status ?? ''}`.toLowerCase().includes(normalizedSearch) && (status === 'ALL' || team.status === status));
}
