import type { LocationItem } from '@/lib/content-api';

export type Position = { latitude: number; longitude: number };
export type LocationPoint = Position & { radius_m: number };
export type MapLocation = LocationPoint & { id: string; name: string; status: string };
export const MERCATOR_LIMIT = 85.0511287798066;

function number(value: unknown): number {
  return typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : NaN;
}
export function position(latitude: unknown, longitude: unknown): Position | undefined {
  const lat = number(latitude), lon = number(longitude);
  return Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 ? { latitude: lat, longitude: lon } : undefined;
}
export function locationPoint(latitude: unknown, longitude: unknown, radius: unknown): LocationPoint | undefined {
  const center = position(latitude, longitude), meters = number(radius);
  return center && Number.isFinite(meters) && meters >= 0.01 && meters <= 100000 ? { ...center, radius_m: meters } : undefined;
}
export function renderable(point: Position): boolean {
  return Boolean(position(point.latitude, point.longitude)) && Math.abs(point.latitude) <= MERCATOR_LIMIT;
}
export function snapshotLocation(item: LocationItem): LocationPoint & { accuracy_m?: number; dwell_seconds?: number } {
  const point = locationPoint(item.latitude, item.longitude, item.default_radius_m);
  if (!point || item.status !== 'ACTIVE') throw new Error('Location is invalid or inactive');
  return { ...point, accuracy_m: item.accuracy_m ?? undefined, dwell_seconds: item.dwell_seconds ?? undefined };
}
export function filterLocations(items: LocationItem[], search: string, status: string, category = '') {
  const query = search.trim().toLocaleLowerCase('fa');
  return items.filter(item => (status === 'ALL' || item.status === status) && (!category || item.category === category) && `${item.name} ${item.category} ${item.description}`.toLocaleLowerCase('fa').includes(query));
}
export function mapLocations(items: LocationItem[]): MapLocation[] {
  return items.flatMap(item => {
    const point = locationPoint(item.latitude, item.longitude, item.default_radius_m);
    return point && renderable(point) ? [{ ...point, id: item.id, name: item.name, status: item.status }] : [];
  });
}
