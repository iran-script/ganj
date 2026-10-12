import { describe, expect, it } from 'vitest';
import { filterTeams } from '@/lib/office-utils';

describe('office module frontend logic', () => {
  it('filters teams by search and status without inventing data', () => {
    const teams = [{ id: '1', name: 'آذر', status: 'READY' }, { id: '2', name: 'باران', status: 'ACTIVE' }];
    expect(filterTeams(teams, 'آذر', 'ALL')).toHaveLength(1);
    expect(filterTeams(teams, '', 'ACTIVE')[0]?.id).toBe('2');
    expect(filterTeams(teams, 'ناشناخته', 'ALL')).toHaveLength(0);
  });
});
