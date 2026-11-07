import React, { useCallback, useEffect, useState } from 'react';
import { Bike, Car, Check, Clock, Medal, Plane, TrendingUp, Wallet } from 'lucide-react';
import { Modal, date, money, number } from './UI';
import { useT } from '../shared/i18n/t';

type Unit = 'taka' | 'people';
type Bar = { unit: Unit; current: string | number; target: string | number; left: string | number; percent: number };
type Period = { month: string; starts_on: string; ends_on: string; days_left: number };
type DealerSalary = Bar & {
    kind: 'dealer_pool';
    status: 'off' | 'waived' | 'met' | 'not_met';
    rate: string;
    estimate: { share: string; division: string | null; division_pool: string; qualifiers: number } | null;
};
type SubDealerSalary = { kind: 'sub_dealer_flat'; status: 'off' | 'earning'; rate: string; current: string; earned: string };
type NextRank = Bar & { rank: string; measure: 'own_purchases' | 'team_leaders'; required_rank?: string; depth?: number; award: string | null };
type Rank = {
    current: string;
    next: NextRank | null;
    award: { rank: string; amount: string | null; status: 'paid' | 'waiting' | 'off' } | null;
    keep: (Bar & { rank: string; status: string; royalty_on: boolean; grace_days: number }) | null;
};
type Reward = Bar & { type: 'bike' | 'car'; status: 'off' | 'in_progress' | 'reached' | 'given'; line_cap: string; team_sales: string; lines: number };
type Tour = {
    status: 'off' | 'saving';
    rate: string;
    saved_this_month: string;
    saved_total: string;
    used: string;
    available: string;
    requests_waiting: number;
    is_active: boolean;
};
type Progress = { role: string; period: Period; salary: DealerSalary | SubDealerSalary | null; rank: Rank | null; rewards: Reward[]; tour: Tour | null };

type Translate = ReturnType<typeof useT>;

const amount = (unit: Unit, value: string | number, translate: Translate) =>
    unit === 'people' ? translate('portal', 'pp_people', { count: number(value) }) : money(value);

/** Grows from empty to its value once shown; CSS turns the growth off for reduced motion. */
function ProgressBar({ bar, label, tone = 'brand' }: { bar: Bar; label: string; tone?: 'brand' | 'ok' | 'muted' }) {
    const translate = useT();
    const [width, setWidth] = useState(0);
    const percent = Math.max(0, Math.min(100, Number(bar.percent) || 0));
    useEffect(() => {
        const frame = window.requestAnimationFrame(() => setWidth(percent));

        return () => window.cancelAnimationFrame(frame);
    }, [percent]);

    return (
        <div className="pp-bar-block">
            <div
                className={`pp-bar tone-${tone}`}
                role="progressbar"
                aria-label={translate('portal', 'pp_bar_label', { label, percent: String(percent) })}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
            >
                <i style={{ width: `${width}%` }} />
            </div>
            <div className="pp-bar-meta">
                <span>
                    <b>{translate('portal', 'pp_done')}</b> {amount(bar.unit, bar.current, translate)}
                    <em> ({number(percent)}%)</em>
                </span>
                <span>
                    <b>{translate('portal', 'pp_left')}</b> {amount(bar.unit, bar.left, translate)}
                </span>
            </div>
        </div>
    );
}

function StatusLine({ ok, children }: { ok: boolean | null; children: React.ReactNode }) {
    return (
        <p className={`pp-status ${ok === null ? 'is-muted' : ok ? 'is-ok' : 'is-todo'}`}>
            {ok ? <Check size={15} aria-hidden="true" /> : <Clock size={15} aria-hidden="true" />}
            <span>{children}</span>
        </p>
    );
}

function Section({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
    return (
        <section className="pp-section">
            <h3>
                <span className="pp-section-icon" aria-hidden="true">{icon}</span>
                {title}
            </h3>
            {children}
        </section>
    );
}

function SalarySection({ salary, period }: { salary: DealerSalary | SubDealerSalary; period: Period }) {
    const translate = useT();
    const title = translate('portal', 'pp_salary_title');
    if (salary.status === 'off') {
        return <Section icon={<Wallet size={16} />} title={title}><StatusLine ok={null}>{translate('portal', 'pp_switched_off')}</StatusLine></Section>;
    }
    if (salary.kind === 'sub_dealer_flat') {
        return (
            <Section icon={<Wallet size={16} />} title={title}>
                <p className="pp-how">{translate('portal', 'pp_salary_sub_how', { rate: number(salary.rate) })}</p>
                <dl className="pp-figures">
                    <div><dt>{translate('portal', 'pp_salary_sub_bought')}</dt><dd>{money(salary.current)}</dd></div>
                    <div><dt>{translate('portal', 'pp_salary_sub_earned')}</dt><dd className="is-strong">{money(salary.earned)}</dd></div>
                </dl>
            </Section>
        );
    }

    return (
        <Section icon={<Wallet size={16} />} title={title}>
            <p className="pp-how">{translate('portal', 'pp_salary_dealer_how', { target: money(salary.target), rate: number(salary.rate) })}</p>
            {salary.status !== 'waived' && <ProgressBar bar={salary} label={title} tone={salary.status === 'met' ? 'ok' : 'brand'} />}
            {salary.status === 'waived' && <StatusLine ok>{translate('portal', 'pp_salary_waived')}</StatusLine>}
            {salary.status === 'met' && <StatusLine ok>{translate('portal', 'pp_salary_met')}</StatusLine>}
            {salary.status === 'not_met' && (
                <StatusLine ok={false}>{translate('portal', 'pp_salary_not_met', { left: money(salary.left), end: date(period.ends_on) })}</StatusLine>
            )}
            {salary.estimate && (
                <div className="pp-estimate">
                    <p>
                        {translate('portal', 'pp_salary_estimate', {
                            share: money(salary.estimate.share),
                            division: salary.estimate.division || '—',
                            pool: money(salary.estimate.division_pool),
                            people: number(salary.estimate.qualifiers),
                        })}
                    </p>
                    <small>{translate('portal', 'pp_salary_estimate_note')}</small>
                </div>
            )}
        </Section>
    );
}

function RankSection({ rank, period }: { rank: Rank; period: Period }) {
    const translate = useT();
    const rankName = (code: string) => translate('portal', `net_rank_${code}`);
    const next = rank.next;
    const keep = rank.keep;

    return (
        <Section icon={<Medal size={16} />} title={translate('portal', 'pp_rank_title')}>
            <p className="pp-rank-now">{translate('portal', 'pp_rank_now', { rank: rankName(rank.current) })}</p>
            {next ? (
                <div className="pp-sub">
                    <h4>{translate('portal', 'pp_rank_next', { rank: rankName(next.rank) })}</h4>
                    <p className="pp-how">
                        {next.measure === 'own_purchases'
                            ? translate('portal', 'pp_rank_next_purchase_how', { target: money(next.target) })
                            : translate('portal', 'pp_rank_next_team_how', {
                                needed: number(next.target),
                                rank: rankName(String(next.required_rank)),
                                depth: number(next.depth ?? 0),
                            })}
                    </p>
                    <ProgressBar bar={next} label={rankName(next.rank)} tone={Number(next.percent) >= 100 ? 'ok' : 'brand'} />
                    {Number(next.percent) >= 100 && <StatusLine ok>{translate('portal', 'pp_rank_met_wait')}</StatusLine>}
                    {next.award
                        ? <p className="pp-award">{translate('portal', 'pp_rank_award', { amount: money(next.award) })}</p>
                        : <StatusLine ok={null}>{translate('portal', 'pp_award_off')}</StatusLine>}
                </div>
            ) : (
                <StatusLine ok>{translate('portal', 'pp_rank_top')}</StatusLine>
            )}
            {rank.award && rank.award.status !== 'off' && (
                <StatusLine ok={rank.award.status === 'paid'}>
                    {translate('portal', rank.award.status === 'paid' ? 'pp_award_paid' : 'pp_award_waiting', {
                        rank: rankName(rank.award.rank),
                        amount: money(rank.award.amount),
                    })}
                </StatusLine>
            )}
            {keep && (
                <div className="pp-sub">
                    <h4>{translate('portal', 'pp_keep_title', { rank: rankName(keep.rank) })}</h4>
                    {!keep.royalty_on ? (
                        <StatusLine ok={null}>{translate('portal', 'pp_switched_off')}</StatusLine>
                    ) : keep.status === 'admin_unconditional' ? (
                        <StatusLine ok>{translate('portal', 'pp_keep_admin', { rank: rankName(keep.rank) })}</StatusLine>
                    ) : (
                        <>
                            <p className="pp-how">{translate('portal', 'pp_keep_how', { target: money(keep.target) })}</p>
                            <ProgressBar bar={keep} label={translate('portal', 'pp_keep_title', { rank: rankName(keep.rank) })} tone={keep.status === 'qualified' ? 'ok' : 'brand'} />
                            {keep.status === 'qualified' && <StatusLine ok>{translate('portal', 'pp_keep_qualified')}</StatusLine>}
                            {keep.status === 'grace_period' && <StatusLine ok>{translate('portal', 'pp_keep_grace', { days: number(keep.grace_days) })}</StatusLine>}
                            {keep.status === 'demoted' && (
                                <StatusLine ok={false}>{translate('portal', 'pp_keep_demoted', { left: money(keep.left), end: date(period.ends_on) })}</StatusLine>
                            )}
                        </>
                    )}
                </div>
            )}
        </Section>
    );
}

function RewardsSection({ rewards, isSubDealer }: { rewards: Reward[]; isSubDealer: boolean }) {
    const translate = useT();

    return (
        <Section icon={<Bike size={16} />} title={translate('portal', 'pp_rewards_title')}>
            {isSubDealer && <p className="pp-how">{translate('portal', 'pp_rank_subdealer_note')}</p>}
            {rewards.map((reward) => {
                const label = translate('portal', `pp_reward_${reward.type}`);

                return (
                    <div className="pp-sub" key={reward.type}>
                        <h4>
                            {reward.type === 'car' ? <Car size={15} aria-hidden="true" /> : <Bike size={15} aria-hidden="true" />}
                            {label}
                        </h4>
                        {reward.status === 'off' ? (
                            <StatusLine ok={null}>{translate('portal', 'pp_switched_off')}</StatusLine>
                        ) : (
                            <>
                                <p className="pp-how">{translate('portal', 'pp_reward_how', { cap: money(reward.line_cap) })}</p>
                                <ProgressBar bar={reward} label={label} tone={reward.status === 'in_progress' ? 'brand' : 'ok'} />
                                <small className="pp-note">{translate('portal', 'pp_reward_team_total', { amount: money(reward.team_sales), lines: number(reward.lines) })}</small>
                                {reward.status === 'reached' && <StatusLine ok>{translate('portal', 'pp_reward_reached')}</StatusLine>}
                                {reward.status === 'given' && <StatusLine ok>{translate('portal', 'pp_reward_given')}</StatusLine>}
                            </>
                        )}
                    </div>
                );
            })}
        </Section>
    );
}

function TourSection({ tour }: { tour: Tour }) {
    const translate = useT();
    const title = translate('portal', 'pp_tour_title');
    if (tour.status === 'off') {
        return <Section icon={<Plane size={16} />} title={title}><StatusLine ok={null}>{translate('portal', 'pp_switched_off')}</StatusLine></Section>;
    }
    const hasMoney = Number(tour.available) > 0;

    return (
        <Section icon={<Plane size={16} />} title={title}>
            <p className="pp-how">{translate('portal', 'pp_tour_how', { rate: number(tour.rate) })}</p>
            <ul className="pp-checklist">
                <li className={tour.is_active ? 'is-ok' : 'is-todo'}>
                    {tour.is_active ? <Check size={15} aria-hidden="true" /> : <Clock size={15} aria-hidden="true" />}
                    <span>{translate('portal', 'pp_tour_check_active')}</span>
                    <em>{translate('portal', tour.is_active ? 'pp_met' : 'pp_not_met')}</em>
                </li>
                <li className="is-ok">
                    <Check size={15} aria-hidden="true" />
                    <span>{translate('portal', 'pp_tour_check_on')}</span>
                    <em>{translate('portal', 'pp_met')}</em>
                </li>
                <li className={hasMoney ? 'is-ok' : 'is-todo'}>
                    {hasMoney ? <Check size={15} aria-hidden="true" /> : <Clock size={15} aria-hidden="true" />}
                    <span>{translate('portal', 'pp_tour_check_money', { amount: money(tour.available) })}</span>
                    <em>{translate('portal', hasMoney ? 'pp_met' : 'pp_not_met')}</em>
                </li>
                <li className="is-todo">
                    <Clock size={15} aria-hidden="true" />
                    <span>
                        {translate('portal', 'pp_tour_check_office')}
                        <small>
                            {tour.requests_waiting > 0
                                ? translate('portal', 'pp_tour_office_waiting', { count: number(tour.requests_waiting) })
                                : translate('portal', 'pp_tour_office_none')}
                        </small>
                    </span>
                    <em>{translate('portal', 'pp_tour_office_step')}</em>
                </li>
            </ul>
            <dl className="pp-figures">
                <div><dt>{translate('portal', 'pp_tour_saved_month')}</dt><dd>{money(tour.saved_this_month)}</dd></div>
                <div><dt>{translate('portal', 'pp_tour_saved_total')}</dt><dd>{money(tour.saved_total)}</dd></div>
                <div><dt>{translate('portal', 'pp_tour_used')}</dt><dd>{money(tour.used)}</dd></div>
                <div><dt>{translate('portal', 'pp_tour_available')}</dt><dd className="is-strong">{money(tour.available)}</dd></div>
            </dl>
        </Section>
    );
}

function Skeleton() {
    const translate = useT();

    return (
        <div className="pp-skeleton" role="status" aria-live="polite">
            <span className="sr-only">{translate('portal', 'pp_loading')}</span>
            {[0, 1, 2].map((row) => (
                <div key={row} className="pp-skeleton-block" aria-hidden="true">
                    <i /><i /><i />
                </div>
            ))}
        </div>
    );
}

function PartnerProgressModal({ onClose }: { onClose: () => void }) {
    const translate = useT();
    const [progress, setProgress] = useState<Progress | null>(null);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const retry = useCallback(() => {
        setFailed(false);
        setAttempt((value) => value + 1);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        fetch('/my-progress', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then((body: { data: Progress }) => setProgress(body.data))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [attempt]);

    return (
        <Modal title={translate('portal', 'pp_title')} onClose={onClose}>
            <div className="pp-body">
                {failed ? (
                    <div className="pp-error" role="alert">
                        <p>{translate('portal', 'pp_error')}</p>
                        <button type="button" className="button small" onClick={retry}>{translate('portal', 'pp_retry')}</button>
                    </div>
                ) : !progress ? (
                    <Skeleton />
                ) : (
                    <>
                        <p className="pp-period">
                            {translate('portal', 'pp_period', {
                                start: date(progress.period.starts_on),
                                end: date(progress.period.ends_on),
                                days: number(progress.period.days_left),
                            })}
                        </p>
                        {progress.salary && <SalarySection salary={progress.salary} period={progress.period} />}
                        {progress.rank && <RankSection rank={progress.rank} period={progress.period} />}
                        {progress.rewards.length > 0 && <RewardsSection rewards={progress.rewards} isSubDealer={progress.role === 'sub_dealer'} />}
                        {progress.tour && <TourSection tour={progress.tour} />}
                        <p className="pp-footnote">{translate('portal', 'pp_counts_note')}</p>
                    </>
                )}
            </div>
        </Modal>
    );
}

/** "See my progress" button for the partner home screen; the numbers load only when it is opened. */
export function PartnerProgressButton() {
    const translate = useT();
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" className="button small dark pp-open" onClick={() => setOpen(true)} aria-haspopup="dialog">
                <TrendingUp size={15} aria-hidden="true" /> {translate('portal', 'pp_open')}
            </button>
            {open && <PartnerProgressModal onClose={() => setOpen(false)} />}
        </>
    );
}
