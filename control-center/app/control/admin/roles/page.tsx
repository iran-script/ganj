import { OfficeUnavailablePage } from '@/components/office-unavailable';

export default function AdminRolesPage() {
  return <OfficeUnavailablePage eyebrow="ADMIN / ROLES" title="نقش‌ها و مجوزها" description="نمایش read-only نقش‌ها و مرزهای دسترسی." feature="ماتریس نقش‌ها" detail="مدل مجوز backend مرجع نهایی است و endpoint policy-aware برای نمایش نقش‌ها در Control Center هنوز ارائه نشده است." />;
}
