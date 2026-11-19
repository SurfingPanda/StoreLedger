import { requestStatus, requestStatusLabel } from '@/lib/request-status';
import { ArrowRight, CheckCircle2, CircleAlert, ClipboardCheck, Clock3, CreditCard, FilePenLine, Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
  status: string;
  role: string;
  revisionTarget: string | null;
  canCorrect: boolean;
  canReopen: boolean;
  canSubmitSoa: boolean;
  sharedSubmission: boolean;
  hasDuplicates: boolean;
  hasPayment: boolean;
  hasVoucherReference: boolean;
  disabled: boolean;
  onProcessVoucher: () => void;
};

function focusSection(id: string) {
  const element = document.getElementById(id);
  if (!element) return;
  element.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
  if (!element.matches('input,select,textarea,button,a,[tabindex]')) element.setAttribute('tabindex', '-1');
  element.focus({ preventScroll: true });
}

export default function RequestNextAction(props: Props) {
  const { status, role, revisionTarget, canCorrect, canReopen, canSubmitSoa, sharedSubmission, hasDuplicates, hasPayment, hasVoucherReference, disabled, onProcessVoucher } = props;
  const submitter = sharedSubmission ? 'Admin / RSO' : 'Admin';
  let title = 'Review this request';
  let description = 'Check the saved bill details and activity to see its latest progress.';
  let label = 'View request details';
  let target = 'renewal-details';
  let owner = 'Request overview';
  let actionable = false;
  let tone = 'waiting';
  let Icon = Clock3;
  let processVoucher = false;

  if (status === 'paid') {
    title = 'Payment complete'; description = 'Payment has been recorded. The transaction details and proof are available for reference.';
    label = hasPayment ? 'View payment & proof' : 'View store history'; target = hasPayment ? 'payment-panel' : 'renewal-history';
    owner = requestStatusLabel('paid'); tone = 'complete'; Icon = CheckCircle2;
  } else if (status === 'cancelled') {
    title = canReopen ? 'Admin: Review this cancellation' : 'This request is cancelled';
    description = canReopen ? 'To continue this unpaid request, reopen it with a reason. Corrections must go through Accounting again.' : 'The request is closed. Its details, cancellation reason, and history remain available.';
    label = canReopen ? 'Review reopening' : 'View store history'; target = canReopen ? 'request-revision' : 'renewal-history';
    owner = canReopen ? 'Admin' : requestStatusLabel('cancelled'); Icon = CircleAlert;
  } else if (hasDuplicates) {
    title = 'Review the possible duplicate first'; description = 'A matching bill was found. Admin or Accounting must review it before this request can move forward.';
    label = 'Review matching requests'; target = 'request-duplicates'; owner = 'Admin / Accounting'; tone = 'attention'; Icon = CircleAlert;
  } else if (status === 'awaiting_soa') {
    title = canSubmitSoa ? `${role === 'rso' ? 'RSO' : 'Admin'}: Attach the current SOA` : `Waiting for ${submitter} to attach the SOA`;
    description = 'Upload this billing cycle’s statement and confirm the amount before Accounting prepares the voucher.';
    label = canSubmitSoa ? 'Attach current SOA' : 'View SOA requirements'; target = canSubmitSoa ? 'current-soa-file' : 'current-statement-panel';
    owner = canSubmitSoa ? 'Your action' : submitter; actionable = canSubmitSoa; tone = canSubmitSoa ? 'action' : 'waiting'; Icon = Upload;
  } else if (status === 'needs_revision') {
    const team = revisionTarget === 'accounting' ? 'Accounting' : submitter;
    title = canCorrect ? `${revisionTarget === 'accounting' ? 'Accounting' : role === 'rso' ? 'RSO' : 'Admin'}: Correct ${revisionTarget === 'accounting' ? 'the voucher' : 'the bill details'}` : `Waiting for ${team} corrections`;
    description = revisionTarget === 'accounting' ? 'Review the return reason, correct the voucher reference, and resubmit to Finance.' : 'Review the return reason, update the bill, and resubmit it for Accounting review. Payment is paused.';
    label = canCorrect ? revisionTarget === 'accounting' ? 'Correct voucher' : 'Correct bill details' : 'View return reason';
    target = canCorrect ? revisionTarget === 'accounting' ? 'revision-voucher' : 'revision-amount' : 'request-revision';
    owner = canCorrect ? 'Your action' : team; actionable = canCorrect; tone = 'attention'; Icon = FilePenLine;
  } else if (status === 'awaiting_voucher') {
    const accounting = role === 'accounting';
    title = accounting ? hasVoucherReference ? 'Accounting: Process the voucher' : 'Accounting: Enter the voucher reference' : 'Waiting for Accounting';
    description = accounting ? 'Review the bill and enter its voucher reference. Confirm processing to send the request to Finance.' : 'Accounting needs to review the bill and process its voucher before Finance can record payment.';
    label = accounting ? hasVoucherReference ? 'Review & process voucher' : 'Enter voucher reference' : 'View bill details';
    target = accounting ? 'voucher-reference' : 'renewal-details'; processVoucher = accounting && hasVoucherReference;
    owner = accounting ? 'Your action' : 'Accounting'; actionable = accounting; tone = accounting ? 'action' : 'waiting'; Icon = ClipboardCheck;
  } else if (status === 'vouchered') {
    const finance = role === 'finance';
    title = finance ? 'Finance: Record the payment' : 'Waiting for Finance';
    description = finance ? 'Enter the completed transaction’s date, method, and reference. Attach proof to mark this request as paid.' : 'The voucher is ready. Finance will record the completed payment and attach its proof.';
    label = finance ? 'Record payment' : 'View voucher details'; target = finance ? 'payment-date' : 'renewal-details';
    owner = finance ? 'Your action' : 'Finance'; actionable = finance; tone = finance ? 'action' : 'waiting'; Icon = CreditCard;
  }

  return <section className={`request-next-action next-action-${tone} request-tone-${requestStatus(status).tone}`} aria-labelledby="request-next-action-title">
    <span className="request-next-action-icon" aria-hidden="true"><Icon size={25} /></span>
    <div className="request-next-action-copy"><div className="request-next-action-eyebrow">{tone === 'complete' ? 'REQUEST COMPLETE' : 'NEXT STEP'}<span>{owner}</span></div><h2 id="request-next-action-title">{title}</h2><p>{description}</p></div>
    <Button type="button" variant={actionable ? 'default' : 'outline'} disabled={disabled} onClick={() => processVoucher ? onProcessVoucher() : focusSection(target)}>{label}<ArrowRight size={16} /></Button>
  </section>;
}
