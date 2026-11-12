<?php

namespace App\Http\Controllers;

use App\Enums\ReportKind;
use App\Models\Report;
use App\Reports\Demo\DemoComplex;
use App\Services\ComplexReportStore;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DemoComplexController extends Controller
{
    public function show(Request $request, Report $report, ComplexReportStore $store): View
    {
        $this->authorizeView($request, $report);
        Filament::setCurrentPanel('app');
        Filament::bootCurrentPanel();

        return view('reports.demo-complex', [
            'report' => $report,
            'data' => $store->load($report),
            'canEdit' => Gate::forUser($request->user()->fresh())->allows('update', $report),
        ]);
    }

    public function save(Request $request, Report $report, ComplexReportStore $store): RedirectResponse
    {
        $this->authorizeView($request, $report);
        $request->mergeIfMissing(['events' => []]);
        $store->save($report, $request->only(['contact', 'events']), $request->user());

        return redirect()->route('demo-complex.show', $report)->with('status', 'Отчёт сохранён.');
    }

    private function authorizeView(Request $request, Report $report): void
    {
        $type = $report->reportType;
        abort_unless($type->key === (new DemoComplex)->key() && $type->kind === ReportKind::Complex, 404);
        abort_unless(Gate::forUser($request->user()->fresh())->allows('view', $report), 404);
    }
}
