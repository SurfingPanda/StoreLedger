import ResponsiveTable from '@/components/responsive-table';
import { useTablePreference } from '@/lib/table-preferences';
import { useTableSort } from '@/components/table-sort';
import { TablePagination } from '@/components/table-presentation';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CheckCircle2, Eye, EyeOff, KeyRound, LoaderCircle, Pencil, Plus, RefreshCw, Search, ShieldCheck, UserCheck, UserRound, UsersRound, UserX } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';

type Account = { id: number; name: string; email: string; role: string; is_active: boolean; created_at: string; last_login_at: string | null };
type Results = { users: { data: Account[]; total: number; last_page: number; from: number | null; to: number | null }; summary: { total: number; active: number; disabled: number; admins: number }; current_user_id: number };
type Editor = { kind: 'create' } | { kind: 'edit' | 'password' | 'status'; account: Account };
const roles: Record<string, { label: string; detail: string }> = {
  admin: { label: 'Admin', detail: 'Manage accounts, records, recurring schedules, and workspace settings.' },
  accounting: { label: 'Accounting', detail: 'Process vouchers, add comments, and review request history.' },
  finance: { label: 'Finance', detail: 'Record payments, attach payment proof, and add comments.' },
  it: { label: 'IT', detail: 'Review the dashboard, monitoring records, and request history.' },
  rso: { label: 'RSO', detail: 'Review requests and receive configured workflow email updates.' },
};
const date = (value: string | null) => value ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded';

export default function Users({ data }: { data: AppData }) {
  const [result, setResult] = useState<Results | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [search, setSearch] = useTablePreference('search', '');
  const [query, setQuery] = useState(search);
  const [roleFilter, setRoleFilter] = useTablePreference('roleFilter', 'all');
  const [statusFilter, setStatusFilter] = useTablePreference('statusFilter', 'all');
  const [page, setPage] = useState(1);
  const sorting = useTableSort('name', 'asc', () => setPage(1));
  const [perPage, setPerPage] = useTablePreference('perPage', 15);
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<Editor | null>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('it');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [visible, setVisible] = useState(false);
  const [saving, setSaving] = useState(false);
  const guard = useRef(false);
  const form = useRef<HTMLFormElement>(null);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [success, setSuccess] = useState('');
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setLoadError('');
    const url = new URL(data.urls.userRecords);
    for (const [key, value] of Object.entries({ search, role: roleFilter, status: statusFilter, sort: sorting.sort, direction: sorting.direction, per_page: String(perPage), page: String(page) })) url.searchParams.set(key, value);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error('Could not load accounts. Check your session and try again.'); return response.json() as Promise<Results>; })
      .then(setResult).catch(error => { if (!controller.signal.aborted) setLoadError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.userRecords, search, roleFilter, statusFilter, page, revision, perPage, sorting.sort, sorting.direction]);

  function open(value: Editor) {
    setError(''); setErrors({}); setPassword(''); setConfirmation(''); setVisible(false);
    if (value.kind === 'edit') { setName(value.account.name); setEmail(value.account.email); setRole(value.account.role); }
    else { setName(''); setEmail(''); setRole('it'); }
    setEditor(value);
  }
  function close() { if (!guard.current) { setEditor(null); setPassword(''); setConfirmation(''); } }
  const ownAccount = editor && editor.kind !== 'create' && editor.account.id === result?.current_user_id;
  const field = (key: string) => ({ 'aria-invalid': !!errors[key] || undefined, 'aria-describedby': errors[key] ? `user-${key}-error` : undefined });
  const fieldError = (key: string) => errors[key] && <p className="field-error" id={`user-${key}-error`} role="alert">{errors[key][0]}</p>;
  async function save(event: FormEvent) {
    event.preventDefault(); if (!editor || guard.current) return;
    guard.current = true; setSaving(true); setError(''); setErrors({}); setSuccess('');
    const account = editor.kind === 'create' ? null : editor.account;
    let url = data.urls.userRecords;
    let payload: Record<string, unknown>;
    if (editor.kind === 'status') { url += `/${account!.id}/status`; payload = { is_active: !account!.is_active }; }
    else if (editor.kind === 'password') { url += `/${account!.id}/password`; payload = { password, password_confirmation: confirmation }; }
    else {
      if (account) url += `/${account.id}`;
      payload = { name: name.trim(), email: email.trim(), role, ...(editor.kind === 'create' ? { password, password_confirmation: confirmation } : {}) };
    }
    try {
      const response = await post<{ message: string; redirect?: string | null; reload?: boolean }>(url, payload);
      setPassword(''); setConfirmation('');
      if (response.redirect) { window.location.assign(response.redirect); return; }
      if (response.reload) { window.location.reload(); return; }
      setSuccess(response.message); setEditor(null);
      if (editor.kind === 'create') { setPage(1); setRoleFilter('all'); setStatusFilter('all'); setSearch(''); setQuery(''); }
      else if (result?.users.data.length === 1 && page > 1) setPage(value => value - 1);
      setRevision(value => value + 1);
    } catch (error) {
      if (error instanceof RequestError) { setErrors(error.errors); setError(Object.keys(error.errors).length ? Object.values(error.errors).flat().join(' ') : error.message); }
      else setError('Could not save the account. Please try again.');
      requestAnimationFrame(() => form.current?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
    } finally { guard.current = false; setSaving(false); }
  }
  function metricFilter(value: string) { setStatusFilter(value === 'admins' ? 'active' : value); setRoleFilter(value === 'admins' ? 'admin' : 'all'); setSearch(''); setQuery(''); setPage(1); }
  return <section className="users-page">
    <div className="dashboard-heading"><div><p className="dashboard-kicker">ADMINISTRATION</p><h1>Users & access</h1><p>Manage who can sign in and what each team member can do.</p></div><Button onClick={() => open({ kind: 'create' })}><Plus size={17} />Add user</Button></div>
    {success && <div className="pos-success" role="status"><CheckCircle2 size={18} />{success}<button onClick={() => setSuccess('')}>Dismiss</button></div>}
    <div className="user-summary-grid">{[
      { label: 'Total accounts', value: result?.summary.total, filter: 'all', icon: UsersRound },
      { label: 'Active accounts', value: result?.summary.active, filter: 'active', icon: UserCheck },
      { label: 'Disabled accounts', value: result?.summary.disabled, filter: 'disabled', icon: UserX },
      { label: 'Active admins', value: result?.summary.admins, filter: 'admins', icon: ShieldCheck },
    ].map(({ label, value, filter, icon: Icon }) => <button key={filter} className="user-summary-card" onClick={() => metricFilter(filter)}><span>{label}<Icon size={18} /></span><strong>{loadError ? '—' : value ?? '—'}</strong></button>)}</div>
    <section className="register-panel user-register"><div className="section-heading"><div><h2>Workspace accounts</h2><p>Disabled accounts stay in the system to preserve request and payment history.</p></div><Button variant="outline" size="sm" disabled={loading} onClick={() => setRevision(value => value + 1)} aria-label="Refresh users"><RefreshCw size={15} className={loading ? 'animate-spin' : ''} /></Button></div>
      <div className="user-register-controls"><form className="user-search" onSubmit={event => { event.preventDefault(); setSearch(query.trim()); setPage(1); }}><label><Search size={16} /><input aria-label="Search users by name or email" placeholder="Search name or email..." value={query} maxLength={150} onChange={event => setQuery(event.target.value)} /></label><Button size="sm" variant="outline" type="submit">Search</Button><Button size="sm" variant="ghost" type="button" onClick={() => { setSearch(''); setQuery(''); setRoleFilter('all'); setStatusFilter('all'); setPage(1); }}>Reset filters</Button></form><label>Role<select value={roleFilter} onChange={event => { setRoleFilter(event.target.value); setPage(1); }}><option value="all">All roles</option>{Object.entries(roles).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}</select></label><label>Status<select value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1); }}><option value="all">All accounts</option><option value="active">Active</option><option value="disabled">Disabled</option></select></label></div>
      {loadError ? <div className="pos-load-error" role="alert"><p>{loadError}</p><Button variant="outline" onClick={() => setRevision(value => value + 1)}>Try again</Button></div> : <div className="voucher-table-scroll" tabIndex={0} role="region" aria-label="Scrollable records" aria-busy={loading}><ResponsiveTable className="ledger-table voucher-table user-table"><thead><tr>{sorting.header('name', 'TEAM MEMBER')}{sorting.header('role', 'ROLE')}{sorting.header('is_active', 'STATUS')}{sorting.header('last_login_at', 'LAST SIGN-IN')}{sorting.header('created_at', 'DATE CREATED')}<th scope="col">ACTIONS</th></tr></thead><tbody>{!loading && result?.users.data.map(account => <tr key={account.id} className={!account.is_active ? 'user-disabled-row' : ''}><td><div className="user-identity"><span className="user-avatar">{account.name.slice(0, 2).toUpperCase()}</span><div><strong>{account.name}{account.id === result.current_user_id && <span className="user-self-tag">You</span>}</strong><small>{account.email}</small></div></div></td><td><span className={`user-role ${account.role === 'admin' ? 'user-role-admin' : ''}`}>{roles[account.role]?.label ?? account.role}</span></td><td><span className={`status-pill ${account.is_active ? 'status-paid' : 'status-review'}`}><i />{account.is_active ? 'Active' : 'Disabled'}</span></td><td>{date(account.last_login_at)}</td><td>{date(account.created_at)}</td><td><div className="user-actions"><button onClick={() => open({ kind: 'edit', account })} title="Edit details" aria-label={`Edit ${account.name}`}><Pencil size={15} /><span>Edit</span></button><button onClick={() => open({ kind: 'password', account })} title="Reset password" aria-label={`Reset password for ${account.name}`}><KeyRound size={15} /><span>Password</span></button><button disabled={account.id === result.current_user_id} onClick={() => open({ kind: 'status', account })} className={account.is_active ? 'user-disable-action' : 'user-enable-action'} title={account.id === result.current_user_id ? 'You cannot disable your own account' : account.is_active ? 'Disable account' : 'Enable account'} aria-label={`${account.is_active ? 'Disable' : 'Enable'} ${account.name}`}>{account.is_active ? <UserX size={15} /> : <UserCheck size={15} />}<span>{account.is_active ? 'Disable' : 'Enable'}</span></button></div></td></tr>)}</tbody></ResponsiveTable>
      {loading ? <div className="register-empty" role="status"><LoaderCircle size={24} className="animate-spin" /><p>Loading accounts...</p></div> : !result?.users.data.length && <div className="register-empty"><UsersRound size={28} /><h3>No matching accounts</h3><p>Try another name, email, role, or status.</p><button onClick={() => { setSearch(''); setQuery(''); setRoleFilter('all'); setStatusFilter('all'); setPage(1); }}>Clear filters</button></div>}</div>}
      <TablePagination page={page} lastPage={result?.users.last_page ?? 1} total={result?.users.total ?? 0} from={result?.users.from ?? 0} to={result?.users.to ?? 0} disabled={loading || !!loadError} onPage={setPage} perPage={perPage} onPerPage={setPerPage} status={loading ? 'Loading records...' : loadError ? 'Records unavailable' : undefined} />
    </section>
    <Dialog open={!!editor} onOpenChange={value => { if (!value) close(); }}><DialogContent showClose={false} className={`user-editor user-editor-${editor?.kind ?? 'create'}`}>
      <div className="user-editor-header"><div className="user-editor-heading"><span className="user-editor-symbol">{editor?.kind === 'password' ? <KeyRound size={23} /> : <UserRound size={23} />}</span><div><p className="user-editor-eyebrow">WORKSPACE ACCESS</p><DialogTitle className="user-editor-title">{editor?.kind === 'create' ? 'Add a team member' : editor?.kind === 'edit' ? 'Edit account' : editor?.kind === 'password' ? 'Reset password' : editor?.kind === 'status' && editor.account.is_active ? 'Disable account?' : 'Enable account?'}</DialogTitle></div></div><DialogDescription className="user-editor-description">{editor?.kind === 'create' ? 'Set up their profile, choose a role, and create their login.' : editor?.kind === 'edit' ? 'Keep profile details and team permissions up to date.' : editor?.kind === 'password' ? `Create a new password for ${editor.account.name}. Existing sessions will be signed out.` : editor?.kind === 'status' ? editor.account.is_active ? 'Access will be revoked immediately. Their request and payment history will be kept.' : 'This user can sign in again with their existing password.' : ''}</DialogDescription></div>
      <form ref={form} onSubmit={save} aria-busy={saving} className="user-editor-form"><div className="user-editor-body">{error && <div className="form-alert" role="alert">{error}</div>}<fieldset className="soa-form-fields" disabled={saving}>
        {(editor?.kind === 'create' || editor?.kind === 'edit') && <>
          <section className="user-form-section" aria-labelledby="user-profile-heading"><div className="user-form-section-heading"><span className="user-section-number">1</span><h3 id="user-profile-heading">Profile details</h3>{editor.kind === 'create' && <span className="user-new-active"><i />Active on creation</span>}</div><div className="user-form-grid"><div className="field-group"><label htmlFor="user-name">Full name <span aria-hidden="true">*</span></label><Input id="user-name" value={name} onChange={event => setName(event.target.value)} required maxLength={100} autoComplete="name" placeholder="e.g. Juan Dela Cruz" {...field('name')} />{fieldError('name')}</div><div className="field-group"><label htmlFor="user-email">Email address <span aria-hidden="true">*</span></label><Input id="user-email" type="email" value={email} onChange={event => setEmail(event.target.value)} required maxLength={254} autoComplete="off" placeholder="name@bwbakeshop.com" {...field('email')} />{fieldError('email')}</div></div></section>
          <section className="user-form-section" aria-labelledby="user-role-heading"><div className="user-form-section-heading"><span className="user-section-number">2</span><h3 id="user-role-heading">Role & permissions</h3></div><div className="field-group"><label htmlFor="user-role">Team role</label><select id="user-role" value={role} onChange={event => setRole(event.target.value)} disabled={!!ownAccount} {...field('role')}>{Object.entries(roles).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}</select>{fieldError('role')}<div className="user-role-explainer"><ShieldCheck size={17} /><div><strong>{roles[role]?.label} access</strong><p>{roles[role]?.detail}{ownAccount && ' Your own admin role cannot be changed here.'}</p></div></div></div></section>
        </>}
        {(editor?.kind === 'create' || editor?.kind === 'password') && <section className="user-form-section" aria-labelledby="user-security-heading"><div className="user-form-section-heading"><span className="user-section-number">{editor.kind === 'create' ? '3' : <KeyRound size={13} />}</span><h3 id="user-security-heading">Login security</h3><button type="button" className="user-password-toggle" aria-pressed={visible} onClick={() => setVisible(value => !value)}>{visible ? <EyeOff size={14} /> : <Eye size={14} />}{visible ? 'Hide' : 'Show'} passwords</button></div><div className="user-form-grid"><div className="field-group"><label htmlFor="user-password">{editor.kind === 'create' ? 'Password' : 'New password'} <span aria-hidden="true">*</span></label><Input id="user-password" type={visible ? 'text' : 'password'} value={password} onChange={event => setPassword(event.target.value)} required minLength={12} maxLength={128} autoComplete="new-password" placeholder="At least 12 characters" {...field('password')} />{fieldError('password')}</div><div className="field-group"><label htmlFor="user-password-confirm">Confirm password <span aria-hidden="true">*</span></label><Input id="user-password-confirm" type={visible ? 'text' : 'password'} value={confirmation} onChange={event => setConfirmation(event.target.value)} required minLength={12} maxLength={128} autoComplete="new-password" placeholder="Re-enter password" /></div></div><div className="user-password-guide"><span className={password.length >= 12 ? 'requirement-met' : ''}><CheckCircle2 size={12} />12 characters minimum</span><span className={password.length > 0 && password === confirmation ? 'requirement-met' : ''}><CheckCircle2 size={12} />Passwords match</span></div></section>}
        {editor?.kind === 'status' && <div className="user-status-summary"><strong>{editor.account.name}</strong><span>{editor.account.email}</span><small>{roles[editor.account.role]?.label}</small></div>}
      </fieldset></div><div className="user-editor-footer"><span><ShieldCheck size={14} />{editor?.kind === 'create' ? 'Share login details securely.' : editor?.kind === 'password' ? 'Share the new password securely.' : 'Admin access only'}</span><div><Button type="button" variant="outline" disabled={saving} onClick={close}>Cancel</Button><Button type="submit" disabled={saving}>{saving ? <><LoaderCircle className="animate-spin" />Saving...</> : editor?.kind === 'create' ? <><Plus size={15} />Create account</> : editor?.kind === 'password' ? 'Reset password' : editor?.kind === 'status' ? editor.account.is_active ? 'Disable account' : 'Enable account' : 'Save changes'}</Button></div></div></form>
    </DialogContent></Dialog>
  </section>;
}
