<?php

namespace App\Filament\Pages;

use App\Enums\NodeType;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && ! static::isOrganizationUser();
    }

    public function mount(): void
    {
        if (static::isOrganizationUser()) {
            $this->redirect(BaseReports::getUrl());
        }
    }

    protected static function isOrganizationUser(): bool
    {
        $user = Filament::auth()->user()?->fresh();

        return $user !== null && ! $user->isAdmin() && $user->node?->type === NodeType::Organization;
    }
}
