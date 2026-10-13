'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';
import { contentApi, downloadContent } from '@/lib/content-api';
import { queries } from '@/lib/queries';
import { Button, ErrorState, LoadingState, PageHeader } from '@/components/ui';

export function MediaLibrary() {
  const client = useQueryClient();
  const media = useQuery({ queryKey: ['content-media'], queryFn: contentApi.media });
  const organizations = useQuery(queries.organizations());
  const [error, setError] = useState('');
  const upload = useMutation({ mutationFn: (body: FormData) => api.upload('/content/media', body), onSuccess: () => client.invalidateQueries({ queryKey: ['content-media'] }) });
  if (media.isPending || organizations.isPending) return <LoadingState />;
  if (media.isError || organizations.isError) return <ErrorState message="کتابخانه بارگذاری نشد." />;
  return <><PageHeader title="کتابخانه رسانه" description="هر فایل یک دارایی مستقل و تغییرناپذیر است. حداکثر حجم ۲۵ مگابایت." /><form className="nexo-surface mb-6 grid gap-4 rounded-xl p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); upload.mutate(new FormData(event.currentTarget)); }}><label>سازمان<select name="organization_id" required className="nexo-input mt-2"><option value="">انتخاب سازمان</option>{organizations.data.map((organization) => <option key={organization.id} value={organization.id}>{organization.name ?? organization.title}</option>)}</select></label><label>نام رسانه<input name="name" required maxLength={255} className="nexo-input mt-2" /></label><label>نوع رسانه<select name="kind" className="nexo-input mt-2"><option value="IMAGE">تصویر</option><option value="AUDIO">صدا</option><option value="FILE">فایل</option></select></label><label>فایل<input name="file" type="file" required accept="image/jpeg,image/png,audio/wav,audio/mpeg,application/pdf" className="nexo-input mt-2" /></label><Button type="submit" disabled={upload.isPending}>{upload.isPending ? 'در حال بارگذاری…' : 'بارگذاری رسانه'}</Button>{upload.isError && <p role="alert">{upload.error.message}</p>}{upload.isSuccess && <p role="status">رسانه ثبت شد.</p>}</form>{error && <p role="alert">{error}</p>}<div className="grid gap-4 md:grid-cols-3">{media.data.map((asset) => <article key={asset.id} className="nexo-surface rounded-xl p-5"><h2 className="font-bold">{asset.name}</h2><p className="my-3 text-xs text-[var(--nexo-muted)]">{asset.kind} • {(asset.size_bytes / 1024).toFixed(0)}KB</p><Button variant="secondary" onClick={() => void downloadContent(`/content/media/${asset.id}/download`, asset.name).catch((caught: Error) => setError(caught.message))}>دریافت فایل</Button></article>)}</div>{media.data.length === 0 && <p>هنوز رسانه‌ای ثبت نشده است.</p>}</>;
}
