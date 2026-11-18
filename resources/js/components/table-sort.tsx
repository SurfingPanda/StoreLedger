import { useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';

export type Direction = 'asc' | 'desc';
type SortValue = string | number | boolean | null | undefined;
const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

export function useTableSort(initial = '', initialDirection: Direction = 'asc', onChange?: () => void) {
  const [sort, setSort] = useState(initial);
  const [direction, setDirection] = useState<Direction>(initialDirection);
  function change(key: string) {
    setDirection(sort === key && direction === 'asc' ? 'desc' : 'asc');
    setSort(key);
    onChange?.();
  }
  function header(key: string, label: ReactNode, amount = false) {
    const active = sort === key;
    const Icon = active ? direction === 'asc' ? ArrowUp : ArrowDown : ArrowUpDown;
    return <th key={key} scope="col" className={`${amount ? 'amount-column' : ''} ${active ? 'pos-sorted-column' : ''}`} aria-sort={active ? direction === 'asc' ? 'ascending' : 'descending' : 'none'}>
      <button type="button" className="pos-sort-button" onClick={() => change(key)} title={`Sort ${active && direction === 'asc' ? 'descending' : 'ascending'}`}>
        {label}<Icon size={14} aria-hidden="true" />
      </button>
    </th>;
  }
  function rows<T>(items: T[], value: (item: T, key: string) => SortValue): T[] {
    if (!sort) return items;
    return [...items].sort((a, b) => {
      const left = value(a, sort), right = value(b, sort);
      if (left == null || right == null) return left == null && right == null ? 0 : left == null ? 1 : -1;
      const comparison = typeof left === 'number' && typeof right === 'number' ? left - right : collator.compare(String(left), String(right));
      return direction === 'asc' ? comparison : -comparison;
    });
  }
  function reset() { setSort(initial); setDirection(initialDirection); onChange?.(); }
  return { sort, direction, header, rows, reset };
}

export function ChangeTable({ rows }: { rows: { field: string; before: string; after: string }[] }) {
  const sorting = useTableSort();
  return <table className="ledger-table voucher-table"><thead><tr>{sorting.header('field', 'FIELD')}{sorting.header('before', 'BEFORE')}{sorting.header('after', 'AFTER')}</tr></thead>
    <tbody>{sorting.rows(rows, (row, key) => row[key as keyof typeof row]).map(row => <tr key={row.field}><td>{row.field}</td><td><pre>{row.before}</pre></td><td><pre>{row.after}</pre></td></tr>)}</tbody>
  </table>;
}
