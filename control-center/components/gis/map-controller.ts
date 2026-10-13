import Map from 'ol/Map';
import View from 'ol/View';
import Feature from 'ol/Feature';
import Point from 'ol/geom/Point';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import Translate from 'ol/interaction/Translate';
import { unByKey } from 'ol/Observable';
import { isEmpty } from 'ol/extent';
import { createBasemap, initialCenter } from '@/lib/gis/map-config';
import { fromMapCoordinate, toMapCoordinate } from '@/lib/gis/projection';
import { radiusGeometry } from '@/lib/gis/geometry';
import type { MapLocation, Position } from '@/lib/gis/domain';
import { markerStyle, radiusStyle } from './location-marker';

export type MapState = { locations: MapLocation[]; selectedId?: string; editable?: boolean };
export type MapCallbacks = { select: (id: string) => void; move: (point: Position) => void; tileError: () => void };

export function createMapController(target: HTMLElement, callbacks: MapCallbacks) {
  const markers = new VectorSource({ wrapX: false });
  const radii = new VectorSource({ wrapX: false });
  const markerLayer = new VectorLayer({ source: markers, properties: { nexoLayer: 'saved-locations' } });
  const radiusLayer = new VectorLayer({ source: radii, style: radiusStyle, properties: { nexoLayer: 'objective-radius' } });
  const basemap = createBasemap();
  const map = new Map({ target, layers: [new TileLayer({ source: basemap }), radiusLayer, markerLayer], view: new View({ center: toMapCoordinate(initialCenter), zoom: 11, maxZoom: 20, multiWorld: false }) });
  const translate = new Translate({ layers: [markerLayer], hitTolerance: 8 });
  translate.setActive(false);
  map.addInteraction(translate);
  let current: MapState = { locations: [] };
  let focusKey = '';
  let initialized = false;
  const fit = () => {
    const extent = markers.getExtent();
    if (extent && !isEmpty(extent)) map.getView().fit(extent, { padding: [50, 50, 50, 50], maxZoom: 16 });
  };
  const keys = [
    basemap.on('tileloaderror', callbacks.tileError),
    map.on('singleclick', event => {
      if (current.editable) {
        const point = fromMapCoordinate(event.coordinate);
        if (point) callbacks.move(point);
      } else {
        const feature = map.forEachFeatureAtPixel(event.pixel, feature => feature, { layerFilter: layer => layer === markerLayer, hitTolerance: 8 });
        if (feature) callbacks.select(String(feature.getId()));
      }
    }),
    translate.on('translating', event => {
      const selected = current.locations.find(item => item.id === current.selectedId);
      const point = fromMapCoordinate((event.features.item(0).getGeometry() as Point).getCoordinates());
      radii.clear();
      const geometry = selected && point && radiusGeometry({ ...selected, ...point });
      if (geometry) radii.addFeature(new Feature(geometry));
    }),
    translate.on('translateend', event => {
      const point = fromMapCoordinate((event.features.item(0).getGeometry() as Point).getCoordinates());
      if (point && current.editable) callbacks.move(point);
      else update(current);
    }),
  ];
  function update(state: MapState) {
    current = state;
    markers.clear(); radii.clear();
    translate.setActive(Boolean(state.editable));
    for (const item of state.locations) {
      const coordinate = toMapCoordinate(item);
      if (!coordinate) continue;
      const marker = new Feature(new Point(coordinate));
      marker.setId(item.id);
      marker.setStyle(markerStyle(item.status === 'ACTIVE', item.id === state.selectedId));
      markers.addFeature(marker);
      if (item.id === state.selectedId) {
        const geometry = radiusGeometry(item);
        if (geometry) radii.addFeature(new Feature(geometry));
      }
    }
    const selected = state.locations.find(item => item.id === state.selectedId);
    const key = selected ? `${selected.id}:${selected.latitude}:${selected.longitude}` : '';
    if (selected && key !== focusKey) {
      const geometry = radiusGeometry(selected), center = toMapCoordinate(selected);
      if (geometry) map.getView().fit(geometry.getExtent(), { padding: [45, 45, 45, 45], maxZoom: 17 });
      else if (center) map.getView().setCenter(center);
    } else if (!initialized && markers.getFeatures().length > 0) fit();
    if (markers.getFeatures().length > 0) initialized = true;
    focusKey = key;
  }
  const observer = new ResizeObserver(() => map.updateSize());
  observer.observe(target);
  return { update, fit, dispose() { observer.disconnect(); unByKey(keys); map.removeInteraction(translate); map.setTarget(undefined); map.dispose(); markers.clear(); radii.clear(); } };
}
