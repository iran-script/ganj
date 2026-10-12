'use client';

import { useParams } from 'next/navigation';
import { EmptyState, PageHeader } from '@/components/ui';
import { InlineNotice } from '@/components/wizard';

export default function TeamReportDetailPage() {
  const { id } = useParams<{ id: string }>();
  return <><PageHeader eyebrow="REPORTS / TEAM DETAIL" title="گزارش تیم" description={`شناسه تیم: ${id}`} /><InlineNotice tone="info">گزارش مستقل تیم هنوز API رسمی ندارد؛ این صفحه به‌عنوان مسیر و جایگاه تجربه کاربری آماده است.</InlineNotice><div className="mt-5"><EmptyState title="جزئیات مستقل تیم در دسترس نیست" description="پس از ارائه endpoint گزارش تیم، اعضا، پیشرفت، timeline و نتیجه نهایی اینجا نمایش داده می‌شود." /></div></>;
}
