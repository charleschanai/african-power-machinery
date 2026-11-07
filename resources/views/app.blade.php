<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#020617">
        <meta name="description" content="E-Card System for Entry Management">
        <title>{{ config('app.name', 'E-Card System') }}</title>

        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32" rx="8" fill='%23059669'/%3E%3Cpath d='M8 11a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V11z' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/%3E%3Cpath d='M12 21V14h8v7M12 17h8' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">

        @php
            $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            $cssFile = $manifest['resources/css/app.css']['file'] ?? '';
            $jsFile = $manifest['resources/js/app.js']['file'] ?? '';
        @endphp
        <style>{!! file_get_contents(public_path("build/{$cssFile}")) !!}</style>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
    </head>
    <body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
        <div id="app">
            <div style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;flex-direction:column;gap:12px">
                <div style="width:32px;height:32px;border:3px solid #059669;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite"></div>
                <p style="color:#64748b;font-size:14px">Inapakia...</p>
            </div>
        </div>
        @if($jsFile)
        <script type="module" src="/build/{{ $jsFile }}"></script>
        @endif
    </body>
</html>
