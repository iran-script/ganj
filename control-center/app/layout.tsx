import type { Metadata } from 'next';
import './globals.css';
import { Providers } from '@/components/providers';

export const metadata: Metadata = { title: 'NEXO Control Center', description: 'مرکز کنترل عملیاتی NEXO' };

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="fa" dir="rtl"><head><script dangerouslySetInnerHTML={{ __html: `(function(){try{var t=localStorage.getItem('nexo-theme');if(t==='light'||t==='gaming-dark')document.documentElement.dataset.theme=t;}catch(e){}})()` }} /></head><body><Providers>{children}</Providers></body></html>;
}
