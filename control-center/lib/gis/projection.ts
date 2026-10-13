import { fromLonLat, toLonLat } from 'ol/proj';
import { position, renderable, type Position } from './domain';

export function toMapCoordinate(point: Position): number[] | undefined {
  return renderable(point) ? fromLonLat([point.longitude, point.latitude]) : undefined;
}
export function fromMapCoordinate(coordinate: number[]): Position | undefined {
  if (coordinate.length < 2 || !coordinate.every(Number.isFinite)) return undefined;
  const [longitude, latitude] = toLonLat(coordinate);
  const point = position(latitude, ((longitude + 180) % 360 + 360) % 360 - 180);
  return point && renderable(point) ? point : undefined;
}
