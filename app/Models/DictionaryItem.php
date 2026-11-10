<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DictionaryItem extends Model
{
    use HasFactory;

    protected $fillable = ['dictionary_id', 'label', 'active', 'sort'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class);
    }
}
