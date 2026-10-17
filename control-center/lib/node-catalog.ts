import type { ScenarioNodeType } from '@/types/scenario';

export type InspectorField = { key: string; label: string; kind?: 'number' | 'boolean' | 'select' | 'roles' | 'text' | 'variable' | 'value' | 'mime'; options?: string[]; min?: number; max?: number; optional?: boolean; modes?: string[] };
type NodeDefinition = { label: string; icon: string; group: string; defaults: Record<string, unknown>; fields: InspectorField[] };
const text = (key: string, label: string, optional = false): InspectorField => ({ key, label, optional });
const number = (key: string, label: string, min = 0, max = 86400, optional = false): InspectorField => ({ key, label, kind: 'number', min, max, optional });
const choice = (key: string, label: string, options: string[]): InspectorField => ({ key, label, kind: 'select', options });
const bool = (key: string, label: string): InspectorField => ({ key, label, kind: 'boolean' });
const variable: InspectorField = { key: 'variable', label: 'متغیر', kind: 'variable' };
const comparison = [choice('operator', 'عملگر', ['==', '!=', '>', '>=', '<', '<=', 'EXISTS', 'NOT_EXISTS']), { key: 'value', label: 'مقدار مقایسه', kind: 'value' } as InspectorField];
export const RUNTIME_ROLES = ['SCANNER', 'NAVIGATOR', 'DECODER', 'AR_OPERATOR'];
const teamDefaults = { minimum_player_count: 1, all_active_players: false, required_roles: [] };
const teamFields: InspectorField[] = [number('minimum_player_count', 'حداقل بازیکنان', 1, 8), bool('all_active_players', 'همه بازیکنان فعال'), { key: 'required_roles', label: 'نقش‌های مورد نیاز', kind: 'roles' }, number('synchronization_window_seconds', 'بازه هماهنگی (ثانیه)', 1, 86400, true)];
const evidenceDefaults = { instruction: '', max_size_mb: 10, allow_resubmit: true, approval_mode: 'GAMEMASTER_REVIEW' };
const evidenceFields: InspectorField[] = [text('instruction', 'دستورالعمل'), number('max_size_mb', 'حداکثر حجم (MB)', 1, 25), bool('allow_resubmit', 'ارسال دوباره پس از رد'), choice('approval_mode', 'بررسی مدرک', ['AUTO_ACCEPT', 'GAMEMASTER_REVIEW'])];
export const NODE_CATALOG: Record<ScenarioNodeType, NodeDefinition> = {
  Start: { label: 'شروع', icon: '▶', group: 'شروع و پایان', defaults: {}, fields: [] },
  Finish: { label: 'پایان', icon: '■', group: 'شروع و پایان', defaults: {}, fields: [] },
  Message: { label: 'پیام', icon: '▤', group: 'محتوا', defaults: { text: '' }, fields: [text('text', 'متن پیام')] },
  Puzzle: { label: 'معما', icon: '?', group: 'محتوا', defaults: { prompt: '', expected_answer: '', case_sensitive: false, trim_whitespace: true }, fields: [text('prompt', 'صورت معما'), text('expected_answer', 'پاسخ مورد انتظار'), number('max_attempts', 'حداکثر تلاش', 1, 1000, true), bool('case_sensitive', 'حساس به بزرگی حروف'), bool('trim_whitespace', 'حذف فاصله‌های ابتدا و انتها')] },
  Password: { label: 'رمز عبور', icon: '⌘', group: 'محتوا', defaults: { prompt: '', expected_answer: '', case_sensitive: false, trim_whitespace: true, hints: [], offline_submission_allowed: false }, fields: [] },
  'Text Answer': { label: 'پاسخ متنی', icon: 'T', group: 'محتوا', defaults: { prompt: '', expected_answer: '', case_sensitive: false, trim_whitespace: true, hints: [], offline_submission_allowed: false }, fields: [] },
  'Multiple Choice': { label: 'چندگزینه‌ای', icon: '☷', group: 'محتوا', defaults: { prompt: '', options: { A: '', B: '' }, correct_option_refs: [], multi_select: false, offline_submission_allowed: false }, fields: [] },
  MediaReveal: { label: 'نمایش رسانه', icon: '▧', group: 'محتوا', defaults: { media_asset_id: '', require_acknowledgement: true }, fields: [text('description', 'توضیح', true), text('cta_label', 'عنوان دکمه', true), bool('require_acknowledgement', 'نیاز به تأیید بازیکن')] },
  'GPS / Location': { label: 'نقطه مکانی', icon: '⌖', group: 'مکان', defaults: { latitude: '', longitude: '', radius_m: '', dwell_seconds: 0 }, fields: [text('checkpoint_id', 'شناسه Checkpoint', true), number('latitude', 'عرض جغرافیایی', -90, 90), number('longitude', 'طول جغرافیایی', -180, 180), number('radius_m', 'شعاع مجاز (متر)', 0.01, 100000), number('accuracy_m', 'حداکثر خطای GPS (متر)', 0.01, 1000, true), number('dwell_seconds', 'زمان ماندن در محدوده (ثانیه)', 0, 86400, true)] },
  QR: { label: 'اسکن QR', icon: '▣', group: 'مکان', defaults: {}, fields: [text('success_message', 'پیام موفقیت', true), text('invalid_message', 'پیام نامعتبر', true)] },
  Time: { label: 'زمان', icon: '◷', group: 'منطق', defaults: { mode: 'DELAY', duration_seconds: 30 }, fields: [choice('mode', 'حالت زمان', ['DELAY', 'TIMEOUT', 'TIME_WINDOW', 'ELAPSED_CHECK']), { ...number('duration_seconds', 'مدت (ثانیه)', 1), modes: ['DELAY', 'TIMEOUT'] }, { ...text('starts_at', 'شروع بازه (ISO با منطقه زمانی)'), modes: ['TIME_WINDOW'] }, { ...text('ends_at', 'پایان بازه (ISO با منطقه زمانی)'), modes: ['TIME_WINDOW'] }, { ...choice('operator', 'عملگر', ['==', '!=', '>', '>=', '<', '<=']), modes: ['ELAPSED_CHECK'] }, { ...number('value', 'زمان سپری‌شده (ثانیه)', 0, 604800), modes: ['ELAPSED_CHECK'] }] },
  Condition: { label: 'شرط', icon: '◇', group: 'منطق', defaults: { operand: 'score', operator: '>=', value: 500 }, fields: [text('operand', 'مبنای شرط'), ...comparison] },
  SetVariable: { label: 'تنظیم متغیر', icon: '=', group: 'منطق', defaults: { variable: '', value: '' }, fields: [variable, { key: 'value', label: 'مقدار', kind: 'value' }] },
  CheckVariable: { label: 'بررسی متغیر', icon: '≟', group: 'منطق', defaults: { variable: '', operator: '==', value: '' }, fields: [variable, ...comparison] },
  Score: { label: 'امتیاز', icon: '+', group: 'منطق', defaults: { operation: 'ADD', amount: 100, reason: '' }, fields: [choice('operation', 'عملیات', ['ADD', 'SUBTRACT']), number('amount', 'مقدار امتیاز', 1, 1000000), text('reason', 'دلیل امتیاز')] },
  RoleCheck: { label: 'بررسی نقش', icon: '◈', group: 'نقش‌ها', defaults: { role: 'NAVIGATOR' }, fields: [choice('role', 'نقش', RUNTIME_ROLES)] },
  AssignRole: { label: 'تخصیص نقش', icon: '↗', group: 'نقش‌ها', defaults: { roles: ['NAVIGATOR', 'DECODER'], assignment_policy: 'LEAST_RECENTLY_USED' }, fields: [{ key: 'roles', label: 'نقش‌ها', kind: 'roles' }, choice('assignment_policy', 'روش تخصیص', ['LEAST_RECENTLY_USED', 'SEEDED_RANDOM'])] },
  RandomPlayer: { label: 'بازیکن تصادفی', icon: '⚄', group: 'نقش‌ها', defaults: { variable: '' }, fields: [variable, text('private_text', 'پیام خصوصی بازیکن منتخب', true)] },
  SplitInformation: { label: 'اطلاعات تقسیم‌شده', icon: '⑂', group: 'تیمی', defaults: { ...teamDefaults, role_content: {} }, fields: teamFields },
  TeamTogether: { label: 'همکاری تیم', icon: '◎', group: 'تیمی', defaults: teamDefaults, fields: teamFields },
  Barrier: { label: 'سد پیشروی', icon: '⊣', group: 'تیمی', defaults: { ...teamDefaults, required_variables: [] }, fields: teamFields },
  Relay: { label: 'رله', icon: '⇢', group: 'تیمی', defaults: { roles: ['NAVIGATOR', 'DECODER'] }, fields: [{ key: 'roles', label: 'ترتیب نقش‌های رله', kind: 'roles' }] },
  LiveChallenge: { label: 'چالش زنده', icon: '◉', group: 'کنترل زنده', defaults: { category: 'TEAM_OBJECTIVE' }, fields: [choice('category', 'نوع چالش', ['MESSAGE', 'TEAM_OBJECTIVE']), text('instruction', 'راهنما', true)] },
  PhotoSubmission: { label: 'ارسال عکس', icon: '▧', group: 'رسانه و مدرک', defaults: { ...evidenceDefaults, camera_policy: 'CAMERA_ONLY', require_location: false, require_timestamp: true }, fields: [...evidenceFields, choice('camera_policy', 'منبع عکس', ['CAMERA_ONLY', 'CAMERA_OR_GALLERY']), bool('require_location', 'موقعیت مکانی الزامی'), bool('require_timestamp', 'زمان ثبت الزامی')] },
  AudioSubmission: { label: 'ارسال صدا', icon: '♫', group: 'رسانه و مدرک', defaults: { ...evidenceDefaults, record_policy: 'RECORD_ONLY', max_duration_seconds: 30 }, fields: [...evidenceFields, number('max_duration_seconds', 'حداکثر مدت (ثانیه)', 1, 300), choice('record_policy', 'منبع صدا', ['RECORD_ONLY', 'RECORD_OR_LIBRARY'])] },
  FileSubmission: { label: 'ارسال فایل', icon: '▱', group: 'رسانه و مدرک', defaults: { ...evidenceDefaults, allowed_mime_types: ['application/pdf', 'image/jpeg'] }, fields: [...evidenceFields, { key: 'allowed_mime_types', label: 'نوع فایل مجاز', kind: 'mime' }] },
};
export const NODE_GROUPS = ['شروع و پایان', 'محتوا', 'مکان', 'منطق', 'نقش‌ها', 'تیمی', 'کنترل زنده', 'رسانه و مدرک'];
export const OUTCOME_LABELS: Record<string, string> = { SUCCESS: 'موفق', FAILURE: 'ناموفق', TIMEOUT: 'پایان زمان', TRUE: 'درست', FALSE: 'نادرست', APPROVED: 'تأیید', REJECTED: 'رد' };
export function nodeOutcomes(type: ScenarioNodeType, config: Record<string, unknown>): string[] {
  if (type === 'Finish') return [];
  if (['Condition', 'CheckVariable', 'RoleCheck'].includes(type) || type === 'Time' && config.mode === 'ELAPSED_CHECK') return ['TRUE', 'FALSE'];
  const result = type.endsWith('Submission') ? ['APPROVED', 'REJECTED'] : ['SUCCESS'];
  if (config.timeout_seconds || type === 'Time' && ['TIMEOUT', 'TIME_WINDOW'].includes(String(config.mode))) result.push('TIMEOUT');
  if (config.max_attempts || config.allow_retry === false) result.push('FAILURE');
  return result;
}
