<?php

namespace App\Filament\Resources\Nodes\Pages;

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\NodeResource;
use App\Models\Node;
use App\Services\NodeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateNode extends CreateRecord
{
    protected static string $resource = NodeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $parent = filled($data['parent_id'] ?? null) ? NodeResource::getEloquentQuery()->findOrFail($data['parent_id']) : null;
        Gate::forUser(auth()->user()->fresh())->authorize('create', [Node::class, $parent]);
        try {
            return app(NodeService::class)->create($parent, NodeType::from($data['type']), $data['name']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data.type' => $exception->getMessage()]);
        }
    }
}
