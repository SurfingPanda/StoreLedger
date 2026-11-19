import { useEffect, useRef, useState } from 'react';
import { Bell, ChevronDown, LogOut, Settings, UsersRound, X } from 'lucide-react';
import type { AppData } from '@/lib/auth';
import { RecentActivity } from '@/pages/dashboard';

export default function WorkspaceMenus({ data, roleLabel, busy, logout }: {
  data: AppData; roleLabel: string; busy: boolean; logout: () => Promise<void>;
}) {
  const [open, setOpen] = useState<'notifications' | 'account' | null>(null);
  const root = useRef<HTMLDivElement>(null);
  const bell = useRef<HTMLButtonElement>(null);
  const account = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!open) return;
    function outside(event: PointerEvent) {
      if (!root.current?.contains(event.target as Node)) setOpen(null);
    }
    function escape(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        (open === 'notifications' ? bell : account).current?.focus();
        setOpen(null);
      }
    }
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', outside);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);

  return <div className="workspace-menus" ref={root} onBlur={event => {
    if (!event.currentTarget.contains(event.relatedTarget as Node)) setOpen(null);
  }}>
    <div className="topbar-menu-anchor">
      <button ref={bell} className="notification-button" aria-label="Recent activity" aria-expanded={open === 'notifications'} aria-controls="notification-dropdown" onClick={() => setOpen(open === 'notifications' ? null : 'notifications')}>
        <Bell size={19} />
      </button>
      {open === 'notifications' && <section id="notification-dropdown" className="topbar-dropdown notification-dropdown" aria-label="Recent activity">
        <div className="dropdown-heading"><div><strong>Recent activity</strong><p>Latest request and payment updates</p></div><button type="button" className="activity-close" aria-label="Close recent activity" onClick={() => { setOpen(null); bell.current?.focus(); }}><X size={16} /></button></div>
        <div className="notification-scroll"><RecentActivity data={data} /></div>
      </section>}
    </div>
    <div className="topbar-menu-anchor">
      <button ref={account} className="account-menu-trigger" aria-label="Account options" aria-expanded={open === 'account'} aria-controls="account-dropdown" onClick={() => setOpen(open === 'account' ? null : 'account')}>
        <span className="account-trigger-name"><strong>{data.user?.name}</strong><small>{roleLabel}</small></span>
        <span className="topbar-avatar">{(data.user?.name || 'U').slice(0, 1).toUpperCase()}</span><ChevronDown size={13} />
      </button>
      {open === 'account' && <section id="account-dropdown" className="topbar-dropdown account-dropdown" aria-label="Account options">
        <div className="account-dropdown-heading"><strong>{data.user?.name}</strong><small>{data.user?.email}</small></div>
        {data.user?.role === 'admin' && <a href={data.urls.users}><UsersRound size={16} />Users & access</a>}
        <a href={`${data.urls.settings}?tab=profile`}><Settings size={16} />My account</a>
        <button className="account-signout" disabled={busy} onClick={() => { setOpen(null); void logout(); }}><LogOut size={16} />{busy ? 'Signing out…' : 'Sign out'}</button>
      </section>}
    </div>
  </div>;
}
