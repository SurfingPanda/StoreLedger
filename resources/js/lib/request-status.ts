export const REQUEST_STATUSES = {
  awaiting_soa: { label: 'Awaiting SOA', tone: 'soa' },
  awaiting_voucher: { label: 'Awaiting voucher', tone: 'voucher' },
  vouchered: { label: 'Awaiting payment', tone: 'payment' },
  needs_revision: { label: 'Needs revision', tone: 'revision' },
  paid: { label: 'Paid', tone: 'paid' },
  cancelled: { label: 'Cancelled', tone: 'cancelled' },
} as const;

export function requestStatus(status: string) {
  return REQUEST_STATUSES[status as keyof typeof REQUEST_STATUSES] ?? { label: status.replaceAll('_', ' '), tone: 'unknown' };
}

export function requestStatusLabel(status: string) { return requestStatus(status).label; }
