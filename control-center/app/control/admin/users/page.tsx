import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function AdminUsersPage() {
  return <OfficeUnavailablePage eyebrow="ADMIN / USERS" title="کاربران و اپراتورها" description="مدیریت و مشاهده کاربران سازمان." feature="فهرست کاربران" detail="فعلاً فقط endpoint احراز هویت کاربر جاری در دسترس است؛ فهرست سازمانی کاربران هنوز API اختصاصی ندارد." />;
}
