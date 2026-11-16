import { useEffect, useRef, useState } from 'react';
import { Bell, ChevronDown, LogOut, Settings } from 'lucide-react';
import type { AppData } from '@/lib/auth';

export default function WorkspaceMenus({ data, roleLabel, busy, logout, sample, read, markRead }: {
  data: AppData; roleLabel: string; busy: boolean; logout: () => Promise<void>;
  sample: boolean; read: boolean; markRead: () => void;
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
      <button ref={bell} className="notification-button" aria-label="Notifications" aria-expanded={open === 'notifications'} aria-controls="notification-dropdown" onClick={() => setOpen(open === 'notifications' ? null : 'notifications')}>
        <Bell size={19} />{sample && !read && <span />}
      </button>
      {open === 'notifications' && <section id="notification-dropdown" className="topbar-dropdown notification-dropdown" aria-label="Notifications">
        <div className="dropdown-heading"><strong>Notifications</strong><button disabled={!sample || read} onClick={markRead}>Mark all as read</button></div>
        <div className="notification-scroll">
          {sample ? <>
            <p className="notification-preview-label">Sample updates · Live notifications are not connected</p>
            {[
              { title: 'Voucher processed', detail: 'Accounting has processed a voucher and sent it to Finance for payment.' },
              { title: 'Payment completed', detail: 'Finance has recorded a payment. RSO can review the completed transaction.' },
            ].map(item => <article className={`notification-row ${read ? 'notification-read' : ''}`} key={item.title}>
              <span className="notification-unread" aria-label={read ? 'Read' : 'Unread'} />
              <div><strong>{item.title}</strong><p>{item.detail}</p><small>Sample notification</small></div>
            </article>)}
          </> : <div className="notification-empty"><Bell size={24} /><strong>No notifications yet</strong><p>You're all caught up.</p></div>}
        </div>
      </section>}
    </div>
    <div className="topbar-menu-anchor">
      <button ref={account} className="account-menu-trigger" aria-label="Account options" aria-expanded={open === 'account'} aria-controls="account-dropdown" onClick={() => setOpen(open === 'account' ? null : 'account')}>
        <span className="account-trigger-name"><strong>{data.user?.name}</strong><small>{roleLabel}</small></span>
        <span className="topbar-avatar">{(data.user?.name || 'U').slice(0, 1).toUpperCase()}</span><ChevronDown size={13} />
      </button>
      {open === 'account' && <section id="account-dropdown" className="topbar-dropdown account-dropdown" aria-label="Account options">
        <div className="account-dropdown-heading"><strong>{data.user?.name}</strong><small>{data.user?.email}</small></div>
        {data.user?.role === 'admin' && <a href={data.urls.settings}><Settings size={16} />Settings</a>}
        <button className="account-signout" disabled={busy} onClick={() => { setOpen(null); void logout(); }}><LogOut size={16} />{busy ? 'Signing out…' : 'Sign out'}</button>
      </section>}
    </div>
  </div>;
}
