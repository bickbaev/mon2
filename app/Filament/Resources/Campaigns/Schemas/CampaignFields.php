<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\ReportStatus;
use App\Models\Node;
use App\Models\ReportType;
use App\Models\User;
use App\Services\NodeSelection;
use App\Services\NodeSelector;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CampaignFields
{
    public static function withParticipantErrors(Closure $add, Schema $schema): void
    {
        try {
            $add();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                $schema->getStatePath().'.node_id' => array_merge(...array_values($exception->errors())),
            ]);
        }
    }

    public static function withFormErrors(Closure $save): Model
    {
        try {
            return $save();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    public static function actor(): User
    {
        return User::query()->findOrFail(Filament::auth()->id());
    }

    public static function settings(): array
    {
        return [
            TextInput::make('title')->label('Название сбора')->maxLength(255),
            Select::make('report_type_id')->label('Вид отчёта')->required()->searchable()
                ->options(fn (): array => ReportType::query()->where('active', true)->where('is_base', false)->orderBy('title')->pluck('title', 'id')->all()),
            Select::make('start_status')->label('Стартовый статус')->required()->default(ReportStatus::New->value)
                ->options(collect(ReportStatus::cases())->mapWithKeys(fn (ReportStatus $status): array => [$status->value => $status->label()])->all()),
            TextInput::make('remind_days_before')->label('Напомнить за дней до срока сдачи')->numeric()->integer()->minValue(0)->maxValue(65535),
        ];
    }

    public static function selection(): array
    {
        $fields = [];
        foreach ([
            'nodeIds' => 'Узлы по одному',
            'allInBranch' => 'Все узлы выбранных веток',
            'organizationsInBranch' => 'Только организации выбранных веток, без филиалов',
            'branchesInBranch' => 'Только филиалы выбранных веток',
        ] as $name => $label) {
            $fields[] = Select::make($name)->label($label)->multiple()->searchable()
                ->options(fn (): array => self::nodeOptions(withSelf: $name !== 'nodeIds'))
                ->visibleOn('create');
        }

        return $fields;
    }

    public static function nodeOptions(?User $owner = null, bool $withSelf = false): array
    {
        $selector = app(NodeSelector::class);
        $query = $selector->available(self::actor(), $withSelf);
        if ($owner !== null) {
            $query->whereIn('id', $selector->available($owner)->select('id'));
        }

        return $query->orderBy('path')->get()->mapWithKeys(fn (Node $node): array => [$node->id => str_repeat('— ', $node->depth).$node->name.' (№'.$node->id.')'])->all();
    }

    public static function selectionFrom(array $data): NodeSelection
    {
        return new NodeSelection($data['nodeIds'] ?? [], $data['allInBranch'] ?? [], $data['organizationsInBranch'] ?? [], $data['branchesInBranch'] ?? []);
    }

    public static function managedQuery(Builder $query): Builder
    {
        $actor = self::actor();
        if ($actor->isBlocked()) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $records) use ($actor): void {
            $records->where('creator_id', $actor->id);
            if ($actor->node !== null) {
                $records->orWhereHas('creatorNode', fn (Builder $nodes): Builder => $nodes->inBranch($actor->node, false));
            }
        });
    }
}
