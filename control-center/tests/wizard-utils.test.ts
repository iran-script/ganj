import { describe, expect, it } from 'vitest';
import { GAME_WIZARD_STEPS, SESSION_WIZARD_STEPS, hasPublishedScenarioVersion, isTeamEligible, pricingLabel } from '@/lib/wizard-utils';

describe('wizard frontend logic', () => {
  it('keeps the approved step order', () => {
    expect(GAME_WIZARD_STEPS).toHaveLength(5);
    expect(SESSION_WIZARD_STEPS[0]).toBe('انتخاب بازی');
    expect(GAME_WIZARD_STEPS.at(-1)).toBe('مرور و ثبت');
  });

  it('presents pricing and published-version states without backend assumptions', () => {
    expect(pricingLabel('FREE')).toBe('رایگان');
    expect(pricingLabel('PAID')).toBe('پولی');
    expect(hasPublishedScenarioVersion(['DRAFT', 'PUBLISHED'])).toBe(true);
    expect(hasPublishedScenarioVersion(['DRAFT'])).toBe(false);
  });

  it('keeps team eligibility visible as the 4–8 member rule', () => {
    expect(isTeamEligible(4)).toBe(true);
    expect(isTeamEligible(8)).toBe(true);
    expect(isTeamEligible(3)).toBe(false);
    expect(isTeamEligible(9)).toBe(false);
  });
});
