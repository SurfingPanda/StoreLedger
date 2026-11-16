export type AppData = {
  assets: { mascot: string; logo: string };
  page: 'login' | 'forgot-password' | 'reset-password' | 'dashboard' | 'utak-pos' | 'pos-voucher' | 'internet-soa' | 'internet-voucher' | 'electricity' | 'rental' | 'water' | 'water-voucher' | 'electricity-voucher' | 'rental-voucher' | 'settings';
  renewalId?: number;
  email: string;
  token: string;
  user: { name: string; email: string; role: string } | null;
  urls: Record<'login' | 'logout' | 'forgotPassword' | 'sendResetLink' | 'resetPassword' | 'dashboard' | 'pos' | 'posRecords' | 'internetSoa' | 'internetSoaRecords' | 'electricity' | 'electricityRecords' | 'rental' | 'rentalRecords' | 'water' | 'waterRecords' | 'settings' | 'notificationSettings', string>;
};

export class RequestError extends Error {
  constructor(message: string, public errors: Record<string, string[]> = {}) { super(message); }
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
    throw new RequestError(message, result.errors ?? {});
  }
  return result as T;
}
