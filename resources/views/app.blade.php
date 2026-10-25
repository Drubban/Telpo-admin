<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Telpo Admin</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
    <noscript>Esta aplicación necesita JavaScript.</noscript>
</body>
</html>
