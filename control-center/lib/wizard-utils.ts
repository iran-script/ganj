export const GAME_WIZARD_STEPS = ['اطلاعات پایه', 'سناریو', 'قیمت‌گذاری', 'تنظیمات بازی', 'مرور و ثبت'] as const;
export const SESSION_WIZARD_STEPS = ['انتخاب بازی', 'زمان‌بندی', 'ثبت‌نام', 'قوانین تیم', 'مرور و ایجاد'] as const;

export function pricingLabel(mode: 'FREE' | 'PAID'): string {
  return mode === 'PAID' ? 'پولی' : 'رایگان';
}

export function isTeamEligible(memberCount: number): boolean {
  return memberCount >= 4 && memberCount <= 8;
}

export function hasPublishedScenarioVersion(statuses: string[]): boolean {
  return statuses.some((status) => status === 'PUBLISHED');
}
