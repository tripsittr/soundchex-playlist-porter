{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Copyright (C) 2026 SoundChex --}}

{{--
    The page's own CSS (S-347).

    The app's Tailwind build scans only its own views, so utility classes
    written here are never compiled — which is why the right-aligned controls
    stayed left and the tooltips stayed visible. Anything this plugin needs to
    look a particular way has to bring its own rules.
--}}
@once
    <style>
        /* One unmatched track: name on the left, everything actionable hard
           right, so the eye reads description then choice. */
        .scpp-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 1.5rem; padding: .875rem 0;
        }
        .scpp-row + .scpp-row { border-top: 1px solid rgb(229 231 235); }
        :is(.dark) .scpp-row + .scpp-row { border-top-color: rgb(255 255 255 / .1); }

        .scpp-row__text { min-width: 0; flex: 1 1 auto; }
        .scpp-row__title {
            font-size: .875rem; font-weight: 500; margin: 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .scpp-row__meta {
            font-size: .75rem; color: rgb(107 114 128); margin: .125rem 0 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        :is(.dark) .scpp-row__meta { color: rgb(156 163 175); }

        .scpp-row__actions {
            display: flex; flex-direction: column; align-items: flex-end;
            gap: .5rem; flex: 0 0 auto; margin-left: auto;
        }
        .scpp-row__actions > * { margin-left: auto; }

        @media (max-width: 640px) {
            .scpp-row { flex-direction: column; gap: .75rem; }
            .scpp-row__actions { align-items: stretch; width: 100%; }
            .scpp-row__actions > * { margin-left: 0; }
        }

        /* The source cards at the top of the page. */
        .scpp-sources {
            display: grid; gap: .75rem;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        }
        .scpp-source {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; padding: .875rem 1rem;
            border: 1px solid rgb(229 231 235); border-radius: .75rem;
        }
        :is(.dark) .scpp-source { border-color: rgb(255 255 255 / .1); }
        .scpp-source__name { font-size: .875rem; font-weight: 600; }
        .scpp-source__soon { font-size: .75rem; color: rgb(107 114 128); }

        /* A two-column form row that collapses on a narrow screen. */
        .scpp-form-grid { display: grid; gap: 1rem; grid-template-columns: 2fr 1fr; }
        @media (max-width: 640px) { .scpp-form-grid { grid-template-columns: 1fr; } }

        .scpp-label {
            display: flex; align-items: center; gap: .375rem;
            font-size: .875rem; font-weight: 500; margin-bottom: .375rem;
        }
    </style>
@endonce
