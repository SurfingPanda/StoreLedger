import { useCallback, useEffect, useRef, useState, type MouseEvent } from 'react';
import { Bell, Check, CheckCheck, CircleAlert, ClipboardList, LoaderCircle, RefreshCw } from 'lucide-react';
import { post, type AppData } from '@/lib/auth';

type Item = { id: number; kind: string; title: string; body: string; read_at: string | null; resolved_at: string | null; created_at: string; url: string | null };
type Result = { unread_count: number; latest_id: number; notifications: { data: Item[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null } };

export function useNotificationInbox(data: AppData, open: boolean) {
  const [result, setResult] = useState<Result | null>(null);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState<number | 'all' | null>(null);
  const guard = useRef(false);
  const wasOpen = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const fetchPage = useCallback(async (page = 1, append = false) => {
    controller.current?.abort();
    const request = new AbortController(); controller.current = request;
    setLoading(true);
    try {
      const url = new URL(data.urls.inbox); url.searchParams.set('page', String(page)); url.searchParams.set('filter', filter);
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
      if (!response.ok) throw new Error(response.status === 401 ? 'Your session expired. Refresh to sign in.' : 'Could not load notifications. Try again.');
      const next = await response.json() as Result;
      if (request.signal.aborted) return;
      setResult(previous => append && previous ? {
        ...next,
        notifications: { ...next.notifications, data: [...previous.notifications.data, ...next.notifications.data].filter((item, index, items) => items.findIndex(candidate => candidate.id === item.id) === index) },
      } : next);
      setError('');
    } catch (error) {
      if (!request.signal.aborted) setError(error instanceof Error ? error.message : 'Could not load notifications.');
    } finally { if (!request.signal.aborted) setLoading(false); }
  }, [data.urls.inbox, filter]);
  const refresh = useCallback(() => fetchPage(), [fetchPage]);
  useEffect(() => {
    void refresh();
    const timer = window.setInterval(() => { if (!document.hidden && !guard.current) void refresh(); }, 60000);
    const visible = () => { if (!document.hidden && !guard.current) void refresh(); };
    document.addEventListener('visibilitychange', visible);
    return () => { clearInterval(timer); controller.current?.abort(); document.removeEventListener('visibilitychange', visible); };
  }, [refresh]);
  useEffect(() => {
    if (open && !wasOpen.current && !guard.current) void refresh();
    wasOpen.current = open;
  }, [open, refresh]);

  async function markRead(id: number | 'all') {
    if (guard.current) return false;
    guard.current = true; setBusy(id); setError(''); controller.current?.abort(); setLoading(false);
    try {
      await post(`${data.urls.inbox}/${id === 'all' ? 'read-all' : `${id}/read`}`, id === 'all' ? { through_id: result?.latest_id ?? 0 } : {});
      await refresh();
      return true;
    } catch { setError('Could not mark notifications as read. Please try again.'); return false; }
    finally { guard.current = false; setBusy(null); }
  }
  return { result, filter, loading, error, busy, refresh, markRead,
    loadMore: () => { if (!loading && busy === null && result && result.notifications.current_page < result.notifications.last_page) void fetchPage(result.notifications.current_page + 1, true); },
    changeFilter: (value: 'all' | 'unread') => { setFilter(value); } };
}

export default function NotificationInbox({ inbox }: { inbox: ReturnType<typeof useNotificationInbox> }) {
  const { result, filter, loading, error, busy, refresh, markRead, loadMore, changeFilter } = inbox;
  function readFromButton(event: MouseEvent<HTMLButtonElement>, id: number | 'all') {
    event.currentTarget.closest('.notification-inbox')?.querySelector<HTMLButtonElement>('.inbox-tabs button[aria-pressed="true"]')?.focus();
    void markRead(id);
  }
  async function open(event: MouseEvent<HTMLAnchorElement>, item: Item) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !item.url) return;
    event.preventDefault();
    if (item.read_at || await markRead(item.id)) window.location.assign(item.url);
  }
  return <div className="notification-inbox" aria-busy={loading}>
    <div className="inbox-toolbar"><div className="inbox-tabs" role="group" aria-label="Filter notifications"><button type="button" aria-pressed={filter === 'all'} onClick={() => changeFilter('all')}>All</button><button type="button" aria-pressed={filter === 'unread'} onClick={() => changeFilter('unread')}>Unread {result ? `(${result.unread_count})` : ''}</button></div><button type="button" className="inbox-refresh" aria-label="Refresh notifications" aria-disabled={loading || busy !== null} onClick={() => { if (!loading && busy === null) void refresh(); }}><RefreshCw size={16} className={loading ? 'animate-spin motion-reduce:animate-none' : ''} /></button></div>
    <div className="inbox-read-all"><span>Times in Manila</span><button type="button" disabled={!result?.unread_count || busy !== null || loading} onClick={event => readFromButton(event, 'all')}><CheckCheck size={15} />{busy === 'all' ? 'Saving...' : 'Mark all as read'}</button></div>
    {error && <div className="inbox-error" role="alert">{error}<button type="button" onClick={() => void refresh()} disabled={busy !== null}>Try again</button></div>}
    {!result && loading ? <div className="inbox-empty" role="status"><LoaderCircle size={23} className="animate-spin motion-reduce:animate-none" /><p>Loading notifications...</p></div> : result && !result.notifications.data.length ? <div className="inbox-empty"><Bell size={25} /><strong>{filter === 'unread' ? 'You are all caught up' : 'No notifications yet'}</strong><p>{filter === 'unread' ? 'Your unread alerts will appear here.' : 'Updates and requests needing your attention will appear here.'}</p></div> : <ul className="inbox-items">{result?.notifications.data.map(item => {
      const unread = !item.read_at && !item.resolved_at;
      const Icon = item.kind === 'overdue' && !item.resolved_at ? CircleAlert : item.kind === 'update' ? CheckCheck : ClipboardList;
      return <li key={item.id} className={`${unread ? 'inbox-unread' : ''} ${item.resolved_at ? 'inbox-resolved' : ''}`}>
        <a href={item.url ?? '#'} onClick={event => void open(event, item)} className="inbox-record-link"><span className={`inbox-event-icon inbox-kind-${item.resolved_at ? 'resolved' : item.kind}`} aria-hidden="true"><Icon size={18} /></span><span className="inbox-event-copy"><strong>{item.title}{unread && <span className="inbox-unread-dot" aria-label="Unread" />}</strong><span className="inbox-event-body">{item.body}</span><time dateTime={item.created_at}>{new Date(item.created_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })}</time></span></a>
        <div className="inbox-item-actions">{item.resolved_at ? <span className="inbox-resolved-label"><Check size={13} />Resolved</span> : <span>{item.kind === 'overdue' ? 'Overdue' : item.kind === 'update' ? 'Update' : 'Action required'}</span>}{unread && <button type="button" disabled={busy !== null} onClick={event => readFromButton(event, item.id)}><Check size={14} />{busy === item.id ? 'Saving...' : 'Mark as read'}</button>}</div>
      </li>;
    })}</ul>}
    {result && result.notifications.current_page < result.notifications.last_page && <div className="inbox-load-more"><button type="button" aria-disabled={loading || busy !== null} onClick={loadMore}>{loading ? 'Loading...' : 'Load older notifications'}</button></div>}
  </div>;
}
