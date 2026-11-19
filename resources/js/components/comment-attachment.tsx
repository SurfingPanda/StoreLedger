import { Download, FileText, FileImage } from 'lucide-react';
import AttachmentPreview from '@/components/attachment-preview';

export type CommentFile = { attachment_url?: string | null; attachment_name?: string | null; attachment_size?: number | null; attachment_mime?: string | null };
export function attachmentSize(size: number) { return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / (1024 * 1024)).toFixed(1)} MB`; }

export default function CommentAttachment({ attachment_url: url, attachment_name: name, attachment_mime: mime, attachment_size: size }: CommentFile) {
  if (!url || !name) return null;
  const isImage = ['image/jpeg', 'image/png'].includes(mime ?? '');
  const canPreview = isImage || mime === 'application/pdf';
  return <div className="comment-attachment-card">
    {isImage ? <img className="comment-attachment-thumbnail" src={`${url}?preview=1`} alt="" loading="lazy" /> : <span className="comment-attachment-icon" aria-hidden="true">{mime?.startsWith('image/') ? <FileImage size={22} /> : <FileText size={22} />}</span>}
    <div className="comment-attachment-details">
      {canPreview ? <AttachmentPreview url={url} name={name} /> : <a className="comment-attachment-name" href={url}>{name}</a>}
      <small>{size != null ? attachmentSize(size) : 'Attachment'}{canPreview ? ' - Click to preview' : ' - Download to open'}</small>
    </div>
    <a className="comment-attachment-download" href={url} aria-label={`Download ${name}`} title="Download attachment"><Download size={17} /></a>
  </div>;
}
