import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import AuthPage from '@/pages/auth';
import Workspace from '@/pages/workspace';
import type { AppData } from '@/lib/auth';
import '../css/app.css';

const data: AppData = JSON.parse(document.getElementById('app-data')!.textContent!);
createRoot(document.getElementById('app')!).render(<StrictMode>{data.page === 'directory' || data.page === 'dashboard' || data.page === 'utak-pos' || data.page === 'pos-voucher' || data.page === 'internet-soa' || data.page === 'internet-voucher' || data.page === 'electricity' || data.page === 'rental' || data.page === 'water' || data.page === 'water-voucher' || data.page === 'electricity-voucher' || data.page === 'rental-voucher' || data.page === 'settings' || data.page === 'users' ? <Workspace data={data} /> : <AuthPage data={data} />}</StrictMode>);
