'use client';
import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { contentApi } from '@/lib/content-api';
import { filterLocations, mapLocations } from '@/lib/gis/domain';
import { Button, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import { LazyMap } from './lazy-map';

export function LocationBank() {
  const query = useQuery({ queryKey: ['content-bank', 'locations'], queryFn: () => contentApi.locations() });
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('ALL');
  const [category, setCategory] = useState('');
  const [selectedId, setSelectedId] = useState<string>();
  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState message="بارگذاری بانک انجام نشد." onRetry={() => void query.refetch()} />;
  const items = filterLocations(query.data, search, status, category);
  const markers = mapLocations(items);
  const selected = items.find(item => item.id === selectedId);
  return <>
    <PageHeader title="بانک موقعیت‌ها" description="موقعیت‌های ذخیره‌شده؛ انتخاب از فهرست یا نقشه" actions={<Button href="/control/content/locations/new">موقعیت جدید</Button>} />
    <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(260px,340px)_minmax(0,1fr)]">
      <section className="nexo-surface space-y-4 rounded-xl p-4" aria-label="فهرست موقعیت‌ها">
        <input aria-label="جستجو در بانک مکان" className="nexo-input" placeholder="نام، توضیح یا دسته…" value={search} onChange={event => setSearch(event.target.value)} />
        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">وضعیت<select className="nexo-input mt-2" value={status} onChange={event => setStatus(event.target.value)}><option value="ALL">همه</option><option value="ACTIVE">فعال</option><option value="INACTIVE">غیرفعال</option></select></label>
          <label className="text-sm">دسته<select className="nexo-input mt-2" value={category} onChange={event => setCategory(event.target.value)}><option value="">همه دسته‌ها</option>{Array.from(new Set(query.data.map(item => item.category).filter(Boolean))).sort().map(value => <option key={value}>{value}</option>)}</select></label>
        </div>
        <p className="text-xs text-[var(--nexo-muted)]">{items.length} موقعیت • {markers.length} نشانگر قابل نمایش</p>
        <div className="max-h-[32rem] space-y-3 overflow-y-auto">
          {items.map(item => <article key={item.id} className={`rounded-lg border p-3 ${selected?.id === item.id ? 'border-[var(--nexo-amber)]' : 'border-[var(--nexo-border)]'}`}>
            <button type="button" aria-pressed={selected?.id === item.id} className="min-h-11 w-full text-right" onClick={() => setSelectedId(item.id)}>
              <span className="flex justify-between gap-2"><strong>{item.name}</strong><StatusBadge status={item.status} /></span>
              <bdi className="mt-2 block text-xs">{item.latitude}, {item.longitude} • {item.default_radius_m} m</bdi>
              {!markers.some(marker => marker.id === item.id) && <span className="block text-xs text-[var(--nexo-warning)]">مختصات نامعتبر یا خارج از پوشش نقشه</span>}
            </button>
            <Link className="mt-2 inline-block p-2 text-sm text-[var(--nexo-amber)]" href={`/control/content/locations/${item.id}`}>ویرایش موقعیت</Link>
          </article>)}
          {!items.length && <p role="status">{query.data.length ? 'موردی با این فیلتر پیدا نشد.' : 'هنوز موقعیتی ثبت نشده است.'}</p>}
        </div>
      </section>
      <div className="min-w-0 space-y-4">
        <LazyMap locations={markers} selectedId={selected?.id} onSelect={setSelectedId} />
        {selected && <div className="nexo-surface space-y-2 rounded-xl p-4"><strong>{selected.name}</strong><p dir="ltr">{selected.latitude}, {selected.longitude} • {selected.default_radius_m} m</p><p className="text-sm">{selected.description}</p><button type="button" className="nexo-input !w-auto" onClick={() => setSelectedId(undefined)}>پاک کردن انتخاب</button></div>}
        <p className="text-xs leading-6 text-[var(--nexo-muted)]">لایه فعال: موقعیت‌های ذخیره‌شده • سبز: فعال • خاکستری: غیرفعال • طلایی: انتخاب‌شده</p>
      </div>
    </div>
  </>;
}
