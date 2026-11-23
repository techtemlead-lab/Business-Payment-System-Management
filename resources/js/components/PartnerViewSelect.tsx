import { FilterDropdown, type FilterOption } from './FilterDropdown';

/**
 * A view, period or report picker for the partner pages: one drop-down instead of a strip of chips.
 * It narrows nothing, so the chosen value is never drawn as an active filter.
 */
export default function PartnerViewSelect({ options, value, onChange, label, className }: {
    options: FilterOption[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    className?: string;
}) {
    return <FilterDropdown className={`pv-select${className ? ` ${className}` : ''}`} options={options} value={value} onChange={onChange} label={label} highlight={false} />;
}
