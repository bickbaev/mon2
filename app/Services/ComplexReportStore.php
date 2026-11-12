<?php

namespace App\Services;

use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Node;
use App\Models\Report;
use App\Models\User;
use App\Reports\Demo\DemoComplex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ComplexReportStore
{
    public function __construct(private ReportStatusService $statuses) {}

    /** @param array<string, mixed> $data */
    public function save(Report $report, array $data, User $actor): void
    {
        DB::transaction(function () use ($report, $data, $actor): void {
            $campaignId = Report::query()->whereKey($report->id)->value('campaign_id');
            $campaign = $campaignId === null ? null : Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            $current = Report::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($current->campaign_id === $campaignId, 409);
            $current->setRelation('campaign', $campaign);
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $nodes = Node::query()->whereIn('id', array_filter([$user->node_id, $current->node_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user->setRelation('node', $nodes->get($user->node_id));
            $current->setRelation('node', $nodes->get($current->node_id));
            Gate::forUser($user)->authorize('update', $current);
            $type = $current->reportType()->lockForUpdate()->firstOrFail();
            abort_unless($type->key === (new DemoComplex)->key() && $type->kind === ReportKind::Complex, 404);

            $validated = Validator::make($data, [
                'contact' => ['required', 'array:full_name,position,phone,email'],
                'contact.full_name' => ['required', 'string', 'max:255'],
                'contact.position' => ['required', 'string', 'max:255'],
                'contact.phone' => ['required', 'string', 'max:50'],
                'contact.email' => ['required', 'string', 'email', 'max:255'],
                'events' => ['present', 'array', 'list', 'max:1000'],
                'events.*' => ['required', 'array:title,date,participants'],
                'events.*.title' => ['required', 'string', 'max:255'],
                'events.*.date' => ['required', 'date_format:Y-m-d'],
                'events.*.participants' => ['required', 'integer', 'min:0', 'max:4294967295'],
            ], [
                'required' => 'Поле «:attribute» обязательно.',
                'present' => 'Передайте поле «:attribute».',
                'array' => 'Поле «:attribute» содержит недопустимые данные.',
                'list' => 'Поле «:attribute» должно содержать список.',
                'string' => 'Поле «:attribute» должно содержать текст.',
                'email' => 'Укажите корректный адрес электронной почты.',
                'date_format' => 'Укажите существующую дату в формате ГГГГ-ММ-ДД.',
                'integer' => 'Поле «:attribute» должно содержать целое число.',
                'min.numeric' => 'Поле «:attribute» должно быть не меньше :min.',
                'max.numeric' => 'Поле «:attribute» должно быть не больше :max.',
                'max.string' => 'Поле «:attribute» должно содержать не больше :max символов.',
                'max.array' => 'Поле «:attribute» должно содержать не больше :max записей.',
            ], [
                'contact' => 'Контактное лицо',
                'contact.full_name' => 'ФИО',
                'contact.position' => 'Должность',
                'contact.phone' => 'Телефон',
                'contact.email' => 'Электронная почта',
                'events' => 'Мероприятия',
                'events.*' => 'Мероприятие',
                'events.*.title' => 'Название мероприятия',
                'events.*.date' => 'Дата мероприятия',
                'events.*.participants' => 'Число участников',
            ])->validate();

            DB::table('complex_demo_contacts')->updateOrInsert(['report_id' => $current->id], $validated['contact']);
            DB::table('complex_demo_events')->where('report_id', $current->id)->delete();
            foreach ($validated['events'] as $event) {
                DB::table('complex_demo_events')->insert(['report_id' => $current->id, ...$event]);
            }
            if (in_array($current->status, [ReportStatus::New, ReportStatus::Rework], true)) {
                $this->statuses->transition($current, ReportStatus::InProgress, $user);
            }
        }, 3);
        $report->refresh();
    }

    /**
     * @return array{contact: array{full_name: string, position: string, phone: string, email: string}, events: list<array{title: string, date: string, participants: int}>}
     */
    public function load(Report $report): array
    {
        $contact = DB::table('complex_demo_contacts')->where('report_id', $report->id)->first(['full_name', 'position', 'phone', 'email']);
        $events = DB::table('complex_demo_events')->where('report_id', $report->id)->orderBy('id')
            ->get(['title', 'date', 'participants'])->map(fn (object $event): array => (array) $event)->all();

        return [
            'contact' => $contact === null ? ['full_name' => '', 'position' => '', 'phone' => '', 'email' => ''] : (array) $contact,
            'events' => $events,
        ];
    }
}
