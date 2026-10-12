'use client';

import { useParams } from 'next/navigation';
import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function PlayerDetailPage() {
  const { id } = useParams<{ id: string }>();
  return <OfficeUnavailablePage eyebrow="PLAYER DETAIL" title="جزئیات بازیکن" description={`شناسه بازیکن: ${id}`} feature="پروفایل مستقل بازیکن" detail="برای نمایش امن نام، تیم، نقش، حضور، دستگاه و فعالیت، endpoint مستقل بازیکن لازم است." />;
}
