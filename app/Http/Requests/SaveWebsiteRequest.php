<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $defaults = config('website');
        $rules = ['revision' => 'required|integer|min:1', 'document' => ['required', 'array:'.implode(',', array_keys($defaults))]];
        foreach ($defaults as $group => $settings) {
            if (in_array($group, ['sections', 'pages', 'navigation', 'translations', 'redirects'], true)) {
                continue;
            }
            $rules['document.'.$group] = ['required', 'array:'.implode(',', array_keys($settings))];
            foreach ($settings as $field => $value) {
                $rules['document.'.$group.'.'.$field] = is_bool($value) ? 'required|boolean' : (is_numeric($value) ? 'required|numeric' : (is_array($value) ? 'present|array|max:40' : 'present|nullable|string|max:4000'));
            }
        }
        foreach (['brand.logo_width' => [40, 300], 'theme.font_size' => [14, 22], 'theme.heading_size' => [32, 96], 'theme.line_height' => [1.2, 2], 'theme.width' => [800, 1600], 'theme.spacing' => [24, 120], 'theme.radius' => [0, 32], 'theme.motion_duration' => [100, 1000], 'hero.height' => [320, 900], 'hero.overlay' => [0, 70]] as $path => [$min, $max]) {
            $rules['document.'.$path] = "required|numeric|min:$min|max:$max";
        }
        foreach (['primary', 'accent', 'background', 'surface', 'text', 'muted', 'border'] as $color) {
            $rules['document.theme.'.$color] = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }
        foreach (['theme.heading_font' => ['Inter', 'Manrope', 'system'], 'theme.body_font' => ['Inter', 'Manrope', 'system'], 'theme.shadow' => ['none', 'subtle', 'strong'], 'theme.button_style' => ['filled', 'outline', 'pill'], 'theme.mode' => ['light', 'dark'], 'theme.density' => ['compact', 'comfortable'], 'theme.motion' => ['none', 'subtle', 'expressive'], 'theme.header_layout' => ['spread', 'center'], 'hero.align' => ['left', 'center'], 'hero.focal' => ['center', 'top', 'bottom', 'left', 'right'], 'languages.default' => ['en', 'bn'], 'portal.landing' => ['/dashboard', '/purchases', '/ledger', '/payouts']] as $path => $values) {
            $rules['document.'.$path] = ['required', Rule::in($values)];
        }
        $rules['document.brand.name'] = 'required|string|max:100';
        $rules['document.contact.email'] = 'nullable|email|max:150';
        $rules['document.contact.whatsapp'] = ['nullable', 'regex:/^\+?[0-9]{8,15}$/'];
        foreach (['announcement.starts_at', 'announcement.ends_at'] as $path) {
            $rules['document.'.$path] = 'nullable|date';
        }
        $rules['document.sections'] = 'present|array|max:30';
        $rules['document.pages'] = 'present|array|max:30';
        $rules['document.pages.*'] = 'array:slug,title,description,visible,index,sections';
        $rules['document.pages.*.slug'] = ['required', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:80'];
        $rules['document.pages.*.title'] = 'required|string|max:160';
        $rules['document.pages.*.description'] = 'nullable|string|max:300';
        $rules['document.pages.*.visible'] = 'required|boolean';
        $rules['document.pages.*.index'] = 'required|boolean';
        $rules['document.pages.*.sections'] = 'present|array|max:30';
        foreach (['document.sections', 'document.pages.*.sections'] as $prefix) {
            $rules[$prefix.'.*'] = 'array:id,type,title,body,visible,layout,background,align,image,image_alt,focal,button_label,button_href,items';
            $rules[$prefix.'.*.id'] = ['required', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:80'];
            $rules[$prefix.'.*.type'] = 'required|in:text,accordion,statistics,workspaces,contact,benefits,steps,products,testimonials,logos,gallery,downloads,cta,territories';
            foreach (['title', 'body', 'image', 'image_alt', 'button_label', 'button_href'] as $field) {
                $rules[$prefix.'.*.'.$field] = 'present|nullable|string|max:4000';
            }
            $rules[$prefix.'.*.visible'] = 'required|boolean';
            $rules[$prefix.'.*.layout'] = 'required|in:grid,stack,split';
            $rules[$prefix.'.*.background'] = 'required|in:paper,surface,primary,accent';
            $rules[$prefix.'.*.align'] = 'required|in:left,center';
            $rules[$prefix.'.*.focal'] = 'required|in:center,top,bottom,left,right';
            $rules[$prefix.'.*.items'] = 'present|array|max:30';
            $rules[$prefix.'.*.items.*'] = 'array:title,body,image,href,alt';
            foreach (['title', 'body', 'image', 'href', 'alt'] as $field) {
                $rules[$prefix.'.*.items.*.'.$field] = 'nullable|string|max:4000';
            }
        }
        foreach (['document.navigation', 'document.footer.links', 'document.contact.socials'] as $prefix) {
            $rules[$prefix] = 'present|array|max:20';
            $rules[$prefix.'.*'] = 'array:label,href,group';
            $rules[$prefix.'.*.label'] = 'required|string|max:100';
            $rules[$prefix.'.*.href'] = 'required|string|max:500';
            $rules[$prefix.'.*.group'] = 'nullable|string|max:80';
        }
        $rules['document.application.field_order.*'] = ['required', 'distinct', Rule::in(array_keys($defaults['application']['labels']))];
        $rules['document.portal.dashboard_order.*'] = ['required', 'distinct', Rule::in($defaults['portal']['dashboard_order'])];
        foreach (['labels', 'hints', 'placeholders'] as $group) {
            $rules['document.application.'.$group] = ['present', 'array:'.implode(',', array_keys($defaults['application']['labels']))];
            $rules['document.application.'.$group.'.*'] = 'nullable|string|max:250';
        }
        $rules['document.application.questions.*'] = 'array:id,label,type,required,options,when_field,when_value';
        $rules['document.application.questions.*.id'] = ['required', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,39}$/'];
        $rules['document.application.questions.*.label'] = 'required|string|max:250';
        $rules['document.application.questions.*.type'] = 'required|in:text,textarea,select';
        $rules['document.application.questions.*.required'] = 'required|boolean';
        $rules['document.application.questions.*.options'] = 'present|array|max:20';
        $rules['document.application.questions.*.options.*'] = 'required|string|max:100';
        $rules['document.application.questions.*.when_field'] = 'nullable|in:level,division';
        $rules['document.application.questions.*.when_value'] = 'nullable|string|max:100';
        $rules['document.redirects'] = 'present|array|max:40';
        $rules['document.redirects.*'] = 'array:from,to';
        $rules['document.redirects.*.from'] = ['required', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:80'];
        $rules['document.redirects.*.to'] = 'required|string|max:500';
        $rules['document.translations'] = 'present|array|max:1000';
        $rules['document.translations.*'] = 'nullable|string|max:4000';

        return $rules;
    }

    /** @return array<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $document = $this->input('document');
            $walk = function (array $values, string $prefix) use (&$walk, $validator): void {
                foreach ($values as $key => $value) {
                    $path = $prefix.'.'.$key;
                    if (is_array($value)) {
                        $walk($value, $path);
                    } elseif (is_string($value) && $value !== '') {
                        if ((str_ends_with((string) $key, 'href') || $key === 'to') && ! preg_match('~^(?:/(?!/)[^\\\\\s]*|https://[^\s]+|mailto:[^\s]+|tel:\+?[0-9 -]+)$~D', $value)) {
                            $validator->errors()->add($path, 'Use a local path, HTTPS, email, or telephone link.');
                        }
                        if (in_array($key, ['image', 'mobile_image', 'logo', 'dark_logo', 'favicon'], true) && ! preg_match('~^/website/media/[a-f0-9-]{36}$~D', $value)) {
                            $validator->errors()->add($path, 'Choose an image from the media library.');
                        }
                    }
                }
            };
            $walk($document, 'document');
            if (count($document['application']['field_order']) !== count(config('website.application.labels'))) {
                $validator->errors()->add('document.application.field_order', 'Every standard application field must be retained.');
            }
            if (count($document['portal']['dashboard_order']) !== count(config('website.portal.dashboard_order'))) {
                $validator->errors()->add('document.portal.dashboard_order', 'Dashboard sections may be reordered but must remain available.');
            }
            foreach ($document['translations'] as $path => $translation) {
                if (! preg_match('/^(?:brand\.tagline|hero\.(?:eyebrow|title|highlight|body|button_label|secondary_label)|(?:sections\.\d+|pages\.\d+\.sections\.\d+)\.(?:title|body|button_label|image_alt|items\.\d+\.(?:title|body|alt))|pages\.\d+\.(?:title|description)|(?:navigation|contact\.socials|footer\.links)\.\d+\.label|contact\.(?:address|hours)|footer\.text|application\.(?:title|intro|button_label|closed_message|success_message|consent|(?:labels|hints|placeholders)\.[a-z_]+|questions\.\d+\.label)|login\.(?:title|body|help_text)|seo\.(?:title|description)|availability\.(?:title|message)|announcement\.text|portal\.announcement)$/D', $path)) {
                    $validator->errors()->add('document.translations', 'Translations may change display text only.');
                }
            }
            foreach ($document['application']['questions'] as $index => $question) {
                if ($question['type'] === 'select' && empty($question['options'])) {
                    $validator->errors()->add('document.application.questions.'.$index.'.options', 'Select questions need at least one option.');
                }
            }
        }];
    }
}
