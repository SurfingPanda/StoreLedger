import { useState } from 'react';
import { Bell, UserRound } from 'lucide-react';
import AccountSettings from '@/pages/account-settings';
import NotificationSettings from '@/pages/notification-settings';
import type { AppData } from '@/lib/auth';

export default function SettingsPage({ data, onProfileChange }: { data: AppData; onProfileChange: (user: NonNullable<AppData['user']>) => void }) {
  const [tab, setTab] = useState<'notifications' | 'profile'>(() => new URLSearchParams(window.location.search).get('tab') === 'profile' ? 'profile' : 'notifications');
  if (data.user?.role !== 'admin') return <AccountSettings data={data} onProfileChange={onProfileChange} />;
  function change(value: 'notifications' | 'profile') {
    setTab(value);
    const url = new URL(window.location.href); url.searchParams.set('tab', value); window.history.replaceState(null, '', url);
  }
  return <><div className="register-tabs account-settings-tabs" role="group" aria-label="Settings sections"><button type="button" className={tab === 'notifications' ? 'tab-active' : ''} aria-pressed={tab === 'notifications'} onClick={() => change('notifications')}><Bell size={16} />Notification recipients</button><button type="button" className={tab === 'profile' ? 'tab-active' : ''} aria-pressed={tab === 'profile'} onClick={() => change('profile')}><UserRound size={16} />My account</button></div>{tab === 'notifications' ? <NotificationSettings data={data} /> : <AccountSettings data={data} onProfileChange={onProfileChange} />}</>;
}
