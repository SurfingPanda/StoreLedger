import ResponsiveTable from '@/components/responsive-table';
import RequestTimeline, { type Timeline } from '@/components/request-timeline';
import { useTablePreference } from '@/lib/table-preferences';
import RequestStatus from '@/components/request-status';
import { requestStatusLabel as status } from '@/lib/request-status';
import RequestNextAction from '@/components/request-next-action';
import { attachmentSize, type CommentFile } from '@/components/comment-attachment';
import CancelRequestDialog from '@/components/cancel-request-dialog';
import { useTableSort } from '@/components/table-sort';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, CheckCircle2, ClipboardList, History, LoaderCircle, MessageSquare, Paperclip, X } from 'lucide-react';
import AttachmentPreview from '@/components/attachment-preview';
import DuplicateBills from '@/components/duplicate-bills';
import { type DuplicateBill } from '@/lib/auth';
import RequestRevisionPanel, { RevisionHistoryPanel, type RevisionPermissions, type RevisionHistory } from '@/pages/request-revisions';
import { TablePagination } from '@/components/table-presentation';
import { Button } from '@/components/ui/button';
import RecurringBillingPanel, { CurrentSoaForm, type Recurrence } from '@/pages/recurring-billing';
import PosPayment, { type Payment } from '@/pages/pos-payment';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';
import { requestModule } from '@/lib/request-module';

type Person = { name: string; role: string };
type Renewal = { cancellation_reason: string | null; workflow_version: number; billing_account_id: number | null; revision_target: string | null; revision_reason: string | null; returned_at: string | null; store_id: number | null; store: { code: string; name: string } | null; account_number: string | null; billing_account: { billing_cycle_months: number | null } | null; duplicate_cancelled_at: string | null; provider: string | null; billing_period: string | null; id: number; store_name: string; amount: string; renewal_date: string; status: string; created_at: string; creator: Person | null; voucher_actor: Person | null; voucher_action_at: string | null; voucher_reference: string | null; detail_url?: string; payment: Payment | null; recurrence: Recurrence | null; recurring_schedule_id: number | null; due_date?: string; attachment_url?: string; attachment_name?: string; purchase_request_url?: string | null; purchase_request_name?: string | null };
type Comment = CommentFile & { id: number; body: string; created_at: string; author: Person | null };
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Details = { timeline: Timeline; revisions: RevisionHistory; revision_permissions: RevisionPermissions; duplicates: DuplicateBill[]; record: Renewal; history: Page<Renewal>; comments: Page<Comment> };
const money = (value: string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
const day = (value: string) => new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
const time = (value: string) => new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });


export default function PosVoucher({ data }: { data: AppData }) {
  const { isSoa, label: moduleLabel, prefix, recordsUrl, listUrl } = requestModule(data);
  const base = `${recordsUrl}/${data.renewalId}`;
  const accounting = data.user?.role === 'accounting';
  const finance = data.user?.role === 'finance';
  const submissionModule = data.page === 'pos-voucher' ? 'utak-pos' : data.page === 'internet-voucher' ? 'internet-soa' : data.page.replace('-voucher', '');
  const rsoSubmission = ['rental', 'electricity', 'water'].includes(submissionModule);
  const canSubmitSoa = data.user?.role === 'admin' || (data.user?.role === 'rso' && rsoSubmission);
  const [details, setDetails] = useState<Details | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [revision, setRevision] = useState(0);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyPerPage, setHistoryPerPage] = useTablePreference('historyPerPage', 10);
  const historySort = useTableSort('date', 'desc', () => setHistoryPage(1));
  const [timelinePage, setTimelinePage] = useState(1);
  const [timelinePerPage, setTimelinePerPage] = useTablePreference('timelinePerPage', 15);
  const [timelineDirection, setTimelineDirection] = useTablePreference<'asc' | 'desc'>('timelineDirection', 'desc');
  const [revisionsPage, setRevisionsPage] = useState(1);
  const [revisionsPerPage, setRevisionsPerPage] = useTablePreference('revisionsPerPage', 10);
  const [body, setBody] = useState('');
  const [commentFile, setCommentFile] = useState<File | null>(null);
  const [commentError, setCommentError] = useState('');
  const commentFileInput = useRef<HTMLInputElement>(null);
  const [reference, setReference] = useState('');
  const [cancellationReason, setCancellationReason] = useState('');
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [action, setAction] = useState<'process' | 'cancel' | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setLoadError('');
    fetch(`${base}/details?history_per_page=${historyPerPage}&revisions_per_page=${revisionsPerPage}&history_sort=${historySort.sort}&history_direction=${historySort.direction}&history_page=${historyPage}&timeline_page=${timelinePage}&timeline_per_page=${timelinePerPage}&timeline_direction=${timelineDirection}&revisions_page=${revisionsPage}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => {
        if (!response.ok) throw new Error(response.status === 404 ? 'This renewal could not be found.' : 'Could not load the voucher details. Refresh or try again.');
        return response.json() as Promise<Details>;
      })
      .then(result => setDetails(isSoa ? { ...result, record: { ...result.record, renewal_date: result.record.due_date! }, history: { ...result.history, data: result.history.data.map(row => ({ ...row, renewal_date: row.due_date! })) } } : result))
      .catch(error => { if (!controller.signal.aborted) setLoadError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [base, isSoa, historyPage, timelinePage, timelinePerPage, timelineDirection, revisionsPage, revision, historySort.sort, historySort.direction, historyPerPage, revisionsPerPage]);

  async function submit(kind: 'comment' | 'process' | 'cancel', commentData?: FormData) {
    if (busyRef.current) return;
    busyRef.current = true; setBusy(true); setError(''); setCommentError(''); setSuccess('');
    try {
      const result = await post<{ message: string }>(`${base}/${kind === 'comment' ? 'comments' : kind === 'cancel' && record?.status === 'vouchered' ? 'duplicate-cancel' : 'voucher'}`, kind === 'comment' ? commentData! : { workflow_version: record?.workflow_version, action: kind, voucher_reference: reference, cancellation_reason: cancellationReason });
      setSuccess(result.message);
      if (kind === 'comment') { setBody(''); setCommentFile(null); if (commentFileInput.current) commentFileInput.current.value = ''; setTimelinePage(1); setTimelineDirection('desc'); }
      setAction(null); setRevision(value => value + 1);
    } catch (error) {
      (kind === 'comment' ? setCommentError : setError)(error instanceof RequestError ? (Object.values(error.errors).flat().join(' ') || error.message) : 'Could not save. Please try again.');
    } finally { busyRef.current = false; setBusy(false); }
  }
  function comment(event: FormEvent<HTMLFormElement>) { event.preventDefault(); void submit('comment', new FormData(event.currentTarget)); }
  const record = details?.record;
  function prepareVoucher() {
    if (busy || loading) return;
    setError('');
    if (!reference.trim()) {
      setError('Enter the voucher reference before processing.');
      document.getElementById('voucher-reference')?.focus();
      return;
    }
    setAction('process');
  }
  return <div className="pos-page voucher-detail-page">
    <a className="voucher-back" href={listUrl}><ArrowLeft size={16} />Back to {moduleLabel}</a>
    <div className="dashboard-heading request-detail-heading"><div><p className="dashboard-kicker">{moduleLabel.toUpperCase()} / REQUEST DETAILS</p><div className="request-title-row"><h1>{record?.store_name ?? 'Renewal details'}</h1>{record && <RequestStatus status={record.status} prominent />}</div><p>Review the voucher, payment, comments, and store history.</p></div></div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}</div>}
    {error && !action && <div className="form-alert" role="alert">{error}</div>}
    {record && details && !loadError && <RequestNextAction status={record.status} role={data.user?.role ?? ''} revisionTarget={record.revision_target} canCorrect={details.revision_permissions.can_correct} canReopen={details.revision_permissions.can_reopen} canSubmitSoa={canSubmitSoa} sharedSubmission={rsoSubmission} hasDuplicates={details.duplicates.length > 0} hasPayment={!!record.payment} hasVoucherReference={!!reference.trim()} disabled={busy || loading} onProcessVoucher={prepareVoucher} />}
    {details && <><div id="request-duplicates"><DuplicateBills matches={details.duplicates} /></div>{details.duplicates.length > 0 && record?.status === 'vouchered' && (accounting || data.user?.role === 'admin') && <Button variant="outline" disabled={busy || loading} onClick={() => { setError(''); setCancellationReason(''); setAction('cancel'); }}>Cancel this duplicate request</Button>}</>}
    {record && !loadError && <nav className="request-section-nav" aria-label="Request sections">{(record.payment || (finance && record.status === 'vouchered')) && <a href="#payment-panel">{record.payment ? 'Payment details' : 'Payment form'}</a>}<a href="#renewal-details">{isSoa ? 'SOA details' : 'Renewal details'}</a><a href="#request-activity">Activity timeline</a><a href="#revision-history">Revision history</a><a href="#renewal-history">Store history</a></nav>}
    {loadError ? <div className="pos-load-error" role="alert"><p>{loadError}</p><Button variant="outline" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : !record || !details ? <div className="register-empty" role="status"><LoaderCircle className="animate-spin" /><p>Loading voucher details...</p></div> : <div className="voucher-detail-layout" aria-busy={loading}>
        <RequestRevisionPanel key={`revision-${record.workflow_version}`} record={record} permissions={details.revision_permissions} data={data} base={base} module={data.page === 'pos-voucher' ? 'utak-pos' : data.page === 'internet-voucher' ? 'internet-soa' : data.page.replace('-voucher', '')} isSoa={isSoa} disabled={busy || loading} onBusy={setBusy} onSaved={message => { setSuccess(message); setError(''); setRevision(value => value + 1); }} />
        {record.status === 'awaiting_soa'  && <CurrentSoaForm amount={record.amount} base={base} canSubmit={canSubmitSoa} submitterLabel={rsoSubmission ? 'RSO or Admin' : 'Admin'} onSaved={message => { setSuccess(message); setRevision(value => value + 1); }} />}
    <PosPayment workflowVersion={record.workflow_version} payment={record.payment} canPay={finance && record.status === 'vouchered'} amount={record.amount} base={base} disabled={busy || loading} onSaved={message => { setSuccess(message); setError(''); setRevision(value => value + 1); document.getElementById('workspace-scroll-region')?.scrollTo({ top: 0, behavior: 'smooth' }); }} />
      <div>
        <section id="renewal-details" className="register-panel voucher-detail-card"><div className="section-heading"><div><h2><ClipboardList size={18} />{isSoa ? 'SOA details' : 'Renewal details'}</h2><p>{prefix}-{String(record.id).padStart(5, '0')}</p></div></div><dl className="voucher-detail-fields"><div><dt>Store ID</dt><dd>{record.store?.code || 'Not linked'}{record.store && <small className="history-meta">Current name: {record.store.name}</small>}</dd></div><div><dt>Supplier account number</dt><dd>{record.account_number || 'Not recorded'}</dd></div><div><dt>Billing cycle</dt><dd>{record.billing_account?.billing_cycle_months ? `${record.billing_account.billing_cycle_months} month(s)` : 'Not configured'}</dd></div><div><dt>Provider / landlord</dt><dd>{record.provider || 'Not recorded'}</dd></div><div><dt>Billing month</dt><dd>{record.billing_period || record.renewal_date.slice(0, 7)}{!record.billing_period && ' (from due date)'}</dd></div>
          <div><dt>Store</dt><dd>{record.store_name}</dd></div><div><dt>{record.status === 'awaiting_soa' ? 'Expected amount (confirm against SOA)' : 'Amount'}</dt><dd>{money(record.amount)}</dd></div>
          <div><dt>{isSoa ? 'Due date' : 'Renewal date'}</dt><dd>{day(record.renewal_date)}</dd></div><div><dt>Status</dt><dd><RequestStatus status={record.status} /></dd></div>
          <div><dt>Created by</dt><dd>{record.creator?.name ?? 'Unknown user'}</dd></div><div><dt>Date created</dt><dd>{time(record.created_at)}</dd></div>
          {record.voucher_action_at && <><div><dt>{record.status === 'needs_revision' ? 'Previous voucher processor' : record.status === 'cancelled' ? 'Cancelled by' : 'Processed by'}</dt><dd>{record.voucher_actor?.name ?? 'Unknown user'}</dd></div><div><dt>Action date</dt><dd>{time(record.voucher_action_at)}</dd></div></>}
          {record.cancellation_reason && <div><dt>Cancellation reason</dt><dd>{record.cancellation_reason}</dd></div>}
          {record.voucher_reference && <div><dt>{record.status === 'needs_revision' ? 'Previous voucher reference (under revision)' : 'Voucher reference'}</dt><dd>{record.voucher_reference}</dd></div>}
          {isSoa && <div className="original-soa-attachment"><dt>Original statement of account</dt><dd>{record.attachment_name ? <AttachmentPreview url={record.attachment_url} name={record.attachment_name} /> : 'Awaiting this cycle?s SOA'}</dd></div>}
          {record.purchase_request_url && record.purchase_request_name && <div className="original-soa-attachment"><dt>Purchase Request (PR)</dt><dd><AttachmentPreview url={record.purchase_request_url} name={record.purchase_request_name} /></dd></div>}
        </dl>
        {data.user?.role === 'admin' && ['awaiting_soa', 'awaiting_voucher'].includes(record.status) && <form className="recurrence-form" onSubmit={async event => { event.preventDefault(); if (busyRef.current) return; const values = new FormData(event.currentTarget); values.set('workflow_version', String(record.workflow_version)); busyRef.current = true; setBusy(true); setError(''); try { const result = await post<{ message: string }>(`${base}/billing-metadata`, values); setSuccess(result.message); setRevision(value => value + 1); } catch (error) { setError(error instanceof RequestError ? Object.values(error.errors).flat().join(' ') || error.message : 'Could not save billing details.'); } finally { busyRef.current = false; setBusy(false); } }}><p className="pos-save-hint">Correct missing provider details or the billing month to resolve a false duplicate match.</p><fieldset className="soa-form-fields" disabled={busy || loading}><div className="user-form-grid"><div className="field-group"><label htmlFor="edit-provider">Provider / landlord</label><Input id="edit-provider" name="provider" readOnly={!!record.billing_account} defaultValue={record.provider ?? ''} required maxLength={150} /></div><div className="field-group"><label htmlFor="edit-period">Billing month</label><Input id="edit-period" name="billing_period" type="month" defaultValue={record.billing_period || record.renewal_date.slice(0, 7)} required /></div></div><Button type="submit" size="sm" variant="outline">Save billing details</Button></fieldset></form>}
        {accounting && record.status === 'awaiting_voucher' && <div className="voucher-completion"><label htmlFor="voucher-reference">Voucher reference <span>*</span></label><Input id="voucher-reference" required value={reference} maxLength={100} disabled={busy} onChange={event => setReference(event.target.value)} placeholder="Enter the voucher number or reference" /><p>Complete this step after preparing the voucher. The request will leave the pending queue.</p><div className="pos-form-actions"><Button variant="outline" disabled={busy || loading} onClick={() => { setError(''); setCancellationReason(''); setAction('cancel'); }}>Cancel request</Button><Button disabled={busy || loading} onClick={prepareVoucher}>Mark voucher processed</Button></div></div>}
        </section>
        <RecurringBillingPanel defaultCycle={record.billing_account?.billing_cycle_months ?? undefined} recurrence={record.recurrence} amount={record.amount} isSoa={isSoa} admin={data.user?.role === 'admin'} base={base} onSaved={() => setRevision(value => value + 1)} />
        <section className="register-panel renewal-progress-panel"><div className="section-heading"><div><h2><ClipboardList size={18} />Request progress</h2><p>Current responsibility and the next step for this request.</p></div></div>
          <div className="renewal-progress-stages" aria-label="Request stages">
            <div className="progress-stage stage-complete"><span><CheckCircle2 size={17} /></span><div><strong>Request created</strong><small>{day(record.created_at)}</small></div></div>
            <div className={`progress-stage ${record.status === 'needs_revision' ? 'stage-current' : record.status === 'cancelled' ? 'stage-cancelled' : record.voucher_action_at ? 'stage-complete' : 'stage-current'}`}><span>{record.voucher_action_at && record.status !== 'cancelled' ? <CheckCircle2 size={17} /> : '2'}</span><div><strong>{record.status === 'cancelled' && !record.duplicate_cancelled_at ? 'Request cancelled' : 'Accounting voucher'}</strong><small>{record.status === 'needs_revision' ? 'Returned for correction' : record.voucher_action_at ? day(record.voucher_action_at) : 'Awaiting processing'}</small></div></div>
            <div className={`progress-stage ${record.payment ? 'stage-complete' : record.status === 'vouchered' ? 'stage-current' : ''}`}><span>{record.payment ? <CheckCircle2 size={17} /> : '3'}</span><div><strong>Finance payment</strong><small>{record.payment ? day(record.payment.paid_on) : record.status === 'cancelled' ? 'Not required' : record.status === 'vouchered' ? 'Awaiting payment' : 'After voucher processing'}</small></div></div>
          </div>

          <dl className="renewal-progress-totals"><div><dt>Amount awaiting payment</dt><dd>{money(record.payment || record.status === 'cancelled' ? '0' : record.amount)}</dd></div><div><dt>Comments</dt><dd>{details.comments.total}</dd></div><div><dt>{isSoa ? 'Store SOAs' : 'Store renewals'}</dt><dd>{details.history.total}</dd></div></dl>
        </section>


      </div>
      <section id="request-activity" className="register-panel voucher-activity"><div className="section-heading"><div><h2><MessageSquare size={18} />Activity timeline</h2><p>Comments, attachments, returns, vouchers, and payments in one timeline.</p></div></div>
        {(accounting || finance) && <form className="voucher-comment-form" onSubmit={comment} encType="multipart/form-data">
          <label htmlFor="voucher-comment">Add a comment</label>
          <textarea id="voucher-comment" name="body" value={body} onChange={event => setBody(event.target.value)} required={!commentFile} maxLength={5000} rows={4} disabled={busy} placeholder="Add voucher notes, follow-ups, or processing details..." />
          <input ref={commentFileInput} id="comment-attachment" name="attachment" type="file" hidden disabled={busy} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" aria-label="Attach a file or image to your comment" onChange={event => {
            const file = event.target.files?.[0] ?? null; setCommentError('');
            if (file && file.size > 10 * 1024 * 1024) { setCommentError('Choose a file smaller than 10 MB.'); event.target.value = ''; setCommentFile(null); return; }
            setCommentFile(file);
          }} />
          {commentFile && <div className="comment-selected-file"><Paperclip size={17} /><span><strong>{commentFile.name}</strong><small>{attachmentSize(commentFile.size)}</small></span><button type="button" disabled={busy} aria-label="Remove selected attachment" onClick={() => { setCommentFile(null); if (commentFileInput.current) commentFileInput.current.value = ''; }}><X size={16} /></button></div>}
          {commentError && <p className="field-error" role="alert">{commentError}</p>}
          <div className="comment-compose-actions"><button type="button" className="comment-attach-button" disabled={busy} onClick={() => commentFileInput.current?.click()}><Paperclip size={15} />{commentFile ? 'Change attachment' : 'Attach file or image'}</button><small>{body.length}/5000</small><Button size="sm" disabled={busy || (!body.trim() && !commentFile)} type="submit">{busy ? 'Saving...' : 'Add comment'}</Button></div>
          <p className="comment-upload-hint">PDF, JPG/PNG, Word, Excel, CSV or text. One file per comment, up to 10 MB.</p>
        </form>}
        <RequestTimeline timeline={details.timeline} direction={timelineDirection} onDirection={value => { setTimelineDirection(value); setTimelinePage(1); }} perPage={timelinePerPage} onPerPage={setTimelinePerPage} onPage={setTimelinePage} disabled={loading || busy} />
      </section>
        <RevisionHistoryPanel history={details.revisions} disabled={loading || busy} onPage={setRevisionsPage} perPage={revisionsPerPage} onPerPage={setRevisionsPerPage} />
        <section id="renewal-history" className="register-panel voucher-history voucher-history-full"><div className="section-heading"><div><h2><History size={18} />{isSoa ? 'Store SOA history' : 'Store renewal history'}</h2><p>Voucher dates, payments and references linked to this store ID, including previous store names.</p></div><span className="record-count">{details.history.total} {details.history.total === 1 ? 'record' : 'records'}</span></div>
          <div className="voucher-table-scroll" role="region" aria-label={isSoa ? 'Store SOA history' : 'Store renewal history'} tabIndex={0}><ResponsiveTable className="ledger-table voucher-table voucher-audit-table"><thead><tr>{historySort.header('id', 'RECORD / CREATED')}{historySort.header('date', isSoa ? 'DUE DATE' : 'RENEWAL DATE')}{historySort.header('amount', 'AMOUNT', true)}{historySort.header('voucher_action_at', 'DATE VOUCHERED')}{historySort.header('paid_on', 'DATE PAID / PAYMENT')}{historySort.header('status', 'STATUS')}</tr></thead><tbody>{details.history.data.map(row => <tr key={row.id} className={row.id === record.id ? 'voucher-current-row' : ''}>
            <td><a className="history-record-link" href={row.detail_url}>{prefix}-{String(row.id).padStart(5, '0')}</a>{row.id === record.id && <span className="history-current-tag">Current</span>}<small className="history-meta">Created {time(row.created_at)}</small><small className="history-meta">By {row.creator?.name ?? 'Unknown user'}</small></td>
            <td><strong className="history-date">{day(row.renewal_date)}</strong></td>
            <td className="amount-column">{money(row.amount)}</td>
            <td>{row.voucher_action_at && (row.status !== 'cancelled' || row.duplicate_cancelled_at) ? <><strong className="history-date">{time(row.voucher_action_at)}</strong><small className="history-meta">By {row.voucher_actor?.name ?? 'Unknown user'}</small><small className="history-reference">Ref: {row.voucher_reference || 'Not provided'}</small></> : <span className="history-pending">{row.status === 'cancelled' ? 'Not processed' : status(row.status)}</span>}</td>
            <td>{row.payment ? <><strong className="history-date">{day(row.payment.paid_on)}</strong><small className="history-meta">{money(row.payment.amount)} / {row.payment.method.replaceAll('_', ' ')}</small><small className="history-reference">Ref: {row.payment.reference}</small><small className="history-meta">By {row.payment.recorded_by?.name ?? 'Unknown user'}</small><small className="history-meta">Recorded {time(row.payment.created_at)}</small>{row.payment.notes && <details className="history-payment-notes"><summary>Payment notes</summary><p>{row.payment.notes}</p></details>}</> : <span className="history-pending">{row.status === 'cancelled' ? 'Not paid' : status(row.status)}</span>}</td>
            <td><RequestStatus status={row.status} />{row.duplicate_cancelled_at && <small className="history-meta">Duplicate cancelled {time(row.duplicate_cancelled_at)}</small>}{row.cancellation_reason && <details className="history-payment-notes"><summary>Cancellation reason</summary><p>{row.cancellation_reason}</p></details>}{row.status === 'cancelled' && !row.duplicate_cancelled_at && row.voucher_action_at && <><small className="history-meta">{time(row.voucher_action_at)}</small><small className="history-meta">By {row.voucher_actor?.name ?? 'Unknown user'}</small></>}</td>
          </tr>)}</tbody></ResponsiveTable></div>
          <Pager total={details.history.total} perPage={historyPerPage} onPerPage={setHistoryPerPage} page={historyPage} last={details.history.last_page} disabled={loading || busy} change={setHistoryPage} label="history" />
        </section>
    </div>}
    <CancelRequestDialog open={action === 'cancel'} onOpenChange={value => { if (!value) setAction(null); }} storeName={record?.store_name} recordLabel={record ? `${prefix}-${String(record.id).padStart(5, '0')}` : undefined} amount={record ? money(record.amount) : undefined} reason={cancellationReason} onReasonChange={setCancellationReason} busy={busy} error={error} onConfirm={() => void submit('cancel')} />
    <Dialog open={action === 'process'} onOpenChange={value => { if (!value && !busyRef.current) setAction(null); }}><DialogContent><DialogTitle>Mark voucher processed?</DialogTitle><DialogDescription>Confirm that the voucher is ready. This records you as the processor and removes the request from the pending queue.</DialogDescription>{error && <div className="form-alert" role="alert">{error}</div>}<div className="pos-form-actions"><Button variant="outline" disabled={busy} onClick={() => setAction(null)}>Go back</Button><Button disabled={busy || !reference.trim()} onClick={() => void submit('process')}>{busy ? 'Saving...' : 'Confirm'}</Button></div></DialogContent></Dialog>
  </div>;
}

function Pager({ page, last, disabled, change, label, total, perPage, onPerPage }: { total: number; perPage: number; onPerPage?: (size: number) => void; page: number; last: number; disabled: boolean; change: (page: number) => void; label: string }) {
  return <TablePagination total={total} from={total ? (page - 1) * perPage + 1 : 0} to={Math.min(page * perPage, total)} page={page} lastPage={last} disabled={disabled} onPage={change} label={label} perPage={perPage} onPerPage={onPerPage} />;
}
