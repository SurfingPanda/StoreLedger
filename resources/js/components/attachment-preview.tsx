import { useEffect, useState } from 'react';
import { Download, ExternalLink, Eye, FileImage, FileText, LoaderCircle, RefreshCw, ZoomIn, ZoomOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

type Preview = { objectUrl: string; mime: string; inlineUrl: string };
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
        objectUrl = URL.createObjectURL(file); setPreview({ objectUrl, mime, inlineUrl: inlineUrl.toString() });
      } catch (error) { if (!controller.signal.aborted) setError(error instanceof Error ? error.message : 'Could not load the preview.'); }
      finally { if (!controller.signal.aborted) setLoading(false); }
    }
    void load();
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [open, url, retry]);
  if (!url) return <span className="history-pending">{label || name}</span>;
  const pdf = preview?.mime === 'application/pdf';
  return <Dialog open={open} onOpenChange={value => { setOpen(value); if (!value) setPreview(null); }}><DialogTrigger asChild><button type="button" className="soa-download attachment-preview-trigger" title={`Preview ${name}`}><Eye size={14} /><span>{label || name}</span></button></DialogTrigger><DialogContent className="user-editor attachment-preview-dialog"><div className="user-editor-header attachment-preview-header"><div className="user-editor-heading"><span className="user-editor-symbol">{preview && !pdf ? <FileImage size={23} /> : <FileText size={23} />}</span><div><p className="user-editor-eyebrow">ATTACHMENT PREVIEW</p><DialogTitle className="user-editor-title" title={name}>{name}</DialogTitle></div></div><DialogDescription className="user-editor-description">{pdf ? 'PDF document. Use the document viewer controls to navigate or zoom.' : preview ? 'Image attachment. Zoom in to check the details.' : 'View this attachment without leaving your request.'}</DialogDescription></div>
    {preview && !pdf && <div className="attachment-image-tools" role="group" aria-label="Image zoom controls"><button type="button" onClick={() => setZoom(value => Math.max(.5, value - .25))} disabled={zoom <= .5} title="Zoom out" aria-label="Zoom out"><ZoomOut size={17} /></button><span aria-live="polite">{Math.round(zoom * 100)}%</span><button type="button" onClick={() => setZoom(value => Math.min(3, value + .25))} disabled={zoom >= 3} title="Zoom in" aria-label="Zoom in"><ZoomIn size={17} /></button><button type="button" onClick={() => setZoom(1)} disabled={zoom === 1}>Fit to view</button></div>}
    <div className="attachment-preview-body">{loading ? <div className="attachment-preview-state" role="status"><LoaderCircle size={29} className="animate-spin motion-reduce:animate-none" /><strong>Loading attachment...</strong></div> : error ? <div className="attachment-preview-state" role="alert"><FileText size={31} /><strong>Preview unavailable</strong><p>{error}</p><Button size="sm" variant="outline" onClick={() => setRetry(value => value + 1)}><RefreshCw size={14} />Retry preview</Button></div> : preview ? pdf ? <iframe className="attachment-pdf" src={`${preview.objectUrl}#view=FitH`} title={`PDF preview: ${name}`} referrerPolicy="no-referrer" onError={() => setError('Your browser could not display this PDF. Open it in a new tab or download it.')} /> : <div className="attachment-image-scroll" style={{ alignItems: zoom < 1 ? 'center' : 'flex-start', justifyContent: zoom < 1 ? 'center' : 'flex-start' }}><div className="attachment-image-stage" style={{ width: `${zoom * 100}%`, height: `${zoom * 100}%` }}><img src={preview.objectUrl} alt={name} onError={() => setError('Your browser could not display this image. Use Download to open it separately.')} /></div></div> : null}</div>
    <div className="user-editor-footer attachment-preview-footer"><span>{pdf ? 'PDF not displaying? Open in a new tab or download it.' : 'Keep a copy using Download.'}</span><div>{preview && <Button asChild variant="outline" size="sm"><a href={preview.inlineUrl} target="_blank" rel="noopener noreferrer"><ExternalLink size={15} />Open in new tab</a></Button>}<Button asChild size="sm"><a href={url}><Download size={15} />Download</a></Button></div></div></DialogContent></Dialog>;
}
