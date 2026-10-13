'use client';

import Link from 'next/link';
import { LocationPicker } from '@/components/gis/location-picker';
import { QrPrintable } from '@/components/qr-printable';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';
import { queries } from '@/lib/queries';
import { contentApi, type LocationItem, type QrItem } from '@/lib/content-api';
import { Button, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

type Bank = 'locations' | 'qr-bank';
const title = (bank: Bank) => bank === 'locations' ? 'بانک موقعیت‌ها' : 'بانک QR';
export function ContentBank({ bank }: { bank: Bank }) {
  const result = useQuery<Array<LocationItem | QrItem>>({ queryKey: bank === 'locations' ? queries.locations().queryKey : queries.qrBank().queryKey, queryFn: () => bank === 'locations' ? contentApi.locations() : contentApi.qr() });
  const [search, setSearch] = useState(''); const [activeOnly, setActiveOnly] = useState(false);
  if (result.isPending) return <LoadingState />;
  if (result.isError) return <ErrorState message="بارگذاری بانک انجام نشد." onRetry={() => void result.refetch()} />;
  const items = result.data.filter((item) => item.name.toLowerCase().includes(search.toLowerCase()) && (!activeOnly || item.status === 'ACTIVE'));
  return <><PageHeader title={title(bank)} description="منابع قابل استفاده در سناریوهای سازمان" actions={<Button href={`/control/content/${bank}/new`}>ساخت مورد جدید</Button>} /><div className="mb-5 flex flex-wrap gap-4"><input aria-label="جستجو در بانک" className="nexo-input max-w-sm" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جستجو…" /><label className="flex items-center gap-2"><input type="checkbox" checked={activeOnly} onChange={(event) => setActiveOnly(event.target.checked)} />فقط فعال</label></div><div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{items.map((item) => <article key={item.id} className="nexo-surface rounded-xl p-5"><Link href={`/control/content/${bank}/${item.id}`} className="nexo-hover block rounded-lg"><div className="flex justify-between gap-3"><h2 className="font-bold">{item.name}</h2><StatusBadge status={item.status} /></div><p className="mt-3 text-sm text-[var(--nexo-muted)]">{'latitude' in item ? `${item.latitude}, ${item.longitude} • ${item.default_radius_m}m` : `${item.label} • ${item.usage_count} ارجاع در نسخه‌ها`}</p></Link>{!('latitude' in item) && <><p className="mt-3 text-sm">{item.location_description || 'محل نصب ثبت نشده'}</p><dl className="my-3 space-y-1 text-xs text-[var(--nexo-muted)]"><div>ایجاد: {item.created_at ? new Date(item.created_at).toLocaleString('fa-IR') : '—'}</div><div>آخرین تغییر: {item.updated_at ? new Date(item.updated_at).toLocaleString('fa-IR') : '—'}</div></dl><QrPrintable id={item.id} available={item.printable_available === true} /></>}</article>)}</div>{!items.length && <p className="nexo-surface rounded-xl p-6">موردی یافت نشد.</p>}</>;
}

export function BankEditor({ bank, id }: { bank: Bank; id?: string }) {
  const router = useRouter();
  const cache = useQueryClient();
  const organizations = useQuery({ ...queries.organizations(), enabled: !id });
  const item = useQuery({ queryKey: ['content-bank', bank, id], queryFn: () => api.get<Record<string, unknown>>(`/content/${bank}/${id}`), enabled: Boolean(id) });
  const [draft, setDraft] = useState<Record<string, unknown>>({ status: 'ACTIVE', default_radius_m: 20 });
  useEffect(() => { if (item.data) setDraft(item.data); }, [item.data]);
  const fields = bank === 'locations' ? [{ key: 'name', label: 'نام' }, { key: 'latitude', label: 'عرض جغرافیایی', min: -90, max: 90 }, { key: 'longitude', label: 'طول جغرافیایی', min: -180, max: 180 }, { key: 'default_radius_m', label: 'شعاع پیش‌فرض (متر)', min: 0.01, max: 100000 }, { key: 'accuracy_m', label: 'حداکثر خطا (متر)', min: 1, max: 1000, optional: true }, { key: 'dwell_seconds', label: 'زمان ماندن (ثانیه)', min: 0, max: 86400, optional: true }, { key: 'category', label: 'دسته', optional: true }, { key: 'description', label: 'توضیحات', optional: true }] : [{ key: 'name', label: 'نام' }, { key: 'label', label: 'کد عملیاتی' }, { key: 'location_description', label: 'توضیح مکان', optional: true }, { key: 'notes', label: 'یادداشت', optional: true }];
  const save = useMutation({ mutationFn: () => {
    const body: Record<string, unknown> = { status: draft.status };
    if (!id) body.organization_id = draft.organization_id;
    fields.forEach((field) => { if (draft[field.key] !== undefined && draft[field.key] !== '' && draft[field.key] !== null) body[field.key] = 'min' in field ? Number(draft[field.key]) : draft[field.key]; else if (bank === 'qr-bank' && field.optional) body[field.key] = null; });
    return id ? api.patch<{ id: string }>(`/content/${bank}/${id}`, body) : api.post<{ id: string }>(`/content/${bank}`, body);
  }, onSuccess: async (result) => { await cache.invalidateQueries({ queryKey: ['content-bank', bank] }); router.push(`/control/content/${bank}/${result.id}`); } });
  if (!id && organizations.isPending || id && item.isPending) return <LoadingState />;
  if (!id && organizations.isError || id && item.isError) return <ErrorState message="اطلاعات بارگذاری نشد." />;
  return <div className={bank === 'locations' ? 'max-w-5xl' : 'max-w-2xl'}><PageHeader title={`${id ? 'ویرایش' : 'ساخت'} ${title(bank)}`} description={bank === 'locations' ? 'انتخاب این مکان در سازنده، یک کپی مستقل از مختصات می‌سازد.' : 'هویت امن توسط سرور مدیریت می‌شود؛ کد عملیاتی یک برچسب قابل ویرایش است.'} />{bank === 'qr-bank' && id && <QrPrintable id={id} available={item.data?.printable_available === true} />}<form className="nexo-surface space-y-5 rounded-xl p-6" onSubmit={(event) => { event.preventDefault(); save.mutate(); }}>{!id && <label className="block">سازمان<select required className="nexo-input mt-2" value={String(draft.organization_id ?? '')} onChange={(event) => setDraft({ ...draft, organization_id: event.target.value })}><option value="">انتخاب سازمان</option>{organizations.data?.map((organization) => <option key={organization.id} value={organization.id}>{organization.name ?? organization.title}</option>)}</select></label>}{bank === 'locations' && <LocationPicker latitude={draft.latitude} longitude={draft.longitude} radius={draft.default_radius_m} onChange={(point) => setDraft(current => ({ ...current, ...point }))} />}{fields.map((field) => <label key={field.key} className="block">{field.label}{field.optional ? ' (اختیاری)' : ''}<input className="nexo-input mt-2" required={!field.optional} maxLength={field.key === 'name' ? 255 : ['label', 'category'].includes(field.key) ? 100 : 2000} type={'min' in field ? 'number' : 'text'} step={field.key === 'dwell_seconds' ? 1 : 'any'} min={'min' in field ? field.min : undefined} max={'max' in field ? field.max : undefined} value={String(draft[field.key] ?? '')} onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })} /></label>)}<label className="block">وضعیت<select className="nexo-input mt-2" value={String(draft.status)} onChange={(event) => setDraft({ ...draft, status: event.target.value })}><option value="ACTIVE">فعال</option><option value="INACTIVE">غیرفعال</option></select></label>{save.isError && <p role="alert" className="text-[var(--nexo-danger)]">{save.error.message}</p>}{save.isSuccess && <p role="status">ذخیره شد.</p>}<div className="flex gap-3"><Button type="submit" disabled={save.isPending}>{save.isPending ? 'در حال ذخیره…' : 'ذخیره'}</Button><Button variant="ghost" href={`/control/content/${bank}`}>بازگشت</Button></div></form></div>;
}
