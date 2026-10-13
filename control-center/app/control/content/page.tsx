import Link from 'next/link';
import { Image, MapPin, MessageSquareText, QrCode } from 'lucide-react';
import { PageHeader } from '@/components/ui';
const cards = [
  { href: '/control/content/locations', title: 'بانک مکان', description: 'تعریف و مدیریت نقاط GPS و سیاست‌های مکانی.', icon: MapPin, tone: 'text-[var(--nexo-amber)]' },
  { href: '/control/content/qr-bank', title: 'بانک QR', description: 'مدیریت QRهای قابل استفاده مجدد و وضعیت آن‌ها.', icon: QrCode, tone: 'text-[var(--nexo-info)]' },
  { href: '/control/content/media', title: 'رسانه و دارایی‌ها', description: 'مدیریت فایل‌های مورد استفاده در پیام‌ها و سناریوها.', icon: Image, tone: 'text-[var(--nexo-info)]' },
  { title: 'قالب پیام و معما', description: 'قالب‌های قابل استفاده مجدد؛ اتصال API در انتظار قرارداد محتواست.', icon: MessageSquareText, tone: 'text-[var(--nexo-muted)]', placeholder: true },
];

export default function ContentPage() {
  return <><PageHeader eyebrow="CONTENT OPERATIONS" title="محتوا و منابع" description="زیرساخت‌های قابل استفاده در سناریوهای سازمان" /><div className="grid gap-4 md:grid-cols-2">{cards.map(({ href, title, description, icon: Icon, tone, placeholder }) => placeholder ? <div key={title} className="nexo-surface rounded-xl p-6 opacity-80"><Icon size={22} className={tone} /><h2 className="mt-4 text-lg font-bold">{title}</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">{description}</p><span className="mt-5 inline-flex rounded-full bg-white/5 px-3 py-1 text-xs text-[var(--nexo-muted)]">در انتظار API</span></div> : <Link key={title} href={href ?? '#'} className="nexo-surface nexo-hover rounded-xl p-6"><Icon size={22} className={tone} /><h2 className="mt-4 text-lg font-bold">{title}</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">{description}</p><span className="mt-5 inline-flex text-xs text-[var(--nexo-amber)]">مشاهده مدیریت ←</span></Link>)}</div></>;
}
