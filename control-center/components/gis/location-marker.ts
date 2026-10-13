import { Fill, Stroke, Style, RegularShape } from 'ol/style';

export function markerStyle(active: boolean, selected: boolean) {
  return new Style({ zIndex: selected ? 3 : 2, image: new RegularShape({
    points: 4, angle: Math.PI / 4, radius: selected ? 12 : 9,
    fill: new Fill({ color: selected ? '#e6ad42' : active ? '#36b99d' : '#6b7280' }),
    stroke: new Stroke({ color: selected ? '#ffffff' : '#101923', width: selected ? 3 : 2 }),
  }) });
}
export const radiusStyle = new Style({ fill: new Fill({ color: 'rgba(230,173,66,0.12)' }), stroke: new Stroke({ color: '#e6ad42', width: 2, lineDash: [6, 4] }) });
