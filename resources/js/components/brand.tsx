import { cn } from '@/lib/utils';

export function Brand({ className, logo }: { className?: string; logo: string }) {
  return <div className={cn('brand bw-brand', className)} aria-label="BW Superbakeshop">
    <img className="bw-logo" src={logo} alt="" width="250" height="250" />
    <span className="bw-brand-caption">Operations<span>Rental & renewals</span></span>
  </div>;
}

export function ShopIllustration() {
  return <svg className="shop-illustration" viewBox="0 0 540 290" fill="none" aria-hidden="true">
    <circle cx="399" cy="65" r="33" fill="#ead5bc" />
    <path d="M27 257h488M46 264h51m340 0h52" stroke="#a89083" strokeWidth="1.2" strokeLinecap="round" />
    <path d="M68 256V87h166v169" fill="#f7f0e7" stroke="#9e7e74" strokeWidth="1.4" />
    <path d="M61 87h180V74H61v13Z" fill="#ded2c4" stroke="#9e7e74" strokeWidth="1.4" />
    <path d="M83 74V64h136v10M103 107h96v29h-96z" stroke="#9e7e74" strokeWidth="1.3" />
    <path d="M123 119h56m-43 6h30" stroke="#a88b7f" strokeWidth="1.4" strokeLinecap="round" />
    <path d="M78 171h147l-12-25H90l-12 25Z" fill="#d6a4a0" stroke="#9e7e74" strokeWidth="1.3" />
    <path d="m113 146-5 25m30-25-2 25m28-25 2 25m23-25 6 25" stroke="#f4e5dc" strokeWidth="7" />
    <path d="M78 171v7c0 9 18 9 18 0 0 9 19 9 19 0 0 9 18 9 18 0 0 9 19 9 19 0 0 9 18 9 18 0 0 9 19 9 19 0 0 9 18 9 18 0 0 9 18 9 18 0v-7" fill="#d6a4a0" stroke="#9e7e74" strokeWidth="1.2" />
    <path d="M91 256v-64h61v64m-51-10v-44h41v44h-41Zm65 10v-64h43v64m-22-64v64" stroke="#9e7e74" strokeWidth="1.2" />
    <path d="m104 224 15-14m-15 24 24-24m49-2h2" stroke="#c4b4a7" strokeWidth="1.2" strokeLinecap="round" />
    <path d="M247 256V112h185v144" fill="#e4e4d9" stroke="#8e9686" strokeWidth="1.4" />
    <path d="M241 112h197V99H241v13Z" fill="#d1d7c8" stroke="#8e9686" strokeWidth="1.4" />
    <path d="M266 99V88h147v11M262 126h155v26H262z" stroke="#8e9686" strokeWidth="1.2" />
    <path d="M308 137h63m-48 6h33" stroke="#8e9686" strokeWidth="1.3" strokeLinecap="round" />
    <path d="M257 176h165l-11-16H268l-11 16Z" fill="#aab49e" stroke="#8e9686" strokeWidth="1.3" />
    <path d="M257 176v8h165v-8M267 256v-63h60v63m13 0v-63h70v63m-35-63v63m-108-17h60m-25-46v46" stroke="#8e9686" strokeWidth="1.2" />
    <path d="m277 220 13-13m-13 22 21-21m52 12 13-13" stroke="#b6beac" strokeWidth="1.2" strokeLinecap="round" />
    <path d="M355 236h41v-23h-41v23Z" fill="#f2e9dc" stroke="#8e9686" strokeWidth="1.2" />
    <path d="M365 223h21m-21 5h13" stroke="#a6ac9c" strokeWidth="1.2" strokeLinecap="round" />
    <path d="M449 256h27l4-26h-35l4 26Z" fill="#c69584" stroke="#9f8171" strokeWidth="1.2" />
    <path d="M463 230v-57m0 39c-18 0-24-12-24-21 16 0 24 11 24 21Zm0-15c16 0 24-12 24-22-16 0-24 12-24 22Zm0-14c-12-4-15-17-11-27 12 6 15 19 11 27Z" fill="#b5bda6" stroke="#8c9a7f" strokeWidth="1.2" strokeLinejoin="round" />
    <path d="M48 256h18l3-21H45l3 21Z" fill="#d7b6a2" stroke="#aa8e7d" strokeWidth="1.1" /><path d="M56 235v-32m0 22c-12-2-14-10-12-15 9 0 13 9 12 15Zm1-9c10-1 14-9 12-15-10 1-14 9-12 15Z" fill="#aab49e" stroke="#8c9a7f" strokeWidth="1.1" />
    <path d="M216 256h35m-26-7h13m-13-6h13" stroke="#a89083" strokeWidth="1.2" strokeLinecap="round" />
  </svg>;
}
