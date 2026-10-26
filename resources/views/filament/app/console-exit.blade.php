{{--
    The CRM's `← Console` exit (fork addition — see NOTICE; card t_619a7e0f).

    Rendered into USER_MENU_BEFORE, so it rides in the topbar's trailing cluster
    on every authenticated page of the app panel — the same persistent position
    the two module SPAs put theirs in, and not a menu entry somebody has to open
    to find.

    A plain <a>: a document navigation to the console, not an in-app route. The
    panel is in SPA mode, but `wire:navigate` is an attribute ON an element, so
    leaving it off is what keeps this a real navigation to a different app.

    `href` and `title` are built by App\Support\Platform\ConsoleExit — the ONE
    place in this codebase that constructs `/console/?realm=<slug>&returnTo=<…>`.
    Nothing is assembled here.
--}}
<a
    href="{{ $href }}"
    title="{{ $title }}"
    aria-label="{{ $title }}"
    data-platform-console-exit
    class="fi-console-exit inline-flex items-center gap-x-1 rounded-lg px-2.5 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 focus-visible:bg-gray-50 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
>
    <span aria-hidden="true">←</span>
    <span>{{ \App\Support\Platform\ConsoleExit::LABEL }}</span>
</a>
