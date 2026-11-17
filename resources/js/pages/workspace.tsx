import { useState } from 'react';
import { ArrowRight, Building2, ChevronRight, Droplets, LayoutDashboard, Menu, Monitor, PanelLeftClose, PanelLeftOpen, Settings, Wifi, ShieldCheck, X, Zap } from 'lucide-react';
import NotificationSettings from '@/pages/notification-settings';
import PosRenewals from '@/pages/pos-renewals';
import PosVoucher from '@/pages/pos-voucher';
import Dashboard from '@/pages/dashboard';
import WorkspaceMenus from '@/components/workspace-menus';
import { confirmAction, showError } from '@/lib/alerts';
import { Brand } from '@/components/brand';
import { post, type AppData } from '@/lib/auth';

type Module = 'All modules' | 'Rental' | 'Electricity' | 'Water' | 'UTAK POS';
const modules = [
  { name: 'Rental' as Module, icon: Building2 }, { name: 'Electricity' as Module, icon: Zap },
  { name: 'Water' as Module, icon: Droplets }, { name: 'UTAK POS' as Module, icon: Monitor },
];
export default function Workspace({ data }: { data: AppData }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const active: Module = (data.page === 'utak-pos' || data.page === 'pos-voucher') ? 'UTAK POS' : (data.page === 'rental' || data.page === 'rental-voucher') ? 'Rental' : (data.page === 'electricity' || data.page === 'electricity-voucher') ? 'Electricity' : (data.page === 'water' || data.page === 'water-voucher') ? 'Water' : 'All modules';
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem('bw-sidebar-collapsed') === 'true'; } catch { return false; } });
  function toggleSidebar() { setCollapsed(value => { try { localStorage.setItem('bw-sidebar-collapsed', String(!value)); } catch {} return !value; }); }
  const [sidebar, setSidebar] = useState(false);
  const name = data.user?.name.split(' ')[0] || 'User';
  const roleLabel = ({ admin: 'Admin', accounting: 'Accounting', finance: 'Finance', it: 'IT', rso: 'RSO' } as Record<string, string>)[data.user?.role ?? ''] ?? 'Team';
  function navigate(module: Module) { const urls: Record<Module, string> = { 'All modules': data.urls.dashboard, Rental: data.urls.rental, Electricity: data.urls.electricity, Water: data.urls.water, 'UTAK POS': data.urls.pos }; window.location.assign(urls[module]); }
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
        <a href={data.urls.internetSoa} title="Internet SOA Monitoring" aria-label="Internet SOA Monitoring" className={`nav-item ${(data.page === 'internet-soa' || data.page === 'internet-voucher') ? 'nav-active' : ''}`} aria-current={(data.page === 'internet-soa' || data.page === 'internet-voucher') ? 'page' : undefined}><Wifi size={18} /><span className="nav-label">Internet SOA Monitoring</span><ChevronRight size={13} className="nav-chevron" /></a>
        {data.user?.role === 'admin' && <><p className="nav-caption modules-caption">ADMINISTRATION</p><a href={data.urls.settings} title="Settings" aria-label="Settings" className={`nav-item ${data.page === 'settings' ? 'nav-active' : ''}`} aria-current={data.page === 'settings' ? 'page' : undefined}><Settings size={18} /><span className="nav-label">Settings</span></a></>}
      </nav>
      <div className="sidebar-process"><span className="nav-caption">ONE CONNECTED PROCESS</span><div><span>IT</span><ArrowRight size={12} /><span>Accounting</span></div><div><span>Finance</span><ArrowRight size={12} /><span>RSO</span></div><p>From voucher to payment,<br />everyone stays in the loop.</p></div>
      <div className="sidebar-account"><span className="account-avatar">{name.slice(0, 1).toUpperCase()}</span><div><strong>{data.user?.name}</strong><small>{roleLabel} workspace</small></div></div>
    </aside>

    <div className="admin-body">
      <header className="admin-topbar"><div className="breadcrumb"><button className="desktop-sidebar-toggle" onClick={toggleSidebar} aria-label={collapsed ? 'Expand sidebar' : 'Minimize sidebar'} aria-expanded={!collapsed} title={collapsed ? 'Expand sidebar' : 'Minimize sidebar'}>{collapsed ? <PanelLeftOpen size={20} /> : <PanelLeftClose size={20} />}</button><button className="mobile-menu" aria-label="Open navigation" onClick={() => setSidebar(true)}><Menu size={21} /></button><span>Workspace</span><ChevronRight size={13} /><strong>{data.page === 'settings' ? 'Settings' : (data.page === 'internet-soa' || data.page === 'internet-voucher') ? 'Internet SOA Monitoring' : active === 'All modules' ? 'Dashboard' : active}</strong></div><div className="topbar-actions"><span className="admin-access"><ShieldCheck size={14} />{roleLabel} access</span><span className="topbar-separator" /><WorkspaceMenus data={data} roleLabel={roleLabel} busy={busy} logout={logout} /></div></header>
      <main className="admin-content">
        {data.page === 'settings' ? <NotificationSettings data={data} /> : ['pos-voucher', 'internet-voucher', 'water-voucher', 'electricity-voucher', 'rental-voucher'].includes(data.page) ? <PosVoucher key={data.page} data={data} /> : ['utak-pos', 'internet-soa', 'water', 'electricity', 'rental'].includes(data.page) ? <PosRenewals key={data.page} data={data} /> : <Dashboard data={data} />}
        {error && <div className="form-alert" role="alert">{error}</div>}
        <footer className="dashboard-footer"><span>BW Superbakeshop · Rental & renewal management</span><span>One workspace. Every detail accounted for.</span></footer>
      </main>
    </div>
  </div>;
}
