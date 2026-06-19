<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Contact Enrichment') - Respaid</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full">
    <div class="min-h-full">
        <nav class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-14 items-center justify-between">
                    <div class="flex items-center gap-8">
                        <span class="text-lg font-semibold text-gray-900 tracking-tight">Respaid</span>
                        <div class="flex gap-1">
                            <a href="{{ route('enrichment.index') }}"
                               class="px-3 py-2 text-sm font-medium rounded-md transition-colors {{ request()->routeIs('enrichment.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}">
                                Enrichment
                            </a>
                            <a href="{{ route('training.index') }}"
                               class="px-3 py-2 text-sm font-medium rounded-md transition-colors {{ request()->routeIs('training.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}">
                                Training
                            </a>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Mock Providers
                        </span>
                    </div>
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
            @yield('content')
        </main>
    </div>
</body>
</html>
