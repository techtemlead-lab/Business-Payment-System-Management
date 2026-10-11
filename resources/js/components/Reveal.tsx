import {
    useEffect,
    useRef,
    useState,
    type CSSProperties,
    type ReactNode,
} from "react";

export function Reveal({
    children,
    className = "",
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);
    useEffect(() => {
        const element = ref.current;
        if (!element) return;
        const preference = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        );
        if (preference.matches || !("IntersectionObserver" in window)) {
            setVisible(true);
            return;
        }
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.08 },
        );
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    return (
        <div
            ref={ref}
            className={`reveal ${className}`}
            data-revealed={visible}
            onFocusCapture={() => setVisible(true)}
            style={{ "--reveal-delay": `${delay}ms` } as CSSProperties}
        >
            {children}
        </div>
    );
}
