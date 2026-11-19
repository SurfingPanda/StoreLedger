import { ClipboardList, CornerUpLeft, CreditCard, FileCheck2, MessageSquare, Paperclip, XCircle } from 'lucide-react';
import CommentAttachment, { type CommentFile } from '@/components/comment-attachment';
import { TablePagination } from '@/components/table-presentation';

type TimelineEvent = {
  id: string; type: string; title: string; occurred_at: string;
  actor: { name: string; role?: string } | null; body: string;
  attachments: CommentFile[]; revision_id: number | null;
};
export type Timeline = { data: TimelineEvent[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
const icons = { created: ClipboardList, comment: MessageSquare, attachment: Paperclip, revision: CornerUpLeft, voucher: FileCheck2, payment: CreditCard, cancelled: XCircle };

export default function RequestTimeline({ timeline, direction, onDirection, perPage, onPerPage, onPage, disabled }: {
  timeline: Timeline; direction: 'asc' | 'desc'; onDirection: (value: 'asc' | 'desc') => void;
  perPage: number; onPerPage: (value: number) => void; onPage: (value: number) => void; disabled: boolean;
}) {
  return <div className="unified-activity" aria-busy={disabled}>
    <div className="timeline-controls"><span>{timeline.total} {timeline.total === 1 ? 'event' : 'events'} <span aria-hidden="true">/</span> Times in Manila</span><label>Order<select value={direction} disabled={disabled} onChange={event => onDirection(event.target.value as 'asc' | 'desc')}><option value="desc">Newest first</option><option value="asc">Oldest first</option></select></label></div>
    <ol className="unified-timeline" aria-label="Request activity in chronological order">
      {timeline.data.map(event => {
        const Icon = icons[event.type as keyof typeof icons] ?? ClipboardList;
        return <li key={event.id} className={`timeline-event timeline-${event.type}`}>
          <span className="timeline-event-icon" aria-hidden="true"><Icon size={16} /></span>
          <article><div className="timeline-event-heading"><h3>{event.title}</h3><time dateTime={event.occurred_at}>{new Date(event.occurred_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })}</time></div>
            <div className="timeline-event-actor">{event.actor?.name ?? 'System / recorded history'}{event.actor?.role && <span>{event.actor.role}</span>}</div>
            {event.body && <p>{event.body}</p>}
            {event.attachments.map((attachment, index) => <CommentAttachment key={`${attachment.attachment_url}-${index}`} {...attachment} attachment_mime={attachment.attachment_mime ?? (/\.pdf$/i.test(attachment.attachment_name ?? '') ? 'application/pdf' : /\.png$/i.test(attachment.attachment_name ?? '') ? 'image/png' : /\.jpe?g$/i.test(attachment.attachment_name ?? '') ? 'image/jpeg' : undefined)} />)}
            {event.revision_id && <a className="timeline-revision-link" href="#revision-history">View revision details</a>}
          </article>
        </li>;
      })}
    </ol>
    {!timeline.total && <p className="voucher-no-comments">No activity recorded yet.</p>}
    <TablePagination page={timeline.current_page} lastPage={timeline.last_page} total={timeline.total} from={timeline.from ?? 0} to={timeline.to ?? 0} disabled={disabled} onPage={onPage} label="activity" perPage={perPage} onPerPage={onPerPage} />
  </div>;
}
