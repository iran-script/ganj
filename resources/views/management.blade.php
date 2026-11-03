<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NEXO | مرکز مدیریت</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
    @endif
</head>
<body><div id="app"><div class="boot-state"><span class="loader"></span>در حال آماده‌سازی مرکز مدیریت…</div></div></body>
</html>
