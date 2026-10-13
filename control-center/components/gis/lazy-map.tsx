'use client';
import dynamic from 'next/dynamic';
import { Component, type ReactNode } from 'react';
const Map = dynamic(() => import('./nexo-map'), { ssr: false, loading: () => <p role="status" className="min-h-80">در حال بارگذاری نقشه… ورود دستی در دسترس است.</p> });
class MapBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  render() { return this.state.failed ? <p role="status">نقشه در دسترس نیست؛ ورود دستی مختصات فعال است.</p> : this.props.children; }
}
export function LazyMap(props: import('./nexo-map').NexoMapProps) { return <MapBoundary><Map {...props} /></MapBoundary>; }
