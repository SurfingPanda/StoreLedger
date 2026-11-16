import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, RefreshCw, Rows3, CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, LoaderCircle, Monitor, Plus, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';

type Renewal = { id: number; store_name: string; renewal_date: string; amount: string; status: string; detail_url: string; created_at: string };
type SortKey = 'id' | 'store_name' | 'renewal_date' | 'amount' | 'status' | 'created_at';
const sortLabels: Record<SortKey, string> = { id: 'Record', store_name: 'Store name', renewal_date: 'Renewal date', amount: 'Amount', status: 'Status', created_at: 'Date added' };
type Records = { data: Renewal[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const currency = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
function date(value: string) { return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }); }

export default function PosRenewals({ data }: { data: AppData }) {
  const accounting = data.user?.role === 'accounting';
  const finance = data.user?.role === 'finance';
  const [action, setAction] = useState<{ record: Renewal; kind: 'process' | 'cancel' } | null>(null);
  const [acting, setActing] = useState(false);
  const actingRef = useRef(false);
  const [actionError, setActionError] = useState('');
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saveError, setSaveError] = useState('');
  const [success, setSuccess] = useState('');
  const [records, setRecords] = useState<Records | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState(accounting ? 'awaiting_voucher' : finance ? 'vouchered' : 'all');
  const [sort, setSort] = useState<SortKey>('renewal_date');
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
  const [compact, setCompact] = useState(false);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const form = useRef<HTMLFormElement>(null);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setLoadError('');
    const url = new URL(data.urls.posRecords);
    url.searchParams.set('page', String(page)); url.searchParams.set('search', search); url.searchParams.set('status', statusFilter); url.searchParams.set('sort', sort); url.searchParams.set('direction', direction);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Refresh to sign in again.' : 'Could not load renewals. Please try again.'); return response.json() as Promise<Records>; })
      .then(result => setRecords(result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message || 'Could not load renewals.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.posRecords, page, search, statusFilter, sort, direction, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setSaveError(''); setErrors({});
    try {
      const values = Object.fromEntries(new FormData(event.currentTarget));
      const result = await post<{ message: string }>(data.urls.posRecords, values);
      setOpen(false); setSuccess(result.message); setPage(1); setSearch(''); setQuery(''); setStatusFilter('awaiting_voucher'); setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) {
        setErrors(error.errors);
        if (!Object.keys(error.errors).length) setSaveError(error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setSaveError('Could not connect. Your renewal has not been confirmed; please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  async function resolveRenewal() {
    if (!action || actingRef.current) return;
    actingRef.current = true; setActing(true); setActionError(''); setSuccess('');
    try {
      const result = await post<{ message: string }>(`${data.urls.posRecords}/${action.record.id}/voucher`, { action: action.kind });
      setSuccess(`${action.record.store_name}: ${result.message}`); setAction(null);
      if (records?.data.length === 1 && page > 1) setPage(value => value - 1);
      else setRevision(value => value + 1);
    } catch (error) {
      setActionError(error instanceof RequestError ? error.message : 'Could not confirm this action. Please try again.');
    } finally { actingRef.current = false; setActing(false); }
  }
  function changeSort(key: SortKey) {
    setDirection(sort === key && direction === 'asc' ? 'desc' : 'asc'); setSort(key); setPage(1);
  }
  function sortHeader(key: SortKey, amount = false) {
    const Icon = sort === key ? direction === 'asc' ? ArrowUp : ArrowDown : ArrowUpDown;
    return <th scope="col" className={`${amount ? 'amount-column' : ''} ${sort === key ? 'pos-sorted-column' : ''}`} aria-sort={sort === key ? direction === 'asc' ? 'ascending' : 'descending' : 'none'}><button type="button" className="pos-sort-button" onClick={() => changeSort(key)} aria-label={`Sort by ${sortLabels[key]}, ${sort === key && direction === 'asc' ? 'descending' : 'ascending'}`}>{sortLabels[key]}<Icon size={14} /></button></th>;
  }
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `${name}-error` : undefined });
  return <div className="pos-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">SUBSCRIPTIONS & RENEWALS</p><h1>{accounting ? 'UTAK POS voucher queue' : finance ? 'UTAK POS payment queue' : 'UTAK POS renewals'}</h1><p>{accounting ? 'New store renewals awaiting your voucher processing.' : finance ? 'Processed vouchers ready for payment. Record payment details and attach proof.' : 'Record store renewals and queue them for Accounting to prepare the voucher.'}</p></div>{data.user?.role === 'admin' && <Button onClick={() => { setErrors({}); setSaveError(''); setOpen(true); }}><Plus size={17} />Add renewal</Button>}</div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button aria-label="Dismiss confirmation" onClick={() => setSuccess('')}>Dismiss</button></div>}
    <div className="pos-workflow-note"><span className="pos-module-icon"><Monitor size={22} /></span><div><strong>{finance ? 'Vouchers are ready. Record the payment and its proof.' : 'From renewal to voucher, then payment.'}</strong><p>Accounting prepares the voucher. Finance records the payment with proof. Open each record to review its details, comments, and store history.</p></div><span className={`status-pill ${finance ? 'status-finance' : 'status-accounting'}`}>{finance ? 'Finance queue' : 'Accounting queue'}</span></div>
    <section className={`register-panel pos-register pos-renewal-register ${compact ? 'pos-compact' : ''}`}><div className="section-heading pos-register-heading"><div className="pos-register-title"><span className="pos-register-symbol"><ClipboardList size={21} /></span><div><h2>Renewal records {records && !loading && <span className="pos-total-badge">{records.total}</span>}</h2><p>Manage each store renewal from voucher to payment.</p></div></div><div className="pos-table-tools"><button type="button" onClick={() => setCompact(value => !value)} aria-pressed={compact} title={compact ? 'Use comfortable rows' : 'Use compact rows'}><Rows3 size={16} /><span>{compact ? 'Compact' : 'Comfortable'}</span></button><button type="button" disabled={loading} onClick={() => setRevision(value => value + 1)} aria-label="Refresh records" title="Refresh records"><RefreshCw size={16} className={loading ? 'animate-spin motion-reduce:animate-none' : ''} /></button></div></div><div className="pos-table-toolbar">
      <div className="pos-status-filter"><label htmlFor="pos-status">Status</label><select id="pos-status" value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1); setSuccess(''); }}><option value="all">All statuses</option><option value="awaiting_voucher">Pending voucher</option><option value="processed">Voucher processed</option><option value="vouchered">Awaiting payment</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select>{statusFilter === 'processed' && <span>Includes vouchers awaiting payment and already paid.</span>}</div>
      <form className="pos-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(query.trim()); }}><label><Search size={16} /><input aria-label="Search by store name" placeholder="Search store name…" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button variant="outline" size="sm" type="submit" disabled={loading}>Search</Button>{search && <button type="button" className="text-link" onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Clear</button>}</form></div>
      <div className="pos-sort-summary"><span><span className="pos-live-dot" />{loading ? 'Updating records...' : 'Saved renewal records'}</span><span>Sorted by <strong>{sortLabels[sort]}</strong> {direction === 'asc' ? 'ascending' : 'descending'}{(sort !== 'renewal_date' || direction !== 'asc') && <button onClick={() => { setSort('renewal_date'); setDirection('asc'); setPage(1); }}>Reset sort</button>}</span></div>
      {loadError ? <div className="pos-load-error" role="alert"><CircleAlert size={20} /><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" aria-busy={loading}>
        <table className="voucher-table pos-interactive-table"><caption className="sr-only">Renewal records. Select a column heading to change sorting.</caption><thead><tr>{sortHeader('id')}{sortHeader('store_name')}{sortHeader('renewal_date')}{sortHeader('amount', true)}{sortHeader('status')}{sortHeader('created_at')}{(accounting || finance) && <th>ACTIONS</th>}</tr></thead><tbody>{!loading && records?.data.map(record => <tr key={record.id}><td><a className="pos-record-id" href={record.detail_url}>POS-{String(record.id).padStart(5, '0')}</a></td><td><a className="pos-store-cell" href={record.detail_url}><span className="pos-store-avatar">{record.store_name.replace(/^BW\s+/i, '').slice(0, 2).toUpperCase()}</span><span><strong className="pos-store-name">{record.store_name}</strong><small>UTAK POS renewal</small></span><ChevronRight size={14} className="pos-store-arrow" /></a></td><td><span className="pos-date"><CalendarDays size={14} />{date(record.renewal_date)}</span></td><td className="amount-column">{currency(record.amount)}</td><td><span className={`status-pill ${record.status === 'awaiting_voucher' ? 'status-accounting' : record.status === 'vouchered' ? 'status-finance' : record.status === 'paid' ? 'status-paid' : 'status-review'}`}><i />{record.status === 'awaiting_voucher' ? 'Pending voucher' : record.status === 'vouchered' ? 'Awaiting payment' : record.status === 'paid' ? 'Paid' : record.status === 'cancelled' ? 'Cancelled' : record.status.replaceAll('_', ' ')}</span></td><td>{date(record.created_at)}</td>{accounting && <td>{record.status !== 'awaiting_voucher' ? <Button variant="outline" size="sm" onClick={() => window.location.assign(record.detail_url)}>View details</Button> : <div className="pos-row-actions"><Button size="sm" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Process renewal for ${record.store_name}`} onClick={() => window.location.assign(record.detail_url)}>Process</Button><Button size="sm" variant="outline" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Cancel renewal for ${record.store_name}`} onClick={() => { setActionError(''); setAction({ record, kind: 'cancel' }); }}>Cancel</Button></div>}</td>}{finance && <td><Button size="sm" onClick={() => window.location.assign(record.detail_url)}>{record.status === 'vouchered' ? 'Record payment' : 'View details'}</Button></td>}</tr>)}</tbody></table>
        {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" size={24} /><p>Loading renewals…</p></div> : !records?.data.length && <div className="register-empty"><ClipboardList size={30} strokeWidth={1.4} /><h3>No matching renewals</h3><p>Try another store name or choose a different status.</p></div>}
      </div>}
      <div className="table-footer"><span>{loading ? 'Loading records…' : loadError ? 'Records unavailable' : `Showing ${records?.from ?? 0}–${records?.to ?? 0} of ${records?.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous page" disabled={loading || !!loadError || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {records?.last_page ?? 1}</span><button aria-label="Next page" disabled={loading || !!loadError || page >= (records?.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
    <Dialog open={!!action} onOpenChange={value => { if (!value && !actingRef.current) setAction(null); }}><DialogContent><DialogTitle>{action?.kind === 'process' ? 'Process voucher' : 'Cancel renewal'}</DialogTitle><DialogDescription>{action?.kind === 'process' ? 'Confirm that the voucher has been processed for this renewal.' : 'Cancel this renewal and remove it from the pending voucher queue. The record will be kept.'}</DialogDescription>{action && <p className="pos-action-summary"><strong>{action.record.store_name}</strong><br />{date(action.record.renewal_date)} - {currency(action.record.amount)}</p>}{actionError && <div className="form-alert" role="alert">{actionError}</div>}<div className="pos-form-actions"><Button variant="outline" disabled={acting} onClick={() => setAction(null)}>Go back</Button><Button disabled={acting} onClick={resolveRenewal}>{acting ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving...</> : action?.kind === 'process' ? 'Confirm process' : 'Confirm cancellation'}</Button></div></DialogContent></Dialog>
    <Dialog open={open} onOpenChange={value => { if (!savingRef.current) setOpen(value); }}><DialogContent className="pos-add-dialog"><span className="dialog-symbol"><Monitor size={22} /></span><DialogTitle className="text-xl font-semibold">Add UTAK POS renewal</DialogTitle><DialogDescription className="text-sm text-muted-foreground">Enter the store’s renewal details. Accounting will prepare the voucher from this record.</DialogDescription><form ref={form} onSubmit={save} aria-busy={saving}>
      {saveError && <div className="form-alert" role="alert">{saveError}</div>}
      <div className="field-group"><label htmlFor="store_name">Store name</label><Input id="store_name" name="store_name" placeholder="e.g. BW Makati" required maxLength={150} autoComplete="organization" {...field('store_name')} />{errors.store_name && <p className="field-error" id="store_name-error" role="alert">{errors.store_name[0]}</p>}</div>
      <div className="field-group"><label htmlFor="renewal_date">Renewal date</label><Input id="renewal_date" name="renewal_date" type="date" required {...field('renewal_date')} />{errors.renewal_date && <p className="field-error" id="renewal_date-error" role="alert">{errors.renewal_date[0]}</p>}</div>
      <div className="field-group"><label htmlFor="amount">Amount (PHP)</label><Input id="amount" name="amount" type="number" inputMode="decimal" min="0.01" max="9999999999.99" step="0.01" placeholder="0.00" required {...field('amount')} />{errors.amount && <p className="field-error" id="amount-error" role="alert">{errors.amount[0]}</p>}</div>
      <p className="pos-save-hint"><ClipboardList size={15} />Initial status: Awaiting voucher · Accounting</p>
      <div className="pos-form-actions"><Button variant="outline" type="button" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={saving}>{saving ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving…</> : <><Plus size={16} />Save renewal</>}</Button></div>
    </form></DialogContent></Dialog>
  </div>;
}
