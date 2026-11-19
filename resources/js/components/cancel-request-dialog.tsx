import { CircleX, History, LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  storeName?: string;
  recordLabel?: string;
  amount?: string;
  reason: string;
  onReasonChange: (reason: string) => void;
  busy: boolean;
  error?: string;
  onConfirm: () => void;
};

export default function CancelRequestDialog({ open, onOpenChange, storeName, recordLabel, amount, reason, onReasonChange, busy, error, onConfirm }: Props) {
  return <Dialog open={open} onOpenChange={value => { if (!busy) onOpenChange(value); }}>
    <DialogContent className="cancel-request-dialog" onEscapeKeyDown={event => { if (busy) event.preventDefault(); }} onPointerDownOutside={event => { if (busy) event.preventDefault(); }}>
      <header className="cancel-request-header">
        <span className="cancel-request-icon" aria-hidden="true"><CircleX size={25} /></span>
        <div><p className="cancel-request-eyebrow">REQUEST CANCELLATION</p><DialogTitle className="cancel-request-title">Cancel this request?</DialogTitle></div>
        <DialogDescription>This will close the request and remove it from the active workflow.</DialogDescription>
      </header>
      <form className="cancel-request-form" onSubmit={event => { event.preventDefault(); if (!busy && reason.trim()) onConfirm(); }}>
        <div className="cancel-request-body">
          {storeName && <div className="cancel-request-summary"><div><span>{recordLabel || 'Request'}</span><strong>{storeName}</strong></div>{amount && <strong className="cancel-request-amount">{amount}</strong>}</div>}
          <div className="cancel-request-reason">
            <label htmlFor="request-cancellation-reason">Reason for cancellation <span>*</span></label>
            <textarea id="request-cancellation-reason" required rows={4} maxLength={5000} value={reason} disabled={busy} onChange={event => onReasonChange(event.target.value)} placeholder="Tell us why this request needs to be cancelled..." aria-describedby="request-cancellation-hint" />
            <p id="request-cancellation-hint">This reason will be saved in the request history.</p>
          </div>
          <div className="cancel-request-note"><History size={17} aria-hidden="true" /><p><strong>Your records are kept.</strong> Request details, attachments, and comments remain available for reference.</p></div>
          {error && <div className="form-alert" role="alert">{error}</div>}
        </div>
        <footer className="cancel-request-footer">
          <Button type="button" variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>Keep request</Button>
          <Button type="submit" className="cancel-request-submit" disabled={busy || !reason.trim()}>{busy ? <LoaderCircle size={16} className="animate-spin" /> : <CircleX size={16} />}{busy ? 'Cancelling...' : 'Cancel request'}</Button>
        </footer>
      </form>
    </DialogContent>
  </Dialog>;
}
