'use client';
import { useState } from 'react';
import { filterLocations, locationPoint, snapshotLocation } from '@/lib/gis/domain';
import type { LocationItem } from '@/lib/content-api';
export function SavedLocationPicker({ items, disabled, onPick }: { items: LocationItem[]; disabled?: boolean; onPick: (snapshot: ReturnType<typeof snapshotLocation>) => void }) {
  const [search, setSearch] = useState('');
  const [inactive, setInactive] = useState(false);
  return <div className="space-y-2">
    <input aria-label="جستجو در بانک مکان" className="nexo-input" placeholder="جستجوی موقعیت…" value={search} onChange={event => setSearch(event.target.value)} />
    <label className="flex gap-2 text-sm"><input type="checkbox" checked={inactive} onChange={event => setInactive(event.target.checked)} />نمایش موقعیت‌های غیرفعال (غیرقابل انتخاب)</label>
    <select aria-label="انتخاب از نقاط ذخیره‌شده" className="nexo-input" disabled={disabled} value="" onChange={event => {
      const item = items.find(item => item.id === event.target.value);
      if (item?.status === 'ACTIVE' && locationPoint(item.latitude, item.longitude, item.default_radius_m)) onPick(snapshotLocation(item));
    }}>
      <option value="">انتخاب از بانک موقعیت‌ها (اختیاری)</option>
      {filterLocations(items, search, inactive ? 'ALL' : 'ACTIVE').map(item => <option key={item.id} value={item.id} disabled={item.status !== 'ACTIVE' || !locationPoint(item.latitude, item.longitude, item.default_radius_m)}>{item.name}</option>)}
    </select>
    <ul className="max-h-36 overflow-auto text-xs leading-6 text-[var(--nexo-muted)]">{filterLocations(items, search, inactive ? 'ALL' : 'ACTIVE').map(item => <li key={item.id}>{item.name} • <bdi>{item.latitude}, {item.longitude} • {item.default_radius_m} m</bdi> • {item.status === 'ACTIVE' ? 'فعال' : 'غیرفعال'} {item.category && `• ${item.category}`}</li>)}</ul>
    <p className="text-xs leading-6">انتخاب موقعیت فقط یک کپی مستقل می‌سازد؛ تغییر بانک، گره GPS را تغییر نمی‌دهد.</p>
  </div>;
}
