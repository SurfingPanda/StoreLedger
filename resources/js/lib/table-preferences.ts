import { useEffect, useState, type Dispatch, type SetStateAction } from 'react';

// Keep table choices per account and page, without storing request data.
export function useTablePreference<T>(name: string, initial: T): [T, Dispatch<SetStateAction<T>>] {
  const account = JSON.parse(document.getElementById('app-data')?.textContent || '{}').user;
  const scope = `${account?.email || 'guest'}:${account?.role || ''}:${location.pathname.replace(/\/\d+(?=\/|$)/g, '/record')}`;
  const key = `monitoring:table:v1:${scope}:${name}`;
  const [value, setValue] = useState<T>(() => {
    try {
      const saved: unknown = JSON.parse(localStorage.getItem(key) || 'null');
      if (saved === null || typeof saved !== typeof initial) return initial;
      if (name.toLowerCase().includes('perpage') && ![5, 10, 15, 20, 25, 50, 100].includes(Number(saved))) return initial;
      if (initial && typeof initial === 'object') {
        if (Array.isArray(saved) || !Object.entries(initial).every(([field, item]) => typeof (saved as Record<string, unknown>)[field] === typeof item)) return initial;
        return Object.fromEntries(Object.keys(initial).map(field => [field, (saved as Record<string, unknown>)[field]])) as T;
      }
      return saved as T;
    } catch { return initial; }
  });
  useEffect(() => {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* Tables still work when storage is unavailable. */ }
  }, [key, value]);
  return [value, setValue];
}
