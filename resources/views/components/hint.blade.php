{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Copyright (C) 2026 SoundChex --}}

{{--
    An explanation that stays out of the way until asked for (S-331).

    The page used to carry its guidance as prose under every heading, which
    made a short form look like a document. The words are the same; they now
    live behind a question mark that shows them on hover and on focus, so the
    keyboard reaches them too.
--}}
@props(['text'])

<span
    class="group relative inline-flex align-middle"
    tabindex="0"
    role="note"
    aria-label="{{ $text }}"
>
    <x-filament::icon
        icon="heroicon-m-question-mark-circle"
        class="h-4 w-4 text-gray-400 transition hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
    />

    <span
        class="pointer-events-none absolute left-1/2 top-full z-20 mt-2 w-64 -translate-x-1/2 rounded-lg bg-gray-900 px-3 py-2 text-xs font-normal leading-relaxed text-white opacity-0 shadow-lg transition group-hover:opacity-100 group-focus:opacity-100 dark:bg-gray-700"
    >
        {{ $text }}
    </span>
</span>
