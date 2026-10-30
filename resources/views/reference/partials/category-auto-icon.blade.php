@php
    $auto = \App\Support\CategoryAutoIcon::forCategory($name ?? '');
    $hue = (int) $auto['hue'];
    $key = $auto['key'];
@endphp
<span class="ref-cat-auto-icon" style="--cat-hue: {{ $hue }};" data-cat-icon="{{ $key }}" aria-hidden="true">
    @switch($key)
        @case('leaf')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 3c.4 2.8-.4 5.1-2.2 6.9S5.8 12.6 3 13c.4-2.8 1.2-5.1 3-6.9S9.2 3.4 12 3Zm0 0c-.4 2.8.4 5.1 2.2 6.9S18.2 12.6 21 13c-.4-2.8-1.2-5.1-3-6.9S14.8 3.4 12 3Zm-1 9.5c.4 2.4.1 4.4-1 5.9-1.2 1.6-3.1 2.5-5.6 2.8.9-2.2 2-4 3.5-5.2 1.4-1.2 3-1.9 5-2.2 0 0-.6-1-1.9-1.3Zm2 0c-.4 2.4-.1 4.4 1 5.9 1.2 1.6 3.1 2.5 5.6 2.8-.9-2.2-2-4-3.5-5.2-1.4-1.2-3-1.9-5-2.2 0 0 .6-1 1.9-1.3Z"/></svg>
            @break
        @case('bottle')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M9 2h6v2h-1v2.1c1.7.5 3 2.1 3 4v9.4A2.5 2.5 0 0 1 14.5 22h-5A2.5 2.5 0 0 1 7 19.5V10.1c0-1.9 1.3-3.5 3-4V4H9V2Zm3 4.2c-1.5 0-2.7 1.1-2.7 2.6v.4h5.4v-.4c0-1.5-1.2-2.6-2.7-2.6Z"/></svg>
            @break
        @case('droplet')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 2.2c.4.6 6.8 9.1 6.8 13.1A6.8 6.8 0 0 1 12 22a6.8 6.8 0 0 1-6.8-6.7C5.2 11.3 11.6 2.8 12 2.2Zm0 8.3c-1.9 0-3.4 1.6-3.4 3.5S10.1 17.5 12 17.5s3.4-1.6 3.4-3.5S13.9 10.5 12 10.5Z"/></svg>
            @break
        @case('bowl')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M4 11h16a8 8 0 0 1-16 0Zm1.2-2h13.6l-.7-2.1A2 2 0 0 0 16.2 5.5H7.8a2 2 0 0 0-1.9 1.4L5.2 9Z"/></svg>
            @break
        @case('sparkle')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 2.5 13.7 8l5.3 1.7-5.3 1.7L12 17l-1.7-5.6L5 9.7 10.3 8 12 2.5Zm6.5 10.2 1 3.1 3.1 1-3.1 1-1 3.1-1-3.1-3.1-1 3.1-1 1-3.1Z"/></svg>
            @break
        @case('capsule')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M8.2 4.2a4.2 4.2 0 0 1 5.9 0l5.7 5.7a4.2 4.2 0 1 1-5.9 5.9l-5.7-5.7a4.2 4.2 0 0 1 0-5.9Zm1.4 1.4a2.2 2.2 0 0 0 0 3.1l1.8 1.8 3.1-3.1-1.8-1.8a2.2 2.2 0 0 0-3.1 0Z"/></svg>
            @break
        @case('seedling')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 21v-7.2C8.8 13.4 6.5 11 6 7.5 9.8 7.8 12.4 10.2 13 13.5c.8-2.8 2.9-4.8 6-5.2-.4 3.8-2.9 6.5-7 7.1V21h-0Z"/></svg>
            @break
        @case('water')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M6 14.5c0-3.4 3.4-7.7 6-10.7 2.6 3 6 7.3 6 10.7A6 6 0 0 1 6 14.5Zm3.2 1.2c.5 1.6 1.9 2.7 3.6 2.7.4 0 .8 0 1.1-.1A4.1 4.1 0 0 1 9.2 15.7Z"/></svg>
            @break
        @case('home')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 3.2 4 10v10h5.5v-5.5h5V20H20V10l-8-6.8Z"/></svg>
            @break
        @case('handshake')
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M10.2 12.8 8 10.6l1.4-1.4 2.4 2.4 4.5-4.5L17.7 8l-5.9 5.9-1.6-1.1ZM4.5 9.2 8 5.7l2.1 2.1L7 11.1 4.5 9.2Zm15 0L17 11.1l-3.1-3.3L16 5.7l3.5 3.5Z"/></svg>
            @break
        @default
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M11 5h2v6.2l3.4 3.4-1.4 1.4L11 12V5Zm1-3a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Z"/></svg>
    @endswitch
</span>
