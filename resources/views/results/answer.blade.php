@if (filled($html))
    {{-- Filament's prose styles plus spacing rules scoped to this block, so nothing needs compiling. --}}
    <style>
        .ai-visibility-answer { line-height: 1.7; overflow-wrap: anywhere; max-width: 72ch; }
        .ai-visibility-answer > :first-child { margin-top: 0; }
        .ai-visibility-answer > :last-child { margin-bottom: 0; }
        .ai-visibility-answer p { margin: 0 0 0.9em; }
        .ai-visibility-answer h1, .ai-visibility-answer h2, .ai-visibility-answer h3,
        .ai-visibility-answer h4, .ai-visibility-answer h5, .ai-visibility-answer h6 { font-weight: 600; line-height: 1.35; margin: 1.4em 0 0.5em; }
        .ai-visibility-answer h1 { font-size: 1.35em; }
        .ai-visibility-answer h2 { font-size: 1.2em; }
        .ai-visibility-answer h3 { font-size: 1.08em; }
        .ai-visibility-answer h4, .ai-visibility-answer h5, .ai-visibility-answer h6 { font-size: 1em; }
        .ai-visibility-answer ul, .ai-visibility-answer ol { margin: 0 0 0.9em; padding-inline-start: 1.5em; }
        .ai-visibility-answer ul { list-style: disc; }
        .ai-visibility-answer ol { list-style: decimal; }
        .ai-visibility-answer ul ul { list-style: circle; }
        .ai-visibility-answer li { margin: 0.25em 0; }
        .ai-visibility-answer li > p { margin: 0; }
        .ai-visibility-answer li > ul, .ai-visibility-answer li > ol { margin: 0.25em 0; }
        .ai-visibility-answer strong, .ai-visibility-answer b { font-weight: 600; }
        .ai-visibility-answer em { font-style: italic; }
        .ai-visibility-answer a { text-decoration: underline; text-underline-offset: 0.15em; }
        .ai-visibility-answer blockquote { margin: 0 0 0.9em; padding-inline-start: 1em; border-inline-start: 3px solid rgba(127, 127, 127, 0.35); }
        .ai-visibility-answer code { font-size: 0.9em; padding: 0.1em 0.3em; border-radius: 0.25rem; background: rgba(127, 127, 127, 0.15); }
        .ai-visibility-answer pre { margin: 0 0 0.9em; padding: 0.75em 1em; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); overflow-x: auto; }
        .ai-visibility-answer pre code { padding: 0; background: none; }
        .ai-visibility-answer table { margin: 0 0 0.9em; border-collapse: collapse; font-size: 0.95em; display: block; overflow-x: auto; }
        .ai-visibility-answer th, .ai-visibility-answer td { padding: 0.4em 0.6em; border: 1px solid rgba(127, 127, 127, 0.25); text-align: start; vertical-align: top; }
        .ai-visibility-answer hr { margin: 1.25em 0; border: 0; border-top: 1px solid rgba(127, 127, 127, 0.25); }
        .ai-visibility-answer mark { color: inherit; }
        /* Competitor highlights are not bold, so they are underlined to tell them apart without colour. */
        .ai-visibility-answer mark[data-subject="competitor"],
        .ai-visibility-answer mark:not([data-subject]):not([style*="font-weight"]) { text-decoration: underline dotted; text-underline-offset: 0.2em; }
        .ai-visibility-answer mark[data-subject="brand"] { font-weight: 600; }
    </style>

    <div class="fi-prose ai-visibility-answer">
        {!! $html !!}
    </div>
@else
    <div style="opacity: 0.75;">{{ $empty }}</div>
@endif
