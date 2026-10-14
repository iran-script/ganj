import { graphlib, layout } from '@dagrejs/dagre';
import type { Edge, Node, XYPosition } from '@xyflow/react';
import type { ScenarioNode } from '@/types/scenario';

export type LayoutMode = 'TREE_VERTICAL' | 'TREE_HORIZONTAL';
type LayoutNode = Node<{ node: ScenarioNode }>;
export type LayoutSnapshot = Map<string, XYPosition>;

export function captureLayout(nodes: LayoutNode[]): LayoutSnapshot {
  return new Map(nodes.map((node) => [node.id, { ...node.position }]));
}

export function restoreLayout<T extends LayoutNode>(nodes: T[], snapshot: LayoutSnapshot): T[] {
  return nodes.map((node) => {
    const position = snapshot.get(node.id);
    return position ? { ...node, position: { ...position } } : node;
  });
}

export function nodeDimensions(node: LayoutNode) {
  return {
    width: node.measured?.width || node.width || 300,
    height: node.measured?.height || node.height || 160,
  };
}

/** Dagre receives disposable geometry only; scenario objects and edges never enter its mutable graph. */
export function layoutScenario<T extends LayoutNode>(nodes: T[], edges: Edge[], mode: LayoutMode) {
  const vertical = mode === 'TREE_VERTICAL';
  const byId = new Map(nodes.map((node) => [node.id, node]));
  const outgoing = new Map(nodes.map((node) => [node.id, [] as string[]]));
  const validEdges = edges.filter((edge) => byId.has(edge.source) && byId.has(edge.target));
  for (const edge of validEdges) outgoing.get(edge.source)!.push(edge.target);
  const reachable = new Set<string>();
  const pending = nodes.filter((node) => node.data.node.node_type === 'Start').map((node) => node.id);
  while (pending.length) {
    const id = pending.pop()!;
    if (reachable.has(id)) continue;
    reachable.add(id);
    pending.push(...outgoing.get(id)!);
  }
  const positions: LayoutSnapshot = new Map();
  let hasCycle = false;
  const groups = [nodes.filter((node) => reachable.has(node.id)), nodes.filter((node) => !reachable.has(node.id))];
  let orphanOffset = 0;
  groups.forEach((group, groupIndex) => {
    if (!group.length) return;
    const ids = new Set(group.map((node) => node.id));
    const graph = new graphlib.Graph({ multigraph: true });
    graph.setGraph({ rankdir: vertical ? 'TB' : 'LR', ranksep: 140, nodesep: 100, edgesep: 40, marginx: 40, marginy: 40 });
    graph.setDefaultEdgeLabel(() => ({}));
    for (const node of group) {
      const size = nodeDimensions(node);
      const branching = outgoing.get(node.id)!.length > 1 || ['Condition', 'SplitInformation', 'Barrier', 'Relay'].includes(node.data.node.node_type);
      graph.setNode(node.id, { width: size.width + (branching ? 60 : 0), height: size.height + (branching ? 40 : 0) });
    }
    for (const edge of validEdges) {
      if (!ids.has(edge.source) || !ids.has(edge.target)) continue;
      const outcome = String((edge.data?.metadata as Record<string, unknown> | undefined)?.outcome ?? '');
      graph.setEdge(edge.source, edge.target, { weight: ['', 'success', 'true', 'completed'].includes(outcome.toLowerCase()) ? 3 : 1 }, edge.id);
    }
    hasCycle ||= !graphlib.alg.isAcyclic(graph);
    layout(graph);
    for (const node of group) {
      const placed = graph.node(node.id);
      const size = nodeDimensions(node);
      positions.set(node.id, { x: placed.x - size.width / 2, y: placed.y - size.height / 2 });
    }

    // Terminal Finish nodes share a final band, without changing edges or folding nodes together.
    const terminals = group.filter((node) => node.data.node.node_type === 'Finish' && outgoing.get(node.id)!.length === 0);
    if (terminals.length) {
      const axis = vertical ? 'y' : 'x';
      const cross = vertical ? 'x' : 'y';
      const extent = vertical ? 'height' : 'width';
      const crossExtent = vertical ? 'width' : 'height';
      const terminalIds = new Set(terminals.map((node) => node.id));
      const nonTerminals = group.filter((node) => !terminalIds.has(node.id));
      const finalRank = Math.max(40, ...nonTerminals.map((node) => positions.get(node.id)![axis] + nodeDimensions(node)[extent] + 140));
      terminals.sort((a, b) => positions.get(a.id)![cross] - positions.get(b.id)![cross]);
      let nextCross = positions.get(terminals[0].id)![cross];
      for (const node of terminals) {
        const position = positions.get(node.id)!;
        position[axis] = finalRank;
        position[cross] = Math.max(position[cross], nextCross);
        nextCross = position[cross] + nodeDimensions(node)[crossExtent] + 100;
      }
    }
    if (groupIndex === 0) {
      orphanOffset = Math.max(...group.map((node) => positions.get(node.id)!.x + nodeDimensions(node).width)) + 260;
    } else {
      for (const node of group) positions.get(node.id)!.x += orphanOffset;
    }
  });
  return { nodes: restoreLayout(nodes, positions), hasCycle, orphanCount: groups[1].length };
}
