# Справочники и основа отчётов — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Выполнить задачи 6–8: административные справочники, DSL и реестр видов, схема сборов и отчётов, статусы и права.

**Architecture:** Стандартные ресурсы Filament 4 и существующее дерево Node. PHP-определения дают правила Laravel Validator, реестр синхронизирует метаданные в БД. ReportPolicy проверяет доступ, ReportStatusService выполняет переходы в транзакции.

**Tech Stack:** Laravel 12.71.0, Filament 4.16.2, Pest 3.8.7, MariaDB, Sail; текущий PHP 8.5.

**Spec:** `docs/superpowers/specs/2026-11-10-dictionaries-report-core-design.md`

## Global Constraints

- Только задачи 6–8; без новых зависимостей, форм заполнения, ReportStore, демо-отчётов, запуска сборов и доставки уведомлений.
- Русский интерфейс, английские идентификаторы. Структура ресурсов как в `app/Filament/Resources/Nodes`.
- Все PHP/Composer/Node/Artisan команды через Sail. Новые Laravel-файлы создавать командами make с --no-interaction.
- Администратор вне дерева. Чужая ветка недоступна. Заблокированный пользователь не получает прав через сервис.
- Принятый базовый отчёт правит и переводит из Принят любой строго вышестоящий; принятый отчёт сбора — конкретный создатель. Администратор имеет доступ, но соблюдает граф переходов.
- Тесты с БД — Feature и RefreshDatabase. Unit только для чистой логики. Не создавать публичные маршруты ради тестов политик.
- Перед каждым блоком кода — падающие тесты; затем узкий прогон. В конце полный набор и `vendor/bin/sail bin pint --dirty --format agent`.
- `.ai/rules` отсутствует при подготовке; перед исполнением проверить снова. Учитывать актуальное состояние git, не трогать посторонние изменения.

## Review Focus

1. Поддельный прямой вызов деактивации обычным пользователем не меняет запись (Task 1).
2. ID активной записи другого справочника и неактивные элементы внутри массива отклоняются (Task 2).
3. Ошибка одного определения при синхронизации не оставляет частично изменённые виды (Task 3).
4. NULL campaign_id не позволяет создать два базовых отчёта одного вида для узла (Task 4).
5. Два экземпляра одного отчёта не позволяют выполнить переход по устаревшему статусу или закрытому сбору (Task 5).

## Task 1: Справочники (исходная задача 6)

**Files:**
- Create: миграция `create_dictionaries_table` (две таблицы), `app/Models/Dictionary.php`, `DictionaryItem.php`, соответствующие фабрики в `database/factories/`.
- Create: `app/Policies/DictionaryPolicy.php`, `DictionaryItemPolicy.php`.
- Create: `app/Filament/Resources/Dictionaries/DictionaryResource.php`, `Pages/ListDictionaries.php`, `CreateDictionary.php`, `EditDictionary.php`, `RelationManagers/ItemsRelationManager.php`.
- Test: `tests/Feature/DictionaryTest.php`.

**Interfaces:**
- `Dictionary::items(): HasMany`, `Dictionary::activeItems(): Illuminate\Database\Eloquent\Collection` (sort, затем id); `DictionaryItem::dictionary(): BelongsTo`.
- Dictionary: id, key unique, name, timestamps. Item: id, dictionary_id FK restrict deletion, label, active default true, sort unsigned default 0, timestamps. Индекс dictionary_id/active/sort.
- Политики разрешают viewAny/view/create/update только незаблокированному администратору; delete/deleteAny всегда false. Политика Item дополнительно `deactivate(User, DictionaryItem): bool`.
- Relation manager: создание и редактирование label/sort, активность через авторизованное действие `deactivate` («Сделать неактивной»). Нет delete/bulk delete, удаления справочника также нет.

- [ ] **Step 1:** Создать Pest feature-тест и проверить: администратор создаёт и редактирует справочник и запись; обычный пользователь получает 403 на страницы и не может вызвать действие; deactivate сохраняет ID и меняет active=false; повторная загрузка сохраняет подпись; activeItems исключает неактивные и сортирует; удалить нельзя; key уникален. Проверять запросы/Livewire и состояние БД, а не наличие методов.
- [ ] **Step 2:** `vendor/bin/sail artisan test --compact tests/Feature/DictionaryTest.php` — FAIL из-за отсутствия моделей/ресурса.
- [ ] **Step 3:** Создать файлы через Artisan, реализовать схему, связи, casts, политики, ресурс и relation manager. Deactivate повторно авторизовать внутри callback, сохраняя только active=false. Поля key/name/label ограничить 255 символами.
- [ ] **Step 4:** Повторить команду — PASS; `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] **Step 5:** Проверить diff, закоммитить только файлы задачи: «Справочники».

## Task 2: DSL полей (часть исходной задачи 7)

**Files:** `app/Enums/FieldType.php`, `app/Enums/ReportKind.php`, `app/Reports/Field.php`, `tests/Unit/FieldRulesTest.php`, `tests/Feature/DictionaryFieldValidationTest.php`.

**Interfaces:**
- FieldType string enum: Number, Text, Radio, Multiselect, Select, Checkbox, Date, Time, Datetime, File; значения lower-case. ReportKind: Simple='simple', List='list', Complex='complex'.
- `Field::{number,text,radio,multiselect,select,checkbox,date,time,datetime,file}(string $name, string $label): self`.
- Цепочка `required(): self`, `min(float $value): self`, `max(float $value): self`, `maxLength(int $length): self`, `multiline(): self`, `options(array $keyToLabel): self`, `dictionary(string $dictionaryKey): self`, `after(string $date): self`, `before(string $date): self`, `files(array $mimes, int $maxKb, bool $multiple = false): self`, `rules(array|Closure $custom): self`.
- Открытые метаданные имени/подписи/типа и настроек для будущих форм; единый документированный контракт без Filament-зависимости.
- `toRules(): array` возвращает правила самого значения. `toValidationRules(): array` возвращает карту name/rules и name.* / elementRules для массивов; нужна для ReportDefinition.
- Closure в rules — стандартная validation Closure `(string $attribute, mixed $value, Closure $fail): void`, не фабрика правил. Массивы rules добавляют штатные строковые правила, Rule-объекты и такие Closure.
- number: numeric; text: string; checkbox: boolean; date: date_format:Y-m-d; time: date_format:H:i; datetime: date_format:Y-m-d H:i:s. Необязательные значения nullable; required добавляется явно. min/max — числовые границы, maxLength — строка. after/before — строгие стандартные правила Laravel.
- scalar select/radio: Rule::in ключей options или Rule::exists для активных ID указанного справочника. multiselect: array/distinct элементов, те же правила на каждый элемент. Dictionary и options взаимоисключающие, недопустимая конфигурация даёт InvalidArgumentException.
- file: file/mimes/max; multiple: array, file/mimes/max на каждый элемент. maxKb в килобайтах. Фактическое хранение файлов позже.

- [ ] **Step 1:** Написать тесты значения `Field::number('count', 'Количество')->required()->min(1)->max(10)` — 0/11/null не проходят, 1/10 проходят; остальные типы и границы проверять через Validator, а чистые метаданные — Unit. Проверить варианты 0/'0', неверный выбор, массив с неверным/повторным элементом, single/multiple файл, maxLength, диапазон дат, nullable и custom Closure с $fail. БД-проверки: чужой dictionary_id, неактивный ID, неизвестный ключ и смесь активного/неактивного массива отвергаются.
- [ ] **Step 2:** Запустить оба файла через `vendor/bin/sail artisan test --compact ...` — FAIL.
- [ ] **Step 3:** Реализовать фабрики, метаданные и построение правил; словарь проверяется при валидации, чтобы ранее созданный Field учитывал последующую деактивацию записи.
- [ ] **Step 4:** Повторить узкий прогон — PASS, Pint.
- [ ] **Step 5:** Коммит «DSL полей отчётов».

## Task 3: Определения и синхронизация видов (завершение исходной задачи 7)

**Files:** `app/Reports/ReportDefinition.php`, `ReportRegistry.php`, `app/Models/ReportType.php`, `database/factories/ReportTypeFactory.php`, миграция `create_report_types_table`, `app/Console/Commands/SyncReports.php`, `config/reports.php`, `tests/Feature/ReportRegistryTest.php`.

**Interfaces:**
- Abstract `ReportDefinition`: `key(): string`, `title(): string`, `kind(): ReportKind`, `table(): string`, `fields(): array` (Field[]); concrete overridable `validate(array $data, array $context = []): array` возвращает Validator::validate по карте правил, без непроверенных полей.
- `ReportRegistry::all(): array<string, ReportDefinition>`, `get(string $key): ReportDefinition`. Разрешение классов через контейнер из config('reports.definitions', []); конфиг по умолчанию пустой. Дубли ключей/некорректные классы/повторные имена полей — явная InvalidArgumentException; неизвестный ключ — InvalidArgumentException.
- ReportType casts kind и все boolean; key уникален, title; флаги is_base/is_required/auto_create=false, excel_enabled/list_allows_empty/active=true. Комплексный вид с is_base=true отвергается; excel_enabled принудительно false на сохранении.
- `reports:sync` проверяет весь реестр, затем транзакционно создаёт/обновляет строки; обновляет только title/kind, кроме принудительного excel=false для Complex. Конфликт Complex с существующим is_base даёт ненулевой exit code без частичной записи. Удалённых из конфигурации типов не касается.

- [ ] **Step 1:** Тестовые определения держать в тестах, не в production config. Проверить all/get/дубли/неизвестный ключ, validate с custom правилами и фильтрацией лишних полей; первый и повторный sync; сохранение административных флагов; смену title/kind; запрет complex+base, принудительный excel=false; конфликт после другой допустимой записи откатывает весь sync; отсутствующий в конфиге тип остаётся.
- [ ] **Step 2:** `vendor/bin/sail artisan test --compact tests/Feature/ReportRegistryTest.php` — FAIL.
- [ ] **Step 3:** Создать файлы Artisan, реализовать контракты. Не регистрировать демо-определения. Отказ при несовместимом kind не должен молча выключать is_base.
- [ ] **Step 4:** Повторить тесты DSL и реестра — PASS, Pint.
- [ ] **Step 5:** Коммит «Реестр видов отчётов и синхронизация».

## Task 4: Схема сборов и отчётов (часть исходной задачи 8)

**Files:** миграции `create_campaigns_table`, `create_reports_table`, `app/Models/Campaign.php`, `Report.php`, `app/Enums/ReportStatus.php`, соответствующие фабрики, `tests/Feature/ReportSchemaTest.php`.

**Interfaces:**
- ReportStatus string enum: New='new', InProgress='in_progress', Rework='rework', Blocked='blocked', Accepted='accepted'; `label(): string` — Новый, В работе, Доработать, Заблокирован, Принят соответственно.
- Campaign: report_type_id, creator_id, creator_node_id nullable для админа, title nullable, starts_at/ends_at date, closes_at nullable date, closed_at nullable timestamp, start_status, remind_days_before unsigned nullable, template_id nullable unsignedBigInteger без FK, timestamps. FK существующих сущностей restrict deletion. Связи reportType/creator/creatorNode/reports.
- `Campaign::isClosed(): bool`: closed_at!=null или closes_at<=today() в timezone приложения; starts_at и ends_at не блокируют доступ этим методом.
- Report: report_type_id, campaign_id nullable, node_id, status default New, timestamps, FK restrict deletion; связи reportType/campaign/node. `scopeInBranchOf(Builder $query, User $user): Builder` — админ все, остальные через Node::inBranch; без узла/заблокированному пустой результат.
- Unique report_type_id/campaign_id/node_id для обычных; дополнительно generated stored column `base_node_id = CASE WHEN campaign_id IS NULL THEN node_id ELSE NULL END` и unique report_type_id/base_node_id для базовых. Поле вычисляемое, не mass assignable.
- Перед сохранением Report: campaign.report_type_id должен совпадать; для null campaign вид is_base=true, kind не Complex, узел Organization. Ошибка — DomainException. Не реализовать автоматическое создание базовых отчётов.

- [ ] **Step 1:** Проверить связи/casts через наблюдаемое поведение, unique обоих видов (включая попытку через query builder в обход модели), допустимость разных сборов/узлов; несовпадающий вид кампании и неверный базовый узел отвергаются; isClosed накануне/в 00:00 дня закрытия/при null/closed_at; ends_at сам не закрывает; scope исключает соседей и предков.
- [ ] **Step 2:** `vendor/bin/sail artisan test --compact tests/Feature/ReportSchemaTest.php` — FAIL.
- [ ] **Step 3:** Создать миграции/модели/фабрики/enum, реализовать проверки и индексы. Сначала повторно инспектировать schema Boost. Проверить синтаксис generated column по установленному Schema Builder и MariaDB.
- [ ] **Step 4:** Повторить файл — PASS, Pint. Миграции пока проверяются на тестовой БД через RefreshDatabase; применение к рабочей БД — после проверки всей задачи.
- [ ] **Step 5:** Коммит «Схема сборов и отчётов».

## Task 5: Политика и переходы (завершение исходной задачи 8)

**Files:** `app/Policies/ReportPolicy.php`, `app/Services/ReportStatusService.php`, `app/Events/ReportReturnedForRework.php`, `tests/Feature/ReportPolicyTest.php`, `tests/Feature/ReportStatusTest.php`.

**Interfaces:**
- Policy `view(User $user, Report $report): bool`, `edit(User $user, Report $report): bool`, `update(User $user, Report $report): bool` (делегирует edit для стандартных интеграций), `setStatus(User $user, Report $report, ReportStatus $to): bool`.
- Всегда отказ заблокированному пользователю; обычному — вне ветки или при isClosed. Админ обходит ограничения роли/закрытия, но сервис сохраняет граф допустимых переходов.
- edit: New/InProgress/Rework — свой узел; Blocked — строгий предок; Accepted — создатель сбора либо, для базового, строгий предок. Без узла обычному отказ.
- `ReportStatusService::transition(Report $report, ReportStatus $to, User $actor): void` перечитывает Report и Campaign с lockForUpdate внутри DB::transaction, также актуального actor и Node. Порядок блокировок одинаков для всех вызовов (Campaign, затем Report). По реальному статусу: недопустимый граф → DomainException; разрешённый граф без прав → AuthorizationException; затем сохранить status и обновить переданный Report.
- Граф: New→InProgress/Blocked; InProgress→Rework/Accepted/Blocked; Rework→InProgress/Blocked; Accepted→InProgress/Rework/Blocked; Blocked→InProgress/Rework. Тот же статус и остальные рёбра запрещены всем.
- setStatus: New/Rework→InProgress только владелец или админ; из Accepted — только creator для сбора, любой предок для базового, либо админ; остальные переходы — строгий предок или админ. Автоматические переходы будут вызываться ReportStore в задаче 9, кнопок для них здесь нет.
- Event `ReportReturnedForRework(public Report $report, public User $actor)` с ShouldDispatchAfterCommit; событие только при успешном переходе в Rework. Нет listener/письма.

- [ ] **Step 1:** Написать dataset матрицы view/edit/setStatus по статусам для владельца, второго пользователя владельца, двух уровней предков, создателя, коллеги создателя, соседней ветки, без узла, заблокированного и админа. Особенно: принятый базовый доступен обоим предкам; принятый в сборе не доступен коллеге создателя. Закрытие запрещает обычным все три действия.
- [ ] **Step 2:** Добавить service-тесты всех разрешённых рёбер и запрета остальных; New→Blocked; возврат владельцем Rework→InProgress; DomainException/AuthorizationException не меняют запись и не порождают событие; Rework event содержит актуальный report и actor; устаревший экземпляр после другого перехода не использует старый статус; закрытие/блокировка пользователя после загрузки учитываются.
- [ ] **Step 3:** Запустить оба файла — FAIL; реализовать политику и сервис по контрактам. Не вводить общий Gate::before, который откроет доступ заблокированному админу.
- [ ] **Step 4:** Повторить оба файла — PASS. Для after-commit события использовать подходящий режим тестовой транзакции или проверку ShouldDispatchAfterCommit с fake, учитывая RefreshDatabase; не менять production поведение ради теста.
- [ ] **Step 5:** Полный набор `vendor/bin/sail artisan test --compact`, Pint, `git diff --check`. Провести ревью изменений; существенные замечания исправить с регрессионным тестом. Применить миграции `vendor/bin/sail artisan migrate --no-interaction`; пустой `reports:sync` должен успешно завершиться. Отметить задачи 6–8 исходного плана после проверки, коммит «Статусы отчётов и права доступа». Не сливать и не публиковать без запроса.

## Self-review

Все разделы дизайна покрыты: справочники — Task 1, DSL — Task 2, реестр/настройки — Task 3, схема/закрытие/уникальность — Task 4, матрица/события/конкурентные переходы — Task 5. Review Focus сопоставлен тестам в соответствующих задачах. Политика проверяется через Gate и реальный сервис, без искусственного HTTP endpoint. Контракт rules Closure уточнён стандартным Validator API. Решение об уникальности NULL адаптировано под фактически установленную MariaDB.
