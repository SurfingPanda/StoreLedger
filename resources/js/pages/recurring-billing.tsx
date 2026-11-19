import RequestStatus from '@/components/request-status';
import { useRef, useState, type FormEvent } from 'react';
import { CalendarClock, CheckCircle2, LoaderCircle, Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { post, RequestError } from '@/lib/auth';

export type Recurrence = { id: number; active: boolean; interval_months: number; lead_days: number; amount: string; next_due_date: string };
const date = (value: string) => new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' });
const message = (error: unknown) => error instanceof RequestError ? Object.values(error.errors).flat().join(' ') || error.message : 'Could not save. Please try again.';

export default function RecurringBillingPanel({ recurrence, amount, isSoa, admin, base, onSaved, defaultCycle }: { defaultCycle?: number; recurrence: Recurrence | null; amount: string; isSoa: boolean; admin: boolean; base: string; onSaved: () => void }) {
  const [interval, setInterval] = useState(String(recurrence?.interval_months ?? defaultCycle ?? (isSoa ? 1 : 12)));
  const [lead, setLead] = useState(String(recurrence?.lead_days ?? 15));
  const [expected, setExpected] = useState(recurrence?.amount ?? amount);
  const [active, setActive] = useState(recurrence?.active ?? true);
  const [saving, setSaving] = useState(false);
  const guard = useRef(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  if (!admin && !recurrence) return null;
  async function save(event: FormEvent) {
    event.preventDefault(); if (guard.current) return; guard.current = true; setSaving(true); setError(''); setSuccess('');
    try {
      const result = await post<{ message: string }>(`${base}/recurrence`, { active, interval_months: Number(interval), lead_days: Number(lead), amount: expected });
      setSuccess(result.message); onSaved();
    } catch (error) { setError(message(error)); }
    finally { guard.current = false; setSaving(false); }
  }
  return <section className="register-panel recurring-billing-panel"><div className="section-heading"><div><h2><CalendarClock size={18} />Recurring billing</h2><p>{recurrence ? 'This schedule automatically prepares each new billing cycle.' : 'Automatically prepare future billing cycles for this store.'}</p></div><span className={`status-pill ${recurrence?.active ? 'status-paid' : 'status-review'}`}>{recurrence ? recurrence.active ? 'Active' : 'Paused' : 'Not enabled'}</span></div>
    {recurrence && <div className="recurrence-next"><span>Next due date</span><strong>{date(recurrence.next_due_date)}</strong><small>{recurrence.active ? `Request is generated ${recurrence.lead_days} days before this date.` : 'Generation is paused until an admin resumes this schedule.'}</small></div>}
    {admin && <form className="recurrence-form" onSubmit={save}>{error && <div role="alert" className="form-alert">{error}</div>}{success && <div role="status" className="pos-success"><CheckCircle2 size={16} />{success}</div>}<fieldset disabled={saving} className="soa-form-fields"><div className="recurrence-form-grid"><div className="field-group"><label htmlFor="recurring-cycle">Billing cycle</label><select id="recurring-cycle" value={interval} onChange={event => setInterval(event.target.value)}><option value="1">Monthly</option><option value="3">Every 3 months</option><option value="6">Every 6 months</option><option value="12">Yearly</option></select></div><div className="field-group"><label htmlFor="recurring-lead">Create request days before due</label><Input id="recurring-lead" type="number" min="0" max="90" required value={lead} onChange={event => setLead(event.target.value)} /></div><div className="field-group"><label htmlFor="recurring-amount">Expected amount (PHP)</label><Input id="recurring-amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value={expected} onChange={event => setExpected(event.target.value)} /></div><div className="field-group"><label htmlFor="recurring-active">Schedule status</label><select id="recurring-active" value={active ? 'active' : 'paused'} onChange={event => setActive(event.target.value === 'active')}><option value="active">Active</option><option value="paused">Paused</option></select></div></div><p className="recurrence-help">{isSoa ? 'Each new cycle starts as Awaiting SOA. An admin must upload that cycle’s statement and confirm the actual amount before it enters Accounting’s queue.' : 'Each new cycle enters Accounting’s voucher queue with the expected amount.'} The first future due date follows this request’s date and your selected cycle. Previously generated requests stay unchanged.</p><div className="pos-form-actions"><Button type="submit">{saving ? <><LoaderCircle className="animate-spin" />Saving...</> : recurrence ? 'Save schedule' : 'Enable recurring billing'}</Button></div></fieldset></form>}
  </section>;
}

export function CurrentSoaForm({ amount, base, canSubmit, submitterLabel = 'Admin', onSaved }: { amount: string; base: string; canSubmit: boolean; submitterLabel?: string; onSaved: (message: string) => void }) {
  const [saving, setSaving] = useState(false);
  const guard = useRef(false);
  const [error, setError] = useState('');
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (guard.current) return;
    const values = new FormData(event.currentTarget); guard.current = true; setSaving(true); setError('');
    try { const result = await post<{ message: string }>(`${base}/statement`, values); onSaved(result.message); }
    catch (error) { setError(message(error)); }
    finally { guard.current = false; setSaving(false); }
  }
  return <section id="current-statement-panel" className="register-panel current-statement-panel"><div className="section-heading"><div><h2><Upload size={19} />Current SOA required</h2><p>{canSubmit ? 'This recurring cycle is ready. Attach its new statement and confirm the amount to send it to Accounting.' : `${submitterLabel} needs to attach the current SOA and confirm its amount before voucher processing.`}</p></div><RequestStatus status="awaiting_soa" /></div>{canSubmit && <form className="recurrence-form" onSubmit={save}>{error && <div role="alert" className="form-alert">{error}</div>}<fieldset className="soa-form-fields" disabled={saving}><div className="recurrence-form-grid"><div className="field-group"><label htmlFor="current-soa-amount">Actual amount (PHP)</label><Input id="current-soa-amount" name="amount" type="number" required defaultValue={amount} min="0.01" max="9999999999.99" step="0.01" /></div><div className="field-group"><label htmlFor="current-soa-file">This cycle’s SOA</label><Input id="current-soa-file" name="soa" type="file" accept=".pdf,.jpg,.jpeg,.png" required /><p className="pos-save-hint">PDF, JPG, or PNG - Maximum 10 MB</p></div></div><div className="pos-form-actions"><Button type="submit">{saving ? 'Saving...' : 'Attach SOA & send to Accounting'}</Button></div></fieldset></form>}</section>;
}
