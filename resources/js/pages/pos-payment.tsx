import { useRef, useState, type FormEvent } from 'react';
import { CheckCircle2, CreditCard, LoaderCircle } from 'lucide-react';
import AttachmentPreview from '@/components/attachment-preview';
import DuplicateBills from '@/components/duplicate-bills';
import { type DuplicateBill } from '@/lib/auth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { post, RequestError } from '@/lib/auth';

export type Payment = { amount: string; paid_on: string; method: string; reference: string; notes: string | null; proof_name: string; proof_url: string; created_at: string; recorded_by: { name: string } | null };
const money = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
const methods: Record<string, string> = { bank_transfer: 'Bank transfer', cash: 'Cash', check: 'Check', e_wallet: 'E-wallet', other: 'Other' };

export default function PosPayment({ payment, canPay, amount, base, disabled, onSaved, workflowVersion }: { workflowVersion: number; payment: Payment | null; canPay: boolean; amount: string; base: string; disabled: boolean; onSaved: (message: string) => void }) {
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const [error, setError] = useState('');
  const [duplicates, setDuplicates] = useState<DuplicateBill[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const form = useRef<HTMLFormElement>(null);
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `payment-${name}-error` : undefined });
  const fieldError = (name: string) => errors[name] && <p className="field-error" id={`payment-${name}-error`} role="alert">{errors[name][0]}</p>;
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    const payload = new FormData(event.currentTarget); payload.set('workflow_version', String(workflowVersion));
    savingRef.current = true; setSaving(true); setError(''); setErrors({}); setDuplicates([]);
    try {
      const result = await post<{ message: string }>(`${base}/payment`, payload);
      onSaved(result.message);
    } catch (error) {
      if (error instanceof RequestError) {
        setDuplicates(error.duplicates); setErrors(error.errors); setError(Object.keys(error.errors).length ? 'Please check the payment details below.' : error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setError('Could not confirm the payment. Please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  if (!payment && !canPay) return null;
  return <section id="payment-panel" className={`register-panel payment-panel ${payment ? 'payment-panel-complete' : 'payment-panel-pending'}`}><div className="section-heading payment-panel-heading"><div className="payment-heading-content"><span className="payment-heading-icon">{payment ? <CheckCircle2 size={25} /> : <CreditCard size={25} />}</span><div><span className="payment-eyebrow">{payment ? 'PAYMENT COMPLETE' : 'FINANCE · ACTION REQUIRED'}</span><h2>{payment ? 'Payment recorded' : 'Record payment'}</h2><p>{payment ? 'This request is paid. Review the payment details or download the proof below.' : 'Enter the transaction details and upload proof, then save to mark this request as paid.'}</p></div></div><span className={`status-pill ${payment ? 'status-paid' : 'status-finance'}`}>{payment ? 'Paid' : 'Awaiting payment'}</span></div>
    {payment ? <><dl className="voucher-detail-fields">
      <div><dt>Amount paid</dt><dd>{money(payment.amount)}</dd></div><div><dt>Payment date</dt><dd>{new Date(`${payment.paid_on.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' })}</dd></div>
      <div><dt>Payment method</dt><dd>{methods[payment.method] ?? payment.method}</dd></div><div><dt>Payment reference</dt><dd>{payment.reference}</dd></div>
      <div><dt>Recorded by</dt><dd>{payment.recorded_by?.name ?? 'Unknown user'}</dd></div><div><dt>Date recorded</dt><dd>{new Date(payment.created_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</dd></div>
      {payment.notes && <div className="payment-notes"><dt>Payment notes</dt><dd>{payment.notes}</dd></div>}
    </dl><div className="payment-proof-link"><AttachmentPreview url={payment.proof_url} name={payment.proof_name} label="View proof of payment" /></div></> : <form ref={form} className="payment-form" onSubmit={save} aria-busy={saving}>
      <DuplicateBills matches={duplicates} />{error && <div className="form-alert" role="alert">{error}</div>}
      <fieldset disabled={saving || disabled} className="soa-form-fields">
        <div className="payment-total"><span>AMOUNT TO RECORD</span><strong>{money(amount)}</strong><small>Full payment for this request</small></div>
        <div className="payment-entry-columns"><div><h3><span>1</span>Payment details</h3><p className="payment-step-hint">Use the date and reference from the completed transaction.</p><div className="payment-form-grid">
          <div className="field-group"><label htmlFor="payment-date">Payment date</label><Input id="payment-date" name="paid_on" type="date" max={new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 10)} required {...field('paid_on')} />{fieldError('paid_on')}</div>
          <div className="field-group"><label htmlFor="payment-method">Payment method</label><select id="payment-method" name="method" required defaultValue="" {...field('method')}><option value="" disabled>Select payment method</option>{Object.entries(methods).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{fieldError('method')}</div>
        </div>
        <div className="field-group"><label htmlFor="payment-reference">Payment reference</label><Input id="payment-reference" name="reference" required maxLength={100} placeholder="Transaction, receipt, or check number" {...field('reference')} />{fieldError('reference')}</div></div>
        <div><h3><span>2</span>Proof & notes</h3><p className="payment-step-hint">Attach the receipt or transfer confirmation for this payment.</p>
        <div className="field-group"><label htmlFor="payment-proof">Proof of payment</label><Input id="payment-proof" name="proof" type="file" accept=".pdf,.jpg,.jpeg,.png" required {...field('proof')} /><p className="pos-save-hint">PDF, JPG, or PNG - Maximum 10 MB</p>{fieldError('proof')}</div>
        <div className="field-group"><label htmlFor="payment-notes">Payment notes (optional)</label><textarea id="payment-notes" name="notes" rows={3} maxLength={5000} placeholder="Bank, payee, or other payment details" {...field('notes')} />{fieldError('notes')}</div></div></div>
        <div className="payment-submit-bar"><p>Saving completes the payment and removes this request from the Finance queue.</p><Button type="submit">{saving ? <><LoaderCircle className="animate-spin" />Saving...</> : <><CheckCircle2 size={17} />Save payment & mark paid</>}</Button></div>
      </fieldset>
    </form>}
  </section>;
}
