import { useRef, useState, type FormEvent } from 'react';
import { CreditCard, Download, LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { post, RequestError } from '@/lib/auth';

export type Payment = { amount: string; paid_on: string; method: string; reference: string; notes: string | null; proof_name: string; proof_url: string; created_at: string; recorded_by: { name: string } | null };
const money = (amount: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount));
const methods: Record<string, string> = { bank_transfer: 'Bank transfer', cash: 'Cash', check: 'Check', e_wallet: 'E-wallet', other: 'Other' };

export default function PosPayment({ payment, canPay, amount, base, disabled, onSaved }: { payment: Payment | null; canPay: boolean; amount: string; base: string; disabled: boolean; onSaved: (message: string) => void }) {
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const form = useRef<HTMLFormElement>(null);
  const field = (name: string) => ({ 'aria-invalid': !!errors[name] || undefined, 'aria-describedby': errors[name] ? `payment-${name}-error` : undefined });
  const fieldError = (name: string) => errors[name] && <p className="field-error" id={`payment-${name}-error`} role="alert">{errors[name][0]}</p>;
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (savingRef.current) return;
    const payload = new FormData(event.currentTarget);
    savingRef.current = true; setSaving(true); setError(''); setErrors({});
    try {
      const result = await post<{ message: string }>(`${base}/payment`, payload);
      onSaved(result.message);
    } catch (error) {
      if (error instanceof RequestError) {
        setErrors(error.errors); setError(Object.keys(error.errors).length ? 'Please check the payment details below.' : error.message);
        requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
      } else setError('Could not confirm the payment. Please try again.');
    } finally { savingRef.current = false; setSaving(false); }
  }
  if (!payment && !canPay) return null;
  return <section className="register-panel voucher-history"><div className="section-heading"><div><h2><CreditCard size={18} />{payment ? 'Payment details' : 'Record payment'}</h2><p>{payment ? 'Payment recorded by Finance, with supporting proof.' : 'Record the full payment and attach its proof to complete this renewal.'}</p></div></div>
    {payment ? <><dl className="voucher-detail-fields">
      <div><dt>Amount paid</dt><dd>{money(payment.amount)}</dd></div><div><dt>Payment date</dt><dd>{new Date(`${payment.paid_on.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' })}</dd></div>
      <div><dt>Payment method</dt><dd>{methods[payment.method] ?? payment.method}</dd></div><div><dt>Payment reference</dt><dd>{payment.reference}</dd></div>
      <div><dt>Recorded by</dt><dd>{payment.recorded_by?.name ?? 'Unknown user'}</dd></div><div><dt>Date recorded</dt><dd>{new Date(payment.created_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</dd></div>
      {payment.notes && <div className="payment-notes"><dt>Payment notes</dt><dd>{payment.notes}</dd></div>}
    </dl><div className="payment-proof-link"><a className="soa-download" href={payment.proof_url}><Download size={16} />{payment.proof_name}</a></div></> : <form ref={form} className="payment-form" onSubmit={save} aria-busy={saving}>
      {error && <div className="form-alert" role="alert">{error}</div>}
      <fieldset disabled={saving || disabled} className="soa-form-fields">
        <p className="payment-total">Full payment: <strong>{money(amount)}</strong></p>
        <div className="payment-form-grid">
          <div className="field-group"><label htmlFor="payment-date">Payment date</label><Input id="payment-date" name="paid_on" type="date" required {...field('paid_on')} />{fieldError('paid_on')}</div>
          <div className="field-group"><label htmlFor="payment-method">Payment method</label><select id="payment-method" name="method" required defaultValue="" {...field('method')}><option value="" disabled>Select payment method</option>{Object.entries(methods).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{fieldError('method')}</div>
        </div>
        <div className="field-group"><label htmlFor="payment-reference">Payment reference</label><Input id="payment-reference" name="reference" required maxLength={100} placeholder="Transaction, receipt, or check number" {...field('reference')} />{fieldError('reference')}</div>
        <div className="field-group"><label htmlFor="payment-proof">Proof of payment</label><Input id="payment-proof" name="proof" type="file" accept=".pdf,.jpg,.jpeg,.png" required {...field('proof')} /><p className="pos-save-hint">PDF, JPG, or PNG - Maximum 10 MB</p>{fieldError('proof')}</div>
        <div className="field-group"><label htmlFor="payment-notes">Payment notes (optional)</label><textarea id="payment-notes" name="notes" rows={3} maxLength={5000} placeholder="Bank, payee, or other payment details" {...field('notes')} />{fieldError('notes')}</div>
        <p className="pos-save-hint">Submitting records the payment as complete and removes this renewal from the Finance queue.</p>
        <div className="pos-form-actions"><Button type="submit">{saving ? <><LoaderCircle className="animate-spin" />Saving...</> : 'Save payment and mark paid'}</Button></div>
      </fieldset>
    </form>}
  </section>;
}
