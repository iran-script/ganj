'use client';

import { useState } from 'react';
import { downloadContent } from '@/lib/content-api';
import { Button } from '@/components/ui';

export function QrPrintable({ id, available }: { id: string; available: boolean }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  return <section className="nexo-surface mb-5 space-y-3 rounded-xl p-5">
    <h2 className="font-bold">نسخه چاپی QR</h2>
    <p className="text-sm text-[var(--nexo-muted)]">فایل SVG را با زمینه سفید و حاشیه کامل چاپ کنید. این فایل امکان اسکن را فراهم می‌کند؛ آن را فقط در محل بازی نصب کنید.</p>
    {available ? <Button disabled={busy} onClick={async () => {
      setBusy(true); setMessage('');
      try { await downloadContent(`/content/qr-bank/${id}/printable`, `nexo-qr-${id}.svg`); setMessage('فایل چاپی دریافت شد.'); }
      catch { setMessage('دریافت QR انجام نشد. دسترسی و فعال بودن آن را بررسی کنید.'); }
      finally { setBusy(false); }
    }}>{busy ? 'در حال دریافت…' : 'دریافت SVG برای چاپ'}</Button> : <p className="text-sm">برای QR غیرفعال یا QR قدیمی فاقد نسخه چاپی، دریافت مجاز نیست. برای مورد قدیمی یک QR جدید بسازید.</p>}
    {message && <p role="status">{message}</p>}
  </section>;
}
