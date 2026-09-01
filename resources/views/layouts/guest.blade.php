<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('auth.app_name')) — {{ __('auth.app_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-bg text-ink antialiased">
    <div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-accent to-gold flex items-center justify-center text-white font-display font-bold">
                CM
            </div>
            <div>
                <div class="font-display font-bold text-lg leading-none">{{ __('auth.app_name') }}</div>
                <div class="text-xs text-ink-muted">{{ __('auth.app_tagline') }}</div>
            </div>
        </div>

        <div class="w-full max-w-md bg-surface border border-border rounded-xl shadow-sm p-6">
            @yield('content')
        </div>
    </div>

    @livewireScripts
</body>
</html>
