import { useEffect, useState, type FormEvent } from 'react';
import { Bell, CheckCircle2, LoaderCircle, Mail, Save } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { post, RequestError, type AppData } from '@/lib/auth';

type Setting = { event: string; mode: 'role' | 'custom' | 'off'; emails: string[]; role: string; role_emails: string[]; resolved_emails: string[]; text?: string };
const labels: Record<string, { title: string; detail: string; available: boolean }> = {
  accounting_voucher: { title: 'Accounting · Voucher reminders', detail: 'UTAK POS renewals and Internet, Water, Electricity, and Rental SOAs awaiting a voucher, starting 15 days before their due date.', available: true },
  finance_payment: { title: 'Finance · Payment reminders', detail: 'Recipients for payment reminders once the voucher and payment workflow is connected.', available: false },
  rso_voucher_processed: { title: 'RSO · Voucher processed', detail: 'Recipients for an update when Accounting finishes processing a voucher.', available: false },
  rso_payment_processed: { title: 'RSO · Payment processed', detail: 'Recipients for an update when Finance records a completed payment.', available: false },
};
export default function NotificationSettings({ data }: { data: AppData }) {
  const [rows, setRows] = useState<Setting[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [mail, setMail] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    fetch(data.urls.notificationSettings, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error('Could not load settings. Check your session and try again.'); return response.json(); })
      .then(result => { setRows(result.settings.map((row: Setting) => ({ ...row, text: row.emails.join('\n') }))); setMail(result.mail_configured); })
      .catch(error => { if (!controller.signal.aborted) setError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.notificationSettings, revision]);
  function change(index: number, values: Partial<Setting>) { setSuccess(''); setRows(rows.map((row, i) => i === index ? { ...row, ...values } : row)); }
  async function save(event: FormEvent) {
    event.preventDefault(); if (saving) return; setSaving(true); setError(''); setSuccess('');
    try {
      const settings = rows.map(row => ({ event: row.event, mode: row.mode, emails: row.mode !== 'custom' ? [] : (row.text ?? '').split(/[\n,;]+/).map(email => email.trim().toLowerCase()).filter(Boolean) }));
      await post(data.urls.notificationSettings, { settings });
      setSuccess('Notification recipients saved.'); setRevision(value => value + 1);
    } catch (error) {
      setError(error instanceof RequestError ? (Object.values(error.errors).flat().join(' ') || error.message) : 'Could not save settings. Please try again.');
    } finally { setSaving(false); }
  }
  return <section className="notification-settings"><div className="dashboard-heading"><div><p className="dashboard-kicker">WORKSPACE SETTINGS</p><h1>Notification recipients</h1><p>Choose who receives voucher, payment, and RSO updates.</p></div><span className="settings-heading-icon"><Bell size={25} /></span></div>
    <div className="settings-mail-note"><Mail size={19} /><p><strong>{mail ? 'SMTP credentials configured' : 'Email delivery needs setup'}</strong><span>{mail ? 'Recipient settings apply to outgoing reminders. Successful inbox delivery still depends on your SMTP service and scheduler.' : 'Add your Hostinger credentials and activate the scheduler to deliver emails. Saving recipients does not send an email.'}</span></p></div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}</div>}
    {error && <div className="form-alert" role="alert">{error}<button type="button" onClick={() => setRevision(value => value + 1)}>Reload</button></div>}
    {loading ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin motion-reduce:animate-none" /><p>Loading notification settings…</p></div> : rows.length > 0 && <form onSubmit={save} aria-busy={saving}>
      <fieldset disabled={saving} className="settings-grid">{rows.map((row, index) => <section key={row.event} className="recipient-card"><div className="recipient-heading"><h2>{labels[row.event].title}</h2><span className={`status-pill ${labels[row.event].available ? 'status-paid' : 'status-review'}`}>{labels[row.event].available ? 'Reminder connected' : 'Trigger pending'}</span></div><p className="recipient-description">{labels[row.event].detail}{!labels[row.event].available && ' Recipient preferences are saved; automatic delivery starts when this workflow is implemented.'}</p>
        <label htmlFor={`${row.event}-mode`}>Send to</label><select id={`${row.event}-mode`} value={row.mode} onChange={event => change(index, { mode: event.target.value as Setting['mode'] })}><option value="role">Registered {row.role.toUpperCase()} team emails</option><option value="custom">Custom email addresses</option><option value="off">Notifications off</option></select>
        {row.mode === 'custom' ? <><label htmlFor={`${row.event}-emails`}>Recipient emails</label><textarea id={`${row.event}-emails`} rows={3} required value={row.text} maxLength={5200} placeholder="name@company.com" aria-describedby={`${row.event}-hint`} onChange={event => change(index, { text: event.target.value })} /><p id={`${row.event}-hint`} className="recipient-hint">One per line, or separated by commas. Up to 20 addresses. These replace the team recipients for this notification.</p></> : <div className="recipient-preview">{row.mode === 'off' ? 'No emails will be sent for this notification.' : row.role_emails.length ? row.role_emails.join(', ') : `No registered ${row.role.toUpperCase()} email addresses yet.`}</div>}
      </section>)}</fieldset><div className="settings-save"><p>Recipient changes apply to future sends. Previously delivered reminders are not resent to the same address.</p><Button type="submit" disabled={saving}>{saving ? <LoaderCircle className="animate-spin motion-reduce:animate-none" /> : <Save size={16} />}{saving ? 'Saving…' : 'Save settings'}</Button></div>
    </form>}
  </section>;
}

