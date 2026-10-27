'use client';

import { useRouter } from 'next/navigation';
import { useRef, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, ErrorState, LoadingState, PageHeader } from '@/components/ui';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import { ApiErrorResponse } from '@/lib/api-error';

type FieldErrors = Record<string, string[]>;

function fieldMessage(errors: FieldErrors, field: string): string | undefined {
  const message = errors[field]?.[0];
  if (!message) return undefined;
  const translations: Record<string, string> = {
    'The title field is required.': 'عنوان سناریو الزامی است.',
    'The reason field is required.': 'دلیل عملیات برای مدیر کل الزامی است.',
    'A reason is required for privileged content administration.': 'دلیل عملیات برای مدیر کل الزامی است.',
    'The organization id field is required.': 'انتخاب سازمان الزامی است.',
    'The organization id must be a valid UUID.': 'شناسه سازمان معتبر نیست.',
    'The reason must be a string.': 'دلیل عملیات باید متن باشد.',
  };
  return translations[message] ?? message;
}

export default function NewScenarioPage() {
  const router = useRouter();
  const organizations = useQuery(queries.organizations());
  const capabilities = useQuery(queries.capabilities());
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [reason, setReason] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);
  const createKey = useRef<string | undefined>(undefined);
  const versionKey = useRef<string | undefined>(undefined);
  const isSuperAdmin = capabilities.data?.global_roles.includes('SUPER_ADMIN') === true;

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    setFieldErrors({});
    try {
      const body = { organization_id: organizationId, title: title.trim(), description: description.trim(), ...(reason.trim() ? { reason: reason.trim() } : {}) };
      const scenario = await scenarioApi.create(body, createKey.current ??= crypto.randomUUID());
      const version = await scenarioApi.createVersion(scenario.id, reason.trim() ? { reason: reason.trim() } : {}, versionKey.current ??= crypto.randomUUID());
      router.push(`/control/scenarios/${scenario.id}/builder?version=${version.id}`);
    } catch (caught) {
      if (caught instanceof ApiErrorResponse) {
        setError(caught.message === 'The request contains invalid fields.' ? 'برخی فیلدها معتبر نیستند؛ خطاهای هر فیلد را بررسی کنید.' : caught.message);
        const details = caught.details?.fields;
        if (details && typeof details === 'object') setFieldErrors(details as FieldErrors);
      } else {
        setError('ساخت سناریو انجام نشد.');
      }
    } finally {
      setBusy(false);
    }
  };

  if (organizations.isPending) return <LoadingState label="در حال دریافت سازمان‌ها..." />;
  if (organizations.isError) return <ErrorState message="سازمان فعال دریافت نشد." />;

  return <div className="max-w-2xl">
    <PageHeader eyebrow="SCENARIO CONTROL" title="ایجاد سناریو" description="سناریو ابتدا به‌صورت پیش‌نویس ایجاد و سپس وارد Builder می‌شود." />
    <form onSubmit={submit} className="space-y-5 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-6">
      <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">سازمان</span><select required value={organizationId} onChange={(event) => setOrganizationId(event.target.value)} className="nexo-input"><option value="">انتخاب سازمان</option>{organizations.data.map((organization) => <option key={organization.id} value={organization.id}>{organization.name ?? organization.title ?? organization.id}</option>)}</select>{fieldMessage(fieldErrors, 'organization_id') && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{fieldMessage(fieldErrors, 'organization_id')}</span>}</label>
      <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">عنوان سناریو</span><input required value={title} onChange={(event) => setTitle(event.target.value)} className="nexo-input" />{fieldMessage(fieldErrors, 'title') && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{fieldMessage(fieldErrors, 'title')}</span>}</label>
      <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">توضیح</span><textarea value={description} onChange={(event) => setDescription(event.target.value)} className="nexo-input min-h-28" />{fieldMessage(fieldErrors, 'description') && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{fieldMessage(fieldErrors, 'description')}</span>}</label>
      {(isSuperAdmin || capabilities.isPending || capabilities.isError) && <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">دلیل عملیات مدیر کل{!isSuperAdmin && ' (در صورت نیاز)'}</span><textarea required={isSuperAdmin} value={reason} onChange={(event) => setReason(event.target.value)} className="nexo-input min-h-24" />{fieldMessage(fieldErrors, 'reason') && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{fieldMessage(fieldErrors, 'reason')}</span>}</label>}
      {error && <div role="alert" className="text-sm text-[var(--nexo-danger)]">{error}</div>}
      <div className="flex justify-end gap-2"><Button href="/control/scenarios" variant="ghost">انصراف</Button><Button type="submit" disabled={busy || capabilities.isPending}>{busy ? 'در حال ایجاد...' : 'ایجاد و باز کردن Builder'}</Button></div>
    </form>
  </div>;
}
