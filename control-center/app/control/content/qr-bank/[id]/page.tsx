import { BankEditor } from '@/components/content-bank';
export default async function Page({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; return <BankEditor bank="qr-bank" id={id} />; }
