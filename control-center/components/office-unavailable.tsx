import { EmptyState, PageHeader } from '@/components/ui';
import { InlineNotice } from '@/components/wizard';

export function OfficeUnavailablePage({ eyebrow, title, description, feature, detail }: { eyebrow: string; title: string; description: string; feature: string; detail: string }) {
  return <><PageHeader eyebrow={eyebrow} title={title} description={description} /><InlineNotice tone="info">این صفحه به‌عنوان foundation فرانت‌اند آماده است و از داده ساختگی استفاده نمی‌کند.</InlineNotice><div className="mt-5"><EmptyState title={`${feature} در دسترس نیست`} description={detail} /></div></>;
}
