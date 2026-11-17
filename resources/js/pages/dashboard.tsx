import { useEffect, useState } from 'react';
import { ArrowDownLeft, ArrowUpRight, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, ClipboardList, CreditCard, FileCheck2, LoaderCircle, RefreshCw, Search } from 'lucide-react';
import { TableIdentity } from '@/components/table-presentation';
import { Button } from '@/components/ui/button';
import type { AppData } from '@/lib/auth';

type Row = { key: string; record_number: string; store_name: string; module: string; amount: string; due_date: string; status: string; detail_url: string };
type Results = {
  summary: { needs_revision_count: number; awaiting_soa_count: number; total_count: number; pending_count: number; awaiting_payment_count: number; paid_count: number; outstanding_amount: string; overdue_count: number; overdue_amount: string };
  breakdown: { module: string; amount: string; total_count: number }[];
  due: Row[];
  records: { data: Row[]; total: number; from: number | null; to: number | null; last_page: number };
  updated_at: string; today: string;
};
const modules: Record<string, string> = { rental: 'Rental', electricity: 'Electricity', water: 'Water', 'utak-pos': 'UTAK POS', 'internet-soa': 'Internet SOA' };
const stages: Record<string, string> = { all: 'All requests', needs_revision: 'Needs revision', awaiting_soa: 'Awaiting SOA', awaiting_voucher: 'Pending voucher', vouchered: 'Awaiting payment', paid: 'Paid', cancelled: 'Cancelled' };
const money = (amount: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
const date = (value: string) => new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
const statusClass = (status: string) => status === 'awaiting_voucher' ? 'status-accounting' : status === 'vouchered' ? 'status-finance' : status === 'paid' ? 'status-paid' : 'status-review';

export default function Dashboard({ data }: { data: AppData }) {
  const [period, setPeriod] = useState('all');
  const [module, setModule] = useState('all');
  const [stage, setStage] = useState('all');
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<Results | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    const url = new URL(data.urls.dashboardRecords);
    for (const [key, value] of Object.entries({ period, module, status: stage, search, page: String(page) })) url.searchParams.set(key, value);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error('Could not load dashboard records. Refresh or try again.'); return response.json() as Promise<Results>; })
      .then(setResult)
      .catch(error => { if (!controller.signal.aborted) setError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.dashboardRecords, period, module, stage, search, page, revision]);
  const summary = result?.summary;
  const available = !!result && !error;
  const totalAmount = result?.breakdown.reduce((sum, row) => sum + Number(row.amount), 0) ?? 0;
  function selectStage(value: string) { setStage(value); setPage(1); }
  return <div className="real-dashboard" aria-busy={loading}>
    <div className="dashboard-heading"><div><p className="dashboard-kicker">THE BIG PICTURE</p><h1>Good to see you, {data.user?.name.split(' ')[0] || 'User'}.</h1><p>Track actual requests, vouchers, and payments across your stores.</p></div><div className="dashboard-date"><span>{new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}</span><label><select aria-label="Dashboard due date range" value={period} onChange={event => { setPeriod(event.target.value); setPage(1); }}><option value="all">All due dates</option><option value="30">Due within ±30 days</option><option value="90">Due within ±90 days</option></select></label></div></div>
    <div className="live-dashboard-toolbar"><label>Module<select value={module} onChange={event => { setModule(event.target.value); setPage(1); }}><option value="all">All modules</option>{Object.entries(modules).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><span role="status">{error ? 'Dashboard unavailable' : loading ? 'Updating dashboard...' : result ? `Updated ${new Date(result.updated_at).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })}` : ''}</span><Button variant="outline" size="sm" disabled={loading} onClick={() => setRevision(value => value + 1)}><RefreshCw size={14} className={loading ? 'animate-spin' : ''} />Refresh</Button></div>
    {error && <div className="form-alert" role="alert">{error}<button onClick={() => setRevision(value => value + 1)}>Try again</button></div>}
    <section className="dashboard-stats" aria-label="Saved payment summary">
      {[
        { title: 'Outstanding amount', value: money(summary?.outstanding_amount ?? '0'), note: `${(summary?.needs_revision_count ?? 0) + (summary?.awaiting_soa_count ?? 0) + (summary?.pending_count ?? 0) + (summary?.awaiting_payment_count ?? 0)} requests awaiting completion`, icon: ClipboardList },
        { title: 'For voucher processing', value: String(summary?.pending_count ?? 0), note: 'Pending with Accounting', icon: FileCheck2 },
        { title: 'For payment', value: String(summary?.awaiting_payment_count ?? 0), note: 'Processed vouchers with Finance', icon: CreditCard },
        { title: 'Overdue dues', value: String(summary?.overdue_count ?? 0), note: `${money(summary?.overdue_amount ?? '0')} outstanding past due`, icon: CircleAlert },
      ].map(({ title, value, note, icon: Icon }, index) => <article key={title} className={index === 3 && (summary?.overdue_count ?? 0) > 0 ? 'stat-attention' : ''}><div className="stat-label">{title}<Icon size={17} /></div><strong>{available ? value : loading ? '—' : 'Unavailable'}</strong><p>{available ? note : 'Totals appear when records load'}</p></article>)}
    </section>
    <section className="workflow-panel real-workflow"><div className="section-heading"><div><h2>Request progress</h2><p>Counts across the selected module and due date range.</p></div><span className="quiet-label">SAVED WORKFLOW STATUS</span></div><div className="workflow-steps">{[
      { title: 'Accounting', detail: 'Prepare voucher', value: summary?.pending_count, state: 'awaiting_voucher', icon: FileCheck2 },
      { title: 'Finance', detail: 'Record payment & proof', value: summary?.awaiting_payment_count, state: 'vouchered', icon: CreditCard },
      { title: 'Paid', detail: 'Payment recorded', value: summary?.paid_count, state: 'paid', icon: CheckCircle2 },
    ].map(({ title, detail, value, state, icon: Icon }) => <button key={state} className={`workflow-step ${stage === state ? 'workflow-selected' : ''}`} aria-pressed={stage === state} onClick={() => selectStage(stage === state ? 'all' : state)}><span className="workflow-icon"><Icon size={18} /></span><span className="workflow-text"><strong>{title}</strong><small>{detail}</small></span><span className="workflow-count">{available ? value : '—'}<small>requests</small></span></button>)}</div></section>
    <div className="dashboard-columns"><div className="dashboard-primary"><section className="register-panel"><div className="section-heading"><div><h2>Request register</h2><p>Open a saved request to review its SOA, voucher, payment, and history.</p></div><span className="record-count">{available ? result.records.total : '—'} records</span></div>
      <div className="register-controls"><div className="register-tabs" role="group" aria-label="Filter requests by status">{Object.entries(stages).map(([key, label]) => <button key={key} aria-pressed={stage === key} className={stage === key ? 'tab-active' : ''} onClick={() => selectStage(key)}>{label}</button>)}</div><form className="dashboard-register-search" onSubmit={event => { event.preventDefault(); setSearch(query.trim()); setPage(1); }}><label className="register-search"><Search size={15} /><input placeholder="Find a store..." aria-label="Search saved requests by store" value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button size="sm" variant="outline" type="submit">Search</Button></form></div>
      <div className="voucher-table-scroll"><table className="ledger-table voucher-table"><thead><tr><th scope="col">REQUEST / STORE</th><th scope="col">CATEGORY</th><th scope="col">DUE DATE</th><th scope="col" className="amount-column">AMOUNT</th><th scope="col">STATUS</th><th scope="col"><span className="sr-only">Details</span></th></tr></thead><tbody>{!loading && !error && result?.records.data.map(row => <tr key={row.key}><td><TableIdentity name={row.store_name} subtitle={<span className="ledger-inline-record">{row.record_number}</span>} href={row.detail_url} /></td><td>{modules[row.module]}</td><td><span className={row.due_date < result.today && ['awaiting_soa', 'awaiting_voucher', 'needs_revision', 'vouchered'].includes(row.status) ? 'overdue-date' : ''}>{date(row.due_date)}</span></td><td className="amount-column">{money(row.amount)}</td><td><span className={`status-pill ${statusClass(row.status)}`}><i />{stages[row.status] ?? row.status}</span></td><td><a className="row-open" aria-label={`View ${row.record_number}`} href={row.detail_url}><ChevronRight size={16} /></a></td></tr>)}</tbody></table>
      {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin" /><p>Loading saved requests...</p></div> : error ? <div className="register-empty"><CircleAlert size={25} /><p>Records unavailable. Try refreshing the dashboard.</p></div> : !result?.records.data.length && <div className="register-empty"><ClipboardList size={26} /><h3>No requests to show</h3><p>{search || stage !== 'all' || module !== 'all' || period !== 'all' ? 'Try a different module, status, date range, or store name.' : 'Saved renewals and SOAs will appear here when an admin adds them.'}</p>{(search || stage !== 'all' || module !== 'all' || period !== 'all') && <button onClick={() => { setSearch(''); setQuery(''); setStage('all'); setModule('all'); setPeriod('all'); setPage(1); }}>Clear filters</button>}</div>}</div>
      <div className="table-footer"><span>{loading ? 'Loading records...' : error ? 'Records unavailable' : `Showing ${result?.records.from ?? 0}–${result?.records.to ?? 0} of ${result?.records.total ?? 0}`}</span><div className="pos-pagination"><button aria-label="Previous request page" disabled={loading || !!error || page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button><span>Page {page} of {result?.records.last_page ?? 1}</span><button aria-label="Next request page" disabled={loading || !!error || page >= (result?.records.last_page ?? 1)} onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button></div></div>
    </section>
    <section className="category-panel"><div className="section-heading"><div><h2>Recorded amounts by module</h2><p>Saved request amounts, excluding cancelled requests.</p></div><strong>{available ? money(totalAmount) : '—'}</strong></div><div className="spend-bar" aria-hidden="true">{Object.keys(modules).map((key, index) => <span key={key} className={`spend-color-${index}`} style={{ width: `${available && totalAmount ? Number(result.breakdown.find(row => row.module === key)?.amount ?? 0) / totalAmount * 100 : 0}%` }} />)}</div><div className="spend-legend">{Object.entries(modules).map(([key, label], index) => <button key={key} onClick={() => { setModule(key); setPage(1); }}><i className={`spend-color-${index}`} /><span>{label}<strong>{available ? money(result.breakdown.find(row => row.module === key)?.amount ?? '0') : '—'}</strong></span><ArrowUpRight size={13} /></button>)}</div></section>
    </div><aside className="dashboard-secondary"><section className="due-panel"><div className="section-heading"><h2>Unpaid dues</h2><span className="due-icon"><ArrowDownLeft size={17} /></span></div><p className="panel-description">Earliest due dates first, including overdue requests.</p><div className="due-list">{available && result.due.map(row => <a className="dashboard-due-link" key={row.key} href={row.detail_url}><span className={`due-date-box ${row.due_date < result.today ? 'due-late' : ''}`}><small>{new Date(`${row.due_date}T12:00:00`).toLocaleDateString('en-PH', { month: 'short' })}</small><strong>{Number(row.due_date.slice(8, 10))}</strong></span><span className="due-details"><strong>{modules[row.module]}</strong><small>{row.store_name}</small><span>{money(row.amount)}</span></span>{row.due_date < result.today ? <span className="due-tag">Overdue</span> : <ChevronRight size={14} />}</a>)}{!loading && available && !result.due.length && <p className="side-empty">No unpaid requests in this view.</p>}{(loading || error) && <p className="side-empty">{error ? 'Dues unavailable' : 'Loading dues...'}</p>}</div></section></aside></div>
  </div>;
}

export function RecentActivity({ data }: { data: AppData }) {
  const [events, setEvents] = useState<(Row & { event: string; occurred_at: string })[] | null>(null);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setError('');
    fetch(data.urls.dashboardActivity, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error('Could not load recent activity.'); return response.json(); })
      .then(result => setEvents(result.events))
      .catch(error => { if (!controller.signal.aborted) setError(error.message); });
    return () => controller.abort();
  }, [data.urls.dashboardActivity, revision]);
  return error ? <div className="activity-state" role="alert"><CircleAlert size={24} /><p>{error}</p><Button variant="outline" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : !events ? <div className="activity-state" role="status"><LoaderCircle size={24} className="animate-spin motion-reduce:animate-none" /><p>Loading activity...</p></div> : !events.length ? <div className="activity-state"><ClipboardList size={26} /><strong>No activity yet</strong><p>Request and payment updates will appear here.</p></div> : <div className="dashboard-activity-list">{events.map((item, index) => {
    const paid = item.event === 'Payment recorded';
    const cancelled = item.event === 'Request cancelled';
    const Icon = paid ? CreditCard : cancelled ? CircleAlert : item.event === 'Voucher processed' ? FileCheck2 : ClipboardList;
    return <a key={`${item.key}-${item.event}-${index}`} href={item.detail_url} className="activity-item"><span className={`activity-event-icon ${paid ? 'activity-event-paid' : cancelled ? 'activity-event-cancelled' : ''}`}><Icon size={17} /></span><span className="activity-item-content"><strong>{item.event}</strong><span className="activity-store">{item.store_name}</span><span className="activity-item-meta"><span>{item.record_number}</span><small>{new Date(item.occurred_at.replace(' ', 'T') + (item.occurred_at.includes('T') ? '' : 'Z')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</small></span></span><ChevronRight size={14} className="activity-item-arrow" /></a>;
  })}</div>;
}
