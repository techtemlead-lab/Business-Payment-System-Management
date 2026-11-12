import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import { FormErrors } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import type { ReasonMode } from '../../../shared/reasonFields';
import { settingsBack } from './SettingsForm';

type Place = { key: string; label: string; mode: ReasonMode; money: boolean };
type Group = { key: string; label: string; places: Place[] };
type Settings = { version: number; groups: Group[] };

const MODES: ReasonMode[] = ['required', 'optional', 'off'];

/**
 * Owners decide, place by place, whether a reason or note box must be filled, may be left
 * blank, or is not shown at all. Only the places that were changed are sent.
 */
export default function ReasonFields({ settings }: { settings: Settings }) {
    const translate = useT();
    const count = settings.groups.reduce((total, group) => total + group.places.length, 0);

    return (
        <OfficePage title={translate('reason_fields', 'title')} subtitle={translate('reason_fields', 'count_line', { count })} back={settingsBack(translate)}>
            {/* A saved page comes back with a new version; starting the form again shows what was saved. */}
            <ChoicesForm key={settings.version} settings={settings} />
        </OfficePage>
    );
}

function ChoicesForm({ settings }: { settings: Settings }) {
    const translate = useT();
    const saved = useMemo(() => Object.fromEntries(settings.groups.flatMap((group) => group.places.map((place) => [place.key, place.mode]))), [settings]);
    const form = useForm<{ version: number; modes: Record<string, ReasonMode> }>({ version: settings.version, modes: saved });
    const changed = Object.keys(saved).filter((place) => form.data.modes[place] !== saved[place]);

    return (
        <form
            className="rf-form"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({ version: data.version, modes: Object.fromEntries(changed.map((place) => [place, data.modes[place]])) }));
                form.post('/commands/owner/reason-fields', { preserveScroll: true });
            }}
        >
            <section className="rf-legend" aria-label={translate('reason_fields', 'legend_title')}>
                {MODES.map((mode) => (
                    <p key={mode}><b>{translate('reason_fields', `mode_${mode}`)}</b> {translate('reason_fields', `legend_${mode}`)}</p>
                ))}
                <p>{translate('reason_fields', 'owner_never_forced')}</p>
            </section>

            {settings.groups.filter((group) => group.places.length > 0).map((group) => (
                <section className="rf-group" key={group.key} data-group={group.key}>
                    <h2>{group.label}</h2>
                    <ul className="rf-places">
                        {group.places.map((place) => (
                            <li className="rf-place" key={place.key} data-place={place.key} data-changed={form.data.modes[place.key] !== saved[place.key] || undefined}>
                                <div className="rf-name">
                                    <span>{place.label}</span>
                                    {/* A money record starts as compulsory; the warning shows only once it is not. */}
                                    {place.money && form.data.modes[place.key] !== 'required' && <small><b>{translate('reason_fields', 'money_mark')}</b> {translate('reason_fields', 'money_warning')}</small>}
                                </div>
                                <fieldset className="rf-choice">
                                    <legend className="sr-only">{translate('reason_fields', 'choice_for', { place: place.label })}</legend>
                                    {MODES.map((mode) => (
                                        <label key={mode} data-mode={mode}>
                                            <input
                                                type="radio"
                                                name={place.key}
                                                value={mode}
                                                checked={form.data.modes[place.key] === mode}
                                                onChange={() => form.setData('modes', { ...form.data.modes, [place.key]: mode })}
                                            />
                                            <span>{translate('reason_fields', `mode_${mode}`)}</span>
                                        </label>
                                    ))}
                                </fieldset>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}

            <FormErrors errors={form.errors} />
            <div className="rf-foot">
                <button className="button dark" disabled={form.processing || changed.length === 0}>
                    {form.processing ? translate('reason_fields', 'saving') : translate('reason_fields', 'save')}
                </button>
                <span role="status">{changed.length > 0 ? translate('reason_fields', 'unsaved', { count: changed.length }) : translate('reason_fields', 'no_changes')}</span>
            </div>
        </form>
    );
}
