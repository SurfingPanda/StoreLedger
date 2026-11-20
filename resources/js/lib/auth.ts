export type AppData = {
  assets: { mascot: string; logo: string };
  page: 'landing' | 'security-audit' | 'automation-health' | 'directory' | 'login' | 'forgot-password' | 'reset-password' | 'dashboard' | 'utak-pos' | 'pos-voucher' | 'internet-soa' | 'internet-voucher' | 'electricity' | 'rental' | 'water' | 'water-voucher' | 'electricity-voucher' | 'rental-voucher' | 'settings' | 'users';
  renewalId?: number;
  email: string;
  loggedOut?: boolean;
  token: string;
  user: { name: string; email: string; role: string } | null;
  urls: Record<'home' | 'login' | 'logout' | 'forgotPassword' | 'sendResetLink' | 'resetPassword' | 'dashboard' | 'dashboardRecords' | 'dashboardActivity' | 'inbox' | 'pos' | 'posRecords' | 'internetSoa' | 'internetSoaRecords' | 'electricity' | 'electricityRecords' | 'rental' | 'rentalRecords' | 'water' | 'waterRecords' | 'settings' | 'notificationSettings' | 'users' | 'userRecords' | 'directory' | 'directoryRecords' | 'automationHealth' | 'automationHealthRecords' | 'securityAudit' | 'securityAuditRecords', string>;
};

export type DuplicateBill = { id: number; store_name: string; provider: string | null; billing_period: string; amount: string; status: string; detail_url: string };

export class RequestError extends Error {
  constructor(message: string, public errors: Record<string, string[]> = {}, public duplicates: DuplicateBill[] = []) { super(message); }
}

export async function post<T = { redirect?: string; message?: string }>(url: string, data: Record<string, unknown> | FormData): Promise<T> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      ...(data instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    },
    body: data instanceof FormData ? data : JSON.stringify(data),
  });
  if (response.status === 419) throw new RequestError('Your session has expired. Refresh this page and try again.');
  const result = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
  if (!response.ok) {
    const message = response.status >= 500 ? 'We couldn’t connect to your workspace. Please try again shortly.'
      : response.status === 429 ? (result.message || 'Too many requests. Please wait a minute and try again.')
      : (result.message || 'Something went wrong. Please try again.');
    throw new RequestError(message, result.errors ?? {}, result.duplicates ?? []);
  }
  return result as T;
}
