import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Empty } from '../UI';
import '../../../css/office-feature-boxes.css';

export type FeatureLink = { key: string; label: string; description: string; href: string };
export type FeatureGroup = { key: string; label: string; links: FeatureLink[] };

/**
 * Groups of boxes, each box a name and one line saying what it is for; the whole box opens the page.
 * Rows are as tall as their tallest box, so a row always looks even. The caller has already left out
 * every page this person may not open.
 */
export default function FeatureBoxes({ groups, emptyLine, columns = 4 }: { groups: FeatureGroup[]; emptyLine: string; columns?: 3 | 4 }) {
    const base = String(usePage<any>().props.portalBase ?? '');

    if (groups.length === 0) {
        return <Empty line={emptyLine} />;
    }

    return (
        <div className="feature-boxes" data-columns={columns}>
            {groups.map((group) => (
                <section key={group.key} aria-labelledby={`feature-group-${group.key}`}>
                    <h2 id={`feature-group-${group.key}`}>{group.label}</h2>
                    <ul>
                        {group.links.map((link) => (
                            <li key={link.key}>
                                <Link href={`${base}${link.href}`} className="feature-box">
                                    <span className="feature-box-title">{link.label}<ChevronRight size={16} aria-hidden="true" /></span>
                                    <span className="feature-box-text">{link.description}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}
