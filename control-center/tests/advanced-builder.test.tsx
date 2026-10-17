import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { AdvancedNodeInspector, VariableEditor } from '@/components/advanced-node-inspector';
import { StructuralSimulation } from '@/components/structural-simulation';
import { NodePolicies } from '@/components/node-policies';
import { NODE_CATALOG, NODE_GROUPS, nodeOutcomes } from '@/lib/node-catalog';
import { defaultScenarioConfig, nodeSummary } from '@/lib/scenario-builder-utils';
import type { ScenarioNode, ScenarioVariable } from '@/types/scenario';

vi.mock('@/lib/content-api', () => ({ contentApi: { media: async () => [{ id: 'asset-1', name: 'Private clue', kind: 'IMAGE' }] } }));
afterEach(cleanup);
it('authors existing hint and scoring policies without empty hints', async () => {
  const changed = vi.fn();
  render(<NodePolicies node={{ node_key: 'puzzle', node_type: 'Puzzle', config: { prompt: 'Solve', expected_answer: 'secret', case_sensitive: false, trim_whitespace: true } }} readOnly={false} onChange={changed} />);
  await userEvent.click(screen.getByText('زمان، پیام و امتیاز مرحله'));
  fireEvent.change(screen.getByLabelText('راهنمایی 1'), { target: { value: 'First clue' } });
  fireEvent.change(screen.getByLabelText('پاداش تلاش اول'), { target: { value: '30' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال سیاست مرحله' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: expect.objectContaining({ hints: ['First clue'], score: { first_attempt_bonus: 30 }, expected_answer: 'secret' }) }));
});
const variables: ScenarioVariable[] = [{ key: 'ready', type: 'BOOLEAN', scope: 'TEAM', default_value: false, writable: true }, { key: 'count', type: 'INTEGER', scope: 'TEAM', default_value: 0, writable: true }];
function mount(node: ScenarioNode, onChange = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><AdvancedNodeInspector selected={node} variables={variables} readOnly={false} onChange={onChange} /></QueryClientProvider>);
  return onChange;
}

it('has exactly the supported 23 authoring types in eight Persian groups', () => {
  expect(Object.keys(NODE_CATALOG)).toHaveLength(26);
  expect(NODE_GROUPS).toHaveLength(8);
  expect(Object.keys(NODE_CATALOG)).not.toContain('AR Challenge');
  expect(nodeOutcomes('Condition', { operand: 'score' })).toEqual(['TRUE', 'FALSE']);
  expect(nodeOutcomes('Time', { mode: 'TIMEOUT' })).toEqual(['SUCCESS', 'TIMEOUT']);
  expect(nodeOutcomes('PhotoSubmission', { timeout_seconds: 10 })).toEqual(['APPROVED', 'REJECTED', 'TIMEOUT']);
});

it('creates independent default configs and meaningful summaries', () => {
  const first = defaultScenarioConfig('AssignRole');
  (first.roles as string[]).push('SCANNER');
  expect(defaultScenarioConfig('AssignRole').roles).toEqual(['NAVIGATOR', 'DECODER']);
  expect(nodeSummary({ node_key: 'audio', node_type: 'AudioSubmission', config: { max_duration_seconds: 30 } })).toContain('30');
  expect(nodeSummary({ node_key: 'role', node_type: 'RoleCheck', config: { role: 'NAVIGATOR' } })).toBe('NAVIGATOR');
});

it('switches Time fields and removes config from the previous mode', async () => {
  const changed = mount({ node_key: 'time', node_type: 'Time', config: defaultScenarioConfig('Time') });
  fireEvent.change(screen.getByLabelText('حالت زمان'), { target: { value: 'ELAPSED_CHECK' } });
  expect(screen.queryByLabelText('مدت (ثانیه)')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('عملگر'), { target: { value: '>=' } });
  fireEvent.change(screen.getByLabelText('زمان سپری‌شده (ثانیه)'), { target: { value: '60' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: { mode: 'ELAPSED_CHECK', operator: '>=', value: 60 } }));
});

it('keeps variable booleans typed in authoring payloads', async () => {
  const changed = mount({ node_key: 'set', node_type: 'SetVariable', config: { variable: 'ready', value: false } });
  fireEvent.change(screen.getByLabelText('مقدار'), { target: { value: 'true' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: { variable: 'ready', value: true } }));
});

it('edits role-directed clues without a JSON editor', async () => {
  const changed = mount({ node_key: 'split', node_type: 'SplitInformation', config: defaultScenarioConfig('SplitInformation') });
  fireEvent.change(screen.getByLabelText('NAVIGATOR', { selector: 'textarea' }), { target: { value: 'North clue' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: expect.objectContaining({ role_content: { NAVIGATOR: { text: 'North clue' } } }) }));
});

it('selects media by reference', async () => {
  const changed = mount({ node_key: 'media', node_type: 'MediaReveal', config: defaultScenarioConfig('MediaReveal') });
  await screen.findByRole('option', { name: 'Private clue • IMAGE' });
  fireEvent.change(screen.getByLabelText('انتخاب از کتابخانه رسانه'), { target: { value: 'asset-1' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: expect.objectContaining({ media_asset_id: 'asset-1' }) }));
});

it('edits evidence review mode and file MIME restrictions', async () => {
  const changed = mount({ node_key: 'file', node_type: 'FileSubmission', config: defaultScenarioConfig('FileSubmission') });
  fireEvent.change(screen.getByLabelText('دستورالعمل'), { target: { value: 'Upload proof' } });
  fireEvent.change(screen.getByLabelText('بررسی مدرک'), { target: { value: 'AUTO_ACCEPT' } });
  await userEvent.click(screen.getByLabelText('image/jpeg'));
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(changed).toHaveBeenCalledWith(expect.objectContaining({ config: expect.objectContaining({ approval_mode: 'AUTO_ACCEPT', allowed_mime_types: ['application/pdf'] }) }));
});

it('creates typed team variables', async () => {
  const changed = vi.fn();
  render(<VariableEditor variables={variables} onChange={changed} readOnly={false} />);
  await userEvent.click(screen.getByText('متغیرهای سناریو (2)'));
  fireEvent.change(screen.getByLabelText('نوع متغیر 1'), { target: { value: 'INTEGER' } });
  expect(changed).toHaveBeenCalledWith(expect.arrayContaining([expect.objectContaining({ key: 'ready', type: 'INTEGER', default_value: 0 })]));
});

it('previews branch traversal and score without network mutations', async () => {
  render(<StructuralSimulation graph={{ nodes: [{ node_key: 'start', node_type: 'Start', config: {} }, { node_key: 'score', node_type: 'Score', config: { operation: 'ADD', amount: 500, reason: 'Preview' } }, { node_key: 'finish', node_type: 'Finish', config: {} }], edges: [{ edge_key: 'a', from_node: 'start', to_node: 'score', priority: 0 }, { edge_key: 'b', from_node: 'score', to_node: 'finish', priority: 0 }], variables, conditions: [], subflows: [] }} />);
  expect(screen.getByText('پیش‌نمایش ساختاری — اجرای واقعی Runtime نیست')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'ادامه ← score' }));
  await userEvent.click(screen.getByRole('button', { name: 'ادامه ← finish' }));
  expect(screen.getByLabelText('امتیاز پیش‌نمایش')).toHaveValue(500);
  expect(screen.getByRole('status')).toHaveTextContent('پایان پیش‌نمایش');
});
