<?php

namespace App\Reports\Demo;

use App\Http\Controllers\DemoComplexController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Reports\ComplexReportDefinition;
use Illuminate\Support\Facades\Route;

class DemoComplex extends ComplexReportDefinition
{
    public function key(): string
    {
        return 'demo_complex';
    }

    public function title(): string
    {
        return 'Демонстрационный: мероприятия и контактное лицо';
    }

    public function table(): string
    {
        return 'complex_demo_contacts';
    }

    public function routes(): void
    {
        Route::prefix('cabinet/complex/'.$this->key())
            ->middleware(['auth', EnsureOnboardingComplete::class])
            ->group(function (): void {
                Route::get('{report}', [DemoComplexController::class, 'show'])->name('demo-complex.show');
                Route::post('{report}', [DemoComplexController::class, 'save'])->name('demo-complex.save');
            });
    }
}
