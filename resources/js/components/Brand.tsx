export function Logo({ light = false }: { light?: boolean }) {
    return (
        <span className={`brand ${light ? "brand-light" : ""}`}>
            <span className="brand-mark">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <path
                        d="M7 24V8h4l10 11V8h4v16h-4L11 13v11z"
                        fill="currentColor"
                    />
                </svg>
            </span>
            <span>
                nexora<span className="brand-period">.</span>
            </span>
        </span>
    );
}
