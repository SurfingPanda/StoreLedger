import ResponsiveTable from '@/components/responsive-table';
import { useTablePreference } from '@/lib/table-preferences';
import { useTableSort } from '@/components/table-sort';
import { useEffect, useRef, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import { Activity, Barcode, CalendarDays, ChevronDown, Coins, CreditCard, Database, FileText, Info, Layers3, LoaderCircle, Mail, Pencil, Phone, Plus, RefreshCw, RotateCcw, Save, Search, StoreIcon, Tag, UserRound, UsersRound, type LucideIcon } from 'lucide-react';
import { TableIdentity, TablePagination } from '@/components/table-presentation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogClose, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { post, RequestError, type AppData } from '@/lib/auth';
import '../../css/directory-modal.css';

type OwnershipType = 'corporate_owned' | 'franchise';
const ownershipLabels: Record<OwnershipType, string> = { corporate_owned: 'Corporate Owned', franchise: 'Franchise' };
export type Store = { id: number; code: string; name: string; ownership_type: OwnershipType | null; is_active: boolean };
export type Supplier = { id: number; name: string; email: string | null; phone: string | null; is_active: boolean };
export type BillingAccount = { id: number; store_id: number; supplier_id: number; module: string; account_number: string | null; amount: string | null; billing_cycle_months: number | null; is_active: boolean; store: Store; supplier: Supplier };
export type DirectoryData = { next_store_codes: Record<OwnershipType, string>; stores: Store[]; suppliers: Supplier[]; accounts: BillingAccount[] };
export const moduleNames: Record<string, string> = { 'utak-pos': 'UTAK POS', 'internet-soa': 'Internet', water: 'Water', electricity: 'Electricity', rental: 'Rental' };
const cycle = (months: number | null) => months ? ({ 1: 'Monthly', 3: 'Quarterly', 6: 'Every 6 months', 12: 'Yearly' }[months] ?? `${months} months`) : 'Needs setup';
type Kind = 'stores' | 'suppliers' | 'accounts';
type Edit = { id?: number; ownership_type?: OwnershipType | null; code?: string; name?: string; email?: string | null; phone?: string | null; store_id?: number; supplier_id?: number; module?: string; account_number?: string | null; amount?: string | null; billing_cycle_months?: number | null; is_active?: boolean };

const directoryLabels = { stores: 'Store', suppliers: 'Supplier', accounts: 'Billing Account' };
const directorySections = {
  stores: 'Store Directory',
  suppliers: 'Supplier Directory',
  accounts: 'Store Billing Accounts',
};
const directoryCategories = [
  ['stores', StoreIcon, 'Stores'],
  ['suppliers', UsersRound, 'Suppliers'],
  ['accounts', CreditCard, 'Billing Accounts'],
] as const;
const directoryColumns = {
  stores: [['code', 'STORE ID'], ['name', 'STORE'], ['ownership_type', 'OWNERSHIP'], ['is_active', 'STATUS']],
  suppliers: [['name', 'SUPPLIER'], ['email', 'EMAIL'], ['phone', 'PHONE'], ['is_active', 'STATUS']],
  accounts: [['name', 'STORE / MODULE'], ['supplier', 'SUPPLIER'], ['account_number', 'ACCOUNT NUMBER'], ['billing_cycle_months', 'BILLING CYCLE'], ['is_active', 'STATUS']],
} as const;
const directoryArt = {
  banner: [44, 60, 642, 153],
  storefront: [946, 27, 288, 207],
  bill: [721, 27, 232, 208],
  boxes: [1266, 159, 118, 80],
  handshake: [1216, 66, 158, 95],
  card: [1388, 92, 134, 100],
  'dashboard-banner': [53, 894, 527, 82],
  'dashboard-bill': [886, 27, 215, 233],
  'dashboard-store': [1096, 39, 258, 214],
} as const;

function DirectoryArt({ asset }: { asset: keyof typeof directoryArt }) {
  const [x, y, width, height] = directoryArt[asset];
  // Coordinates select a single illustration from the supplied 1536 × 1024 asset sheet.
  const style = {
    aspectRatio: asset.endsWith('banner') ? undefined : `${width} / ${height}`,
    backgroundSize: `${1536 / width * 100}% ${1024 / height * 100}%`,
    backgroundPosition: `${x / (1536 - width) * 100}% ${y / (1024 - height) * 100}%`,
  };
  return <span aria-hidden="true" className={`directory-art directory-art-${asset}`} style={style} />;
}

function DirectoryField({ id, label, icon: Icon, controlIcon: ControlIcon, required, select, children, hint }: {
  id: string; label: string; icon: LucideIcon; controlIcon?: ReactNode; required?: boolean; select?: boolean; children: ReactNode; hint?: ReactNode;
}) {
  return <div className="directory-modal-field">
    <label htmlFor={id}><Icon aria-hidden="true" />{label}{required && <span aria-hidden="true">*</span>}</label>
    <div className="directory-modal-control">
      <span className="directory-modal-control-icon" aria-hidden="true">{ControlIcon ?? <Icon />}</span>
      {children}
      {select && <ChevronDown className="directory-modal-chevron" aria-hidden="true" />}
    </div>
    {hint}
  </div>;
}

function DirectoryEditor({ kind, editing, entries, sheet, busy, error, save }: {
  kind: Kind; editing: Edit; entries: DirectoryData | null; sheet: string; busy: boolean; error: string; save: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const [module, setModule] = useState(editing.module ?? '');
  const [ownership, setOwnership] = useState<OwnershipType | ''>(editing.ownership_type ?? '');
  const keepsStoreCode = editing.id && ownership === editing.ownership_type && (ownership === 'corporate_owned' ? /^BW[0-9]{4,}$/ : /^FR[0-9]{4,}$/).test(editing.code ?? '');
  const storeCode = keepsStoreCode ? editing.code ?? '' : ownership ? entries?.next_store_codes[ownership] ?? '' : '';

  return <>
    <header className="directory-modal-header" style={{ '--directory-sheet': `url("${sheet}")` } as CSSProperties}>
      <DirectoryArt asset="banner" />
      <div className="directory-modal-illustration" aria-hidden="true">
        {kind === 'accounts' ? <DirectoryArt asset="bill" /> : <DirectoryArt asset="storefront" />}
        {kind === 'suppliers' && <DirectoryArt asset="boxes" />}
      </div>
      <div className="directory-modal-heading">
        <span className="directory-modal-emblem" aria-hidden="true">
          {kind === 'stores' ? <StoreIcon /> : <DirectoryArt asset={kind === 'suppliers' ? 'handshake' : 'card'} />}
        </span>
        <div>
          <p className="directory-modal-eyebrow">Store &amp; Supplier Directory</p>
          <DialogTitle className="directory-modal-title">{editing.id ? 'Edit' : 'Add'} {directoryLabels[kind]}</DialogTitle>
        </div>
      </div>
      <DialogDescription className="directory-modal-description">
        {kind === 'accounts' ? 'Save the account used for this store and module. Existing bills retain their recorded account number.' : 'A stable directory entry keeps history together when a name changes.'}
      </DialogDescription>
    </header>
    <form className="directory-modal-form" onSubmit={save}>
      <div className="directory-modal-body">
        {error && <div className="form-alert" role="alert">{error}</div>}
        <fieldset className="directory-modal-grid" disabled={busy}>
          {kind === 'stores' && <>
            <DirectoryField id="directory-code" label="Store ID (Automatic)" icon={Barcode} controlIcon={<Database />} hint={<p id="directory-code-hint">Store ID is generated automatically based on ownership.</p>}>
              <Input id="directory-code" readOnly tabIndex={-1} value={storeCode} placeholder="Select ownership type" aria-describedby="directory-code-hint" />
            </DirectoryField>
            <DirectoryField id="directory-name" label="Store Name" icon={StoreIcon} required>
              <Input id="directory-name" name="name" required maxLength={150} defaultValue={editing.name} placeholder="Enter store name" />
            </DirectoryField>
            <DirectoryField id="directory-ownership" label="Ownership" icon={UsersRound} controlIcon={<Tag />} required select>
              <select id="directory-ownership" name="ownership_type" required value={ownership} onChange={event => setOwnership(event.target.value as OwnershipType | '')}>
                <option value="" disabled>Select ownership type</option>
                {Object.entries(ownershipLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </DirectoryField>
          </>}
          {kind === 'suppliers' && <>
            <DirectoryField id="directory-name" label="Supplier Name" icon={StoreIcon} controlIcon={<UserRound />} required>
              <Input id="directory-name" name="name" required maxLength={150} defaultValue={editing.name} placeholder="Enter supplier name" />
            </DirectoryField>
            <DirectoryField id="directory-email" label="Email" icon={Mail}>
              <Input id="directory-email" name="email" type="email" maxLength={254} defaultValue={editing.email ?? ''} placeholder="Enter email address" />
            </DirectoryField>
            <DirectoryField id="directory-phone" label="Phone" icon={Phone}>
              <Input id="directory-phone" name="phone" type="tel" maxLength={60} defaultValue={editing.phone ?? ''} placeholder="Enter phone number" />
            </DirectoryField>
          </>}
          {kind === 'accounts' && <>
            <DirectoryField id="directory-store" label="Store" icon={StoreIcon} required select>
              <select id="directory-store" name="store_id" required defaultValue={editing.store_id ?? ''}>
                <option value="">Choose store</option>
                {entries?.stores.map(store => <option key={store.id} value={store.id}>{store.code} / {store.name}{!store.is_active && ' (inactive)'}</option>)}
              </select>
            </DirectoryField>
            <DirectoryField id="directory-supplier" label="Supplier" icon={UsersRound} required select>
              <select id="directory-supplier" name="supplier_id" required defaultValue={editing.supplier_id ?? ''}>
                <option value="">Choose supplier</option>
                {entries?.suppliers.map(supplier => <option key={supplier.id} value={supplier.id}>{supplier.name}{!supplier.is_active && ' (inactive)'}</option>)}
              </select>
            </DirectoryField>
            <DirectoryField id="directory-module" label="Module" icon={Layers3} required select>
              <select id="directory-module" name="module" required value={module} onChange={event => setModule(event.target.value)}>
                <option value="">Choose module</option>
                {Object.entries(moduleNames).map(([key, name]) => <option key={key} value={key}>{name}</option>)}
              </select>
            </DirectoryField>
            <DirectoryField id="directory-number" label="Account Number" icon={CreditCard}>
              <Input id="directory-number" name="account_number" maxLength={100} defaultValue={editing.account_number ?? ''} placeholder="Supplier account / contract number" />
            </DirectoryField>
            {module === 'internet-soa' && <DirectoryField id="directory-amount" label="Amount" icon={Coins} controlIcon="₱">
              <Input id="directory-amount" name="amount" type="number" min="0" max="9999999999.99" step="0.01" defaultValue={editing.amount ?? ''} placeholder="Enter amount" />
            </DirectoryField>}
            <DirectoryField id="directory-cycle" label="Billing Cycle" icon={RefreshCw} controlIcon={<CalendarDays />} required select>
              <select id="directory-cycle" name="billing_cycle_months" required defaultValue={editing.billing_cycle_months ?? ''}>
                <option value="">Choose cycle</option>
                {[1, 3, 6, 12].map(value => <option key={value} value={value}>{cycle(value)}</option>)}
              </select>
            </DirectoryField>
          </>}
          <DirectoryField id="directory-status" label="Status" icon={Activity} controlIcon={<i className="directory-modal-status-dot" />} select>
            <select id="directory-status" className="directory-modal-status" name="is_active" defaultValue={String(editing.is_active ?? true)}>
              <option value="true">Active</option>
              <option value="false">Inactive</option>
            </select>
          </DirectoryField>
        </fieldset>
      </div>
      <footer className="directory-modal-footer">
        <p><Info aria-hidden="true" />History stays linked to the original ID.</p>
        <div className="directory-modal-actions">
          <DialogClose asChild><Button type="button" variant="outline" disabled={busy}>Cancel</Button></DialogClose>
          <Button type="submit" disabled={busy}>{busy ? <LoaderCircle className="animate-spin" aria-hidden="true" /> : <Save aria-hidden="true" />}{busy ? 'Saving…' : 'Save Entry'}</Button>
        </div>
      </footer>
    </form>
  </>;
}

export default function Directory({ data }: { data: AppData }) {
  const admin = data.user?.role === 'admin';
  const [entries, setEntries] = useState<DirectoryData | null>(null);
  const [kind, setKind] = useState<Kind>('accounts');
  const [search, setSearch] = useTablePreference('search', '');
  const [statusFilter, setStatusFilter] = useTablePreference('statusFilter', 'all');
  const [ownershipFilter, setOwnershipFilter] = useTablePreference('ownershipFilter', 'all');
  const [storeFilter, setStoreFilter] = useTablePreference('storeFilter', 'all');
  const [supplierFilter, setSupplierFilter] = useTablePreference('supplierFilter', 'all');
  const [moduleFilter, setModuleFilter] = useTablePreference('moduleFilter', 'all');
  const [cycleFilter, setCycleFilter] = useTablePreference('cycleFilter', 'all');
  const [page, setPage] = useState(1);
  const sorting = useTableSort('', 'asc', () => setPage(1));
  const [perPage, setPerPage] = useTablePreference('perPage', 15);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [editing, setEditing] = useState<Edit | null>(null);
  const editorOpener = useRef<HTMLElement | null>(null);
  const [busy, setBusy] = useState(false);
  const guard = useRef(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setLoadError('');
    fetch(data.urls.directoryRecords, { headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error('Could not load the directory. Refresh and try again.'); return response.json() as Promise<DirectoryData>; })
      .then(setEntries).catch(error => { if (!controller.signal.aborted) setLoadError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [data.urls.directoryRecords, revision]);
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!editing || guard.current) return;
    const values = Object.fromEntries(new FormData(event.currentTarget));
    guard.current = true; setBusy(true); setError('');
    try {
      const result = await post<{ message: string }>(`${data.urls.directory}/${kind}${editing.id ? `/${editing.id}` : ''}`, { ...values, is_active: values.is_active === 'true' });
      setEditing(null); setSuccess(result.message); setPage(1); setRevision(value => value + 1);
    } catch (error) { setError(error instanceof RequestError ? Object.values(error.errors).flat().join(' ') || error.message : 'Could not save this entry. Try again.'); }
    finally { guard.current = false; setBusy(false); }
  }
  const query = search.toLowerCase().trim();
  const filtered = (entries?.[kind] ?? []).filter(row => {
    if (!JSON.stringify(row, (key, value) => key === 'assigned_rso' || key === 'assigned_rso_id' ? undefined : value).toLowerCase().includes(query)) return false;
    if (statusFilter !== 'all' && row.is_active !== (statusFilter === 'active')) return false;
    if (kind === 'stores' && ownershipFilter !== 'all') {
      const ownership = (row as Store).ownership_type;
      if (ownershipFilter === 'unset' ? !!ownership : ownership !== ownershipFilter) return false;
    }
    if (kind === 'accounts') {
      const account = row as BillingAccount;
      if (storeFilter !== 'all' && String(account.store_id) !== storeFilter) return false;
      if (supplierFilter !== 'all' && String(account.supplier_id) !== supplierFilter) return false;
      if (moduleFilter !== 'all' && account.module !== moduleFilter) return false;
      if (cycleFilter !== 'all' && (cycleFilter === 'unset' ? !!account.billing_cycle_months : String(account.billing_cycle_months) !== cycleFilter)) return false;
    }
    return true;
  });
  const rows = sorting.rows(filtered, (row, key) => {
    if (key === 'name') return (row as Store | Supplier).name ?? (row as BillingAccount).store.name;
    if (key === 'store') return (row as BillingAccount).store.name;
    if (key === 'supplier') return (row as BillingAccount).supplier?.name;
    if (key === 'module') return moduleNames[(row as BillingAccount).module];
    if (key === 'billing_cycle_months') return (row as BillingAccount).billing_cycle_months;
    if (key === 'ownership_type') return (row as Store).ownership_type ? ownershipLabels[(row as Store).ownership_type!] : null;
    return (row as unknown as Record<string, string | boolean | null>)[key];
  });
  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const shown = rows.slice((page - 1) * perPage, page * perPage);
  const hasFilters = !!search || [statusFilter, ownershipFilter, storeFilter, supplierFilter, moduleFilter, cycleFilter].some(value => value !== 'all');
  function clearFilters() {
    setSearch(''); setOwnershipFilter('all'); setStatusFilter('all'); setStoreFilter('all'); setSupplierFilter('all'); setModuleFilter('all'); setCycleFilter('all'); setPage(1);
  }
  function edit(value: Edit) {
    editorOpener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setEditing(value);
    setError('');
  }
  return <div className="pos-page directory-page">
    <header className="directory-hero" style={{ '--directory-sheet': `url("${data.assets.directoryDashboardSheet}")` } as CSSProperties}>
      <DirectoryArt asset="dashboard-banner" />
      <div className="directory-hero-art" aria-hidden="true">
        <DirectoryArt asset="dashboard-store" />
        <DirectoryArt asset="dashboard-bill" />
      </div>
      <div className="directory-hero-heading">
        <span className="directory-hero-icon"><StoreIcon aria-hidden="true" /></span>
        <div>
          <p className="directory-hero-kicker">Shared Master Data</p>
          <h1>Stores &amp; Suppliers</h1>
          <p className="directory-hero-description">One store ID, shared supplier accounts and billing cycles.</p>
        </div>
      </div>
      {admin && <Button className="directory-add" disabled={loading || !!loadError} onClick={() => edit({})}><Plus aria-hidden="true" />Add {directoryLabels[kind]}</Button>}
    </header>
    {success && <div className="pos-success" role="status">{success}</div>}
    <div className="directory-summary" role="group" aria-label="Directory categories">
      {directoryCategories.map(([key, Icon, label]) => <button key={key} type="button" aria-pressed={key === kind} onClick={() => { setKind(key); sorting.reset(); clearFilters(); }}>
        <span className="directory-dashboard-icon"><Icon aria-hidden="true" /></span>
        <span className="directory-summary-label">{label}<strong>{entries?.[key].length ?? 0}</strong></span>
      </button>)}
    </div>
    <section className="register-panel directory-register" aria-labelledby="directory-register-title">
      <header className="directory-register-header">
        <div className="directory-register-heading">
          <span className="directory-dashboard-icon"><FileText aria-hidden="true" /></span>
          <div>
            <h2 id="directory-register-title">{directorySections[kind]}</h2>
            <p>{admin ? 'Edit entries or mark them inactive. Bill history is retained.' : 'Contact an admin to add or update directory entries.'}</p>
          </div>
        </div>
        <span className="directory-record-count" aria-live="polite">{rows.length} {rows.length === 1 ? 'record' : 'records'}</span>
      </header>
      <div className="directory-toolbar">
        <div className="directory-search">
          <Search aria-hidden="true" />
          <Input type="search" aria-label="Search directory" placeholder="Search store ID, name or account number..." value={search} onChange={event => { setSearch(event.target.value); setPage(1); }} />
        </div>
        <Button variant="outline" size="sm" onClick={() => setRevision(value => value + 1)}><RotateCcw aria-hidden="true" />Refresh</Button>
      </div>
      <div className="directory-filters" data-kind={kind} role="group" aria-label="Directory table filters">
        {kind === 'stores' && <label>Ownership<select value={ownershipFilter} disabled={loading} onChange={event => { setOwnershipFilter(event.target.value); setPage(1); }}>
          <option value="all">All ownership types</option><option value="corporate_owned">Corporate Owned</option><option value="franchise">Franchise</option><option value="unset">Not specified</option>
        </select></label>}
        {kind === 'accounts' && <>
          <label>Store<select value={storeFilter} disabled={loading} onChange={event => { setStoreFilter(event.target.value); setPage(1); }}>
            <option value="all">All stores</option>{entries?.stores.map(store => <option key={store.id} value={store.id}>{store.code} / {store.name}</option>)}
          </select></label>
          <label>Supplier<select value={supplierFilter} disabled={loading} onChange={event => { setSupplierFilter(event.target.value); setPage(1); }}>
            <option value="all">All suppliers</option>{entries?.suppliers.map(supplier => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
          </select></label>
          <label>Module<select value={moduleFilter} disabled={loading} onChange={event => { setModuleFilter(event.target.value); setPage(1); }}>
            <option value="all">All modules</option>{Object.entries(moduleNames).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select></label>
          <label>Billing Cycle<select value={cycleFilter} disabled={loading} onChange={event => { setCycleFilter(event.target.value); setPage(1); }}>
            <option value="all">All billing cycles</option><option value="unset">Needs setup</option>{[1, 3, 6, 12].map(months => <option key={months} value={months}>{cycle(months)}</option>)}
          </select></label>
        </>}
        <label>Status<select value={statusFilter} disabled={loading} onChange={event => { setStatusFilter(event.target.value); setPage(1); }}>
          <option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
        </select></label>
        <Button variant="ghost" size="sm" onClick={clearFilters}><RotateCcw aria-hidden="true" />Reset Filters</Button>
      </div>
      {loadError && <div className="form-alert" role="alert">{loadError}</div>}
      <div className="voucher-table-scroll" tabIndex={0} role="region" aria-label="Scrollable records">
        <ResponsiveTable className="ledger-table voucher-table">
          <thead>
            <tr>
              {directoryColumns[kind].map(([key, label]) => sorting.header(key, label))}
              {admin && <th scope="col">ACTIONS</th>}
            </tr>
          </thead>
          <tbody>
            {!loading && shown.map(row => <tr key={row.id}>
              {kind === 'stores' ? <>
                <td><span className="pos-record-id">{(row as Store).code}</span></td>
                <td><TableIdentity name={(row as Store).name} subtitle="Store location" /></td>
                <td>{(row as Store).ownership_type ? ownershipLabels[(row as Store).ownership_type!] : <span className="history-pending">Not specified</span>}</td>
              </> : kind === 'suppliers' ? <>
                <td><TableIdentity name={(row as Supplier).name} subtitle="Supplier / provider" /></td>
                <td>{(row as Supplier).email || '-'}</td>
                <td>{(row as Supplier).phone || '-'}</td>
              </> : <>
                <td><TableIdentity name={(row as BillingAccount).store.name} subtitle={`${(row as BillingAccount).store.code} / ${moduleNames[(row as BillingAccount).module]}`} /></td>
                <td>{(row as BillingAccount).supplier.name}</td>
                <td>{(row as BillingAccount).account_number || 'Not recorded'}</td>
                <td>{cycle((row as BillingAccount).billing_cycle_months)}</td>
              </>}
              <td><span className="directory-status" data-active={row.is_active}><i aria-hidden="true" />{row.is_active ? 'Active' : 'Inactive'}</span></td>
              {admin && <td><Button size="sm" variant="outline" onClick={() => edit(row)}><Pencil size={13} aria-hidden="true" />Edit</Button></td>}
            </tr>)}
          </tbody>
        </ResponsiveTable>
        {loading ? <div className="register-empty"><LoaderCircle className="animate-spin" aria-hidden="true" />Loading directory...</div> : !shown.length && (
          <div className="register-empty">
            {hasFilters ? 'No entries match your filters.' : 'No entries found.'}
            {hasFilters ? <Button variant="outline" size="sm" onClick={clearFilters}>Reset filters</Button> : admin && 'Add a store and supplier, then create a billing account.'}
          </div>
        )}
      </div>
      <TablePagination page={page} lastPage={pages} total={rows.length} disabled={loading} onPage={setPage} label="directory" perPage={perPage} onPerPage={setPerPage} />
    </section>
    <Dialog open={!!editing} onOpenChange={open => { if (!open && !guard.current) setEditing(null); }}>
      <DialogContent className="directory-modal" showClose={false} onCloseAutoFocus={event => {
        event.preventDefault();
        editorOpener.current?.focus();
      }}>
        {editing && <DirectoryEditor key={`${kind}-${editing.id ?? 'new'}`} kind={kind} editing={editing} entries={entries} sheet={data.assets.directoryModalSheet} busy={busy} error={error} save={save} />}
      </DialogContent>
    </Dialog>
  </div>;
}

export function DirectoryBillFields({ url, module, directoryUrl, errors, initialStoreId, initialAccountId }: { url: string; module: string; directoryUrl: string; errors: Record<string, string[]>; initialStoreId?: number | null; initialAccountId?: number | null }) {
  const [entries, setEntries] = useState<DirectoryData | null>(null);
  const [storeId, setStoreId] = useState(String(initialStoreId ?? ''));
  const [accountId, setAccountId] = useState(String(initialAccountId ?? ''));
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setError(''); setEntries(null);
    fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal }).then(async response => { if (!response.ok) throw new Error('Could not load saved accounts.'); return response.json() as Promise<DirectoryData>; }).then(setEntries).catch(error => { if (!controller.signal.aborted) setError(error.message); });
    return () => controller.abort();
  }, [url, retry]);
  const accounts = entries?.accounts.filter(account => account.module === module && account.is_active && account.store.is_active && account.supplier.is_active && String(account.store_id) === storeId) ?? [];
  const selected = accounts.find(account => String(account.id) === accountId);
  return <div className="directory-bill-fields">{error && <div className="form-alert" role="alert">{error} <button type="button" onClick={() => setRetry(value => value + 1)}>Retry</button></div>}<div className="user-form-grid"><div className="field-group"><label htmlFor="bill-store-id">Store *</label><select id="bill-store-id" name="store_id" required value={storeId} onChange={event => { setStoreId(event.target.value); setAccountId(''); }}><option value="">{entries ? 'Choose saved store' : 'Loading stores...'}</option>{entries?.stores.filter(store => store.is_active).map(store => <option key={store.id} value={store.id}>{store.code} / {store.name}</option>)}</select></div><div className="field-group"><label htmlFor="bill-account-id">Supplier account *</label><select id="bill-account-id" name="billing_account_id" required value={selected ? accountId : ''} onChange={event => setAccountId(event.target.value)}><option value="">Choose account</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.supplier.name} / {account.account_number || 'No account number'}</option>)}</select></div></div><input type="hidden" name="store_name" value={selected?.store.name ?? ''} /><input type="hidden" name="provider" value={selected?.supplier.name ?? ''} />{selected && <div className="directory-account-preview"><span>Account<strong>{selected.account_number || 'Not recorded'}</strong></span><span>Cycle<strong>{cycle(selected.billing_cycle_months)}</strong></span></div>}<p className="pos-save-hint">Missing a store or account? <a href={directoryUrl} target="_blank" rel="noreferrer">Open directory</a>, then close and reopen this form after saving.</p>{['billing_account_id', 'store_id', 'store_name', 'provider'].map(key => errors[key] && <p key={key} className="field-error" role="alert">{errors[key][0]}</p>)}</div>;
}
