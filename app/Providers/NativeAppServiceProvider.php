<?php

namespace App\Providers;

use App\Models\User;
// use Native\Laravel\Facades\GlobalShortcut;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\Window;
use Native\Laravel\Facades\Screen;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Get responsive window dimensions (75% of screen size)
        $dimensions = $this->getResponsiveWindowDimensions();

        // Create an overlay window for sales assistant
        Window::open()
            ->route('realtime-agent')
            ->width($dimensions['width'])
            ->height($dimensions['height'])
            ->minWidth($dimensions['minWidth'])
            ->minHeight($dimensions['minHeight'])
            ->titleBarStyle('hidden')
            ->transparent()
            ->backgroundColor('#00000000')
            ->resizable()
            ->position(100)
            ->webPreferences([
                'nodeIntegration' => true,
                'contextIsolation' => false,
                'webSecurity' => false,
                'backgroundThrottling' => false,
                'sandbox' => false,
            ])
            ->rememberState()
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

    /**
     * Get responsive window dimensions based on screen size.
     * Returns 75% of screen dimensions with sensible minimums.
     */
    protected function getResponsiveWindowDimensions(): array
    {
        try {
            // Get primary display dimensions
            $displays = null;
            try {
                $displays = Screen::displays();
            } catch (\Throwable $e) {
                // Screen facade not available in non-native environment
                throw new \Exception('Screen information not available');
            }

            // Handle case where displays() returns null (non-native environment)
            if ($displays === null || empty($displays)) {
                throw new \Exception('Screen information not available');
            }

            $primaryDisplay = $displays[0] ?? null;

            if (!$primaryDisplay) {
                // Fallback to reasonable defaults if screen info unavailable
                return [
                    'width' => 1280,
                    'height' => 720,
                    'minWidth' => 400,
                    'minHeight' => 500,
                ];
            }

            // Get screen dimensions from workArea (excludes taskbars/docks)
            $screenWidth = $primaryDisplay['workArea']['width'];
            $screenHeight = $primaryDisplay['workArea']['height'];

            // Calculate 75% of screen dimensions
            $width = (int) ($screenWidth * 0.75);
            $height = (int) ($screenHeight * 0.75);

            // Use fixed minimum dimensions (original values)
            $minWidth = 400;
            $minHeight = 500;

            // Ensure we don't exceed reasonable maximums (90% of screen)
            $maxWidth = (int) ($screenWidth * 0.9);
            $maxHeight = (int) ($screenHeight * 0.9);

            return [
                'width' => min($width, $maxWidth),
                'height' => min($height, $maxHeight),
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
            ];

        } catch (\Exception $e) {
            // Fallback to reasonable defaults if anything fails
            return [
                'width' => 1280,
                'height' => 720,
                'minWidth' => 400,
                'minHeight' => 500,
            ];
        }
    }
}
