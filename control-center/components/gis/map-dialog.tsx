'use client';
import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import type { NexoMapProps } from './nexo-map';
import { LazyMap } from './lazy-map';

export function MapDialog({ onClose, ...props }: NexoMapProps & { onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => { const dialog = ref.current!; dialog.showModal(); return () => dialog.close(); }, []);
  return createPortal(<dialog ref={ref} onCancel={onClose} aria-label="انتخاب موقعیت روی نقشه بزرگ" className="m-auto w-[min(95vw,960px)] rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-5 text-[var(--nexo-text)] backdrop:bg-black/70">
    <div className="mb-4 flex items-center justify-between"><h2>انتخاب موقعیت</h2><button type="button" className="nexo-input !w-auto" onClick={onClose}>بستن نقشه بزرگ</button></div>
    <LazyMap {...props} />
  </dialog>, document.body);
}
