import { requestStatusLabel } from '@/lib/request-status';
import { CircleAlert, ArrowUpRight } from 'lucide-react';
import type { DuplicateBill } from '@/lib/auth';

export default function DuplicateBills({ matches }: { matches: DuplicateBill[] }) {
  if (!matches.length) return null;
  return <aside className="duplicate-bill-warning" role="alert"><div className="duplicate-bill-heading"><CircleAlert size={20} /><div><strong>Possible duplicate bill</strong><p>These records have the same store, provider and billing month. Missing provider details are treated as a possible match. Review and correct the billing details or cancel the duplicate before processing.</p></div></div><ul>{matches.map(match => <li key={match.id}><a href={match.detail_url} target="_blank" rel="noreferrer"><span><strong>#{String(match.id).padStart(5, '0')} ? {match.store_name}</strong><small>{match.provider || 'Provider not recorded'} ? {match.billing_period} ? {requestStatusLabel(match.status)}</small></span><span>{new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(match.amount))}<ArrowUpRight size={14} /></span></a></li>)}</ul></aside>;
}
