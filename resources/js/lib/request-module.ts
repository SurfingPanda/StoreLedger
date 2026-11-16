import type { AppData } from '@/lib/auth';

export function requestModule(data: AppData) {
  const modules = {
    'internet-soa': { label: 'Internet SOA', prefix: 'NET', recordsUrl: data.urls.internetSoaRecords, listUrl: data.urls.internetSoa },
    water: { label: 'Water SOA', prefix: 'WTR', recordsUrl: data.urls.waterRecords, listUrl: data.urls.water },
    electricity: { label: 'Electricity SOA', prefix: 'ELEC', recordsUrl: data.urls.electricityRecords, listUrl: data.urls.electricity },
    rental: { label: 'Rental SOA', prefix: 'RENT', recordsUrl: data.urls.rentalRecords, listUrl: data.urls.rental },
  };
  const key = (data.page === 'internet-voucher' ? 'internet-soa' : data.page.replace(/-voucher$/, '')) as keyof typeof modules;
  return modules[key] ? { ...modules[key], isSoa: true } : { label: 'UTAK POS', prefix: 'POS', recordsUrl: data.urls.posRecords, listUrl: data.urls.pos, isSoa: false };
}
