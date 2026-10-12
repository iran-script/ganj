export type Checkpoint = { id: string; organization_id: string; name: string; type: 'POINT' | 'ZONE'; geom?: { type: string; coordinates: unknown } | null; radius_meters?: number | null; config?: Record<string, unknown>; status: 'ACTIVE' | 'INACTIVE' | 'ARCHIVED' };
export type QrTrigger = { id: string; organization_id: string; status: 'ACTIVE' | 'INACTIVE' | 'REVOKED'; metadata?: Record<string, unknown>; checkpoint?: { id: string; name: string; status: string } | null; created_at?: string; updated_at?: string };
export type QrCreateResult = { trigger: QrTrigger; identifier: string };
