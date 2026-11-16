import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, LoaderCircle, Wifi, Plus, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';

type Soa = { id: number; store_name: string; due_date: string; amount: string; attachment_name: string; attachment_url: string; created_at: string };
type Records = { data: Soa[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const currency = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
function date(value: string) { return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }); }

export default function InternetSoas({ data }: { data: AppData }) {
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
    const url = new URL(data.urls.internetSoaRecords);
    url.searchParams.set('page', String(page)); url.searchParams.set('search', search);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Refresh to sign in again.' : 'Could not load SOA records. Please try again.'); return response.json() as Promise<Records>; })
      .then(result => setRecords(result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message || 'Could not load SOA records.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.internetSoaRecords, page, search, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setSaveError(''); setErrors({});
    try {
      const values = new FormData(event.currentTarget);
      const result = await post<{ message: string }>(data.urls.internetSoaRecords, values);
      setOpen(false); setSuccess(result.message); setPage(1); setSearch(''); setQuery(''); setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) {
        setErrors(error.errors);
        if (!Object.keys(error.errors).length) setSaveError(error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setSaveError('Could not connect. Your SOA has not been confirmed; please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `${name}-error` : undefined });
  return <div className="pos-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">INTERNET BILLING</p><h1>Internet SOA Monitoring</h1><p>Track each store internet bill, due date, and statement of account.</p></div>{data.user?.role === 'admin' && <Button onClick={() => { setErrors({}); setSaveError(''); setOpen(true); }}><Plus size={17} />Add SOA</Button>}</div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button aria-label="Dismiss confirmation" onClick={() => setSuccess('')}>Dismiss</button></div>}
    <div className="pos-workflow-note"><span className="pos-module-icon"><Wifi size={22} /></span><div><strong>Every internet bill, with its SOA in one place.</strong><p>Admins can add store billing details and attach a PDF or image. Download the statement from its record whenever needed.</p></div></div>
    <section className="register-panel pos-register"><div className="section-heading"><div><h2>Internet SOA records</h2><p>Saved records, ordered by due date.</p></div>{records && !loading && <span className="record-count">{records.total} {records.total === 1 ? 'record' : 'records'}</span>}</div>
      <form className="pos-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(query.trim()); }}><label><Search size={16} /><input aria-label="Search by store name" placeholder="Search store name…" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button variant="outline" size="sm" type="submit" disabled={loading}>Search</Button>{search && <button type="button" className="text-link" onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Clear</button>}</form>
      {loadError ? <div className="pos-load-error" role="alert"><CircleAlert size={20} /><p>{loadError}</p><Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" aria-busy={loading}>
        <table className="voucher-table"><thead><tr><th>RECORD</th><th>STORE NAME</th><th>DUE DATE</th><th className="amount-column">AMOUNT</th><th>SOA ATTACHMENT</th><th>DATE ADDED</th></tr></thead><tbody>{!loading && records?.data.map(record => <tr key={record.id}><td><span className="pos-record-id">NET-{String(record.id).padStart(5, '0')}</span></td><td><strong className="pos-store-name">{record.store_name}</strong></td><td><span className="pos-date"><CalendarDays size={14} />{date(record.due_date)}</span></td><td className="amount-column">{currency(record.amount)}</td><td><a className="soa-download" href={record.attachment_url}><ClipboardList size={14} />{record.attachment_name}</a></td><td>{date(record.created_at)}</td></tr>)}</tbody></table>
        {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" size={24} /><p>Loading SOA records…</p></div> : !records?.data.length && <div className="register-empty"><ClipboardList size={30} strokeWidth={1.4} /><h3>{search ? 'No matching stores' : 'Your first SOA starts here.'}</h3><p>{search ? 'Try another store name or clear your search.' : 'Add a store, amount, due date, and attach its statement of account.'}</p></div>}
      </div>}
      <div className="table-footer"><span>{loading ? 'Loading records…' : loadError ? 'Records unavailable' : `Showing ${records?.from ?? 0}–${records?.to ?? 0} of ${records?.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous page" disabled={loading || !!loadError || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {records?.last_page ?? 1}</span><button aria-label="Next page" disabled={loading || !!loadError || page >= (records?.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
    <Dialog open={open} onOpenChange={value => { if (!savingRef.current) setOpen(value); }}><DialogContent className="pos-add-dialog"><span className="dialog-symbol"><Wifi size={22} /></span><DialogTitle className="text-xl font-semibold">Add Internet SOA</DialogTitle><DialogDescription className="text-sm text-muted-foreground">Enter the store internet billing details and attach its statement of account.</DialogDescription><form ref={form} onSubmit={save} aria-busy={saving}><fieldset disabled={saving} className="soa-form-fields">
      {saveError && <div className="form-alert" role="alert">{saveError}</div>}
      <div className="field-group"><label htmlFor="store_name">Store name</label><Input id="store_name" name="store_name" placeholder="e.g. BW Makati" required maxLength={150} autoComplete="organization" {...field('store_name')} />{errors.store_name && <p className="field-error" id="store_name-error" role="alert">{errors.store_name[0]}</p>}</div>
      <div className="field-group"><label htmlFor="due_date">Due date</label><Input id="due_date" name="due_date" type="date" required {...field('due_date')} />{errors.due_date && <p className="field-error" id="due_date-error" role="alert">{errors.due_date[0]}</p>}</div>
      <div className="field-group"><label htmlFor="amount">Amount (PHP)</label><Input id="amount" name="amount" type="number" inputMode="decimal" min="0.01" max="9999999999.99" step="0.01" placeholder="0.00" required {...field('amount')} />{errors.amount && <p className="field-error" id="amount-error" role="alert">{errors.amount[0]}</p>}</div>
      <div className="field-group"><label htmlFor="soa">SOA attachment</label><Input id="soa" name="soa" type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-describedby={errors.soa ? 'soa-hint soa-error' : 'soa-hint'} aria-invalid={!!errors.soa || undefined} /><p className="pos-save-hint" id="soa-hint">PDF, JPG, or PNG - Maximum 10 MB</p>{errors.soa && <p className="field-error" id="soa-error" role="alert">{errors.soa[0]}</p>}</div>
      <div className="pos-form-actions"><Button variant="outline" type="button" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={saving}>{saving ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />Saving…</> : <><Plus size={16} />Save SOA</>}</Button></div>
    </fieldset></form></DialogContent></Dialog>
  </div>;
}
