# Добавление нового отчёта

В этом проекте новый вид отчёта задаётся PHP-классом, таблицей для данных и регистрацией в конфигурации. Для простых и списочных отчётов форма строится автоматически по описанию полей.

Ниже — пример отчёта «Численность обучающихся».

## 1. Создать определение отчёта

```bash
vendor/bin/sail artisan make:class Reports/StudentCount --no-interaction
```

Содержимое `app/Reports/StudentCount.php`:

```php
<?php

namespace App\Reports;

use App\Enums\ReportKind;

class StudentCount extends ReportDefinition
{
    public function key(): string
    {
        return 'student_count';
    }

    public function title(): string
    {
        return 'Численность обучающихся';
    }

    public function kind(): ReportKind
    {
        return ReportKind::Simple;
    }

    public function table(): string
    {
        return 'student_count_data';
    }

    public function fields(): array
    {
        return [
            Field::number('total', 'Всего обучающихся')
                ->required()
                ->min(0)
                ->max(100000)
                ->rules(['integer']),

            Field::date('as_of', 'Дата сведений')
                ->required(),

            Field::text('email', 'Электронная почта')
                ->required()
                ->maxLength(255)
                ->rules(['email']),

            Field::text('comment', 'Комментарий')
                ->multiline()
                ->maxLength(2000),

            Field::file('attachment', 'Подтверждающий документ')
                ->files(['pdf', 'jpg', 'png'], 5120),
        ];
    }
}
```

`key()` — уникальный постоянный ключ. Имена полей, например `total`, должны совпадать с названиями столбцов таблицы.

## 2. Создать таблицу данных

```bash
vendor/bin/sail artisan make:migration create_student_count_data_table --no-interaction
```

В миграции:

```php
public function up(): void
{
    Schema::create('student_count_data', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('report_id')
            ->unique()
            ->constrained()
            ->cascadeOnDelete();

        $table->unsignedInteger('total');
        $table->date('as_of');
        $table->string('email');
        $table->text('comment')->nullable();
        $table->json('attachment')->nullable();
    });
}

public function down(): void
{
    Schema::dropIfExists('student_count_data');
}
```

Используются стандартные импорты `Schema` и `Blueprint` из сгенерированной миграции.

Особенности хранения:

- Для простого отчёта `report_id` уникален: один отчёт — одна строка.
- Необязательные поля должны допускать `NULL`.
- Файлы хранятся в JSON как метаданные; сами файлы сохраняет существующий сервис.
- Множественный выбор тоже хранится в JSON.
- Отдельная Eloquent-модель для этой таблицы не нужна.
- Обязательные `created_at` и `updated_at` добавлять не следует: текущий сервис их не заполняет.

## 3. Зарегистрировать и синхронизировать

Добавить класс в существующий массив `definitions` в [config/reports.php](config/reports.php):

```php
App\Reports\StudentCount::class,
```

Затем выполнить:

```bash
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan config:clear
vendor/bin/sail artisan reports:sync
```

Команда `reports:sync` создаёт или обновляет вид отчёта, но не назначает его организациям.

Для базового отчёта откройте «Виды отчётов», включите «Базовый отчёт», сохраните и используйте «Назначить организациям». Переключатель «Создавать автоматически» назначает отчёт всем существующим и новым организациям.

## 4. Какие поля доступны

Все методы определены в [Field.php](app/Reports/Field.php).

| Поле | Пример |
| --- | --- |
| Число | `Field::number('count', 'Количество')->min(0)` |
| Текст | `Field::text('name', 'Название')->maxLength(255)` |
| Многострочный текст | `Field::text('note', 'Примечание')->multiline()` |
| Одиночный выбор | `Field::select('shift', 'Смена')->options(['first' => 'Первая', 'second' => 'Вторая'])` |
| Радиокнопки | `Field::radio('shift', 'Смена')->options(['first' => 'Первая', 'second' => 'Вторая'])` |
| Множественный выбор | `Field::multiselect('directions', 'Направления')->options(['sport' => 'Спорт', 'art' => 'Искусство'])` |
| Флажок | `Field::checkbox('confirmed', 'Сведения проверены')` |
| Дата | `Field::date('date', 'Дата')` |
| Время | `Field::time('time', 'Время')` |
| Дата и время | `Field::datetime('starts_at', 'Начало')` |
| Один файл | `Field::file('document', 'Документ')->files(['pdf'], 5120)` |
| Несколько файлов | `Field::file('documents', 'Документы')->files(['pdf'], 5120, multiple: true)` |

Выбор из справочника:

```php
Field::select('organization_type', 'Тип организации')
    ->required()
    ->dictionary('organization_types'),
```

Справочник должен существовать. Форма показывает активные элементы, а сервер проверяет принадлежность выбранного элемента этому справочнику. В таблице сохраняется ID элемента. `dictionary()` и `options()` одновременно использовать нельзя.

## 5. Как задавать валидацию

Базовые правила формируются автоматически:

- `required()` делает поле обязательным; по умолчанию поле допускает `null`.
- `number()` проверяет число. Для целого числа добавьте `rules(['integer'])`.
- `min()` и `max()` ограничивают число, длину текста или размер массива — в зависимости от типа.
- `maxLength()` ограничивает длину текста.
- `after()` и `before()` задают строгие ограничения даты.
- `files()` задаёт допустимые расширения и максимальный размер каждого файла в КБ.
- Поля выбора автоматически проверяют допустимость значения; множественный выбор также проверяет отсутствие повторов.

Дополнительные правила Laravel передаются массивом:

```php
Field::text('code', 'Код')
    ->required()
    ->rules(['regex:/^[A-Z]{2}[0-9]{4}$/']);

Field::date('ends_on', 'Дата окончания')
    ->required()
    ->rules(['after_or_equal:starts_on']);
```

Во втором примере в отчёте должно быть поле `starts_on`.

Можно задать собственную проверку:

```php
Field::number('participants', 'Количество участников')
    ->required()
    ->rules([
        'integer',
        function (string $attribute, mixed $value, \Closure $fail): void {
            if ((int) $value % 2 !== 0) {
                $fail('Количество участников должно быть чётным.');
            }
        },
    ]);
```

Для дополнительных сообщений переопределите метод в классе отчёта:

```php
public function validationMessages(): array
{
    return [
        ...parent::validationMessages(),
        'email.email' => 'Укажите корректный адрес электронной почты.',
        'ends_on.after_or_equal' =>
            'Дата окончания должна быть не раньше даты начала.',
    ];
}
```

Названия полей в сообщениях автоматически берутся из подписей `Field`.

Для сложных проверок нескольких полей можно переопределить `validate(array $data, array $context = []): array`. При этом вызывайте `parent::validate($data, $context)`: сервис передаёт через контекст специальные правила для ранее загруженных файлов.

## 6. Как сделать списочный или сложный отчёт

### Списочный

Используйте `ReportKind::List` и уберите `unique()` у `report_id`. Метод `fields()` описывает одну строку списка; интерфейс повторения строк создаётся автоматически. Сервер проверяет каждую строку, допускает максимум 1000 строк. Возможность сохранить пустой список регулируется настройкой вида отчёта «Разрешить пустой список».

Образец: [DemoList.php](app/Reports/Demo/DemoList.php).

### Сложный

Наследуйтесь от `ComplexReportDefinition`. Понадобятся собственные маршруты, контроллер, форма, таблицы и сервис сохранения с проверкой прав и валидацией. Универсальная форма для такого отчёта не строится. Текущий `ComplexReportStore` предназначен именно для демонстрационного отчёта — новый сложный отчёт автоматически он не обслужит.

Образец: [DemoComplex.php](app/Reports/Demo/DemoComplex.php).

## 7. Как изменять уже используемый отчёт

Если в отчёте уже сохранены данные, `reports:sync` запрещает:

- менять таблицу или формат отчёта;
- удалять поля или менять их тип;
- делать существующее необязательное поле обязательным;
- менять одиночный файл на множественный и обратно.

Безопасный обычный порядок: добавить новый nullable-столбец миграцией, добавить необязательное поле в определение, выполнить миграцию и `reports:sync`.

Перед использованием проверьте сохранение корректных данных и отказ на некорректных. Для файлов дополнительно проверьте повторное сохранение с уже загруженным вложением.
