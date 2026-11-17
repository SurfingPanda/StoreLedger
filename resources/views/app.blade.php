<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="description" content="Your workspace for rental payments, electricity, water, and UTAK POS renewals.">
        <meta name="theme-color" content="#1030bc">
        <title>BW Superbakeshop — {{ ['automation-health' => 'Automation health', 'directory' => 'Stores & suppliers', 'login' => 'Sign in', 'forgot-password' => 'Reset your password', 'reset-password' => 'New password', 'dashboard' => 'Your workspace', 'utak-pos' => 'UTAK POS renewals', 'pos-voucher' => 'UTAK POS voucher details', 'users' => 'Users & access', 'settings' => 'Notification settings', 'water-voucher' => 'Water SOA request details', 'electricity-voucher' => 'Electricity SOA request details', 'rental-voucher' => 'Rental SOA request details', 'internet-voucher' => 'Internet SOA request details', 'internet-soa' => 'Internet SOA Monitoring', 'electricity' => 'Electricity SOA Monitoring', 'rental' => 'Rental SOA Monitoring', 'water' => 'Water SOA Monitoring'][$appData['page']] }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
    </head>
    <body>
        <div id="app"></div>
        <script id="app-data" type="application/json">{!! json_encode($appData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <noscript><p style="padding:2rem;font-family:system-ui">Please enable JavaScript to use BW Operations.</p></noscript>
    </body>
</html>
