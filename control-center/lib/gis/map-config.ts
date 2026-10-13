import XYZ from 'ol/source/XYZ';
import OSM from 'ol/source/OSM';

// Replace this factory for WMTS/municipal/intranet sources; no component changes.
export function createBasemap() {
  const url = process.env.NEXT_PUBLIC_MAP_TILE_URL;
  return url ? new XYZ({ url, attributions: process.env.NEXT_PUBLIC_MAP_ATTRIBUTION ?? '', maxZoom: 19, crossOrigin: 'anonymous' }) : new OSM({ maxZoom: 19 });
}
export const initialCenter = { latitude: 35.6892, longitude: 51.389 };
