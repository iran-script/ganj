import { describe, expect, it } from 'vitest';
import { canPublish, defaultScenarioConfig, hasValidEdgeReferences } from '@/lib/scenario-builder-utils';
import type { ScenarioNode } from '@/types/scenario';

const nodes: ScenarioNode[] = [{ node_key: 'start', node_type: 'Start', config: {} }, { node_key: 'finish', node_type: 'Finish', config: {} }];

describe('scenario builder foundation', () => {
  it('creates canonical starter configs without arbitrary fields', () => {
    expect(defaultScenarioConfig('Message')).toEqual({ text: '' });
    expect(defaultScenarioConfig('Puzzle')).toMatchObject({ case_sensitive: false, trim_whitespace: true });
    expect(defaultScenarioConfig('Start')).toEqual({});
  });

  it('rejects dangling and self-referencing edges', () => {
    expect(hasValidEdgeReferences(nodes, [{ edge_key: 'e1', from_node: 'start', to_node: 'finish', priority: 0 }])).toBe(true);
    expect(hasValidEdgeReferences(nodes, [{ edge_key: 'e2', from_node: 'start', to_node: 'missing', priority: 0 }])).toBe(false);
    expect(hasValidEdgeReferences(nodes, [{ edge_key: 'e3', from_node: 'start', to_node: 'start', priority: 0 }])).toBe(false);
  });

  it('only enables publish after a server PASS for a draft', () => {
    expect(canPublish({ status: 'PASS' }, 'DRAFT')).toBe(true);
    expect(canPublish({ status: 'FAIL' }, 'DRAFT')).toBe(false);
    expect(canPublish({ status: 'PASS' }, 'PUBLISHED')).toBe(false);
  });
});
