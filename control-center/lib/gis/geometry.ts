import { circular } from 'ol/geom/Polygon';
import { locationPoint, renderable, type LocationPoint } from './domain';

export function radiusGeometry(point: LocationPoint) {
  if (!locationPoint(point.latitude, point.longitude, point.radius_m) || !renderable(point)) return undefined;
  const polygon = circular([point.longitude, point.latitude], point.radius_m, 96);
  const ring = polygon.getCoordinates()[0];
  if (ring.some(([, latitude]) => !renderable({ latitude, longitude: point.longitude }))) return undefined;
  // Keep the ring contiguous across the antimeridian before projecting.
  polygon.setCoordinates([ring.map(([lon, lat]) => [point.longitude + ((lon - point.longitude + 540) % 360 - 180), lat])]);
  return polygon.transform('EPSG:4326', 'EPSG:3857');
}
