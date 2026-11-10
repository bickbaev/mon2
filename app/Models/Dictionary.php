<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dictionary extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name'];

    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class);
    }

    /** @return Collection<int, DictionaryItem> */
    public function activeItems(): Collection
    {
        return $this->items()->where('active', true)->orderBy('sort')->orderBy('id')->get();
    }
}
