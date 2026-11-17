import { useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, ArrowRight, Check, CircleAlert, CircleHelp, Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, Mail } from 'lucide-react';
import { useEffect } from 'react';
import { showSuccess, showError } from '@/lib/alerts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Brand } from '@/components/brand';
import { post, RequestError, type AppData } from '@/lib/auth';

function AccessHelp() {
  return <Dialog>
    <DialogTrigger className="help-link"><CircleHelp size={16} strokeWidth={1.6} /><span>Need a hand?</span></DialogTrigger>
    <DialogContent>
      <span className="dialog-symbol"><KeyRound size={21} strokeWidth={1.5} /></span>
      <DialogTitle className="text-2xl font-medium tracking-tight">A little help getting in.</DialogTitle>
      <DialogDescription className="text-sm leading-6 text-muted-foreground">BW Operations is a private workspace. To get an account or update your access, contact your workspace administrator. If you already have an account, use “Forgot password?” to reset your password.</DialogDescription>
      <DialogClose asChild><Button className="mt-1 w-full">Got it</Button></DialogClose>
    </DialogContent>
  </Dialog>;
}

function PasswordInput({ id, label, confirm = false, invalid, error, reset = false }: { id: string; label: string; confirm?: boolean; invalid?: boolean; error?: string; reset?: boolean }) {
  const [visible, setVisible] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  return <div className="field-group">
    <label htmlFor={id}>{label}</label>
    <div className="input-wrap">
      <LockKeyhole className="input-icon" size={17} strokeWidth={1.5} />
      <Input id={id} name={confirm ? 'password_confirmation' : 'password'} type={visible ? 'text' : 'password'} autoComplete={reset ? 'new-password' : 'current-password'} placeholder={reset ? (confirm ? 'Enter your password again' : 'Create a new password') : 'Enter your password'} required minLength={reset ? 12 : undefined} maxLength={reset ? 128 : 1024} aria-invalid={invalid || undefined} aria-describedby={error ? `${id}-error` : capsLock ? `${id}-caps` : reset && !confirm ? `${id}-hint` : undefined} className="pl-11 pr-12" onKeyUp={e => setCapsLock(e.getModifierState('CapsLock'))} onKeyDown={e => setCapsLock(e.getModifierState('CapsLock'))} onBlur={() => setCapsLock(false)} />
      <button type="button" className="password-toggle" onClick={() => setVisible(!visible)} aria-label={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`} aria-pressed={visible}>{visible ? <EyeOff size={17} strokeWidth={1.5} /> : <Eye size={17} strokeWidth={1.5} />}</button>
    </div>
    {error ? <p className="field-error" id={`${id}-error`} role="alert">{error}</p> : capsLock ? <p className="field-hint" id={`${id}-caps`}>Caps lock is on.</p> : reset && !confirm ? <p className="field-hint" id={`${id}-hint`}>Use at least 12 characters.</p> : null}
  </div>;
}

export default function AuthPage({ data }: { data: AppData }) {
  const mode = data.page;
  const login = mode === 'login';
  const reset = mode === 'reset-password';
  const [remember, setRemember] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const submitting = useRef(false);
  const formRef = useRef<HTMLFormElement>(null);
  const logoutNoticeShown = useRef(false);

  useEffect(() => {
    if (!login || !data.loggedOut || logoutNoticeShown.current) return;
    logoutNoticeShown.current = true;
    void showSuccess('Signed out successfully', 'You have been safely signed out of your workspace.');
  }, [login, data.loggedOut]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting.current) return;
    submitting.current = true;
    setBusy(true); setError(''); setErrors({});
    const values = Object.fromEntries(new FormData(event.currentTarget));
    try {
      const url = login ? data.urls.login : reset ? data.urls.resetPassword : data.urls.sendResetLink;
      const result = await post(url, { ...values, email: String(values.email ?? '').trim(), remember, ...(reset ? { token: data.token } : {}) });
      if (result.redirect) {
        if (login) {
          await showSuccess('Signed in successfully', 'Opening your workspace...');
        }
        window.location.assign(result.redirect);
        return;
      }
      setSuccess(result.message ?? 'Your request has been received.');
      await showSuccess('Success', result.message ?? 'Your request has been received.');
    } catch (failure) {
      if (failure instanceof RequestError) {
        setErrors(failure.errors);
        if (Object.keys(failure.errors).length === 0) setError(failure.message);
      } else {
        setError('We couldn’t connect. Check your connection and try again.');
      }
      const message = failure instanceof RequestError
        ? (Object.values(failure.errors).flat().join(' ') || failure.message)
        : 'We could not connect. Check your connection and try again.';
      await showError(message, login ? 'Sign in failed' : 'Request failed');
      requestAnimationFrame(() => formRef.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
    } finally { setBusy(false); submitting.current = false; }
  }

  return <main className="auth-layout">
    <aside className="mascot-panel" aria-label="Moymoy, the BW Superbakeshop mascot">
      <img src={data.assets.mascot} alt="Moymoy, the smiling baker mascot" className="login-mascot" fetchPriority="high" />
      <span className="mascot-shadow" aria-hidden="true" />
    </aside>

    <section className="sign-in-panel" aria-label={login ? 'Sign in' : 'Password recovery'}>
      <header className="auth-header"><AccessHelp /></header>
      <div className="form-area"><Brand className="login-brand" logo={data.assets.logo} />
        {success ? <div className="auth-form success-state" role="status">
          <span className="form-symbol success-symbol"><Check size={22} strokeWidth={1.6} /></span>
          <div className="eyebrow">{reset ? 'A FRESH START' : 'ONE STEP CLOSER'}</div>
          <h1>{reset ? 'You’re all set.' : 'Check your inbox.'}</h1>
          <p className="form-intro">{success}</p>
          <Button asChild className="w-full"><a href={data.urls.login}>Back to sign in<ArrowRight /></a></Button>
          {!reset && <button type="button" className="text-link mx-auto mt-5 block" onClick={() => setSuccess('')}>Try another email address</button>}
        </div> : <div className="auth-form">
          {!login && <a className="back-link" href={data.urls.login}><ArrowLeft size={15} />Back to sign in</a>}
          <span className="form-symbol login-form-symbol">{login ? <LockKeyhole size={21} strokeWidth={1.4} /> : <KeyRound size={21} strokeWidth={1.4} />}</span>
          <div className="eyebrow">{login ? 'YOUR DAILY STARTING POINT' : reset ? 'A FRESH START' : 'LET’S GET YOU BACK IN'}</div>
          <h1>{login ? 'Welcome back' : reset ? 'A new password.' : 'Forgot your password?'}</h1>
          <p className="form-intro">{login ? 'Sign in to manage your rentals and renewals.' : reset ? 'Choose a strong password for your account.' : 'It happens. Enter your email and we’ll send you a reset link.'}</p>

          <form ref={formRef} onSubmit={submit} aria-busy={busy} onChange={() => { if (error) setError(''); if (Object.keys(errors).length) setErrors({}); }}>
            {error && <div className="form-alert" role="alert"><CircleAlert size={17} /><span>{error}</span></div>}
            <div className="field-group">
              <label htmlFor="email">Email address</label>
              <div className="input-wrap"><Mail className="input-icon" size={17} strokeWidth={1.5} /><Input id="email" name="email" type="email" autoComplete="username" placeholder="you@bwbakeshop.com" defaultValue={data.email} required maxLength={254} aria-invalid={!!errors.email || undefined} aria-describedby={errors.email ? 'email-error' : undefined} className="pl-11" /></div>
              {errors.email && <p className="field-error" id="email-error" role="alert">{errors.email[0]}</p>}
            </div>
            {(login || reset) && <PasswordInput id="password" label={reset ? 'New password' : 'Password'} reset={reset} invalid={!!errors.password} error={errors.password?.[0]} />}
            {reset && <PasswordInput id="confirm-password" label="Confirm password" confirm reset invalid={!!errors.password_confirmation} error={errors.password_confirmation?.[0]} />}
            {login && <div className="form-options"><div className="remember-option"><Checkbox id="remember" checked={remember} onCheckedChange={value => setRemember(value === true)} /><label htmlFor="remember">Keep me signed in</label></div><a className="text-link" href={data.urls.forgotPassword}>Forgot password?</a></div>}
            <Button className="submit-button w-full" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" />{login ? 'Signing in…' : reset ? 'Updating password…' : 'Sending link…'}</> : <>{login ? 'Sign in' : reset ? 'Update password' : 'Send reset link'}<ArrowRight className="submit-arrow" /></>}</Button>
          </form>

        </div>}
      </div>

    </section>
    <footer className="bw-auth-footer">
      {['back', 'front'].map(layer => <svg key={layer} className={`footer-wave footer-wave-${layer}`} viewBox="0 0 2880 100" preserveAspectRatio="none" aria-hidden="true">
        {[0, 1440].map(offset => <path key={offset} transform={`translate(${offset} 0)`} d="M0 40C120 15 240 15 360 40S600 65 720 40S960 15 1080 40S1320 65 1440 40V100H0Z" fill="currentColor" />)}
      </svg>)}
      <span>© {new Date().getFullYear()} Eljin-Arvin</span>
    </footer>
  </main>;
}
