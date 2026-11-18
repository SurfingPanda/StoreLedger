import { useEffect, useState } from 'react';
import { Download, ExternalLink, Eye, FileImage, FileText, LoaderCircle, RefreshCw, ZoomIn, ZoomOut, Info } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

type Preview = { objectUrl: string; mime: string; inlineUrl: string; size: number };
export default function AttachmentPreview({ url, name, label }: { url?: string | null; name: string; label?: string }) {
  const [open, setOpen] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [zoom, setZoom] = useState(1);
  useEffect(() => {
    if (!open || !url) return;
    const controller = new AbortController(); let objectUrl: string | undefined;
    setPreview(null); setLoading(true); setError(''); setZoom(1);
    async function load() {
      try {
        const inlineUrl = new URL(url!, window.location.origin);
        if (inlineUrl.origin !== window.location.origin) throw new Error('This attachment cannot be previewed here.');
        inlineUrl.searchParams.set('preview', '1');
        const response = await fetch(inlineUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
        if (!response.ok) {
          if (response.status === 401 || response.status === 419) throw new Error('Your session expired. Refresh the page and sign in again.');
          if (response.status === 403) throw new Error('You do not have access to this attachment.');
          if (response.status === 404) throw new Error('This attachment could not be found.');
          if (response.status === 415) throw new Error('Preview is unavailable for this file type. Use Download instead.');
          throw new Error('Could not load the attachment preview. Please retry.');
        }
        const mime = response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() ?? '';
        if (!['application/pdf', 'image/jpeg', 'image/png'].includes(mime)) throw new Error('Preview is unavailable for this file type. Use Download instead.');
        const file = await response.blob();
        if (controller.signal.aborted) return;
        objectUrl = URL.createObjectURL(file); setPreview({ objectUrl, mime, inlineUrl: inlineUrl.toString(), size: file.size });
      } catch (error) { if (!controller.signal.aborted) setError(error instanceof Error ? error.message : 'Could not load the preview.'); }
      finally { if (!controller.signal.aborted) setLoading(false); }
    }
    void load();
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [open, url, retry]);
  if (!url) return <span className="history-pending">{label || name}</span>;
  const pdf = preview?.mime === 'application/pdf';
  const fileType = preview ? (pdf ? 'PDF document' : preview.mime === 'image/png' ? 'PNG image' : 'JPEG image') : 'Attachment';
  const fileSize = preview ? (preview.size < 1024 * 1024 ? `${Math.max(1, Math.round(preview.size / 1024))} KB` : `${(preview.size / (1024 * 1024)).toFixed(1)} MB`) : '';
  return (
    <Dialog open={open} onOpenChange={value => { setOpen(value); if (!value) setPreview(null); }}>
      <DialogTrigger asChild>
        <button type="button" className="soa-download attachment-preview-trigger" title={`Preview ${name}`}>
          <Eye size={14} /><span>{label || name}</span>
        </button>
      </DialogTrigger>
      <DialogContent className="attachment-preview-dialog">
        <header className="attachment-preview-header">
          <span className="attachment-preview-icon" aria-hidden="true">
            {preview && !pdf ? <FileImage size={24} /> : <FileText size={24} />}
          </span>
          <div className="attachment-preview-heading">
            <p className="attachment-preview-eyebrow">Attachment preview</p>
            <DialogTitle className="attachment-preview-title" title={name}>{name}</DialogTitle>
            <DialogDescription className="attachment-preview-meta">
              <span>{fileType}</span>{fileSize && <><span aria-hidden="true">?</span><span>{fileSize}</span></>}
              <span className="attachment-preview-readonly">Read only</span>
            </DialogDescription>
          </div>
        </header>

        {preview && !pdf && (
          <div className="attachment-image-tools" role="group" aria-label="Image zoom controls">
            <button type="button" onClick={() => setZoom(value => Math.max(.5, value - .25))} disabled={zoom <= .5} title="Zoom out" aria-label="Zoom out"><ZoomOut size={17} /></button>
            <span aria-live="polite">{Math.round(zoom * 100)}%</span>
            <button type="button" onClick={() => setZoom(value => Math.min(3, value + .25))} disabled={zoom >= 3} title="Zoom in" aria-label="Zoom in"><ZoomIn size={17} /></button>
            <span className="attachment-tools-divider" aria-hidden="true" />
            <button type="button" onClick={() => setZoom(1)} disabled={zoom === 1}>Fit to view</button>
          </div>
        )}

        <div className="attachment-preview-body">
          {loading ? (
            <div className="attachment-preview-state" role="status">
              <span className="attachment-state-icon"><LoaderCircle size={28} className="animate-spin motion-reduce:animate-none" /></span>
              <strong>Preparing your preview</strong><p>Your attachment will appear here in a moment.</p>
            </div>
          ) : error ? (
            <div className="attachment-preview-state" role="alert">
              <span className="attachment-state-icon"><FileText size={28} /></span>
              <strong>Preview unavailable</strong><p>{error}</p>
              <Button size="sm" variant="outline" onClick={() => setRetry(value => value + 1)}><RefreshCw size={14} />Retry preview</Button>
            </div>
          ) : preview ? pdf ? (
            <iframe className="attachment-pdf" src={`${preview.objectUrl}#view=FitH&pagemode=none`} title={`PDF preview: ${name}`} referrerPolicy="no-referrer" onError={() => setError('Your browser could not display this PDF. Open it in a new tab or download it.')} />
          ) : (
            <div className="attachment-image-scroll" style={{ alignItems: zoom < 1 ? 'center' : 'flex-start', justifyContent: zoom < 1 ? 'center' : 'flex-start' }}>
              <div className="attachment-image-stage" style={{ width: `${zoom * 100}%`, height: `${zoom * 100}%` }}>
                <img src={preview.objectUrl} alt={name} onError={() => setError('Your browser could not display this image. Use Download to open it separately.')} />
              </div>
            </div>
          ) : null}
        </div>

        <footer className="attachment-preview-footer">
          <p className="attachment-preview-hint"><Info size={16} /><span>{pdf ? 'Trouble viewing? Open the original in a new tab.' : 'Download to keep a copy of this attachment.'}</span></p>
          <div className="attachment-preview-actions">
            {preview && <Button asChild variant="outline" size="sm" className="attachment-open-button"><a href={preview.inlineUrl} target="_blank" rel="noopener noreferrer"><ExternalLink size={15} />Open in new tab</a></Button>}
            <Button asChild size="sm" className="attachment-download-button"><a href={url}><Download size={15} />Download</a></Button>
          </div>
        </footer>
      </DialogContent>
    </Dialog>
  );
}
