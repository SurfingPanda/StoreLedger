import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ArrowDown, ArrowUp, ArrowUpRight, Banknote, CreditCard, ArrowUpDown, RefreshCw, Rows3, CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, FileCheck2, LoaderCircle, Monitor, Wifi, Droplets, Zap, Building2, Upload, Plus, Search } from 'lucide-react';
import { DirectoryBillFields } from '@/pages/directory';
import DuplicateBills from '@/components/duplicate-bills';
import { type DuplicateBill } from '@/lib/auth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';
import { requestModule } from '@/lib/request-module';
import { showSuccess } from '@/lib/alerts';

type ExtraFilters = { period: string; date_from: string; date_to: string; amount_min: string; amount_max: string };
const defaultFilters: ExtraFilters = { period: 'all', date_from: '', date_to: '', amount_min: '', amount_max: '' };
type Renewal = { workflow_version: number; duplicate_count: number; provider?: string; billing_period?: string; id: number; store_name: string; renewal_date: string; amount: string; status: string; detail_url: string; created_at: string; due_date?: string; attachment_url?: string; attachment_name?: string };
type SortKey = 'id' | 'store_name' | 'renewal_date' | 'amount' | 'status' | 'created_at';
const sortLabels: Record<SortKey, string> = { id: 'Record', store_name: 'Store name', renewal_date: 'Renewal date', amount: 'Amount', status: 'Status', created_at: 'Date added' };
type Summary = { needs_revision_count: number; awaiting_soa_count: number; pending_count: number; pending_amount: string; awaiting_payment_count: number; awaiting_payment_amount: string; paid_count: number; paid_amount: string; overdue_pending_count: number };
type Records = { summary: Summary; data: Renewal[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const currency = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
function date(value: string) { return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }); }

export default function PosRenewals({ data }: { data: AppData }) {
  const { isSoa, label: moduleLabel, prefix, recordsUrl } = requestModule(data);
  const ModuleIcon = data.page === 'internet-soa' ? Wifi : data.page === 'water' ? Droplets : data.page === 'electricity' ? Zap : data.page === 'rental' ? Building2 : Monitor;
  const labels = { ...sortLabels, renewal_date: isSoa ? 'Due date' : 'Renewal date' };
  const accounting = data.user?.role === 'accounting';
  const finance = data.user?.role === 'finance';
  const canSubmit = data.user?.role === 'admin' || (data.user?.role === 'rso' && ['rental', 'electricity', 'water'].includes(data.page));
  const [action, setAction] = useState<{ record: Renewal; kind: 'process' | 'cancel' } | null>(null);
  const [acting, setActing] = useState(false);
  const actingRef = useRef(false);
  const [actionError, setActionError] = useState('');
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saveError, setSaveError] = useState('');
  const [duplicates, setDuplicates] = useState<DuplicateBill[]>([]);
  const [success, setSuccess] = useState('');
  const [saveConfirmation, setSaveConfirmation] = useState('');
  const [records, setRecords] = useState<Records | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [draftFilters, setDraftFilters] = useState<ExtraFilters>(defaultFilters);
  const [extraFilters, setExtraFilters] = useState<ExtraFilters>(defaultFilters);
  const [filterError, setFilterError] = useState('');
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
    for (const [key, value] of Object.entries(extraFilters)) if (value !== '') url.searchParams.set(key, value);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Refresh to sign in again.' : 'Could not load requests. Please try again.'); return response.json() as Promise<Records>; })
      .then(result => setRecords(isSoa ? { ...result, data: result.data.map(record => ({ ...record, renewal_date: record.due_date! })) } : result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message || 'Could not load requests.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [recordsUrl, isSoa, page, search, statusFilter, sort, direction, extraFilters, revision]);

  useEffect(() => {
    if (open || !saveConfirmation) return;
    setSaveConfirmation('');
    void showSuccess(isSoa ? 'SOA saved successfully' : 'Renewal saved successfully', saveConfirmation);
  }, [open, saveConfirmation, isSoa]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setSaveError(''); setErrors({}); setDuplicates([]);
    try {
      const values = new FormData(event.currentTarget);
      const result = await post<{ message: string }>(recordsUrl, values);
      setSaveConfirmation(result.message);
      setOpen(false); setSuccess(result.message); setPage(1); setSearch(''); setQuery(''); setStatusFilter('awaiting_voucher'); setExtraFilters(defaultFilters); setDraftFilters(defaultFilters); setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) {
        setDuplicates(error.duplicates);
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
      const result = await post<{ message: string }>(`${recordsUrl}/${action.record.id}/voucher`, { workflow_version: action.record.workflow_version, action: action.kind });
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
    setStatusFilter(status); setSearch(''); setQuery(''); setPage(1); setExtraFilters(defaultFilters); setDraftFilters(defaultFilters); setFilterError('');
    document.getElementById('renewal-register')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function applyFilters(event: FormEvent) {
    event.preventDefault(); setFilterError('');
    if (draftFilters.date_from && draftFilters.date_to && draftFilters.date_from > draftFilters.date_to) { setFilterError('The end date must be on or after the start date.'); return; }
    if (draftFilters.amount_min !== '' && draftFilters.amount_max !== '' && Number(draftFilters.amount_min) > Number(draftFilters.amount_max)) { setFilterError('Maximum amount must be at least the minimum amount.'); return; }
    setExtraFilters({ ...draftFilters }); setPage(1);
  }
  function clearFilters() { setExtraFilters(defaultFilters); setDraftFilters(defaultFilters); setFilterError(''); setStatusFilter('all'); setSearch(''); setQuery(''); setPage(1); }
  const extraCount = Object.entries(extraFilters).filter(([key, value]) => key === 'period' ? value !== 'all' : value !== '').length;
  const summary = records?.summary;
  const summaryCards = [
    { label: 'Pending vouchers', value: String(summary?.pending_count ?? 0), note: summary?.overdue_pending_count ? `${summary.overdue_pending_count} past due - needs attention` : 'Ready for Accounting to process', filter: 'awaiting_voucher', icon: ClipboardList, tone: 'orange', attention: !!summary?.overdue_pending_count },
    { label: 'Needs revision', value: String(summary?.needs_revision_count ?? 0), note: 'Returned requests awaiting corrections', filter: 'needs_revision', icon: Banknote, tone: 'blue', attention: !!summary?.needs_revision_count },
    { label: 'Awaiting Finance payment', value: String(summary?.awaiting_payment_count ?? 0), note: `${currency(summary?.awaiting_payment_amount ?? '0')} in processed vouchers`, filter: 'vouchered', icon: CreditCard, tone: 'violet', attention: false },
    { label: isSoa ? 'Paid SOAs' : 'Paid renewals', value: String(summary?.paid_count ?? 0), note: `${currency(summary?.paid_amount ?? '0')} paid in total`, filter: 'paid', icon: CheckCircle2, tone: 'green', attention: false },
  ];
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `${name}-error` : undefined });
  return <div className="pos-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">{isSoa ? `${moduleLabel.replace(' SOA', '').toUpperCase()} BILLING` : 'SUBSCRIPTIONS & RENEWALS'}</p><h1>{isSoa ? accounting ? `${moduleLabel} voucher queue` : finance ? `${moduleLabel} payment queue` : `${moduleLabel} Monitoring` : accounting ? 'UTAK POS voucher queue' : finance ? 'UTAK POS payment queue' : 'UTAK POS renewals'}</h1><p>{accounting ? 'Review pending requests and prepare their vouchers.' : finance ? 'Processed vouchers ready for payment. Record payment details and attach proof.' : isSoa ? 'Record store bills, attach the SOA, and track voucher and payment progress.' : 'Record store renewals and queue them for Accounting to prepare the voucher.'}</p></div>{canSubmit && <Button onClick={() => { setErrors({}); setSaveError(''); setDuplicates([]); setOpen(true); }}><Plus size={17} />{isSoa ? 'Add SOA' : 'Add renewal'}</Button>}</div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button aria-label="Dismiss confirmation" onClick={() => setSuccess('')}>Dismiss</button></div>}
    {accounting && <section className="accounting-overview" aria-label="Accounting request summary" aria-busy={loading}><div className="accounting-overview-label"><h2>Your Accounting overview</h2><span>All stores / totals across all statuses</span></div><div className="accounting-overview-grid">{summaryCards.map(({ label, value, note, filter, icon: Icon, tone, attention }) => <button key={label} type="button" className={`accounting-metric metric-${tone} ${attention ? 'metric-needs-attention' : ''}`} disabled={loading || !!loadError} onClick={() => showSummaryStatus(filter)} aria-label={`${label}: ${summary && !loadError ? value : 'unavailable'}. View matching requests.`}><div className="accounting-metric-top"><span>{label}</span><span className="accounting-metric-icon"><Icon size={19} /></span></div><strong>{loadError ? 'Unavailable' : !summary ? '--' : value}</strong><p>{loadError ? 'Refresh records to load totals' : !summary ? 'Loading totals...' : note}</p><div className="accounting-metric-footer">View records<ArrowUpRight size={14} /></div></button>)}</div></section>}
    <section id="renewal-register" className={`register-panel pos-register pos-renewal-register ${compact ? 'pos-compact' : ''}`}><div className="section-heading pos-register-heading"><div className="pos-register-title"><span className="pos-register-symbol"><ClipboardList size={21} /></span><div><h2>{isSoa ? `${moduleLabel} records` : 'Renewal records'} {records && !loading && <span className="pos-total-badge">{records.total}</span>}</h2><p>Track each request from voucher to payment.</p></div></div><div className="pos-table-tools"><button type="button" onClick={() => setCompact(value => !value)} aria-pressed={compact} title={compact ? 'Use comfortable rows' : 'Use compact rows'}><Rows3 size={16} /><span>{compact ? 'Compact' : 'Comfortable'}</span></button><button type="button" disabled={loading} onClick={() => setRevision(value => value + 1)} aria-label="Refresh records" title="Refresh records"><RefreshCw size={16} className={loading ? 'animate-spin motion-reduce:animate-none' : ''} /></button></div></div><div className="pos-table-toolbar">
      <div className="pos-status-filter"><label htmlFor="pos-status">Status</label><select id="pos-status" value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1); setSuccess(''); }}><option value="all">All statuses</option>{isSoa && <option value="awaiting_soa">Awaiting SOA</option>}<option value="needs_revision">Needs revision</option><option value="awaiting_voucher">Pending voucher</option><option value="processed">Voucher processed</option><option value="vouchered">Awaiting payment</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select>{statusFilter === 'processed' && <span>Includes vouchers awaiting payment and already paid.</span>}</div>
      <form className="pos-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(query.trim()); }}><label><Search size={16} /><input aria-label="Search by store name" placeholder="Search store name…" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button variant="outline" size="sm" type="submit" disabled={loading}>Search</Button>{search && <button type="button" className="text-link" onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Clear</button>}</form></div>
      <form className="request-filter-panel" onSubmit={applyFilters} aria-label="Filter request dates and amounts"><div className="request-filter-heading"><strong>Date & amount filters</strong>{(extraCount > 0 || statusFilter !== 'all' || search) && <button type="button" onClick={clearFilters}>Clear all filters</button>}</div><div className="request-filter-grid"><label>Quick dates<select value={draftFilters.period} onChange={event => setDraftFilters(value => ({ ...value, period: event.target.value, date_from: '', date_to: '' }))}><option value="all">All dates</option><option value="overdue">Overdue & unpaid</option><option value="next7">Due in next 7 days</option><option value="next30">Due in next 30 days</option><option value="this_month">Due this month</option></select></label><label>{isSoa ? 'Due' : 'Renewal'} date from<input type="date" value={draftFilters.date_from} onChange={event => setDraftFilters(value => ({ ...value, period: 'all', date_from: event.target.value }))} /></label><label>{isSoa ? 'Due' : 'Renewal'} date to<input type="date" value={draftFilters.date_to} min={draftFilters.date_from || undefined} onChange={event => setDraftFilters(value => ({ ...value, period: 'all', date_to: event.target.value }))} /></label><label>Min amount (PHP)<input type="number" min="0" max="9999999999.99" step="0.01" placeholder="No minimum" value={draftFilters.amount_min} onChange={event => setDraftFilters(value => ({ ...value, amount_min: event.target.value }))} /></label><label>Max amount (PHP)<input type="number" min={draftFilters.amount_min || '0'} max="9999999999.99" step="0.01" placeholder="No maximum" value={draftFilters.amount_max} onChange={event => setDraftFilters(value => ({ ...value, amount_max: event.target.value }))} /></label><Button type="submit" size="sm" disabled={loading}>Apply filters{extraCount > 0 && <span className="filter-count">{extraCount}</span>}</Button></div>{filterError && <p className="field-error" role="alert">{filterError}</p>}</form>
      <div className="pos-sort-summary"><span><span className="pos-live-dot" />{loading ? 'Updating records...' : isSoa ? `Saved ${moduleLabel} records` : 'Saved renewal records'}</span><span>Sorted by <strong>{labels[sort]}</strong> {direction === 'asc' ? 'ascending' : 'descending'}{(sort !== 'renewal_date' || direction !== 'asc') && <button onClick={() => { setSort('renewal_date'); setDirection('asc'); setPage(1); }}>Reset sort</button>}</span></div>
      {loadError ? <div className="pos-load-error" role="alert"><CircleAlert size={20} /><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" aria-busy={loading}>
        <table className="ledger-table voucher-table pos-interactive-table"><caption className="sr-only">Request records. Select a column heading to change sorting.</caption><thead><tr>{sortHeader('id')}{sortHeader('store_name')}{sortHeader('renewal_date')}{sortHeader('amount', true)}{sortHeader('status')}{sortHeader('created_at')}{isSoa && <th scope="col">SOA</th>}{(accounting || finance || (canSubmit && isSoa)) && <th scope="col">ACTIONS</th>}</tr></thead><tbody>{!loading && records?.data.map(record => <tr key={record.id}><td><a className="pos-record-id" href={record.detail_url}>{prefix}-{String(record.id).padStart(5, '0')}</a></td><td><a className="pos-store-cell" href={record.detail_url}><span className="pos-store-avatar">{record.store_name.replace(/^BW\s+/i, '').slice(0, 2).toUpperCase()}</span><span><strong className="pos-store-name">{record.store_name}</strong><small>{record.provider || (isSoa ? moduleLabel : 'UTAK POS renewal')}{record.billing_period ? ` ? ${record.billing_period}` : ''}</small>{record.duplicate_count > 0 && <small className="duplicate-row-tag"><CircleAlert size={12} />Possible duplicate</small>}</span><ChevronRight size={14} className="pos-store-arrow" /></a></td><td><span className="pos-date"><CalendarDays size={14} />{date(record.renewal_date)}</span></td><td className="amount-column">{currency(record.amount)}</td><td><span className={`status-pill ${record.status === 'awaiting_voucher' ? 'status-accounting' : record.status === 'vouchered' ? 'status-finance' : record.status === 'paid' ? 'status-paid' : 'status-review'}`}><i />{record.status === 'needs_revision' ? 'Needs revision' : record.status === 'awaiting_soa' ? 'Awaiting SOA' : record.status === 'awaiting_voucher' ? 'Pending voucher' : record.status === 'vouchered' ? 'Awaiting payment' : record.status === 'paid' ? 'Paid' : record.status === 'cancelled' ? 'Cancelled' : record.status.replaceAll('_', ' ')}</span></td><td>{date(record.created_at)}</td>{isSoa && <td>{record.attachment_name ? <a className="soa-download" href={record.attachment_url} title={record.attachment_name}>View SOA</a> : <span className="history-pending">Current SOA needed</span>}</td>}{accounting && <td>{record.status !== 'awaiting_voucher' ? <Button variant="outline" size="sm" onClick={() => window.location.assign(record.detail_url)}>View details</Button> : <div className="pos-row-actions"><Button size="sm" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Process request for ${record.store_name}`} onClick={() => window.location.assign(record.detail_url)}>Process</Button><Button size="sm" variant="outline" disabled={acting || loading || record.status !== 'awaiting_voucher'} aria-label={`Cancel request for ${record.store_name}`} onClick={() => { setActionError(''); setAction({ record, kind: 'cancel' }); }}>Cancel</Button></div>}</td>}{finance && <td><Button size="sm" onClick={() => window.location.assign(record.detail_url)}>{record.status === 'vouchered' ? 'Record payment' : 'View details'}</Button></td>}{canSubmit && isSoa && <td><Button size="sm" variant={record.status === 'awaiting_soa' ? 'default' : 'outline'} onClick={() => window.location.assign(record.detail_url)}>{record.status === 'awaiting_soa' ? 'Attach SOA' : 'View details'}</Button></td>}</tr>)}</tbody></table>
        {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" size={24} /><p>Loading renewals…</p></div> : !records?.data.length && <div className="register-empty"><ClipboardList size={30} strokeWidth={1.4} /><h3>No matching requests</h3><p>Try another store name or choose a different status.</p></div>}
      </div>}
      <div className="table-footer"><span>{loading ? 'Loading records…' : loadError ? 'Records unavailable' : `Showing ${records?.from ?? 0}–${records?.to ?? 0} of ${records?.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous page" disabled={loading || !!loadError || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {records?.last_page ?? 1}</span><button aria-label="Next page" disabled={loading || !!loadError || page >= (records?.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
    <Dialog open={!!action} onOpenChange={value => { if (!value && !actingRef.current) setAction(null); }}><DialogContent className="user-editor user-editor-status request-confirm-editor"><div className="user-editor-header"><div className="user-editor-heading"><span className="user-editor-symbol"><CircleAlert size={23} /></span><div><p className="user-editor-eyebrow">{moduleLabel.toUpperCase()}</p><DialogTitle className="user-editor-title">{action?.kind === 'process' ? 'Process voucher' : 'Cancel this request?'}</DialogTitle></div></div><DialogDescription className="user-editor-description">{action?.kind === 'process' ? 'Confirm that the voucher has been prepared and processed.' : 'The request will leave the pending queue. Its details and history will be kept.'}</DialogDescription></div><div className="user-editor-body">{action && <dl className="request-confirm-summary"><div><dt>Store</dt><dd>{action.record.store_name}</dd></div><div><dt>{isSoa ? 'Due date' : 'Renewal date'}</dt><dd>{date(action.record.renewal_date)}</dd></div><div><dt>Amount</dt><dd>{currency(action.record.amount)}</dd></div></dl>}{actionError && <div className="form-alert" role="alert">{actionError}</div>}</div><div className="user-editor-footer"><span>Request history is preserved.</span><div><Button variant="outline" disabled={acting} onClick={() => setAction(null)}>Go back</Button><Button disabled={acting} onClick={resolveRenewal}>{acting ? <><LoaderCircle className="animate-spin" />Saving...</> : action?.kind === 'process' ? 'Confirm process' : 'Cancel request'}</Button></div></div></DialogContent></Dialog>
    <Dialog open={open} onOpenChange={value => { if (!savingRef.current) setOpen(value); }}><DialogContent className="user-editor request-entry-editor"><div className="user-editor-header"><div className="user-editor-heading"><span className="user-editor-symbol"><ModuleIcon size={23} /></span><div><p className="user-editor-eyebrow">{moduleLabel.toUpperCase()} / NEW REQUEST</p><DialogTitle className="user-editor-title">{isSoa ? `Add ${moduleLabel}` : 'Add UTAK POS renewal'}</DialogTitle></div></div><DialogDescription className="user-editor-description">{isSoa ? 'Add the store billing details and attach this current statement of account.' : 'Add the store renewal details to start Accounting voucher processing.'}</DialogDescription></div><form ref={form} onSubmit={save} aria-busy={saving} className="user-editor-form"><div className="user-editor-body"><DuplicateBills matches={duplicates} />{saveError && <div className="form-alert" role="alert">{saveError}</div>}<fieldset className="soa-form-fields" disabled={saving}>
      <section className="user-form-section" aria-labelledby="request-details-heading"><div className="user-form-section-heading"><span className="user-section-number">1</span><h3 id="request-details-heading">Store & billing details</h3><span className="request-required-label">Required fields marked *</span></div><DirectoryBillFields url={data.urls.directoryRecords} directoryUrl={data.urls.directory} module={data.page === 'utak-pos' ? 'utak-pos' : data.page} errors={errors} /><div className="field-group request-store-field"><label htmlFor="billing_period">Billing month *</label><Input id="billing_period" name="billing_period" type="month" required {...field('billing_period')} />{errors.billing_period && <p className="field-error">{errors.billing_period[0]}</p>}<p className="pos-save-hint">Use the month covered by this bill, even if the due date is in another month.</p></div><div className="user-form-grid"><div className="field-group"><label htmlFor="renewal_date">{isSoa ? 'Due date' : 'Renewal date'} <span aria-hidden="true">*</span></label><Input id="renewal_date" name={isSoa ? 'due_date' : 'renewal_date'} type="date" required {...field('renewal_date')} />{errors.renewal_date && <p className="field-error" id="renewal_date-error" role="alert">{errors.renewal_date[0]}</p>}</div><div className="field-group"><label htmlFor="amount">Amount <span aria-hidden="true">*</span></label><div className="request-currency-input"><span aria-hidden="true">PHP</span><Input id="amount" name="amount" type="number" inputMode="decimal" min="0.01" max="9999999999.99" step="0.01" placeholder="0.00" required {...field('amount')} /></div>{errors.amount && <p className="field-error" id="amount-error" role="alert">{errors.amount[0]}</p>}</div></div></section>
      {isSoa && <section className="user-form-section" aria-labelledby="request-soa-heading"><div className="user-form-section-heading"><span className="user-section-number">2</span><h3 id="request-soa-heading">Statement of account</h3></div><div className="field-group request-upload-field"><label htmlFor="soa"><Upload size={19} /><span>Attach the current SOA<small>PDF, JPG, or PNG / Up to 10 MB</small></span></label><Input id="soa" name="soa" type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-describedby={errors.soa ? 'soa-hint soa-error' : 'soa-hint'} aria-invalid={!!errors.soa || undefined} /><p id="soa-hint">Use the statement for this billing cycle so Accounting can verify the amount and due date.</p>{errors.soa && <p className="field-error" id="soa-error" role="alert">{errors.soa[0]}</p>}</div></section>}
      <div className="request-next-step"><span className="request-next-icon"><FileCheck2 size={18} /></span><div><strong>Next: Accounting voucher processing</strong><p>This request will be saved as <b>Awaiting voucher</b> and added to the Accounting queue.</p></div><ChevronRight size={15} /></div>
      </fieldset></div><div className="user-editor-footer"><span><ClipboardList size={14} />{isSoa ? 'Statement and details saved together.' : 'Create a new renewal request.'}</span><div><Button variant="outline" type="button" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={saving}>{saving ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving...</> : <><Plus size={15} />{isSoa ? 'Save SOA' : 'Save renewal'}</>}</Button></div></div></form></DialogContent></Dialog>
  </div>;
}
