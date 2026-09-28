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

{{-- Styling is in resources/css/plugin.css, compiled by the host (S-349). --}}
<span class="scpp-hint" tabindex="0" role="note" aria-label="{{ $text }}">
    <span class="scpp-hint__icon" aria-hidden="true">?</span>
    <span class="scpp-hint__bubble">{{ $text }}</span>
</span>
