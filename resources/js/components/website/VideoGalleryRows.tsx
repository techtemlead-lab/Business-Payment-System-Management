import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import type { VideoGalleryItem } from './types';
import type { WebsiteSectionProps } from './WebsiteLayout';

const WORD_BOXES = [
    { box: 'title_bn', label: 'sce_video_row_title', language: 'sce_language_bangla' },
    { box: 'title', label: 'sce_video_row_title', language: 'sce_language_english' },
    { box: 'subtitle_bn', label: 'sce_video_row_subtitle', language: 'sce_language_bangla' },
    { box: 'subtitle', label: 'sce_video_row_subtitle', language: 'sce_language_english' },
] as const;

/** The videos in the home page section: add, change, reorder and remove. The page's Save button stores them. */
export function VideoGalleryRows({ form }: Pick<WebsiteSectionProps, 'form'>) {
    const translate = useT();
    const rows = form.data.customization.video_gallery_items || [];
    const setRows = (video_gallery_items: VideoGalleryItem[]) => form.setData('customization', { ...form.data.customization, video_gallery_items });
    const updateRow = (index: number, box: keyof VideoGalleryItem, value: string) => setRows(rows.map((row, at) => (at === index ? { ...row, [box]: value } : row)));
    const moveRow = (from: number, to: number) => {
        const reordered = [...rows];
        const [moved] = reordered.splice(from, 1);
        reordered.splice(to, 0, moved);
        setRows(reordered);
    };

    return (
        <div className="ed-stack ed-mt">
            <p className="ed-desc is-sm is-flush">{translate('portal', 'sce_video_rows_hint')}</p>
            {rows.length === 0 && <div className="ed-empty is-sm">{translate('portal', 'sce_video_empty')}</div>}
            {rows.map((row, index) => (
                <div key={index} className="ed-card is-soft">
                    <div className="ed-head is-sm">
                        <strong className="ed-strong">{translate('portal', 'sce_video_number', { number: String(index + 1) })}</strong>
                        <div className="ed-row is-tight">
                            <button type="button" disabled={index === 0} onClick={() => moveRow(index, index - 1)} className="ed-icon-btn" title={translate('portal', 'sce_move_up')} aria-label={translate('portal', 'sce_move_up')}>↑</button>
                            <button type="button" disabled={index === rows.length - 1} onClick={() => moveRow(index, index + 1)} className="ed-icon-btn" title={translate('portal', 'sce_move_down')} aria-label={translate('portal', 'sce_move_down')}>↓</button>
                            <button type="button" className="button small danger" onClick={() => setRows(rows.filter((_, at) => at !== index))}>
                                <Trash2 size={14} /> {translate('portal', 'sce_remove')}
                            </button>
                        </div>
                    </div>
                    <div className="form-grid ed-grid is-md">
                        <label className="ed-block hub-span-all" data-field={`video_gallery_items.${index}.video_url`}>
                            <span className="ed-label">{translate('portal', 'sce_video_link')}</span>
                            <input type="url" className="ed-input" value={row.video_url || ''} placeholder="https://www.youtube.com/watch?v=..." onChange={(event) => updateRow(index, 'video_url', event.target.value)} />
                        </label>
                        {WORD_BOXES.map(({ box, label, language }) => (
                            <label key={box} className="ed-block">
                                <span className="ed-label">{translate('portal', label, { language: translate('portal', language) })}</span>
                                <input className="ed-input" maxLength={200} value={row[box] || ''} onChange={(event) => updateRow(index, box, event.target.value)} />
                            </label>
                        ))}
                    </div>
                </div>
            ))}
            <button type="button" className="button small dark" onClick={() => setRows([...rows, { title: '', title_bn: '', subtitle: '', subtitle_bn: '', video_url: '' }])}>
                <Plus size={14} /> {translate('portal', 'sce_video_add')}
            </button>
        </div>
    );
}
