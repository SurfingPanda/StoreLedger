import { useMemo, useState } from 'react';
import { ArrowDownLeft, ArrowRight, Bell, Building2, Check, ChevronRight, CircleAlert, ClipboardList, CreditCard, Droplets, FileCheck2, LayoutDashboard, Menu, Monitor, PanelLeftClose, PanelLeftOpen, Settings, Wifi, Search, Send, ShieldCheck, SlidersHorizontal, X, Zap } from 'lucide-react';
import { confirmAction, showError } from '@/lib/alerts';
import WorkspaceMenus from '@/components/workspace-menus';
import NotificationSettings from '@/pages/notification-settings';
import PosRenewals from '@/pages/pos-renewals';
import InternetSoas from '@/pages/internet-soas';
import { Brand } from '@/components/brand';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, type AppData } from '@/lib/auth';

type Module = 'All modules' | 'Rental' | 'Electricity' | 'Water' | 'UTAK POS';
type Stage = 'IT review' | 'Accounting' | 'Finance' | 'Paid';
type Voucher = { id: string; location: string; description: string; module: Module; amount: number; offset: number; stage: Stage };
const modules = [
  { name: 'Rental' as Module, icon: Building2 }, { name: 'Electricity' as Module, icon: Zap },
  { name: 'Water' as Module, icon: Droplets }, { name: 'UTAK POS' as Module, icon: Monitor },
];
const stages: Stage[] = ['IT review', 'Accounting', 'Finance', 'Paid'];
const samples: Voucher[] = [
  { id: 'VCH-0018', location: 'Makati branch', description: 'Monthly space rental', module: 'Rental', amount: 28500, offset: -2, stage: 'Finance' },
  { id: 'VCH-0019', location: 'BGC branch', description: 'Electricity billing', module: 'Electricity', amount: 6840, offset: 2, stage: 'Accounting' },
  { id: 'VCH-0020', location: 'Quezon City branch', description: 'Annual subscription renewal', module: 'UTAK POS', amount: 18000, offset: 5, stage: 'IT review' },
  { id: 'VCH-0021', location: 'Makati branch', description: 'Water billing', module: 'Water', amount: 1250, offset: 7, stage: 'Accounting' },
  { id: 'VCH-0016', location: 'Pasig branch', description: 'Monthly space rental', module: 'Rental', amount: 22000, offset: -5, stage: 'Paid' },
  { id: 'VCH-0017', location: 'BGC branch', description: 'Subscription renewal', module: 'UTAK POS', amount: 18000, offset: 12, stage: 'Finance' },
  { id: 'VCH-0022', location: 'Pasig branch', description: 'Electricity billing', module: 'Electricity', amount: 5320, offset: 40, stage: 'IT review' },
];
const money = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value);
function dueDate(offset: number) { const day = new Date(); day.setDate(day.getDate() + offset); return day; }
const dateLabel = (offset: number) => dueDate(offset).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
const stageClass = (stage: Stage) => `status-${stage === 'IT review' ? 'review' : stage.toLowerCase()}`;

export default function Workspace({ data }: { data: AppData }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [active, setActive] = useState<Module>(data.page === 'utak-pos' ? 'UTAK POS' : (['Rental', 'Electricity', 'Water'].includes(new URLSearchParams(window.location.search).get('module') ?? '') ? new URLSearchParams(window.location.search).get('module') as Module : 'All modules'));
  const [sample, setSample] = useState(true);
  const [query, setQuery] = useState('');
  const [stage, setStage] = useState<Stage | 'All vouchers'>('All vouchers');
  const [period, setPeriod] = useState('30');
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem('bw-sidebar-collapsed') === 'true'; } catch { return false; } });
  function toggleSidebar() { setCollapsed(value => { try { localStorage.setItem('bw-sidebar-collapsed', String(!value)); } catch {} return !value; }); }
  const [sidebar, setSidebar] = useState(false);
  const [selected, setSelected] = useState<Voucher | null>(null);
  const [read, setRead] = useState(false);
  const records = sample ? samples : [];
  const scoped = records.filter(row => (active === 'All modules' || row.module === active) && (period === 'all' || Math.abs(row.offset) <= Number(period)));
  const filtered = scoped.filter(row => (stage === 'All vouchers' || row.stage === stage) && `${row.id} ${row.location} ${row.description} ${row.module}`.toLowerCase().includes(query.toLowerCase()));
  const outstanding = scoped.filter(row => row.stage !== 'Paid');
  const overdue = outstanding.filter(row => row.offset < 0);
  const due = [...outstanding].sort((a, b) => a.offset - b.offset).slice(0, 4);
  const totals = useMemo(() => modules.map(module => ({ ...module, total: scoped.filter(row => row.module === module.name).reduce((sum, row) => sum + row.amount, 0) })), [scoped]);
  const totalAmount = scoped.reduce((sum, row) => sum + row.amount, 0);
  const name = data.user?.name.split(' ')[0] || 'User';
  const roleLabel = ({ admin: 'Admin', accounting: 'Accounting', finance: 'Finance', it: 'IT', rso: 'RSO' } as Record<string, string>)[data.user?.role ?? ''] ?? 'Team';
  function navigate(module: Module) { if (module === 'UTAK POS') { window.location.assign(data.urls.pos); return; } if (data.page === 'utak-pos' || data.page === 'internet-soa' || data.page === 'settings') { const url = new URL(data.urls.dashboard); if (module !== 'All modules') url.searchParams.set('module', module); window.location.assign(url); return; } setActive(module); setStage('All vouchers'); setQuery(''); setSidebar(false); }
  async function logout() {
    if (busy) return;
    setBusy(true);
    const confirmed = await confirmAction('You will be signed out of your workspace.', 'Yes, sign out');
    if (!confirmed) { setBusy(false); return; }
    setError('');
    try { const result = await post(data.urls.logout, {}); window.location.assign(result.redirect ?? data.urls.login); }
    catch {
      setError('Could not sign you out. Please try again.');
      await showError('Could not sign you out. Please try again.', 'Sign out failed');
      setBusy(false);
    }
  }
  return <div className={`admin-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
    {sidebar && <button className="sidebar-shade" aria-label="Close navigation" onClick={() => setSidebar(false)} />}
    <aside className={`admin-sidebar ${sidebar ? 'sidebar-open' : ''}`} aria-label="Main navigation">
      <div className="sidebar-brand"><Brand logo={data.assets.logo} /><button className="mobile-close" onClick={() => setSidebar(false)} aria-label="Close navigation"><X size={19} /></button></div>
      <div className="workspace-label"><span className="workspace-initial">BW</span><div>Operations workspace<small>{roleLabel}</small></div><ShieldCheck size={15} /></div>
      <nav className="sidebar-nav">
        <p className="nav-caption">WORKSPACE</p>
        <button className={`nav-item ${active === 'All modules' && data.page === 'dashboard' ? 'nav-active' : ''}`} aria-current={active === 'All modules' && data.page === 'dashboard' ? 'page' : undefined} title="Dashboard" aria-label="Dashboard" onClick={() => navigate('All modules')}><LayoutDashboard size={18} /><span className="nav-label">Dashboard</span></button>
        <p className="nav-caption modules-caption">MONITORING</p>
        {modules.map(({ name, icon: Icon }) => <button key={name} className={`nav-item ${active === name ? 'nav-active' : ''}`} aria-current={active === name ? 'page' : undefined} title={name} aria-label={name} onClick={() => navigate(name)}><Icon size={18} /><span className="nav-label">{name}</span><ChevronRight size={13} className="nav-chevron" /></button>)}
        <a href={data.urls.internetSoa} title="Internet SOA Monitoring" aria-label="Internet SOA Monitoring" className={`nav-item ${data.page === 'internet-soa' ? 'nav-active' : ''}`} aria-current={data.page === 'internet-soa' ? 'page' : undefined}><Wifi size={18} /><span className="nav-label">Internet SOA Monitoring</span><ChevronRight size={13} className="nav-chevron" /></a>
        {data.user?.role === 'admin' && <><p className="nav-caption modules-caption">ADMINISTRATION</p><a href={data.urls.settings} title="Settings" aria-label="Settings" className={`nav-item ${data.page === 'settings' ? 'nav-active' : ''}`} aria-current={data.page === 'settings' ? 'page' : undefined}><Settings size={18} /><span className="nav-label">Settings</span></a></>}
      </nav>
      <div className="sidebar-process"><span className="nav-caption">ONE CONNECTED PROCESS</span><div><span>IT</span><ArrowRight size={12} /><span>Accounting</span></div><div><span>Finance</span><ArrowRight size={12} /><span>RSO</span></div><p>From voucher to payment,<br />everyone stays in the loop.</p></div>
      <div className="sidebar-account"><span className="account-avatar">{name.slice(0, 1).toUpperCase()}</span><div><strong>{data.user?.name}</strong><small>{roleLabel} workspace</small></div></div>
    </aside>

    <div className="admin-body">
      <header className="admin-topbar"><div className="breadcrumb"><button className="desktop-sidebar-toggle" onClick={toggleSidebar} aria-label={collapsed ? 'Expand sidebar' : 'Minimize sidebar'} aria-expanded={!collapsed} title={collapsed ? 'Expand sidebar' : 'Minimize sidebar'}>{collapsed ? <PanelLeftOpen size={20} /> : <PanelLeftClose size={20} />}</button><button className="mobile-menu" aria-label="Open navigation" onClick={() => setSidebar(true)}><Menu size={21} /></button><span>Workspace</span><ChevronRight size={13} /><strong>{data.page === 'settings' ? 'Settings' : data.page === 'internet-soa' ? 'Internet SOA Monitoring' : active === 'All modules' ? 'Dashboard' : active}</strong></div><div className="topbar-actions"><span className="admin-access"><ShieldCheck size={14} />{roleLabel} access</span><span className="topbar-separator" /><WorkspaceMenus data={data} roleLabel={roleLabel} busy={busy} logout={logout} sample={sample} read={read} markRead={() => setRead(true)} /></div></header>
      <main className="admin-content">
        {data.page === 'settings' ? <NotificationSettings data={data} /> : data.page === 'utak-pos' ? <PosRenewals data={data} /> : data.page === 'internet-soa' ? <InternetSoas data={data} /> : <>
        <div className="dashboard-heading"><div><p className="dashboard-kicker">THE BIG PICTURE</p><h1>{active === 'All modules' ? `Good to see you, ${name}.` : `${active} overview.`}</h1><p>Keep an eye on your dues. Keep every payment moving.</p></div><div className="dashboard-date"><span>{new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}</span><label><SlidersHorizontal size={14} /><select aria-label="Dashboard due date range" value={period} onChange={event => setPeriod(event.target.value)}><option value="30">Due within ±30 days</option><option value="90">Due within ±90 days</option><option value="all">All due dates</option></select></label></div></div>
        {error && <div className="form-alert" role="alert">{error}</div>}
        <div className="preview-banner"><div><span className="preview-dot" /><strong>{sample ? 'Sample workspace' : 'Your workspace is ready'}</strong><span>{sample ? 'Illustrative records for reviewing the dashboard. No live vouchers or payments.' : 'No live records yet. Voucher and payment modules are the next step.'}</span></div><button onClick={() => { setSample(!sample); setRead(false); setSelected(null); }}>{sample ? 'Show empty workspace' : 'Preview sample data'}<ArrowRight size={13} /></button></div>
        <section className="dashboard-stats" aria-label="Payment summary">
          {[
            { title: 'Outstanding amount', value: money(outstanding.reduce((sum, row) => sum + row.amount, 0)), note: `${outstanding.length} vouchers awaiting completion`, icon: ClipboardList },
            { title: 'For voucher processing', value: String(scoped.filter(row => row.stage === 'Accounting').length).padStart(2, '0'), note: 'In Accounting’s queue', icon: FileCheck2 },
            { title: 'For payment', value: String(scoped.filter(row => row.stage === 'Finance').length).padStart(2, '0'), note: 'In Finance’s queue', icon: CreditCard },
            { title: 'Overdue dues', value: String(overdue.length).padStart(2, '0'), note: overdue.length ? `${money(overdue.reduce((sum, row) => sum + row.amount, 0))} needs attention` : 'No overdue payments', icon: CircleAlert },
          ].map(({ title, value, note, icon: Icon }, index) => <article key={title} className={index === 3 && overdue.length ? 'stat-attention' : ''}><div className="stat-label">{title}<Icon size={17} strokeWidth={1.5} /></div><strong>{value}</strong><p>{index === 3 && overdue.length ? <span className="attention-dot" /> : null}{note}</p></article>)}
        </section>

        <section className="workflow-panel" aria-label="Voucher workflow"><div className="section-heading"><div><h2>A clear path to paid.</h2><p>Four teams. One shared view of what happens next.</p></div><span className="quiet-label">VOUCHER WORKFLOW</span></div><div className="workflow-steps">{[
          { title: 'IT', detail: 'Review & submit', count: scoped.filter(row => row.stage === 'IT review').length, state: 'IT review' as Stage, icon: ClipboardList },
          { title: 'Accounting', detail: 'Prepare voucher', count: scoped.filter(row => row.stage === 'Accounting').length, state: 'Accounting' as Stage, icon: FileCheck2 },
          { title: 'Finance', detail: 'Process & pay', count: scoped.filter(row => row.stage === 'Finance').length, state: 'Finance' as Stage, icon: CreditCard },
          { title: 'RSO', detail: 'Receive status updates', count: scoped.filter(row => row.stage === 'Paid').length, state: 'Paid' as Stage, icon: Bell },
        ].map(({ title, detail, count, state, icon: Icon }, index) => <button key={title} className={`workflow-step ${stage === state ? 'workflow-selected' : ''}`} onClick={() => setStage(stage === state ? 'All vouchers' : state)} aria-pressed={stage === state}><span className="workflow-icon"><Icon size={18} strokeWidth={1.5} /></span><span className="workflow-text"><strong>{title}</strong><small>{detail}</small></span><span className="workflow-count">{count}<small>{index === 3 ? 'paid' : 'pending'}</small></span>{index < 3 && <ChevronRight className="workflow-arrow" size={15} />}</button>)}</div><div className="workflow-footnote"><Send size={12} />Planned RSO alerts: voucher processed by Accounting, then payment completed by Finance.</div></section>

        <div className="dashboard-columns"><div className="dashboard-primary">
          <section className="register-panel"><div className="section-heading"><div><h2>{active === 'All modules' ? 'Voucher register' : `${active} vouchers`}</h2><p>Track each request from submission to settlement.</p></div><span className="record-count">{scoped.length} records</span></div>
            <div className="register-controls"><div className="register-tabs" role="group" aria-label="Filter vouchers by stage">{(['All vouchers', ...stages] as const).map(label => <button key={label} aria-pressed={stage === label} className={stage === label ? 'tab-active' : ''} onClick={() => setStage(label)}>{label}</button>)}</div><label className="register-search"><Search size={15} /><input placeholder="Find a voucher…" aria-label="Search vouchers" value={query} onChange={event => setQuery(event.target.value)} /></label></div>
            <div className="voucher-table-scroll"><table className="voucher-table"><thead><tr><th>VOUCHER / LOCATION</th><th>CATEGORY</th><th>DUE DATE</th><th className="amount-column">AMOUNT</th><th>STATUS</th><th><span className="sr-only">Details</span></th></tr></thead><tbody>{filtered.map(row => <tr key={row.id}><td><button className="voucher-name" onClick={() => setSelected(row)}>{row.location}</button><span className="voucher-id">{row.id}</span></td><td><span className="category-label">{row.module}</span></td><td><span className={row.offset < 0 && row.stage !== 'Paid' ? 'overdue-date' : ''}>{dateLabel(row.offset)}</span></td><td className="amount-column">{money(row.amount)}</td><td><span className={`status-pill ${stageClass(row.stage)}`}><i />{row.stage === 'Finance' ? 'For payment' : row.stage}</span></td><td><button className="row-open" aria-label={`View ${row.id}`} onClick={() => setSelected(row)}><ChevronRight size={16} /></button></td></tr>)}</tbody></table>{!filtered.length && <div className="register-empty"><ClipboardList size={26} strokeWidth={1.3} /><h3>{scoped.length ? 'No matching vouchers' : 'A clean starting point.'}</h3><p>{scoped.length ? 'Try a different search or status filter.' : 'Vouchers will appear here once the monitoring modules are connected.'}</p>{scoped.length > 0 && <button onClick={() => { setQuery(''); setStage('All vouchers'); }}>Clear filters</button>}</div>}</div><div className="table-footer"><span>Showing {filtered.length} of {scoped.length} records</span><span>{sample ? 'Sample records · PHP' : 'Amounts in PHP'}</span></div>
          </section>
          <section className="category-panel"><div className="section-heading"><div><h2>Where the spend goes</h2><p>Voucher amounts across your monitoring categories.</p></div><strong>{money(totalAmount)}</strong></div><div className="spend-bar" aria-hidden="true">{totals.map((item, index) => <span key={item.name} className={`spend-color-${index}`} style={{ width: `${totalAmount ? item.total / totalAmount * 100 : 25}%`, opacity: totalAmount ? 1 : .18 }} />)}</div><div className="spend-legend">{totals.map((item, index) => <button key={item.name} onClick={() => navigate(item.name)}><i className={`spend-color-${index}`} /><span>{item.name}<strong>{money(item.total)}</strong></span><small>{totalAmount ? Math.round(item.total / totalAmount * 100) : 0}%</small></button>)}</div></section>
        </div><aside className="dashboard-secondary">
          <section className="due-panel"><div className="section-heading"><h2>On the horizon</h2><span className="due-icon"><ArrowDownLeft size={17} /></span></div><p className="panel-description">Unpaid dues, earliest first.</p><div className="due-list">{due.map(row => <button key={row.id} onClick={() => setSelected(row)}><span className={`due-date-box ${row.offset < 0 ? 'due-late' : ''}`}><small>{dueDate(row.offset).toLocaleDateString('en-PH', { month: 'short' })}</small><strong>{dueDate(row.offset).getDate()}</strong></span><span className="due-details"><strong>{row.module}</strong><small>{row.location}</small><span>{money(row.amount)}</span></span>{row.offset < 0 ? <span className="due-tag">Overdue</span> : <ChevronRight size={14} />}</button>)}{!due.length && <p className="side-empty">No upcoming dues to show.</p>}</div></section>
          <section className="rso-panel"><div className="rso-icon"><Bell size={19} strokeWidth={1.5} /></div><span className="quiet-label">CLOSING THE LOOP</span><h2>The right update.<br />At the right time.</h2><p>RSO stays informed when a voucher is processed and again when its payment is completed.</p><button onClick={() => { const bell = document.querySelector<HTMLButtonElement>('button[aria-controls="notification-dropdown"]'); bell?.focus(); bell?.click(); }}>Preview RSO updates<ArrowRight size={14} /></button><small>Notification delivery will be connected with the workflow.</small></section>
        </aside></div>
        </>}
        <footer className="dashboard-footer"><span>BW Superbakeshop · Rental & renewal management</span><span>One workspace. Every detail accounted for.</span></footer>
      </main>
    </div>
    <Dialog open={!!selected} onOpenChange={open => { if (!open) setSelected(null); }}><DialogContent className="voucher-dialog">{selected && <><span className="quiet-label">SAMPLE VOUCHER · {selected.id}</span><DialogTitle className="text-2xl font-medium">{selected.location}</DialogTitle><DialogDescription>{selected.description} · {selected.module}</DialogDescription><div className="detail-amount">{money(selected.amount)}<span>Due {dateLabel(selected.offset)}</span></div><div className="detail-timeline">{stages.map((item, index) => <div key={item} className={index <= stages.indexOf(selected.stage) ? 'timeline-reached' : ''}><span>{index < stages.indexOf(selected.stage) ? <Check size={14} /> : index + 1}</span><div><strong>{item === 'Paid' ? 'Payment complete / RSO update' : item}</strong><small>{index < stages.indexOf(selected.stage) ? 'Previous stage' : index === stages.indexOf(selected.stage) ? 'Current stage' : 'Upcoming stage'}</small></div></div>)}</div><p className="text-xs text-muted-foreground">Preview only. These records do not create vouchers, record payments, or send notifications.</p></>}</DialogContent></Dialog>
  </div>;
}
