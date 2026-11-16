import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ArrowDown, ArrowUp, ArrowUpRight, Banknote, CreditCard, ArrowUpDown, RefreshCw, Rows3, CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, LoaderCircle, Monitor, Plus, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';
import { requestModule } from '@/lib/request-module';

type Renewal = { id: number; store_name: string; renewal_date: string; amount: string; status: string; detail_url: string; created_at: string; due_date?: string; attachment_url?: string; attachment_name?: string };
type SortKey = 'id' | 'store_name' | 'renewal_date' | 'amount' | 'status' | 'created_at';
const sortLabels: Record<SortKey, string> = { id: 'Record', store_name: 'Store name', renewal_date: 'Renewal date', amount: 'Amount', status: 'Status', created_at: 'Date added' };
type Summary = { pending_count: number; pending_amount: string; awaiting_payment_count: number; awaiting_payment_amount: string; paid_count: number; paid_amount: string; overdue_pending_count: number };
type Records = { summary: Summary; data: Renewal[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const currency = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
function date(value: string) { return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }); }

export default function PosRenewals({ data }: { data: AppData }) {
  const { isSoa, label: moduleLabel, prefix, recordsUrl } = requestModule(data);
  const labels = { ...sortLabels, renewal_date: isSoa ? 'Due date' : 'Renewal date' };
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
    const url = new URL(recordsUrl);
    url.searchParams.set('page', String(page)); url.searchParams.set('search', search); url.searchParams.set('status', statusFilter); url.searchParams.set('sort', isSoa && sort === 'renewal_date' ? 'due_date' : sort); url.searchParams.set('direction', direction);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Refresh to sign in again.' : 'Could not load requests. Please try again.'); return response.json() as Promise<Records>; })
      .then(result => setRecords(isSoa ? { ...result, data: result.data.map(record => ({ ...record, renewal_date: record.due_date! })) } : result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message || 'Could not load requests.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [recordsUrl, isSoa, page, search, statusFilter, sort, direction, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setSaveError(''); setErrors({});
    try {
      const values = new FormData(event.currentTarget);
      const result = await post<{ message: string }>(recordsUrl, values);
      setOpen(false); setSuccess(result.message); setPage(1); setSearch(''); setQuery(''); setStatusFilter('awaiting_voucher'); setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) {
        setErrors(isSoa && error.errors.due_date ? { ...error.errors, renewal_date: error.errors.due_date } : error.errors);
        if (!Object.keys(error.errors).length) setSaveError(error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setSaveError('Could not connect. Your request has not been confirmed; please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  async function resolveRenewal() {
    if (!action || actingRef.current) return;
    actingRef.current = true; setActing(true); setActionError(''); setSuccess('');
    try {
      const result = await post<{ message: string }>(`${recordsUrl}/${action.record.id}/voucher`, { action: action.kind });
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
    return <th scope="col" className={`${amount ? 'amount-column' : ''} ${sort === key ? 'pos-sorted-column' : ''}`} aria-sort={sort === key ? direction === 'asc' ? 'ascending' : 'descending' : 'none'}><button type="button" className="pos-sort-button" onClick={() => changeSort(key)} aria-label={`Sort by ${labels[key]}, ${sort === key && direction === 'asc' ? 'descending' : 'ascending'}`}>{labels[key]}<Icon size={14} /></button></th>;
  }
  function showSummaryStatus(status: string) {
    setStatusFilter(status); setSearch(''); setQuery(''); setPage(1);
    document.getElementById('renewal-register')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  const summary = records?.summary;
  const summaryCards = [
    { label: 'Pending vouchers', value: String(summary?.pending_count ?? 0), note: summary?.overdue_pending_count ? `${summary.overdue_pending_count} past due - needs attention` : 'Ready for Accounting to process', filter: 'awaiting_voucher', icon: ClipboardList, tone: 'orange', attention: !!summary?.overdue_pending_count },
    { label: 'Amount for vouchering', value: currency(summary?.pending_amount ?? '0'), note: 'Total value of pending vouchers', filter: 'awaiting_voucher', icon: Banknote, tone: 'blue', attention: false },
    { label: 'Awaiting Finance payment', value: String(summary?.awaiting_payment_count ?? 0), note: `${currency(summary?.awaiting_payment_amount ?? '0')} in processed vouchers`, filter: 'vouchered', icon: CreditCard, tone: 'violet', attention: false },
    { label: isSoa ? 'Paid SOAs' : 'Paid renewals', value: String(summary?.paid_count ?? 0), note: `${currency(summary?.paid_amount ?? '0')} paid in total`, filter: 'paid', icon: CheckCircle2, tone: 'green', attention: false },
  ];
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `${name}-error` : undefined });
  return <div className="pos-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">{isSoa ? `${moduleLabel.replace(' SOA', '').toUpperCase()} BILLING` : 'SUBSCRIPTIONS & RENEWALS'}</p><h1>{isSoa ? accounting ? `${moduleLabel} voucher queue` : finance ? `${moduleLabel} payment queue` : `${moduleLabel} Monitoring` : accounting ? 'UTAK POS voucher queue' : finance ? 'UTAK POS payment queue' : 'UTAK POS renewals'}</h1><p>{accounting ? 'Review pending requests and prepare their vouchers.' : finance ? 'Processed vouchers ready for payment. Record payment details and attach proof.' : isSoa ? 'Record store bills, attach the SOA, and track voucher and payment progress.' : 'Record store renewals and queue them for Accounting to prepare the voucher.'}</p></div>{data.user?.role === 'admin' && <Button onClick={() => { setErrors({}); setSaveError(''); setOpen(true); }}><Plus size={17} />{isSoa ? 'Add SOA' : 'Add renewal'}</Button>}</div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button aria-label="Dismiss confirmation" onClick={() => setSuccess('')}>Dismiss</button></div>}
    {accounting && <section className="accounting-overview" aria-label="Accounting request summary" aria-busy={loading}><div className="accounting-overview-label"><h2>Your Accounting overview</h2><span>All stores / totals across all statuses</span></div><div className="accounting-overview-grid">{summaryCards.map(({ label, value, note, filter, icon: Icon, tone, attention }) => <button key={label} type="button" className={`accounting-metric metric-${tone} ${attention ? 'metric-needs-attention' : ''}`} disabled={loading || !!loadError} onClick={() => showSummaryStatus(filter)} aria-label={`${label}: ${summary && !loadError ? value : 'unavailable'}. View matching requests.`}><div className="accounting-metric-top"><span>{label}</span><span className="accounting-metric-icon"><Icon size={19} /></span></div><strong>{loadError ? 'Unavailable' : !summary ? '--' : value}</strong><p>{loadError ? 'Refresh records to load totals' : !summary ? 'Loading totals...' : note}</p><div className="accounting-metric-footer">View records<ArrowUpRight size={14} /></div></button>)}</div></section>}
    <section id="renewal-register" className={`register-panel pos-register pos-renewal-register ${compact ? 'pos-compact' : ''}`}><div className="section-heading pos-register-heading"><div className="pos-register-title"><span className="pos-register-symbol"><ClipboardList size={21} /></span><div><h2>{isSoa ? `${moduleLabel} records` : 'Renewal records'} {records && !loading && <span className="pos-total-badge">{records.total}</span>}</h2><p>Track each request from voucher to payment.</p></div></div><div className="pos-table-tools"><button type="button" onClick={() => setCompact(value => !value)} aria-pressed={compact} title={compact ? 'Use comfortable rows' : 'Use compact rows'}><Rows3 size={16} /><span>{compact ? 'Compact' : 'Comfortable'}</span></button><button type="button" disabled={loading} onClick={() => setRevision(value => value + 1)} aria-label="Refresh records" title="Refresh records"><RefreshCw size={16} className={loading ? 'animate-spin motion-reduce:animate-none' : ''} /></button></div></div><div className="pos-table-toolbar">
      <div className="pos-status-filter"><label htmlFor="pos-status">Status</label><select id="pos-status" value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1); setSuccess(''); }}><option value="all">All statuses</option><option value="awaiting_voucher">Pending voucher</option><option value="processed">Voucher processed</option><option value="vouchered">Awaiting payment</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select>{statusFilter === 'processed' && <span>Includes vouchers awaiting payment and already paid.</span>}</div>
      <form className="pos-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(query.trim()); }}><label><Search size={16} /><input aria-label="Search by store name" placeholder="Search store name…" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button variant="outline" size="sm" type="submit" disabled={loading}>Search</Button>{search && <button type="button" className="text-link" onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Clear</button>}</form></div>
      <div className="pos-sort-summary"><span><span className="pos-live-dot" />{loading ? 'Updating records...' : isSoa ? `Saved ${moduleLabel} records` : 'Saved renewal records'}</span><span>Sorted by <strong>{labels[sort]}</strong> {direction === 'asc' ? 'ascending' : 'descending'}{(sort !== 'renewal_date' || direction !== 'asc') && <button onClick={() => { setSort('renewal_date'); setDirection('asc'); setPage(1); }}>Reset sort</button>}</span></div>
      {loadError ? <div className="pos-load-error" role="alert"><CircleAlert size={20} /><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" aria-busy={loading}>
        <table className="voucher-table pos-interactive-table"><caption className="sr-only">Request records. Select a column heading to change sorting.</caption><thead><tr>{sortHeader('id')}{sortHeader('store_name')}{sortHeader('renewal_date')}{sortHeader('amount', true)}{sortHeader('status')}{sortHeader('created_at')}{isSoa && <th scope="col">SOA</th>}{(accounting || finance) && <th>ACTIONS</th>}</tr></thead><tbody>{!loading && records?.data.map(record => <tr key={record.id}><td><a className="pos-record-id" href={record.detail_url}>{prefix}-{String(record.id).padStart(5, '0')}</a></td><td><a className="pos-store-cell" href={record.detail_url}><span className="pos-store-avatar">{record.store_name.replace(/^BW\s+/i, '').slice(0, 2).toUpperCase()}</span><span><strong className="pos-store-name">{record.store_name}</strong><small>{isSoa ? moduleLabel : 'UTAK POS renewal'}</small></span><ChevronRight size={14} className="pos-store-arrow" /></a></td><td><span className="pos-date"><CalendarDays size={14} />{date(record.renewal_date)}</span></td><td className="amount-column">{currency(record.amount)}</td><td><span className={`status-pill ${record.status === 'awaiting_voucher' ? 'status-accounting' : record.status === 'vouchered' ? 'status-finance' : record.status === 'paid' ? 'status-paid' : 'status-review'}`}><i />{record.status === 'awaiting_voucher' ? 'Pending voucher' : record.status === 'vouchered' ? 'Awaiting payment' : record.status === 'paid' ? 'Paid' : record.status === 'cancelled' ? 'Cancelled' : record.status.replaceAll('_', ' ')}</span></td><td>{date(record.created_at)}</td>{isSoa && <td><a className="soa-download" href={record.attachment_url} title={record.attachment_name}>View SOA</a></td>}{accounting && <td>{record.status !== 'awaiting_voucher' ? <Button variant="outline" size="sm" onClick={() => window.location.assign(record.detail_url)}>View details</Button> : <div className="pos-row-actions"><Button size="sm" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Process request for ${record.store_name}`} onClick={() => window.location.assign(record.detail_url)}>Process</Button><Button size="sm" variant="outline" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Cancel request for ${record.store_name}`} onClick={() => { setActionError(''); setAction({ record, kind: 'cancel' }); }}>Cancel</Button></div>}</td>}{finance && <td><Button size="sm" onClick={() => window.location.assign(record.detail_url)}>{record.status === 'vouchered' ? 'Record payment' : 'View details'}</Button></td>}</tr>)}</tbody></table>
        {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" size={24} /><p>Loading renewals…</p></div> : !records?.data.length && <div className="register-empty"><ClipboardList size={30} strokeWidth={1.4} /><h3>No matching requests</h3><p>Try another store name or choose a different status.</p></div>}
      </div>}
      <div className="table-footer"><span>{loading ? 'Loading records…' : loadError ? 'Records unavailable' : `Showing ${records?.from ?? 0}–${records?.to ?? 0} of ${records?.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous page" disabled={loading || !!loadError || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {records?.last_page ?? 1}</span><button aria-label="Next page" disabled={loading || !!loadError || page >= (records?.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
    <Dialog open={!!action} onOpenChange={value => { if (!value && !actingRef.current) setAction(null); }}><DialogContent><DialogTitle>{action?.kind === 'process' ? 'Process voucher' : 'Cancel request'}</DialogTitle><DialogDescription>{action?.kind === 'process' ? 'Confirm that the voucher has been processed for this request.' : 'Cancel this request and remove it from the pending voucher queue. The record will be kept.'}</DialogDescription>{action && <p className="pos-action-summary"><strong>{action.record.store_name}</strong><br />{date(action.record.renewal_date)} - {currency(action.record.amount)}</p>}{actionError && <div className="form-alert" role="alert">{actionError}</div>}<div className="pos-form-actions"><Button variant="outline" disabled={acting} onClick={() => setAction(null)}>Go back</Button><Button disabled={acting} onClick={resolveRenewal}>{acting ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving...</> : action?.kind === 'process' ? 'Confirm process' : 'Confirm cancellation'}</Button></div></DialogContent></Dialog>
    <Dialog open={open} onOpenChange={value => { if (!savingRef.current) setOpen(value); }}><DialogContent className="pos-add-dialog"><span className="dialog-symbol"><Monitor size={22} /></span><DialogTitle className="text-xl font-semibold">{isSoa ? `Add ${moduleLabel}` : 'Add UTAK POS renewal'}</DialogTitle><DialogDescription className="text-sm text-muted-foreground">{isSoa ? 'Enter the billing details and attach the original statement. Accounting will prepare the voucher.' : 'Enter the store renewal details. Accounting will prepare the voucher from this record.'}</DialogDescription><form ref={form} onSubmit={save} aria-busy={saving}>
      {saveError && <div className="form-alert" role="alert">{saveError}</div>}
      <div className="field-group"><label htmlFor="store_name">Store name</label><Input id="store_name" name="store_name" placeholder="e.g. BW Makati" required maxLength={150} autoComplete="organization" {...field('store_name')} />{errors.store_name && <p className="field-error" id="store_name-error" role="alert">{errors.store_name[0]}</p>}</div>
      <div className="field-group"><label htmlFor="renewal_date">{isSoa ? 'Due date' : 'Renewal date'}</label><Input id="renewal_date" name={isSoa ? 'due_date' : 'renewal_date'} type="date" required {...field('renewal_date')} />{errors.renewal_date && <p className="field-error" id="renewal_date-error" role="alert">{errors.renewal_date[0]}</p>}</div>
      <div className="field-group"><label htmlFor="amount">Amount (PHP)</label><Input id="amount" name="amount" type="number" inputMode="decimal" min="0.01" max="9999999999.99" step="0.01" placeholder="0.00" required {...field('amount')} />{errors.amount && <p className="field-error" id="amount-error" role="alert">{errors.amount[0]}</p>}</div>
      {isSoa && <div className="field-group"><label htmlFor="soa">SOA attachment</label><Input id="soa" name="soa" type="file" accept=".pdf,.jpg,.jpeg,.png" required {...field('soa')} /><p className="pos-save-hint">PDF, JPG, or PNG - Maximum 10 MB</p>{errors.soa && <p className="field-error" id="soa-error" role="alert">{errors.soa[0]}</p>}</div>}
      <p className="pos-save-hint"><ClipboardList size={15} />Initial status: Awaiting voucher · Accounting</p>
      <div className="pos-form-actions"><Button variant="outline" type="button" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={saving}>{saving ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving…</> : <><Plus size={16} />{isSoa ? 'Save SOA' : 'Save renewal'}</>}</Button></div>
    </form></DialogContent></Dialog>
  </div>;
}
