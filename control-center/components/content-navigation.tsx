import Link from 'next/link';
import { contentNavigation } from '@/lib/content-navigation';

export function ContentNavigation({ current }: { current?: string }) {
  return <nav aria-label="محتوا" className="flex flex-wrap gap-2">{contentNavigation.map(({ href, label }) => <Link key={href} href={href} aria-current={current?.startsWith(href) ? 'page' : undefined} className="rounded-lg border border-[var(--nexo-border)] px-3 py-3 text-sm text-[var(--nexo-muted)] hover:text-[var(--nexo-amber)] aria-[current=page]:text-[var(--nexo-amber)]">{label}</Link>)}</nav>;
}
