import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import AuthPage from '@/pages/auth';
import Workspace from '@/pages/workspace';
import type { AppData } from '@/lib/auth';
import '../css/app.css';

const data: AppData = JSON.parse(document.getElementById('app-data')!.textContent!);
createRoot(document.getElementById('app')!).render(<StrictMode>{data.page === 'dashboard' || data.page === 'utak-pos' || data.page === 'internet-soa' || data.page === 'settings' ? <Workspace data={data} /> : <AuthPage data={data} />}</StrictMode>);
