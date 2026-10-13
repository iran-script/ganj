'use client';

import Image from 'next/image';
import { useEffect, useState } from 'react';
import { fetchContentBlob, type MediaItem } from '@/lib/content-api';

export function SecureMediaPreview({ asset, path }: { asset: MediaItem; path: string }) {
  const [url, setUrl] = useState(''); const [error, setError] = useState('');
  const image = ['image/jpeg', 'image/png'].includes(asset.mime_type);
  const audio = ['audio/wav', 'audio/x-wav', 'audio/mpeg'].includes(asset.mime_type);
  useEffect(() => {
    if (!image && !audio) return;
    let disposed = false; let objectUrl = '';
    void fetchContentBlob(path).then((blob) => { if (!disposed) { objectUrl = URL.createObjectURL(blob); setUrl(objectUrl); } }).catch(() => { if (!disposed) setError('پیش‌نمایش دریافت نشد.'); });
    return () => { disposed = true; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [path, image, audio]);
  if (error) return <p role="alert">{error}</p>;
  if (!image && !audio) return <p className="text-sm text-[var(--nexo-muted)]">{asset.name} • {asset.mime_type} • {(asset.size_bytes / 1024).toFixed(0)}KB</p>;
  if (!url) return <p role="status">در حال دریافت پیش‌نمایش…</p>;
  return image ? <Image unoptimized src={url} alt={asset.name} width={600} height={400} className="max-h-80 w-full rounded-lg object-contain" /> : <audio controls src={url} aria-label={asset.name} className="w-full" />;
}
