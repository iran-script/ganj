import { describe, expect, it } from 'vitest';
import { getDistance } from 'ol/sphere';
import { toLonLat } from 'ol/proj';
import { fromMapCoordinate, toMapCoordinate } from '@/lib/gis/projection';
import { radiusGeometry } from '@/lib/gis/geometry';
import { locationPoint, position, snapshotLocation, mapLocations } from '@/lib/gis/domain';
import type { LocationItem } from '@/lib/content-api';

describe('GIS coordinates and radius', () => {
  it('projects longitude first and round trips domain coordinates', () => {
    const coordinate = toMapCoordinate({ latitude: 35.6892, longitude: 51.389 })!;
    expect(coordinate[0]).toBeCloseTo(6378137 * 51.389 * Math.PI / 180, 0);
    const point = fromMapCoordinate(coordinate)!;
    expect(point.latitude).toBeCloseTo(35.6892, 7);
    expect(point.longitude).toBeCloseTo(51.389, 7);
    expect(toMapCoordinate({ latitude: 0, longitude: 0 })![0]).toBe(0);
  });
  it.each([['', 51, 20], [91, 51, 20], [35, 181, 20], [35, 51, 0], [35, 51, -2], [null, 51, 20], [false, 51, 20], [NaN, 51, 20], [35, 51, Infinity]])('rejects invalid domain point %s %s %s', (lat, lon, radius) => {
    expect(locationPoint(lat, lon, radius)).toBeUndefined();
  });
  it('retains valid polar domain coordinates without rendering beyond Mercator', () => {
    expect(position(90, 180)).toEqual({ latitude: 90, longitude: 180 });
    expect(toMapCoordinate({ latitude: 90, longitude: 180 })).toBeUndefined();
    expect(fromMapCoordinate([Infinity, 0])).toBeUndefined();
    expect(locationPoint('35.5', '51.5', '20')).toEqual({ latitude: 35.5, longitude: 51.5, radius_m: 20 });
  });
  it.each([0, 35.6892, 70])('keeps radius in real meters at latitude %s', latitude => {
    const point = { latitude, longitude: 51, radius_m: 150 };
    const ring = radiusGeometry(point)!.getCoordinates()[0];
    for (const coordinate of ring) expect(getDistance([51, latitude], toLonLat(coordinate))).toBeCloseTo(150, 4);
    const larger = radiusGeometry({ ...point, radius_m: 300 })!;
    expect(larger.getArea()).toBeGreaterThan(radiusGeometry(point)!.getArea() * 3.9);
  });
  it('keeps antimeridian circles contiguous', () => {
    const polygon = radiusGeometry({ latitude: 0, longitude: 179.999, radius_m: 1000 })!;
    const extent = polygon.getExtent();
    expect(extent[2] - extent[0]).toBeLessThan(2100);
  });
  it('copies saved values without any mutable location dependency', () => {
    const item: LocationItem = { id: 'saved', organization_id: 'org', name: 'Square', status: 'ACTIVE', latitude: 35, longitude: 51, default_radius_m: 20, accuracy_m: 5, dwell_seconds: 8, category: '', description: '' };
    const snapshot = snapshotLocation(item);
    item.latitude = 40; item.default_radius_m = 200;
    expect(snapshot).toEqual({ latitude: 35, longitude: 51, radius_m: 20, accuracy_m: 5, dwell_seconds: 8 });
    expect(() => snapshotLocation({ ...item, status: 'INACTIVE' })).toThrow();
    expect(mapLocations([{ ...item, latitude: NaN }])).toEqual([]);
  });
});
