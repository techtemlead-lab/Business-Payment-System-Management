import { useEffect, useRef, useState } from "react";
import { ArrowUpRight, Pause, Play } from "lucide-react";

const levels = [
    {
        name: "Divisional",
        short: "Division",
        title: "The bigger picture.",
        detail: "A divisional dealer profile brings regional coverage into the company workspace.",
        x: 380,
        y: 100,
        path: "M230 210 H302 V100 H380",
    },
    {
        name: "District",
        short: "District",
        title: "Closer to your partners.",
        detail: "District profiles keep dealer contacts, account status, and territory information together.",
        x: 394,
        y: 284,
        path: "M230 210 H320 V284 H394",
    },
    {
        name: "Upazila / Thana",
        short: "Upazila",
        title: "Local knowledge matters.",
        detail: "Upazila and Thana profiles connect local businesses with their approved territories.",
        x: 173,
        y: 346,
        path: "M230 210 V280 H173 V346",
    },
    {
        name: "Union",
        short: "Union",
        title: "Right where business happens.",
        detail: "Union-level profiles give the company a clear view of its community dealer network.",
        x: 88,
        y: 148,
        path: "M230 210 H155 V148 H88",
    },
];

export function NetworkScene() {
    const [selected, setSelected] = useState(0);
    const [paused, setPaused] = useState(false);
    const [inView, setInView] = useState(false);
    const [reduced, setReduced] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const preference = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        );
        const update = () => setReduced(preference.matches);
        update();
        preference.addEventListener("change", update);
        const observer = new IntersectionObserver(([entry]) =>
            setInView(entry.isIntersecting),
        );
        if (ref.current) observer.observe(ref.current);
        return () => {
            observer.disconnect();
            preference.removeEventListener("change", update);
        };
    }, []);
    return (
        <div
            ref={ref}
            className="network-scene"
            data-running={inView && !paused && !reduced}
        >
            <div className="scene-heading">
                <span>
                    <i /> THE CONNECTION ATLAS
                </span>
                <button
                    type="button"
                    className="scene-motion"
                    aria-label={
                        paused
                            ? "Play network animation"
                            : "Pause network animation"
                    }
                    aria-pressed={paused}
                    onClick={() => setPaused(!paused)}
                    disabled={reduced}
                >
                    {paused || reduced ? (
                        <Play size={13} />
                    ) : (
                        <Pause size={13} />
                    )}
                </button>
            </div>
            <div className="scene-canvas">
                <svg
                    viewBox="0 0 480 420"
                    role="img"
                    aria-label="An illustration connecting the company to four dealer levels"
                >
                    <defs>
                        <pattern
                            id="atlas-grid"
                            width="24"
                            height="24"
                            patternUnits="userSpaceOnUse"
                        >
                            <circle
                                cx="1"
                                cy="1"
                                r=".75"
                                fill="#afc6b8"
                                opacity=".25"
                            />
                        </pattern>
                    </defs>
                    <rect width="480" height="420" fill="url(#atlas-grid)" />
                    <g
                        className="scene-contours"
                        fill="none"
                        stroke="#456256"
                        strokeWidth=".8"
                    >
                        <path d="M120 0C85 48 55 49 45 110S90 190 60 236 58 309 120 352 141 404 136 420" />
                        <path d="M145 0C112 70 77 66 83 122S125 192 104 249 135 312 167 360 177 397 180 420" />
                        <path d="M175 0C180 79 114 71 126 149S178 229 163 281 217 360 204 420" />
                        <path d="M341 0C326 68 404 90 363 140S353 251 407 290 388 380 428 420" />
                        <path d="M378 0C370 65 446 89 408 171S412 224 444 282 419 364 461 395" />
                    </g>
                    <g fill="none" strokeLinecap="round">
                        {levels.map((level, i) => (
                            <g key={level.name}>
                                <path
                                    d={level.path}
                                    className={`scene-route ${selected === i ? "scene-route-active" : ""}`}
                                />
                                <path
                                    d={level.path}
                                    className={`scene-flow ${selected === i ? "scene-flow-active" : ""}`}
                                />
                            </g>
                        ))}
                    </g>
                    <rect
                        x="193"
                        y="173"
                        width="74"
                        height="74"
                        rx="17"
                        fill="#d7ee9b"
                    />
                    <rect
                        x="186"
                        y="166"
                        width="88"
                        height="88"
                        rx="23"
                        fill="none"
                        stroke="#d7ee9b"
                        opacity=".2"
                    />
                    <path
                        d="M212 229V191H219L242 217V191H249V229H242L219 204V229Z"
                        fill="#18372d"
                    />
                    <text
                        x="230"
                        y="276"
                        textAnchor="middle"
                        className="scene-company"
                    >
                        COMPANY HQ
                    </text>
                    {levels.map((level, i) => (
                        <g
                            key={level.name}
                            className={`scene-point ${selected === i ? "scene-point-active" : ""}`}
                        >
                            <circle
                                cx={level.x}
                                cy={level.y}
                                r="25"
                                className="scene-ring"
                            />
                            <circle cx={level.x} cy={level.y} r="17" />
                            <text
                                x={level.x}
                                y={level.y + 4}
                                textAnchor="middle"
                            >
                                0{i + 1}
                            </text>
                            <text
                                x={level.x}
                                y={level.y + 44}
                                textAnchor="middle"
                                className="scene-point-label"
                            >
                                {level.short.toUpperCase()}
                            </text>
                        </g>
                    ))}
                    <text x="20" y="401" className="scene-coordinate">
                        23°41′ N / 90°21′ E
                    </text>
                    <text
                        x="462"
                        y="401"
                        textAnchor="end"
                        className="scene-coordinate"
                    >
                        STRUCTURE / ILLUSTRATIVE
                    </text>
                </svg>
                <span className="scene-note">
                    <i /> Four levels. One shared direction.
                </span>
            </div>
            <div
                className="scene-select"
                role="group"
                aria-label="Explore dealer levels"
            >
                {levels.map((level, i) => (
                    <button
                        key={level.name}
                        type="button"
                        aria-pressed={selected === i}
                        onClick={() => setSelected(i)}
                    >
                        <span>0{i + 1}</span>
                        {level.short}
                    </button>
                ))}
            </div>
            <div className="scene-detail" aria-live="polite" aria-atomic="true">
                <div key={selected}>
                    <span>{levels[selected].name}</span>
                    <h3>{levels[selected].title}</h3>
                    <p>{levels[selected].detail}</p>
                </div>
                <ArrowUpRight size={20} aria-hidden="true" />
            </div>
        </div>
    );
}
