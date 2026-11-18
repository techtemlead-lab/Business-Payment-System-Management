/** Titles that come before a name in Bangladesh; they are not the person's first name. */
const TITLES = new Set([
    'md', 'mohammad', 'mohammed', 'muhammad', 'mst', 'mosammat', 'mrs', 'mr', 'ms', 'miss', 'haji', 'hajji', 'hazi', 'alhaj', 'al-haj', 'dr', 'prof', 'engr', 'sheikh-md',
    'মোঃ', 'মো', 'মোহাম্মদ', 'মুহাম্মদ', 'মোছাঃ', 'মোছা', 'মোসাঃ', 'মোসা', 'মোসাম্মৎ', 'মোসাম্মাৎ', 'হাজী', 'হাজি', 'আলহাজ্ব', 'আলহাজ', 'ডাঃ', 'ডা', 'প্রফেসর', 'মিসেস', 'মিস', 'জনাব', 'বেগম',
]);

const clean = (word: string): string => word.replace(/[.,]/g, '').toLowerCase();

/** The first real name: "Md Abdur Rahman Sarkar" is greeted as "Abdur", "মোছাঃ শিউলি বেগম" as "শিউলি". */
export function firstNameOf(fullName: string | null | undefined): string {
    const words = String(fullName ?? '').trim().split(/\s+/).filter(Boolean);
    const first = words.find((word) => !TITLES.has(clean(word)));

    return first ?? words[0] ?? '';
}
