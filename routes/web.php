<?php

use App\Http\Controllers\ReportFileController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Reports\ComplexReportDefinition;
use App\Reports\ReportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

foreach (app(ReportRegistry::class)->all() as $definition) {
    if ($definition instanceof ComplexReportDefinition) {
        $definition->routes();
    }
}

Route::get('/cabinet/reports/{report}/files/{field}/{row?}/{index?}', ReportFileController::class)
    ->whereNumber('row')->whereNumber('index')
    ->middleware(['auth', EnsureOnboardingComplete::class])
    ->name('reports.files.download');

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('email.verify');

Route::get('/', function (): RedirectResponse {
    return to_route(auth()->check() ? 'filament.app.pages.dashboard' : 'filament.app.auth.login');
});
