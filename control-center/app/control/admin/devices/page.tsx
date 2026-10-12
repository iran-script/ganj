'use client';

import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function AdminDevicesPage() {
  const devices = useQuery(queries.devices());
  if (devices.isPending) return <LoadingState label="در حال دریافت دستگاه‌ها..." />;
  if (devices.isError) return <ErrorState message="اطلاعات دستگاه‌ها در دسترس نیست." onRetry={() => void devices.refetch()} />;
  return <><PageHeader eyebrow="ADMIN / DEVICES" title="دستگاه‌ها" description="دستگاه‌های قابل مشاهده برای اپراتور فعلی." />{devices.data.length === 0 ? <EmptyState title="دستگاهی ثبت نشده است" description="این بخش فقط داده واقعی endpoint دستگاه‌ها را نمایش می‌دهد." /> : <DataTable headers={['شناسه', 'کاربر', 'پلتفرم', 'وضعیت', 'آخرین مشاهده', 'آمادگی']}><>{devices.data.map((device) => <tr key={device.id} className="hover:bg-white/[2%]"><td className="px-5 py-4 font-medium">{device.public_id ?? device.name ?? device.id}</td><td className="px-5 py-4 text-[var(--nexo-muted)]">{device.user?.name ?? '—'}</td><td className="px-5 py-4 text-[var(--nexo-muted)]">{device.platform ?? '—'}</td><td className="px-5 py-4"><StatusBadge status={device.status ?? 'UNKNOWN'} /></td><td className="px-5 py-4 text-xs text-[var(--nexo-muted)]">{device.last_seen_at ? new Date(device.last_seen_at).toLocaleString('fa-IR') : 'ثبت نشده'}</td><td className="px-5 py-4 text-[var(--nexo-muted)]">{device.readiness ?? '—'}</td></tr>)}</></DataTable>}</>;
}
