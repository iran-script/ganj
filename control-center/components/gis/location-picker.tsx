'use client';
import { useState } from 'react';
import { MapDialog } from './map-dialog';
import { LazyMap } from './lazy-map';
import { locationPoint, renderable, type Position } from '@/lib/gis/domain';

export function LocationPicker({ latitude, longitude, radius, onChange, readOnly = false }: { latitude: unknown; longitude: unknown; radius: unknown; onChange: (point: Position) => void; readOnly?: boolean }) {
  const [expanded, setExpanded] = useState(false);
  const [large, setLarge] = useState(false);
  const point = locationPoint(latitude, longitude, radius);
  const valid = point && renderable(point);
  const mapProps = { locations: valid ? [{ ...point, id: 'draft', name: 'موقعیت انتخاب‌شده', status: 'ACTIVE' }] : [], selectedId: 'draft', editable: !readOnly, onMove: onChange };
  return <div className="space-y-3 rounded-xl border border-[var(--nexo-border)] p-3">
    <button type="button" aria-expanded={expanded} className="nexo-input min-h-11" onClick={() => setExpanded(value => !value)}>{expanded ? 'بستن نقشه' : 'انتخاب روی نقشه'}</button>
    <p className="text-xs leading-6 text-[var(--nexo-muted)]">مختصات و شعاع بر حسب متر ذخیره می‌شوند؛ نقشه جایگزین اعتبارسنجی سرور نیست.</p>
    {point && <output dir="ltr" className="block text-sm">{point.latitude.toFixed(6)}, {point.longitude.toFixed(6)} • {point.radius_m} m</output>}
    {!point && <p className="text-sm text-[var(--nexo-warning)]">مختصات معتبر و شعاع مثبت وارد کنید؛ یا با شعاع معتبر روی نقشه نقطه بگذارید.</p>}
    {point && !valid && <p role="status" className="text-sm text-[var(--nexo-warning)]">این عرض جغرافیایی خارج از پوشش نقشه مرکاتور است؛ ورود دستی حفظ می‌شود.</p>}
    {expanded && <button type="button" className="nexo-input min-h-11" onClick={() => setLarge(true)}>نقشه بزرگ</button>}
    {large && <MapDialog onClose={() => setLarge(false)} {...mapProps} />}
    {expanded && !large && <LazyMap {...mapProps} />}
  </div>;
}