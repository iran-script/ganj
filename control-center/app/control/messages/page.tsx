import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function MessagesPage() {
  return <OfficeUnavailablePage eyebrow="MESSAGES CENTER" title="مرکز پیام‌ها" description="پیام‌های GameMaster برای تیم‌ها و وضعیت ارسال." feature="تاریخچه پیام‌ها" detail="ارسال پیام GameMaster در API موجود است، اما endpoint تاریخچه پیام‌ها و وضعیت تحویل برای نمایش در مرکز پیام‌ها در دسترس نیست." />;
}
