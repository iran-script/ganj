import Link from 'next/link';
import { Image, MapPin, MessageSquareText, QrCode } from 'lucide-react';
import { PageHeader } from '@/components/ui';
const cards = [
  { href: '/control/content/locations', title: 'بانک موقعیت‌ها', description: 'تعریف و مدیریت نقاط GPS و سیاست‌های مکانی.', icon: MapPin, tone: 'text-[var(--nexo-amber)]' },
  { href: '/control/content/qr-bank', title: 'بانک QR', description: 'مدیریت QRهای قابل استفاده مجدد و وضعیت آن‌ها.', icon: QrCode, tone: 'text-[var(--nexo-info)]' },
  { href: '/control/content/media', title: 'رسانه‌ها', description: 'مدیریت فایل‌های مورد استفاده در پیام‌ها و سناریوها.', icon: Image, tone: 'text-[var(--nexo-info)]' },
  { href: '/control/content/puzzles', title: 'محتوای معما', description: 'وضعیت محتوای معما و قابلیت‌های در دسترس.', icon: MessageSquareText, tone: 'text-[var(--nexo-muted)]' },
  { href: '/control/messages', title: 'پیام‌ها', description: 'پیام‌های عملیاتی سازمان.', icon: MessageSquareText, tone: 'text-[var(--nexo-muted)]' },
];

export default function ContentPage() {
  return <><PageHeader eyebrow="CONTENT OPERATIONS" title="محتوا و منابع" description="زیرساخت‌های قابل استفاده در سناریوهای سازمان" /><div className="grid gap-4 md:grid-cols-2">{cards.map(({ href, title, description, icon: Icon, tone }) => <Link key={title} href={href ?? '#'} className="nexo-surface nexo-hover rounded-xl p-6"><Icon size={22} className={tone} /><h2 className="mt-4 text-lg font-bold">{title}</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">{description}</p><span className="mt-5 inline-flex text-xs text-[var(--nexo-amber)]">مشاهده مدیریت ←</span></Link>)}</div></>;
}
