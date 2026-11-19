import { useEffect, useRef, useState } from 'react';
import { Bell, ChevronDown, LogOut, Settings, UsersRound, X } from 'lucide-react';
import type { AppData } from '@/lib/auth';
import NotificationInbox, { useNotificationInbox } from '@/components/notification-inbox';

export default function WorkspaceMenus({ data, roleLabel, busy, logout }: {
  data: AppData; roleLabel: string; busy: boolean; logout: () => Promise<void>;
}) {
  const [open, setOpen] = useState<'notifications' | 'account' | null>(null);
  const inbox = useNotificationInbox(data, open === 'notifications');
  const unread = inbox.result?.unread_count ?? 0;
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
    // A temporarily disabled or removed control may blur without a new focus target.
    // Outside pointer clicks and Escape are handled above.
    if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget as Node)) setOpen(null);
  }}>
    <span className="sr-only" role="status">{inbox.result ? `${unread} unread notifications` : ''}</span>
    <div className="topbar-menu-anchor">
      <button ref={bell} className="notification-button" aria-label={`Notifications, ${unread} unread`} aria-expanded={open === 'notifications'} aria-controls="notification-dropdown" onClick={() => setOpen(open === 'notifications' ? null : 'notifications')}>
        <Bell size={19} />{unread > 0 && <span className="notification-unread-badge" aria-hidden="true">{unread > 99 ? '99+' : unread}</span>}
      </button>
      {open === 'notifications' && <section id="notification-dropdown" className="topbar-dropdown notification-dropdown" aria-label="Notifications">
        <div className="dropdown-heading"><div><strong>Notifications</strong><p>Requests and updates for your role</p></div><button type="button" className="activity-close" aria-label="Close notifications" onClick={() => { setOpen(null); bell.current?.focus(); }}><X size={16} /></button></div>
        <div className="notification-scroll"><NotificationInbox inbox={inbox} /></div>
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
