import { expect, it } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import type { ScenarioNode } from '@/types/scenario';
import { captureLayout, layoutScenario, nodeDimensions, restoreLayout } from '@/lib/scenario-layout';

function scenarioNode(id: string, type: ScenarioNode['node_type'] = 'Message'): Node<{ node: ScenarioNode }> {
  return {
    id,
    type: 'nexo',
    position: { x: Number(id.slice(1)) * 13, y: Number(id.slice(1)) * 7 },
    measured: { width: 250 + Number(id.slice(1)) % 3 * 20, height: 140 + Number(id.slice(1)) % 2 * 20 },
    data: { node: { node_key: id, node_type: type, config: { unchanged: true } } },
  };
}

function edge(id: string, source: string, target: string): Edge {
  return { id, source, target, label: id, data: { metadata: { label: id, outcome: 'success' } } };
}

it('lays out branching graphs vertically and horizontally without overlap or semantic changes', () => {
  const nodes = [scenarioNode('n0', 'Start'), scenarioNode('n1', 'Condition'), scenarioNode('n2'), scenarioNode('n3'), scenarioNode('n4', 'Finish')];
  const edges = [edge('a', 'n0', 'n1'), edge('b', 'n1', 'n2'), edge('c', 'n1', 'n3'), edge('d', 'n2', 'n4'), edge('e', 'n3', 'n4')];
  const before = JSON.stringify({ nodes, edges });
  const vertical = layoutScenario(nodes, edges, 'TREE_VERTICAL');
  expect(vertical.nodes).toHaveLength(nodes.length);
  expect(vertical.nodes.every((node) => node.position.y >= 0)).toBe(true);
  expect(vertical.nodes.find((node) => node.id === 'n1')!.position.y).toBeLessThan(vertical.nodes.find((node) => node.id === 'n2')!.position.y);
  expect(vertical.nodes.find((node) => node.id === 'n2')!.position.x).not.toBe(vertical.nodes.find((node) => node.id === 'n3')!.position.x);
  expectNoOverlap(vertical.nodes);

  const horizontal = layoutScenario(nodes, edges, 'TREE_HORIZONTAL');
  expect(horizontal.nodes.find((node) => node.id === 'n0')!.position.x).toBeLessThan(horizontal.nodes.find((node) => node.id === 'n1')!.position.x);
  expectNoOverlap(horizontal.nodes);
  expect(JSON.stringify({ nodes, edges })).toBe(before);
});

it('keeps orphans in a separate area and places multiple Finish nodes at the last rank', () => {
  const nodes = [scenarioNode('n0', 'Start'), scenarioNode('n1'), scenarioNode('n2', 'Finish'), scenarioNode('n3', 'Finish'), scenarioNode('n4')];
  const edges = [edge('a', 'n0', 'n1'), edge('b', 'n1', 'n2'), edge('c', 'n1', 'n3')];
  const result = layoutScenario(nodes, edges, 'TREE_VERTICAL');
  expect(result.orphanCount).toBe(1);
  expect(result.nodes).toHaveLength(nodes.length);
  expect(result.nodes.find((node) => node.id === 'n4')!.position.x).toBeGreaterThan(Math.max(...result.nodes.filter((node) => node.id !== 'n4').map((node) => node.position.x + nodeDimensions(node).width)));
  const terminalY = result.nodes.filter((node) => node.data.node.node_type === 'Finish').map((node) => node.position.y);
  expect(terminalY[0]).toBe(terminalY[1]);
  expectNoOverlap(result.nodes);
});

it('handles cycles and restores the previous position snapshot', () => {
  const nodes = [scenarioNode('n0', 'Start'), scenarioNode('n1'), scenarioNode('n2')];
  const edges = [edge('a', 'n0', 'n1'), edge('b', 'n1', 'n2'), edge('c', 'n2', 'n1')];
  const snapshot = captureLayout(nodes);
  const result = layoutScenario(nodes, edges, 'TREE_VERTICAL');
  expect(result.hasCycle).toBe(true);
  expect(result.nodes).toHaveLength(nodes.length);
  expect(restoreLayout(result.nodes, snapshot).map((node) => node.position)).toEqual(nodes.map((node) => node.position));
});

it('lays out 50 nodes without overlap', () => {
  const nodes = Array.from({ length: 50 }, (_, index) => scenarioNode(`n${index}`, index === 0 ? 'Start' : index === 49 ? 'Finish' : 'Message'));
  const edges = nodes.slice(1).map((node, index) => edge(`e${index}`, nodes[index].id, node.id));
  const result = layoutScenario(nodes, edges, 'TREE_VERTICAL');
  expect(result.nodes).toHaveLength(50);
  expectNoOverlap(result.nodes);
});

function expectNoOverlap(nodes: Node<{ node: ScenarioNode }>[]) {
  for (let firstIndex = 0; firstIndex < nodes.length; firstIndex++) {
    for (let secondIndex = firstIndex + 1; secondIndex < nodes.length; secondIndex++) {
      const first = nodes[firstIndex];
      const second = nodes[secondIndex];
      const firstSize = nodeDimensions(first);
      const secondSize = nodeDimensions(second);
      const overlapsX = first.position.x < second.position.x + secondSize.width && first.position.x + firstSize.width > second.position.x;
      const overlapsY = first.position.y < second.position.y + secondSize.height && first.position.y + firstSize.height > second.position.y;
      expect(overlapsX && overlapsY, `${first.id} overlaps ${second.id}`).toBe(false);
    }
  }
}
