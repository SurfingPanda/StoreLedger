import { useRef, useState, type FormEvent } from 'react';
import { CheckCircle2, Eye, EyeOff, KeyRound, LoaderCircle, Save, ShieldCheck, UserRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { post, RequestError, type AppData } from '@/lib/auth';

type Profile = NonNullable<AppData['user']>;
export default function AccountSettings({ data, onProfileChange }: { data: AppData; onProfileChange: (user: Profile) => void }) {
  const [name, setName] = useState(data.user?.name ?? '');
  const [email, setEmail] = useState(data.user?.email ?? '');
  const [profilePassword, setProfilePassword] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);
  const [section, setSection] = useState<'profile' | 'password'>('profile');
  const [saving, setSaving] = useState<'profile' | 'password' | null>(null);
  const guard = useRef(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [errorSection, setErrorSection] = useState<'profile' | 'password'>('profile');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const emailLocked = ['rso', 'accounting', 'admin', 'finance'].includes(data.user?.role ?? '');
  const changedEmail = !emailLocked && email.trim().toLowerCase() !== data.user?.email.toLowerCase();
  const role = ({ admin: 'Admin', accounting: 'Accounting', finance: 'Finance', rso: 'RSO', it: 'IT' } as Record<string, string>)[data.user?.role ?? ''] ?? 'Team';
  async function save(event: FormEvent, section: 'profile' | 'password') {
    event.preventDefault(); if (guard.current) return;
    setSection(section);
    guard.current = true; setSaving(section); setError(''); setSuccess(''); setErrors({}); setErrorSection(section);
    try {
      if (section === 'profile') {
        const result = await post<{ message: string; user: Profile; csrf_token: string }>(`${data.urls.settings}/profile`, { name, email, current_password: changedEmail ? profilePassword : undefined });
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]'); if (csrf) csrf.content = result.csrf_token;
        setName(result.user.name); setEmail(result.user.email); setProfilePassword(''); onProfileChange(result.user); setSuccess(result.message);
      } else {
        const result = await post<{ message: string; csrf_token: string }>(`${data.urls.settings}/password`, { current_password: currentPassword, password, password_confirmation: confirmation });
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]'); if (csrf) csrf.content = result.csrf_token;
        setCurrentPassword(''); setPassword(''); setConfirmation(''); setShowPasswords(false); setSuccess(result.message);
      }
    } catch (failure) {
      if (failure instanceof RequestError) { setErrors(failure.errors); setError(Object.keys(failure.errors).length ? 'Please check the highlighted fields.' : failure.message); }
      else setError('Could not save your account settings. Please try again.');
    } finally { guard.current = false; setSaving(null); }
  }
  function fieldError(section: 'profile' | 'password', field: string) {
    return section === errorSection && errors[field] && <p className="field-error" id={`${section}-${field}-error`} role="alert">{errors[field][0]}</p>;
  }
  function field(section: 'profile' | 'password', key: string) {
    return { 'aria-invalid': section === errorSection && !!errors[key] || undefined, 'aria-describedby': section === errorSection && errors[key] ? `${section}-${key}-error` : undefined };
  }
  return <div className="account-settings-page" data-section={section}>
    <div className="dashboard-heading"><div><p className="dashboard-kicker">PERSONAL SETTINGS</p><h1>My account</h1><p>Manage your profile and keep your sign-in details up to date.</p></div><span className="settings-heading-icon"><UserRound size={24} /></span></div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}</div>}
    <div className="account-profile-summary"><span className="account-profile-avatar">{(data.user?.name ?? 'U').slice(0, 2).toUpperCase()}</span><div><strong>{data.user?.name}</strong><span>{data.user?.email}</span></div><span className="account-profile-role"><ShieldCheck size={14} />{role}</span></div>
    <div className="account-section-switch" role="group" aria-label="Account settings sections"><button type="button" aria-pressed={section === 'profile'} onClick={() => setSection('profile')}><UserRound size={16} />Profile</button><button type="button" aria-pressed={section === 'password'} onClick={() => setSection('password')}><KeyRound size={16} />Password</button></div>
    <div className="account-settings-grid">
      <section className="register-panel account-settings-card account-profile-card"><div className="section-heading"><div><h2><UserRound size={18} />Account profile</h2><p>Your personal information in the workspace.</p></div></div>
        <form onSubmit={event => void save(event, 'profile')} aria-busy={saving === 'profile'}>
          <fieldset disabled={!!saving}>
            {error && errorSection === 'profile' && <div className="form-alert" role="alert">{error}</div>}
            <div className="field-group"><label htmlFor="profile-name">Full name *</label><Input id="profile-name" autoComplete="name" required maxLength={100} value={name} onChange={event => setName(event.target.value)} {...field('profile', 'name')} />{fieldError('profile', 'name')}</div>
            <div className="field-group"><label htmlFor="profile-email">Email address{!emailLocked && ' *'}</label><Input id="profile-email" type="email" autoComplete="email" disabled={emailLocked} required={!emailLocked} maxLength={254} value={email} onChange={event => { if (!emailLocked) setEmail(event.target.value); }} {...field('profile', 'email')} />{fieldError('profile', 'email')}</div>
            {changedEmail && <div className="field-group"><label htmlFor="profile-current-password">Current password *</label><Input id="profile-current-password" type="password" autoComplete="current-password" required value={profilePassword} onChange={event => setProfilePassword(event.target.value)} {...field('profile', 'current_password')} /><p className="account-field-hint">Confirm your current password to change your email address.</p>{fieldError('profile', 'current_password')}</div>}
            <div className="account-settings-note"><ShieldCheck size={16} /><p>Your access role is <strong>{role}</strong>. Contact an admin if your role needs to change.</p></div>
          </fieldset>
          <div className="account-settings-actions"><Button type="submit" size="sm" disabled={!!saving}>{saving === 'profile' ? <LoaderCircle size={16} className="animate-spin" /> : <Save size={16} />}{saving === 'profile' ? 'Saving...' : 'Save profile'}</Button></div>
        </form>
      </section>
      <section className="register-panel account-settings-card account-password-card"><div className="section-heading"><div><h2><KeyRound size={18} />Change password</h2><p>Use a strong password that you do not use elsewhere.</p></div></div>
        <form onSubmit={event => void save(event, 'password')} aria-busy={saving === 'password'}>
          <fieldset disabled={!!saving}>
            {error && errorSection === 'password' && <div className="form-alert" role="alert">{error}</div>}
            <div className="field-group"><label htmlFor="account-current-password">Current password *</label><Input id="account-current-password" type={showPasswords ? 'text' : 'password'} autoComplete="current-password" required maxLength={1024} value={currentPassword} onChange={event => setCurrentPassword(event.target.value)} {...field('password', 'current_password')} />{fieldError('password', 'current_password')}</div>
            <div className="field-group"><label htmlFor="account-new-password">New password *</label><Input id="account-new-password" type={showPasswords ? 'text' : 'password'} autoComplete="new-password" required minLength={12} maxLength={128} value={password} onChange={event => setPassword(event.target.value)} {...field('password', 'password')} /><p className="account-field-hint">At least 12 characters. Choose a different password from your current one.</p>{fieldError('password', 'password')}</div>
            <div className="field-group"><label htmlFor="account-password-confirmation">Confirm new password *</label><Input id="account-password-confirmation" type={showPasswords ? 'text' : 'password'} autoComplete="new-password" required minLength={12} maxLength={128} value={confirmation} onChange={event => setConfirmation(event.target.value)} {...field('password', 'password_confirmation')} />{fieldError('password', 'password_confirmation')}</div>
            <button className="account-password-toggle" type="button" aria-pressed={showPasswords} onClick={() => setShowPasswords(value => !value)}>{showPasswords ? <EyeOff size={15} /> : <Eye size={15} />}{showPasswords ? 'Hide passwords' : 'Show passwords'}</button>
            <p className="account-field-hint">After changing your password, other sessions will be signed out. You will stay signed in here.</p>
          </fieldset>
          <div className="account-settings-actions"><Button type="submit" size="sm" disabled={!!saving}>{saving === 'password' ? <LoaderCircle size={16} className="animate-spin" /> : <KeyRound size={16} />}{saving === 'password' ? 'Updating...' : 'Update password'}</Button></div>
        </form>
      </section>
    </div>
  </div>;
}
