import type { LocationItem } from '@/lib/content-api';

export type LocationPoint = { latitude: number; longitude: number; radius_m: number };

function finiteNumber(value: unknown): number | undefined {
  if (value === '' || value === null || value === undefined) return undefined;
  const number = Number(value);
  return Number.isFinite(number) ? number : undefined;
}

export function locationPoint(latitude: unknown, longitude: unknown, radius: unknown): LocationPoint | undefined {
  const lat = finiteNumber(latitude);
  const lon = finiteNumber(longitude);
  const meters = finiteNumber(radius);
  if (lat === undefined || lon === undefined || meters === undefined || Math.abs(lat) > 90 || Math.abs(lon) > 180 || meters < 0.01 || meters > 100000) return undefined;
  return { latitude: lat, longitude: lon, radius_m: meters };
}

export function coordinatesFromMap(coordinates: number[]): Pick<LocationPoint, 'latitude' | 'longitude'> | undefined {
  const [longitude, latitude] = coordinates;
  if (!Number.isFinite(longitude) || !Number.isFinite(latitude) || Math.abs(latitude) > 90) return undefined;
  return { latitude, longitude: ((longitude + 180) % 360 + 360) % 360 - 180 };
}

export function snapshotLocation(location: LocationItem) {
  return { latitude: location.latitude, longitude: location.longitude, radius_m: location.default_radius_m, accuracy_m: location.accuracy_m ?? undefined, dwell_seconds: location.dwell_seconds ?? undefined };
}

export function filterLocations(locations: LocationItem[], search: string, status: string): LocationItem[] {
  const query = search.trim().toLocaleLowerCase('fa');
  return locations.filter((location) => (status === 'ALL' || location.status === status) && `${location.name} ${location.category ?? ''} ${location.description ?? ''}`.toLocaleLowerCase('fa').includes(query));
}
