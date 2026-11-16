import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, LoaderCircle, Monitor, Plus, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';

type Renewal = { id: number; store_name: string; renewal_date: string; amount: string; status: string; created_at: string };
type Records = { data: Renewal[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const currency = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
function date(value: string) { return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }); }

export default function PosRenewals({ data }: { data: AppData }) {
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
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const form = useRef<HTMLFormElement>(null);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setLoadError('');
    const url = new URL(data.urls.posRecords);
    url.searchParams.set('page', String(page)); url.searchParams.set('search', search);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Refresh to sign in again.' : 'Could not load renewals. Please try again.'); return response.json() as Promise<Records>; })
      .then(result => setRecords(result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message || 'Could not load renewals.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.posRecords, page, search, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setSaveError(''); setErrors({});
    try {
      const values = Object.fromEntries(new FormData(event.currentTarget));
      const result = await post<{ message: string }>(data.urls.posRecords, values);
      setOpen(false); setSuccess(result.message); setPage(1); setSearch(''); setQuery(''); setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) {
        setErrors(error.errors);
        if (!Object.keys(error.errors).length) setSaveError(error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setSaveError('Could not connect. Your renewal has not been confirmed; please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `${name}-error` : undefined });
  return <div className="pos-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">SUBSCRIPTIONS & RENEWALS</p><h1>{data.user?.role === 'accounting' ? 'UTAK POS voucher queue' : 'UTAK POS renewals'}</h1><p>{data.user?.role === 'accounting' ? 'New store renewals awaiting your voucher processing.' : 'Record store renewals and queue them for Accounting to prepare the voucher.'}</p></div>{data.user?.role === 'admin' && <Button onClick={() => { setErrors({}); setSaveError(''); setOpen(true); }}><Plus size={17} />Add renewal</Button>}</div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button aria-label="Dismiss confirmation" onClick={() => setSuccess('')}>Dismiss</button></div>}
    <div className="pos-workflow-note"><span className="pos-module-icon"><Monitor size={22} /></span><div><strong>Start with the renewal. Accounting takes it from here.</strong><p>Every new record is saved as <b>Awaiting voucher</b>. Voucher processing and payment will follow in their respective team workflows.</p></div><span className="status-pill status-accounting">Accounting queue</span></div>
    <section className="register-panel pos-register"><div className="section-heading"><div><h2>Renewal records</h2><p>Saved records, ordered by renewal date.</p></div>{records && !loading && <span className="record-count">{records.total} {records.total === 1 ? 'record' : 'records'}</span>}</div>
      <form className="pos-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(query.trim()); }}><label><Search size={16} /><input aria-label="Search by store name" placeholder="Search store name…" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button variant="outline" size="sm" type="submit" disabled={loading}>Search</Button>{search && <button type="button" className="text-link" onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Clear</button>}</form>
      {loadError ? <div className="pos-load-error" role="alert"><CircleAlert size={20} /><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" aria-busy={loading}>
        <table className="voucher-table"><thead><tr><th>RECORD</th><th>STORE NAME</th><th>RENEWAL DATE</th><th className="amount-column">AMOUNT</th><th>STATUS</th><th>DATE ADDED</th></tr></thead><tbody>{!loading && records?.data.map(record => <tr key={record.id}><td><span className="pos-record-id">POS-{String(record.id).padStart(5, '0')}</span></td><td><strong className="pos-store-name">{record.store_name}</strong></td><td><span className="pos-date"><CalendarDays size={14} />{date(record.renewal_date)}</span></td><td className="amount-column">{currency(record.amount)}</td><td><span className="status-pill status-accounting"><i />Awaiting voucher</span></td><td>{date(record.created_at)}</td></tr>)}</tbody></table>
        {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" size={24} /><p>Loading renewals…</p></div> : !records?.data.length && <div className="register-empty"><ClipboardList size={30} strokeWidth={1.4} /><h3>{search ? 'No matching stores' : 'Your first renewal starts here.'}</h3><p>{search ? 'Try another store name or clear your search.' : 'Add a store, its renewal date, and the amount to send it to the Accounting queue.'}</p></div>}
      </div>}
      <div className="table-footer"><span>{loading ? 'Loading records…' : loadError ? 'Records unavailable' : `Showing ${records?.from ?? 0}–${records?.to ?? 0} of ${records?.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous page" disabled={loading || !!loadError || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {records?.last_page ?? 1}</span><button aria-label="Next page" disabled={loading || !!loadError || page >= (records?.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
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
