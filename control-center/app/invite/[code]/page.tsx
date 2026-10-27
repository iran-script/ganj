export const metadata = { title: 'دعوت تیم NEXO', robots: { index: false, follow: false } };

export default async function InviteHelpPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const validInvite = /^[A-Za-z0-9_-]{16,64}$/.test(code);
  return <main className="nexo-grid flex min-h-screen items-center justify-center p-6">
    <section className="nexo-surface w-full max-w-lg rounded-2xl p-8 text-center">
      <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--nexo-amber)] text-xl font-black text-[#17120b]">N</div>
      <h1 className="text-xl font-bold">دعوت تیم در اپلیکیشن NEXO باز می‌شود</h1>
      <p className="mt-3 text-sm leading-7 text-[var(--nexo-muted)]">این پیوند را در اپ NEXO باز کنید. برای پیوستن به تیم، ابتدا در اپلیکیشن بازیکن وارد شوید.</p>
      {validInvite && <a href={`nexo://invite/${encodeURIComponent(code)}`} className="mt-6 inline-flex rounded-lg bg-[var(--nexo-amber)] px-5 py-3 font-bold text-[#17120b]">باز کردن NEXO</a>}
    </section>
  </main>;
}
