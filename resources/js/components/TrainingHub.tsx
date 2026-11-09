import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Award, BookOpen, Download, Eye, EyeOff, FileText, ListChecks, Pencil, PlayCircle, Plus, Trash2 } from 'lucide-react';
import { Empty, FormErrors, Modal } from './UI';
import { useT } from '../shared/i18n/t';

export type TrainingItem = {
    id: number;
    title_bn: string;
    title_en: string;
    summary?: string | null;
    type: 'video' | 'pdf' | 'article';
    topic: string;
    audience: string;
    embed_url?: string | null;
    provider?: 'youtube' | 'facebook' | null;
    body?: string | null;
    pdf_url?: string | null;
    pdf_download_url?: string | null;
    pdf_name?: string | null;
    pdf_size?: number | null;
    video_file_url?: string | null;
    video_download_url?: string | null;
    video_name?: string | null;
    video_size?: number | null;
    video_mime?: string | null;
    is_new: boolean;
    published_at?: string | null;
    video_url?: string | null;
    published?: boolean;
    sort_order?: number;
    quiz?: Quiz | null;
    progress?: QuizProgress;
};

type QuizQuestion = { id: number; question: string; options: string[]; correct_option?: number };

type Quiz = { pass_percent: number; questions: QuizQuestion[] };

type QuizProgress = { completed: boolean; completed_at?: string | null; attempts: number; last_score: number | null; certificate_url: string | null };

type PartnerData = {
    mode: 'partner';
    groups: { topic: string; items: TrainingItem[] }[];
    new_for_days: number;
};

type StaffData = {
    mode: 'staff';
    can_edit: boolean;
    can_create: boolean;
    items: TrainingItem[];
    topics: string[];
    types: string[];
    audiences: string[];
    pdf_max_mb: number;
    video_max_mb: number;
    new_for_days: number;
    quiz_limit?: { per_hour: number; version: number; can_edit: boolean; max: number };
};

export type TrainingData = PartnerData | StaffData;

type Translate = ReturnType<typeof useT>;

const TYPE_ICONS = { video: PlayCircle, pdf: FileText, article: BookOpen } as const;

function useTitle(): (item: TrainingItem) => { main: string; other: string } {
    const { locale } = usePage<any>().props;
    return (item) => (locale === 'bn'
        ? { main: item.title_bn, other: item.title_en }
        : { main: item.title_en, other: item.title_bn });
}

function fileSize(bytes?: number | null): string {
    if (!bytes) {
        return '';
    }
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * Click-to-play video: nothing is loaded from YouTube or Facebook until the
 * partner presses play. The embed address comes from the server, which only
 * builds it for allowed hosts.
 */
function VideoFacade({ item, title, translate }: { item: TrainingItem; title: string; translate: Translate }) {
    const [playing, setPlaying] = useState(false);
    if (!item.embed_url) {
        return null;
    }
    const provider = item.provider === 'facebook' ? 'Facebook' : 'YouTube';
    if (playing) {
        return (
            <div className="training-video">
                <iframe
                    src={item.embed_url}
                    title={title}
                    loading="lazy"
                    referrerPolicy="strict-origin-when-cross-origin"
                    sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
                    allow="encrypted-media; picture-in-picture; fullscreen"
                    allowFullScreen
                />
            </div>
        );
    }
    return (
        <div className="training-video is-idle">
            <button type="button" className="training-play" onClick={() => setPlaying(true)}>
                <PlayCircle size={34} aria-hidden="true" />
                <span>{translate('portal', 'training_play_video')}</span>
            </button>
            <small>{translate('portal', 'training_video_privacy_note', { provider })}</small>
        </div>
    );
}

/** An uploaded video file: plays in the page (served from the company's own server) and can be downloaded. */
function UploadedVideo({ item, translate }: { item: TrainingItem; translate: Translate }) {
    if (!item.video_file_url) {
        return null;
    }
    return (
        <div className="training-video-file">
            <video controls preload="metadata" playsInline>
                <source src={item.video_file_url} type={item.video_mime ?? 'video/mp4'} />
                {translate('portal', 'training_video_unsupported')}
            </video>
            {item.video_download_url && (
                <a className="button small" href={item.video_download_url} download>
                    <Download size={16} aria-hidden="true" />{translate('portal', 'training_download')}
                    {item.video_size ? <span className="muted"> · {fileSize(item.video_size)}</span> : null}
                </a>
            )}
        </div>
    );
}

function TrainingCard({ item, translate, children }: { item: TrainingItem; translate: Translate; children?: React.ReactNode }) {
    const titleOf = useTitle();
    const { main, other } = titleOf(item);
    const Icon = TYPE_ICONS[item.type] ?? BookOpen;
    return (
        <article className="training-card">
            <header className="training-card-head">
                <span className={`training-type is-${item.type}`}><Icon size={15} aria-hidden="true" />{translate('portal', `training_type_${item.type}`)}</span>
                {item.is_new && <span className="training-new">{translate('portal', 'training_new_badge')}</span>}
            </header>
            <h3>{main}</h3>
            {other && other !== main && <p className="training-alt-title">{other}</p>}
            {item.summary && <p className="training-summary">{item.summary}</p>}
            {item.type === 'video' && (item.video_file_url
                ? <UploadedVideo item={item} translate={translate} />
                : <VideoFacade item={item} title={main} translate={translate} />)}
            {item.type === 'pdf' && item.pdf_url && (
                <div className="training-file-actions">
                    <a className="button small training-pdf" href={item.pdf_url} target="_blank" rel="noopener">
                        <FileText size={16} aria-hidden="true" />{translate('portal', 'training_open_pdf')}
                        {item.pdf_size ? <span className="muted"> · {fileSize(item.pdf_size)}</span> : null}
                    </a>
                    {item.pdf_download_url && (
                        <a className="button small" href={item.pdf_download_url} download>
                            <Download size={16} aria-hidden="true" />{translate('portal', 'training_download')}
                        </a>
                    )}
                </div>
            )}
            {item.type === 'article' && item.body && (
                <details className="training-article">
                    <summary>{translate('portal', 'training_read_article')}</summary>
                    <div className="training-article-body">{item.body}</div>
                </details>
            )}
            {children}
        </article>
    );
}

/**
 * The partner side of a lesson quiz: progress, the questions as radio buttons, and the
 * certificate link once passed. The server marks the answers; it never sends the right ones.
 */
function PartnerQuiz({ item, translate }: { item: TrainingItem; translate: Translate }) {
    const [open, setOpen] = useState(false);
    const [answers, setAnswers] = useState<Record<number, number>>({});
    const [sending, setSending] = useState(false);
    const quiz = item.quiz;
    if (!quiz || quiz.questions.length === 0) {
        return null;
    }
    const progress = item.progress;
    const allAnswered = quiz.questions.every((question) => answers[question.id] !== undefined);
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setSending(true);
        router.post(`/commands/training/${item.id}/quiz-attempt`, { answers }, {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); setAnswers({}); },
            onFinish: () => setSending(false),
        });
    };
    return (
        <div className="training-quiz">
            <div className="training-meta">
                {progress?.completed && <span className="badge approved">{translate('portal', 'training_quiz_completed')}</span>}
                {progress?.last_score !== null && progress?.last_score !== undefined && <span className="training-chip">{translate('portal', 'training_quiz_last_score', { score: String(progress.last_score) })}</span>}
                <span className="muted">{translate('portal', 'training_quiz_pass_hint', { count: String(quiz.questions.length), pass: String(quiz.pass_percent) })}</span>
            </div>
            <div className="training-actions">
                <button type="button" className="button small" onClick={() => setOpen(!open)}>
                    {translate('portal', open ? 'training_quiz_hide' : (progress?.attempts ? 'training_quiz_retake' : 'training_quiz_take'))}
                </button>
                {progress?.certificate_url && (
                    <a className="button small dark" href={progress.certificate_url} target="_blank" rel="noopener noreferrer">
                        <Award size={15} aria-hidden="true" />{translate('portal', 'training_quiz_certificate')}
                    </a>
                )}
            </div>
            {open && (
                <form className="action-form training-quiz-form" onSubmit={submit}>
                    {quiz.questions.map((question, index) => (
                        <fieldset key={question.id}>
                            <legend>{index + 1}. {question.question}</legend>
                            {question.options.map((option, optionIndex) => (
                                <label key={optionIndex} className="training-quiz-option">
                                    <input
                                        type="radio"
                                        name={`question-${item.id}-${question.id}`}
                                        checked={answers[question.id] === optionIndex}
                                        onChange={() => setAnswers({ ...answers, [question.id]: optionIndex })}
                                    />
                                    <span>{option}</span>
                                </label>
                            ))}
                        </fieldset>
                    ))}
                    <button type="submit" className="button dark" disabled={!allAnswered || sending}>
                        {sending ? translate('portal', 'saving') : translate('portal', 'training_quiz_submit')}
                    </button>
                </form>
            )}
        </div>
    );
}

type EditableQuestion = { question: string; options: string[]; correct_option: number };

/** Office editor for a lesson's quiz: pass mark and questions with 2 to 4 answers each. */
function QuizEditor({ item, onDone }: { item: TrainingItem; onDone: () => void }) {
    const translate = useT();
    const [passPercent, setPassPercent] = useState(String(item.quiz?.pass_percent ?? 70));
    const [questions, setQuestions] = useState<EditableQuestion[]>(
        (item.quiz?.questions ?? []).map((question) => ({ question: question.question, options: [...question.options], correct_option: question.correct_option ?? 0 })),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const change = (index: number, next: Partial<EditableQuestion>) => setQuestions(questions.map((question, position) => (position === index ? { ...question, ...next } : question)));
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        setSaving(true);
        router.put(`/commands/training/${item.id}/quiz`, { pass_percent: passPercent, questions }, {
            preserveScroll: true,
            onSuccess: () => onDone(),
            onError: (next) => setErrors(next as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    };
    return (
        <form className="action-form training-form" onSubmit={save}>
            <FormErrors errors={errors} />
            <p className="muted">{translate('portal', 'training_quiz_editor_hint')}</p>
            <label>{translate('portal', 'training_quiz_pass_percent')}
                <input type="number" min={1} max={100} required value={passPercent} onChange={(event) => setPassPercent(event.target.value)} />
            </label>
            {questions.map((question, index) => (
                <fieldset key={index} className="training-quiz-edit">
                    <legend>{translate('portal', 'training_quiz_question', { n: String(index + 1) })}</legend>
                    <textarea rows={2} required maxLength={500} value={question.question} onChange={(event) => change(index, { question: event.target.value })} />
                    {question.options.map((option, optionIndex) => (
                        <div key={optionIndex} className="training-quiz-edit-option">
                            <label className="training-quiz-option" title={translate('portal', 'training_quiz_correct_label')}>
                                <input type="radio" name={`correct-${index}`} checked={question.correct_option === optionIndex} onChange={() => change(index, { correct_option: optionIndex })} aria-label={translate('portal', 'training_quiz_correct_label')} />
                            </label>
                            <input type="text" required maxLength={200} placeholder={translate('portal', 'training_quiz_option', { n: String(optionIndex + 1) })} value={option} onChange={(event) => change(index, { options: question.options.map((value, position) => (position === optionIndex ? event.target.value : value)) })} />
                            {question.options.length > 2 && (
                                <button type="button" className="icon-button" aria-label={translate('portal', 'training_delete')} onClick={() => change(index, {
                                    options: question.options.filter((_, position) => position !== optionIndex),
                                    correct_option: question.correct_option === optionIndex ? 0 : question.correct_option > optionIndex ? question.correct_option - 1 : question.correct_option,
                                })}>
                                    <Trash2 size={15} />
                                </button>
                            )}
                        </div>
                    ))}
                    <div className="training-actions">
                        {question.options.length < 4 && (
                            <button type="button" className="button small" onClick={() => change(index, { options: [...question.options, ''] })}>
                                <Plus size={15} aria-hidden="true" />{translate('portal', 'training_quiz_add_option')}
                            </button>
                        )}
                        <button type="button" className="button small" onClick={() => setQuestions(questions.filter((_, position) => position !== index))}>
                            <Trash2 size={15} aria-hidden="true" />{translate('portal', 'training_quiz_remove_question')}
                        </button>
                    </div>
                </fieldset>
            ))}
            {questions.length < 20 && (
                <button type="button" className="button small" onClick={() => setQuestions([...questions, { question: '', options: ['', ''], correct_option: 0 }])}>
                    <Plus size={15} aria-hidden="true" />{translate('portal', 'training_quiz_add_question')}
                </button>
            )}
            <button type="submit" className="button dark" disabled={saving}>
                {saving ? translate('portal', 'saving') : translate('portal', 'training_quiz_save')}
            </button>
        </form>
    );
}

function PartnerLibrary({ data }: { data: PartnerData }) {
    const translate = useT();
    return (
        <div className="training-hub" data-tour="training-library">
            <section className="panel training-intro">
                <h2>{translate('portal', 'training')}</h2>
                <p className="muted">{translate('portal', 'training_partner_intro', { days: String(data.new_for_days) })}</p>
            </section>
            {data.groups.length === 0 && (
                <section className="panel"><Empty title={translate('portal', 'training_empty_title')} body={translate('portal', 'training_empty_body')} /></section>
            )}
            {data.groups.map((group) => (
                <section key={group.topic} className="training-topic" aria-labelledby={`training-topic-${group.topic}`}>
                    <h2 id={`training-topic-${group.topic}`}>{translate('portal', `training_topic_${group.topic}`)}</h2>
                    <div className="training-grid">
                        {group.items.map((item) => (
                            <TrainingCard key={item.id} item={item} translate={translate}>
                                <PartnerQuiz item={item} translate={translate} />
                            </TrainingCard>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

type FormShape = {
    title_bn: string;
    title_en: string;
    summary: string;
    type: string;
    topic: string;
    audience: string;
    video_url: string;
    body: string;
    pdf: File | null;
    video_file: File | null;
};

function TrainingForm({ data, item, onDone }: { data: StaffData; item: TrainingItem | null; onDone: () => void }) {
    const translate = useT();
    const form = useForm<FormShape>({
        title_bn: item?.title_bn ?? '',
        title_en: item?.title_en ?? '',
        summary: item?.summary ?? '',
        type: item?.type ?? 'video',
        topic: item?.topic ?? data.topics[0] ?? 'products',
        audience: item?.audience ?? 'all',
        video_url: item?.video_url ?? '',
        body: item?.body ?? '',
        pdf: null,
        video_file: null,
    });
    const keepingVideoFile = item?.type === 'video' && item.video_name;
    // An uploaded video is shown first when the item already has one; otherwise a link.
    const [videoSource, setVideoSource] = useState<'link' | 'file'>(keepingVideoFile ? 'file' : 'link');
    const chooseVideoSource = (source: 'link' | 'file') => {
        setVideoSource(source);
        if (source === 'link') {
            form.setData('video_file', null);
        } else {
            form.setData('video_url', '');
        }
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: () => onDone() };
        if (item) {
            form.transform((values) => ({ ...values, _method: 'patch' }));
            form.post(`/commands/training/${item.id}`, options);
        } else {
            form.transform((values) => values);
            form.post('/commands/training', options);
        }
    };
    const error = (name: keyof FormShape) => (form.errors[name] ? <span className="field-error" role="alert">{String(form.errors[name])}</span> : null);
    const keepingPdf = item?.type === 'pdf' && item.pdf_name;
    return (
        <form className="action-form training-form" onSubmit={submit}>
            <FormErrors errors={form.errors} besideFields={['title_bn', 'title_en', 'summary', 'body', 'video_url', 'video_file', 'pdf']} />
            <label>{translate('portal', 'training_field_title_bn')}
                <input type="text" lang="bn" required maxLength={200} value={form.data.title_bn} onChange={(e) => form.setData('title_bn', e.target.value)} />{error('title_bn')}
            </label>
            <label>{translate('portal', 'training_field_title_en')}
                <input type="text" lang="en" required maxLength={200} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />{error('title_en')}
            </label>
            <label>{translate('portal', 'training_field_summary')}
                <textarea rows={2} maxLength={500} value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} />{error('summary')}
            </label>
            <div className="training-form-row">
                <label>{translate('portal', 'training_field_type')}
                    <select required value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                        {data.types.map((type) => <option key={type} value={type}>{translate('portal', `training_type_${type}`)}</option>)}
                    </select>
                </label>
                <label>{translate('portal', 'training_field_topic')}
                    <select required value={form.data.topic} onChange={(e) => form.setData('topic', e.target.value)}>
                        {data.topics.map((topic) => <option key={topic} value={topic}>{translate('portal', `training_topic_${topic}`)}</option>)}
                    </select>
                </label>
                <label>{translate('portal', 'training_field_audience')}
                    <select required value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)}>
                        {data.audiences.map((audience) => <option key={audience} value={audience}>{translate('portal', `training_audience_${audience}`)}</option>)}
                    </select>
                </label>
            </div>
            {form.data.type === 'video' && (
                <fieldset className="training-video-source">
                    <legend>{translate('portal', 'training_field_video_source')}</legend>
                    <label className="inline-choice">
                        <input type="radio" name="video_source" checked={videoSource === 'file'} onChange={() => chooseVideoSource('file')} />
                        {translate('portal', 'training_video_source_file')}
                    </label>
                    <label className="inline-choice">
                        <input type="radio" name="video_source" checked={videoSource === 'link'} onChange={() => chooseVideoSource('link')} />
                        {translate('portal', 'training_video_source_link')}
                    </label>
                </fieldset>
            )}
            {form.data.type === 'video' && videoSource === 'file' && (
                <label>{translate('portal', 'training_field_video_file')}
                    <input type="file" accept="video/mp4,video/webm,.mp4,.webm" required={!keepingVideoFile} onChange={(e) => form.setData('video_file', e.target.files?.[0] ?? null)} />
                    <small className="field-hint">
                        {translate('portal', 'training_field_video_file_hint', { mb: String(data.video_max_mb) })}
                        {keepingVideoFile ? ` ${translate('portal', 'training_field_pdf_keep_hint', { name: String(item?.video_name) })}` : ''}
                    </small>{error('video_file')}
                    {form.progress && <progress value={form.progress.percentage ?? 0} max={100} aria-label={translate('portal', 'training_uploading')} />}
                </label>
            )}
            {form.data.type === 'video' && videoSource === 'link' && (
                <label>{translate('portal', 'training_field_video_url')}
                    <input type="url" inputMode="url" required pattern="https://.*" maxLength={500} placeholder="https://www.youtube.com/watch?v=..." value={form.data.video_url} onChange={(e) => form.setData('video_url', e.target.value)} />
                    <small className="field-hint">{translate('portal', 'training_field_video_hint')}</small>{error('video_url')}
                </label>
            )}
            {form.data.type === 'pdf' && (
                <label>{translate('portal', 'training_field_pdf')}
                    <input type="file" accept="application/pdf,.pdf" required={!keepingPdf} onChange={(e) => form.setData('pdf', e.target.files?.[0] ?? null)} />
                    <small className="field-hint">
                        {translate('portal', 'training_field_pdf_hint', { mb: String(data.pdf_max_mb) })}
                        {keepingPdf ? ` ${translate('portal', 'training_field_pdf_keep_hint', { name: String(item?.pdf_name) })}` : ''}
                    </small>{error('pdf')}
                </label>
            )}
            {form.data.type === 'article' && (
                <label>{translate('portal', 'training_field_body')}
                    <textarea rows={10} required maxLength={20000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />{error('body')}
                </label>
            )}
            <button type="submit" className="button dark" disabled={form.processing}>
                {form.processing ? translate('portal', 'saving') : translate('portal', 'training_save')}
            </button>
        </form>
    );
}

/** Owners: how many quiz tries a partner gets per quiz in one hour. */
function QuizLimitForm({ limit }: { limit: NonNullable<StaffData['quiz_limit']> }) {
    const translate = useT();
    const form = useForm({ per_hour: String(limit.per_hour), version: limit.version });
    return (
        <form className="action-form training-quiz-limit" onSubmit={(event) => { event.preventDefault(); form.post('/commands/training/quiz-limit', { preserveScroll: true }); }}>
            <label>{translate('portal', 'training_quiz_limit_label')}
                <input type="number" min={1} max={limit.max} required value={form.data.per_hour} onChange={(event) => form.setData('per_hour', event.target.value)} />
            </label>
            <p className="muted">{translate('portal', 'training_quiz_limit_hint')}</p>
            {form.errors.per_hour && <p className="error">{form.errors.per_hour}</p>}
            <button type="submit" className="button small" disabled={form.processing}>{translate('portal', 'training_quiz_limit_save')}</button>
        </form>
    );
}

function StaffEditor({ data }: { data: StaffData }) {
    const translate = useT();
    const titleOf = useTitle();
    const [editing, setEditing] = useState<TrainingItem | 'new' | null>(null);
    const [quizFor, setQuizFor] = useState<TrainingItem | null>(null);
    const items = data.items;

    const move = (index: number, step: -1 | 1) => {
        const target = index + step;
        if (target < 0 || target >= items.length) {
            return;
        }
        const ids = items.map((item) => item.id);
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post('/commands/training/reorder', { ids }, { preserveScroll: true });
    };
    const togglePublished = (item: TrainingItem) => {
        router.post(`/commands/training/${item.id}/publish`, { published: !item.published }, { preserveScroll: true });
    };
    const remove = (item: TrainingItem) => {
        if (window.confirm(translate('portal', 'training_delete_confirm', { title: titleOf(item).main }))) {
            router.delete(`/commands/training/${item.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="training-hub" data-tour="training-editor">
            <section className="panel training-intro">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'training')}</h2>
                        <p className="muted">{translate('portal', 'training_staff_intro')}</p>
                        {data.quiz_limit?.can_edit && <QuizLimitForm key={data.quiz_limit.version} limit={data.quiz_limit} />}
                    </div>
                    {data.can_create && (
                        <button type="button" className="button dark" onClick={() => setEditing('new')}>
                            <Plus size={17} aria-hidden="true" />{translate('portal', 'training_add')}
                        </button>
                    )}
                </div>
            </section>
            {items.length === 0 && (
                <section className="panel"><Empty title={translate('portal', 'training_empty_title')} body={translate('portal', 'training_staff_empty_body')} /></section>
            )}
            <div className="training-grid">
                {items.map((item, index) => (
                    <TrainingCard key={item.id} item={item} translate={translate}>
                        <div className="training-meta">
                            <span className={`badge ${item.published ? 'approved' : 'draft'}`}>{translate('portal', item.published ? 'training_status_published' : 'training_status_draft')}</span>
                            <span className="training-chip">{translate('portal', `training_topic_${item.topic}`)}</span>
                            <span className="training-chip">{translate('portal', `training_audience_${item.audience}`)}</span>
                            {(item.quiz?.questions.length ?? 0) > 0 && <span className="training-chip">{translate('portal', 'training_quiz_count', { count: String(item.quiz?.questions.length ?? 0) })}</span>}
                        </div>
                        {data.can_edit && (
                            <div className="training-actions">
                                <button type="button" className="button small" onClick={() => togglePublished(item)}>
                                    {item.published ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
                                    {translate('portal', item.published ? 'training_unpublish' : 'training_publish')}
                                </button>
                                <button type="button" className="button small" onClick={() => setEditing(item)}>
                                    <Pencil size={15} aria-hidden="true" />{translate('portal', 'training_edit')}
                                </button>
                                <button type="button" className="button small" onClick={() => setQuizFor(item)}>
                                    <ListChecks size={15} aria-hidden="true" />{translate('portal', 'training_quiz_edit')}
                                </button>
                                <button type="button" className="icon-button" disabled={index === 0} onClick={() => move(index, -1)} aria-label={translate('portal', 'training_move_up')} title={translate('portal', 'training_move_up')}>
                                    <ArrowUp size={16} />
                                </button>
                                <button type="button" className="icon-button" disabled={index === items.length - 1} onClick={() => move(index, 1)} aria-label={translate('portal', 'training_move_down')} title={translate('portal', 'training_move_down')}>
                                    <ArrowDown size={16} />
                                </button>
                                <button type="button" className="icon-button training-delete" onClick={() => remove(item)} aria-label={translate('portal', 'training_delete')} title={translate('portal', 'training_delete')}>
                                    <Trash2 size={16} />
                                </button>
                            </div>
                        )}
                    </TrainingCard>
                ))}
            </div>
            {editing && (
                <Modal title={translate('portal', editing === 'new' ? 'training_modal_add' : 'training_modal_edit')} onClose={() => setEditing(null)}>
                    <TrainingForm data={data} item={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
                </Modal>
            )}
            {quizFor && (
                <Modal title={`${translate('portal', 'training_quiz_modal')}: ${titleOf(quizFor).main}`} onClose={() => setQuizFor(null)}>
                    <QuizEditor item={quizFor} onDone={() => setQuizFor(null)} />
                </Modal>
            )}
        </div>
    );
}

/** Training library: office editor for staff, topic-grouped lessons for partners. */
export default function TrainingHub({ data }: { data?: TrainingData | null }) {
    if (!data) {
        return null;
    }
    return data.mode === 'staff' ? <StaffEditor data={data} /> : <PartnerLibrary data={data} />;
}
