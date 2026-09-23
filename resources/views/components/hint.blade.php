{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Copyright (C) 2026 SoundChex --}}

{{--
    An explanation that stays out of the way until asked for (S-331, S-347).

    Styled with its own CSS rather than Tailwind utilities: the app's Tailwind
    build only scans its own views, so utility classes written in a plugin are
    never generated. `opacity-0` simply did not exist, which is why the tooltip
    text sat visible on the page with nothing hovering it.
--}}
@props(['text'])

@once
    <style>
        .scpp-hint { position: relative; display: inline-flex; vertical-align: middle; }
        .scpp-hint__icon {
            width: 1rem; height: 1rem; border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .7rem; font-weight: 700; line-height: 1;
            color: rgb(107 114 128); border: 1px solid currentColor;
            cursor: help; transition: color .15s ease;
        }
        .scpp-hint:hover .scpp-hint__icon,
        .scpp-hint:focus-visible .scpp-hint__icon { color: rgb(59 130 246); }

        .scpp-hint__bubble {
            position: absolute; left: 50%; top: calc(100% + .5rem);
            transform: translateX(-50%);
            z-index: 50; width: 16rem;
            border-radius: .5rem; padding: .5rem .75rem;
            background: rgb(17 24 39); color: #fff;
            font-size: .75rem; font-weight: 400; line-height: 1.5;
            text-align: left; white-space: normal;
            box-shadow: 0 10px 20px rgb(0 0 0 / .25);
            /* Hidden outright, not merely transparent: a see-through tooltip
               still takes part in layout and can be read by a screen reader
               twice. */
            visibility: hidden; opacity: 0;
            transition: opacity .15s ease, visibility .15s ease;
            pointer-events: none;
        }
        .scpp-hint:hover .scpp-hint__bubble,
        .scpp-hint:focus-visible .scpp-hint__bubble { visibility: visible; opacity: 1; }
    </style>
@endonce

<span class="scpp-hint" tabindex="0" role="note" aria-label="{{ $text }}">
    <span class="scpp-hint__icon" aria-hidden="true">?</span>
    <span class="scpp-hint__bubble">{{ $text }}</span>
</span>
