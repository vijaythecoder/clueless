<?php

use App\Providers\NativeAppServiceProvider;

test('native BrowserWindow uses hardened web preferences', function () {
    $preferences = (new NativeAppServiceProvider)->browserWindowWebPreferences();

    expect($preferences)->toMatchArray([
        'contextIsolation' => true,
        'webSecurity' => true,
        'sandbox' => false,
        'nodeIntegration' => false,
    ]);
});
