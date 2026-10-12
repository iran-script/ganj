import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function AdminAuditPage() {
  return <OfficeUnavailablePage eyebrow="ADMIN / AUDIT" title="گزارش ممیزی" description="خط زمانی actor، action، entity و وضعیت عملیات." feature="فهرست Audit Log" detail="timeline جلسه‌ها موجود است، اما endpoint عمومی و صفحه‌بندی‌شده برای Audit Log مدیریتی در قرارداد frontend وجود ندارد." />;
}
