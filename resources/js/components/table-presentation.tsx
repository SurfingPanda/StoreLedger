import type { ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export function TablePagination({ page, lastPage, total, from, to, disabled, onPage, label = 'records' }: { page: number; lastPage: number; total?: number; from?: number; to?: number; disabled?: boolean; onPage: (page: number) => void; label?: string }) {
  return <div className="table-footer"><span>{total !== undefined ? `Showing ${from ?? (total ? (page - 1) * 15 + 1 : 0)}-${to ?? Math.min(page * 15, total)} of ${total}` : `Page ${page} of ${lastPage}`}</span><nav className="pos-pagination" aria-label={`${label} pagination`}><button type="button" aria-label={`Previous ${label} page`} title="Previous page" disabled={disabled || page <= 1} onClick={() => onPage(page - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {lastPage}</span><button type="button" aria-label={`Next ${label} page`} title="Next page" disabled={disabled || page >= lastPage} onClick={() => onPage(page + 1)}><ChevronRight size={16} /></button></nav></div>;
}

export function TableIdentity({ name, subtitle, href }: { name: string; subtitle?: ReactNode; href?: string }) {
  const content = <><span className="pos-store-avatar" aria-hidden="true">{name.replace(/^BW\s+/i, '').slice(0, 2).toUpperCase()}</span><span><strong className="pos-store-name">{name}</strong>{subtitle && <small>{subtitle}</small>}</span></>;
  return href ? <a className="pos-store-cell ledger-identity" href={href}>{content}</a> : <div className="pos-store-cell ledger-identity">{content}</div>;
}
