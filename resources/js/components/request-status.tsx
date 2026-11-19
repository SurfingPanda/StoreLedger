import { requestStatus } from '@/lib/request-status';

export default function RequestStatus({ status, prominent = false }: { status: string; prominent?: boolean }) {
  const { label, tone } = requestStatus(status);
  return <span className={`status-pill request-status request-tone-${tone}${prominent ? ' request-status-prominent' : ''}`} role={prominent ? 'status' : undefined}><i aria-hidden="true" />{label}</span>;
}
