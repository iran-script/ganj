'use client';

import 'ol/ol.css';
import { useEffect, useRef, useState } from 'react';
import { createMapController, type MapState } from './map-controller';
import type { Position } from '@/lib/gis/domain';

export type NexoMapProps = MapState & { onSelect?: (id: string) => void; onMove?: (point: Position) => void };
export default function NexoMap(props: NexoMapProps) {
  const target = useRef<HTMLDivElement>(null);
  const controller = useRef<ReturnType<typeof createMapController> | null>(null);
  const latest = useRef(props);
  const [failure, setFailure] = useState('');
  useEffect(() => { latest.current = props; controller.current?.update(props); }, [props]);
  useEffect(() => {
    try {
      controller.current = createMapController(target.current!, {
        select: id => latest.current.onSelect?.(id),
        move: point => latest.current.onMove?.(point),
        tileError: () => setFailure('نقشه زمینه بارگذاری نشد؛ مختصات و ورود دستی همچنان در دسترس است.'),
      });
      controller.current.update(latest.current);
    } catch {
      setFailure('نقشه در دسترس نیست؛ از ورود دستی مختصات استفاده کنید.');
    }
    return () => { controller.current?.dispose(); controller.current = null; };
  }, []);
  return <section className="space-y-2" aria-label="نقشه موقعیت‌ها">
    {failure && <p role="status" className="text-sm text-[var(--nexo-warning)]">{failure}</p>}
    <div className="flex items-center justify-between gap-3 text-sm">
      <span>انتخاب موقعیت {props.editable ? 'با کلیک یا جابه‌جایی نشانگر' : 'از فهرست یا نقشه'}</span>
      <button type="button" className="nexo-input !w-auto min-h-11" onClick={() => controller.current?.fit()}>نمایش همه</button>
    </div>
    <div ref={target} dir="ltr" tabIndex={0} aria-label="نقشه تعاملی" className="nexo-gis-map h-80 min-w-0 overflow-hidden rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] sm:h-96" />
  </section>;
}
