import { afterEach, expect, it, vi } from 'vitest';
import type Translate from 'ol/interaction/Translate';
import type VectorLayer from 'ol/layer/Vector';
import type VectorSource from 'ol/source/Vector';
import type Feature from 'ol/Feature';
import type Point from 'ol/geom/Point';
import Collection from 'ol/Collection';
import { createMapController } from '@/components/gis/map-controller';
import { toMapCoordinate } from '@/lib/gis/projection';

const harness = vi.hoisted(() => ({ dispatch: (_event: unknown) => {}, layers: [] as VectorLayer<VectorSource>[], interaction: undefined as Translate | undefined, fit: vi.fn(), dispose: vi.fn(), disconnect: vi.fn(), setTarget: vi.fn() }));
vi.mock('ol/Map', async () => {
  const { default: Observable } = await import('ol/Observable');
  return { default: class extends Observable {
    constructor(options: { layers: VectorLayer<VectorSource>[] }) { super(); harness.layers = options.layers; harness.dispatch = event => this.dispatchEvent(event as never); }
    getView() { return { fit: harness.fit, setCenter: vi.fn() }; }
    addInteraction(value: Translate) { harness.interaction = value; }
    removeInteraction() {}
    updateSize() {}
    setTarget = harness.setTarget;
    dispose = harness.dispose;
    forEachFeatureAtPixel() { return harness.layers[2].getSource()!.getFeatures()[0]; }
  } };
});
afterEach(() => { vi.unstubAllGlobals(); vi.clearAllMocks(); });
function mount() {
  vi.stubGlobal('ResizeObserver', class { observe() {} disconnect = harness.disconnect; });
  const callbacks = { select: vi.fn(), move: vi.fn(), tileError: vi.fn() };
  const controller = createMapController(document.createElement('div'), callbacks);
  controller.update({ locations: [{ id: 'a', name: 'A', status: 'ACTIVE', latitude: 35, longitude: 51, radius_m: 20 }], selectedId: 'a', editable: true });
  return { controller, callbacks };
}
it('converts actual map click and marker drag events to canonical form coordinates', () => {
  const { controller, callbacks } = mount();
  harness.dispatch({ type: 'singleclick', coordinate: toMapCoordinate({ latitude: 36, longitude: 52 }), pixel: [10, 10] });
  expect(callbacks.move).toHaveBeenLastCalledWith(expect.objectContaining({ latitude: expect.closeTo(36, 6), longitude: expect.closeTo(52, 6) }));
  const marker = harness.layers[2].getSource()!.getFeatures()[0] as Feature<Point>;
  marker.getGeometry()!.setCoordinates(toMapCoordinate({ latitude: 37, longitude: 53 })!);
  harness.interaction!.dispatchEvent({ type: 'translateend', features: new Collection([marker]) } as never);
  expect(callbacks.move).toHaveBeenLastCalledWith(expect.objectContaining({ latitude: expect.closeTo(37, 6), longitude: expect.closeTo(53, 6) }));
  controller.dispose();
  expect(harness.disconnect).toHaveBeenCalled();
  expect(harness.setTarget).toHaveBeenCalledWith(undefined);
  expect(harness.dispose).toHaveBeenCalled();
});
it('selects a marker in browse mode and disables dragging', () => {
  const { controller, callbacks } = mount();
  controller.update({ locations: [{ id: 'b', name: 'B', status: 'INACTIVE', latitude: 35, longitude: 51, radius_m: 40 }] });
  harness.dispatch({ type: 'singleclick', pixel: [10, 10] });
  expect(callbacks.select).toHaveBeenCalledWith('b');
  expect(callbacks.move).not.toHaveBeenCalled();
  expect(harness.interaction!.getActive()).toBe(false);
  controller.dispose();
});
