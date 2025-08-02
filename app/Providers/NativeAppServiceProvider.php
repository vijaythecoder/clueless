<?php

namespace App\Providers;

use App\Models\User;
// use Native\Laravel\Facades\GlobalShortcut;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Create an overlay window for sales assistant
        Window::open()
            ->route('realtime-agent-v2')
            ->width(1200)
            ->height(700)
            ->minWidth(400)
            ->minHeight(500)
            ->titleBarStyle('hidden')
            ->transparent()
            ->backgroundColor('#00000000')
            ->resizable()
            ->position(50, 50)
            ->webPreferences([
                'contextIsolation' => true,
                'webSecurity' => false,
                'backgroundThrottling' => false,
                'sandbox' => false,
            ])
            // Set window to floating panel level for better screen protection
            ->alwaysOnTop(false);

        // Register global shortcut to toggle the window
        // GlobalShortcut::register('CmdOrCtrl+Shift+J', function () {
        //     Window::get()->toggle();
        // });

        // Check if this is first run and seed database if needed
        // Run this after window is created to avoid blocking startup
        $this->seedDatabaseIfNeeded();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'upload_max_filesize' => '50M',
            'post_max_size' => '50M',
        ];
    }

    /**
     * Seed the database if it's empty (first run).
     */
    protected function seedDatabaseIfNeeded(): void
    {
        try {
            // Check if users table exists and has data
            if (Schema::hasTable('users') && User::count() === 0) {
                // Run database seeder for initial data
                Artisan::call('db:seed', ['--force' => true]);
            }
        } catch (\Exception $e) {
            // Silently catch any errors to not break app startup
            // The app will still work without seed data
        }
    }
}
