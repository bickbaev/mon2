<?php

namespace App\Reports;

use App\Enums\FieldType;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class Field
{
    public bool $isRequired = false;

    public bool $isMultiline = false;

    public bool $isMultiple = false;

    public ?float $minimum = null;

    public ?float $maximum = null;

    public ?int $length = null;

    public ?string $afterDate = null;

    public ?string $beforeDate = null;

    public ?string $dictionaryKey = null;

    /** @var array<string|int, string>|null */
    public ?array $choices = null;

    /** @var list<string> */
    public array $mimes = [];

    public ?int $maxKb = null;

    /** @var list<mixed> */
    private array $customRules = [];

    public function __construct(public readonly string $name, public readonly string $label, public readonly FieldType $type) {}

    public static function number(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Number);
    }

    public static function text(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Text);
    }

    public static function radio(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Radio);
    }

    public static function multiselect(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Multiselect);
    }

    public static function select(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Select);
    }

    public static function checkbox(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Checkbox);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Date);
    }

    public static function time(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Time);
    }

    public static function datetime(string $name, string $label): self
    {
        return new self($name, $label, FieldType::Datetime);
    }

    public static function file(string $name, string $label): self
    {
        return new self($name, $label, FieldType::File);
    }

    public function required(): self
    {
        $this->isRequired = true;

        return $this;
    }

    public function min(float $value): self
    {
        $this->minimum = $value;

        return $this;
    }

    public function max(float $value): self
    {
        $this->maximum = $value;

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->length = $length;

        return $this;
    }

    public function multiline(): self
    {
        $this->isMultiline = true;

        return $this;
    }

    public function after(string $date): self
    {
        $this->afterDate = $date;

        return $this;
    }

    public function before(string $date): self
    {
        $this->beforeDate = $date;

        return $this;
    }

    /** @param array<string|int, string> $keyToLabel */
    public function options(array $keyToLabel): self
    {
        if ($this->dictionaryKey !== null) {
            throw new InvalidArgumentException('Нельзя одновременно задать справочник и варианты.');
        }
        $this->choices = $keyToLabel;

        return $this;
    }

    public function dictionary(string $dictionaryKey): self
    {
        if ($this->choices !== null) {
            throw new InvalidArgumentException('Нельзя одновременно задать справочник и варианты.');
        }
        $this->dictionaryKey = $dictionaryKey;

        return $this;
    }

    /** @param list<string> $mimes */
    public function files(array $mimes, int $maxKb, bool $multiple = false): self
    {
        $this->mimes = $mimes;
        $this->maxKb = $maxKb;
        $this->isMultiple = $multiple;

        return $this;
    }

    /** @param list<mixed>|Closure $custom */
    public function rules(array|Closure $custom): self
    {
        $this->customRules = [...$this->customRules, ...($custom instanceof Closure ? [$custom] : $custom)];

        return $this;
    }

    /** @return list<mixed> */
    public function toRules(): array
    {
        $rules = [$this->isRequired ? 'required' : 'nullable'];
        $rules = [...$rules, ...match ($this->type) {
            FieldType::Number => ['numeric'],
            FieldType::Text => ['string'],
            FieldType::Checkbox => ['boolean'],
            FieldType::Date => ['date_format:Y-m-d'],
            FieldType::Time => ['date_format:H:i'],
            FieldType::Datetime => ['date_format:Y-m-d H:i:s'],
            FieldType::Multiselect => ['array'],
            FieldType::Select, FieldType::Radio => $this->choiceRules(),
            FieldType::File => $this->isMultiple ? ['array'] : $this->fileRules(),
        }];
        if ($this->minimum !== null) {
            $rules[] = 'min:'.$this->minimum;
        }
        if ($this->maximum !== null) {
            $rules[] = 'max:'.$this->maximum;
        }
        if ($this->length !== null) {
            $rules[] = 'max:'.$this->length;
        }
        if ($this->afterDate !== null) {
            $rules[] = 'after:'.$this->afterDate;
        }
        if ($this->beforeDate !== null) {
            $rules[] = 'before:'.$this->beforeDate;
        }

        return [...$rules, ...$this->customRules];
    }

    /** @return array<string, list<mixed>> */
    public function toValidationRules(): array
    {
        $rules = [$this->name => $this->toRules()];
        if ($this->type === FieldType::Multiselect) {
            $rules[$this->name.'.*'] = ['required', 'distinct', ...$this->choiceRules()];
        } elseif ($this->type === FieldType::File && $this->isMultiple) {
            $rules[$this->name.'.*'] = ['required', ...$this->fileRules()];
        }

        return $rules;
    }

    /** @return list<mixed> */
    private function choiceRules(): array
    {
        if ($this->dictionaryKey !== null) {
            return ['integer', Rule::exists(DictionaryItem::class, 'id')->where(function (Builder $query): void {
                $query->where('active', true)->whereIn('dictionary_id', Dictionary::query()->select('id')->where('key', $this->dictionaryKey));
            })];
        }

        return [function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) && ! is_int($value)) {
                $fail('Недопустимый вариант выбора.');
            }
        }, Rule::in(array_keys($this->choices ?? []))];
    }

    /** @return list<string> */
    private function fileRules(): array
    {
        $rules = ['file'];
        if ($this->mimes !== []) {
            $rules[] = 'mimes:'.implode(',', $this->mimes);
        }
        if ($this->maxKb !== null) {
            $rules[] = 'max:'.$this->maxKb;
        }

        return $rules;
    }
}
