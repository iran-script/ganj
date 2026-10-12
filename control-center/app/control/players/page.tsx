import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function PlayersPage() {
  return <OfficeUnavailablePage eyebrow="PLAYERS" title="بازیکنان" description="نمایش بازیکنان و وضعیت حضور آن‌ها در تیم‌ها." feature="فهرست مستقل بازیکنان" detail="در حال حاضر API مستقل برای فهرست بازیکنان Control Center وجود ندارد. داده بازیکنان فقط در payload بعضی جلسه‌ها قابل مشاهده است." />;
}
