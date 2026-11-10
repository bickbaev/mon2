# Система сбора данных с образовательных организаций. План этапа 1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Каркас системы сбора отчётов: дерево узлов, пользователи, сборы, статусы, расписание, уведомления, статистика, архив, Excel, перенос из Yii1 и 3 демо-отчёта.

**Architecture:** Laravel + Filament (одна панель, доступ определяется положением пользователя в дереве и двумя отметками прав). Дерево хранится материализованным путём. Простые и списочные отчёты описываются PHP-классами-определениями (`ReportDefinition`), по которым система строит форму, проверку, сохранение и Excel; данные каждого вида лежат в своей таблице. Сложные отчёты подключаются через регистрацию маршрутов.

**Tech Stack:** PHP 8.3, Laravel 12, Filament 4, MariaDB/MySQL, Pest (+ pest-plugin-livewire), maatwebsite/excel, Laravel Scheduler и очереди (database driver).

**Spec:** `docs/superpowers/specs/2026-11-08-edu-data-collection-design.md`

## Global Constraints

- Интерфейс и тексты писем на русском, `app.locale=ru`. Идентификаторы в коде на английском.
- Часовой пояс `Europe/Samara` (**решение плана**: на скриншотах Самарская область).
- Статусы отчёта ровно пять, подписи: «Новый», «В работе», «Доработать», «Заблокирован», «Принят».
- Типы поля: число, текст, радиокнопка, множественный выбор, выпадающий список, галка, дата, время, дата и время, файл.
- Узел бывает трёх видов: орган управления, образовательная организация, структурное подразделение/филиал. Филиал не имеет подчинённых. Корень — орган управления.
- Строгое дерево, число уровней не ограничено. Администратор системы вне дерева (`users.node_id = null`).
- Вход по логину, не по почте. Уведомления и сброс пароля идут только на **подтверждённую** почту.
- Отчёт нельзя сохранить с пустыми обязательными полями. Черновиков нет.
- Права «может запускать сборы» и «может добавлять организации и уровни» выдаёт только администратор.
- Перед записью кода к каждой задаче: сначала падающий тест. БД тестов — MariaDB/MySQL `edu_test` (в окружении нет `pdo_sqlite`).
- Коммиты на русском, в конце сообщения строка `Co-Authored-By: Claude <noreply@anthropic.com>`.

## Review Focus

1. Пользователь открывает по прямой ссылке отчёт, сбор или узел чужой ветки: ожидается 403/404, а не данные (тест в Task 8 и Task 15).
2. Шаблон сбора отрабатывает дважды за один день (повторный запуск планировщика): ожидается один сбор, а не два (Task 13).
3. Ежемесячный шаблон со стартом 31-го числа в месяце без 31-го дня: ожидается последний день месяца, а не пропуск (Task 13).
4. Узел переносят под собственного потомка: ожидается отказ без изменения дерева (Task 1).
5. Запуск сбора с пустым набором узлов или без прав: ожидается ошибка проверки, сбор не создаётся (Task 12).

---

## Структура файлов

```
app/Enums/                NodeType, ReportStatus, FieldType, ReportKind, Periodicity
app/Models/               Node, User, Dictionary, DictionaryItem, ReportType, Report, Campaign, CampaignTemplate
app/Services/             NodeService, NodeSelector, ReportStore, ReportStatusService, CampaignService,
                          StatusStats, BaseReportGate, ReportExporter, ScheduleCalculator, Yii1Importer
app/Reports/              ReportDefinition, Field, ReportRegistry, ComplexReportDefinition, Demo/*
app/Policies/             UserPolicy, NodePolicy, ReportPolicy, CampaignPolicy
app/Notifications/        NewReportNotification, ReportReturnedNotification, DeadlineReminderNotification
app/Filament/             Pages (Onboarding, CampaignsOverview, CampaignStats, Archive, ExcelExport, BaseReports),
                          Resources (Node, User, Dictionary, ReportType, Campaign, CampaignTemplate)
app/Modules/              ModuleContract, ModuleRegistry, ReportGateway
app/Console/Commands/     SyncReports, SpawnCampaigns, CloseDueCampaigns, SendDeadlineReminders, ImportYii1, MakeAdmin
database/migrations/, tests/Feature/, tests/Unit/
```

---

### Task 0: Каркас проекта

**Files:**
- Create: Laravel-проект в корне репозитория, `.env.testing`, `tests/Feature/SmokeTest.php`
- Modify: `phpunit.xml`, `config/app.php`, `.gitignore`

**Interfaces:**
- Produces: панель Filament `app` по пути `/cabinet`; Pest настроен на БД `edu_test`; хелпер `tests/Pest.php` с `actingAsUser(User $u)`.

- [ ] **Step 1: Окружение.** Добавить `docker-compose.yml` с сервисом `db` (образ `mariadb:11`, порт `3306`, том для данных, переменные `MARIADB_ROOT_PASSWORD`, `MARIADB_DATABASE=edu`), скрипт инициализации создаёт вторую БД `edu_test`. Запуск: `docker compose up -d db`. Проверка: `docker compose exec db mariadb -uroot -p -e "show databases"` показывает `edu` и `edu_test`.
- [ ] **Step 2: Создать проект.** Из-за существующих `docs/`, `README.md` развернуть Laravel 12 во временной папке (`composer create-project laravel/laravel`) и скопировать в репозиторий, не затирая `docs/`. `промт.txt` и `img/` не добавлять в git. Установить `filament/filament:^4`, `maatwebsite/excel`, `pestphp/pest`, `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-livewire`. Node 18 не годится для Vite 6+: фронтенд-сборку не использовать, Filament работает на своих готовых ресурсах.
- [ ] **Step 3: Failing test** `SmokeTest::test_login_page_opens`: `get('/cabinet/login')->assertOk()`.
- [ ] **Step 4: Run** `php artisan test --filter=SmokeTest` — FAIL (панели нет).
- [ ] **Step 5: Implement.** `php artisan filament:install --panels` (id `app`, path `cabinet`), `APP_LOCALE=ru`, `APP_TIMEZONE=Europe/Samara`, очереди `QUEUE_CONNECTION=database`.
- [ ] **Step 6: Run** — PASS.
- [ ] **Step 7: Commit** «Каркас Laravel + Filament».

### Task 1: Дерево узлов

**Files:**
- Create: `app/Enums/NodeType.php`, `app/Models/Node.php`, `app/Services/NodeService.php`, `app/Exceptions/InvalidNodeMove.php`, миграция `nodes`
- Test: `tests/Unit/NodeTreeTest.php`

**Interfaces:**
- Produces:
  - `enum NodeType: string { Authority='authority'; Organization='organization'; Branch='branch' }`
  - таблица `nodes(id, parent_id null, type, name, path, depth, timestamps)`; `path` вида `/1/5/9/` включает сам узел
  - `Node::scopeInBranch(Builder $q, Node $root, bool $withSelf = true)`
  - `Node::isAncestorOf(Node $other): bool` (строго выше)
  - `NodeService::create(?Node $parent, NodeType $type, string $name): Node` — бросает `InvalidArgumentException` при нарушении правил типов: корень только `Authority`; `Authority` — только под `Authority`; `Organization` — только под `Authority`; `Branch` — только под `Organization`
  - `NodeService::move(Node $node, Node $newParent): void` — пересчитывает `path`/`depth` у всей ветки; бросает `InvalidNodeMove` при переносе под себя/потомка и при нарушении правил типов
  - событие `NodeCreated(Node $node)` после `create`

- [ ] **Step 1: Failing tests:** `test_path_and_depth_are_built`, `test_branch_must_sit_under_organization`, `test_branch_cannot_have_children`, `test_in_branch_scope_returns_descendants_only_when_without_self`, `test_move_recalculates_whole_subtree`, `test_move_under_own_descendant_is_rejected_and_tree_unchanged`.
- [ ] **Step 2: Run** `php artisan test tests/Unit/NodeTreeTest.php` — FAIL.
- [ ] **Step 3: Implement** по сигнатурам выше; `move` в транзакции, пересчёт `path` обновлением по префиксу.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** «Дерево узлов».

### Task 2: Пользователи и доступ по ветке

**Files:**
- Create: миграция `users` (изменить стандартную), `app/Models/User.php`, `app/Policies/UserPolicy.php`, `app/Policies/NodePolicy.php`
- Test: `tests/Unit/UserAccessTest.php`

**Interfaces:**
- Consumes: `Node::scopeInBranch`, `Node::isAncestorOf`.
- Produces: `users(id, node_id null, login unique, name, email null (не unique), pending_email null, email_verified_at null, pd_consent_at null, password, is_admin bool, can_start_campaigns bool, can_manage_structure bool, blocked_at null, timestamps)`; методы `User::isAdmin()`, `User::canSeeNode(Node): bool` (узел в ветке пользователя, включая свой; админ — любой), `User::canManageUser(User $other): bool` (узел `other` строго ниже моего или я админ; себя через этот метод управлять нельзя), `User::isBlocked()`, `User::scopeInBranchOf(Builder, User $viewer)`.
- `UserPolicy`: `viewAny/view/update/delete/block` через `canManageUser`; `create(User, Node $node)`. `NodePolicy::create(User, ?Node $parent)`: админ или (`can_manage_structure` и `canSeeNode($parent)`). Менять отметки прав и `is_admin` может только админ — проверяется в `UserPolicy::grantFlags`.

- [x] **Step 1: Failing tests:** `test_superior_manages_users_of_descendants`, `test_user_cannot_manage_peer_or_ancestor`, `test_admin_without_node_sees_everything`, `test_only_admin_can_grant_flags`, `test_can_manage_structure_flag_limits_creation_to_own_branch`.
- [x] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [x] **Step 5: Commit** «Пользователи и права по ветке».

**Результат (2026-11-09):** выполнено в ветке `worktree-stage1`. Новые поля добавлены отдельной миграцией для совместимости с существующей БД; прежним пользователям назначается логин `user_<id>`. Откат запрещён при NULL или повторяющейся почте, несовместимой с исходной схемой. Поля прав и `node_id` защищены от массового присваивания. Проверка: 8 новых тестов, полный набор — 25 тестов / 134 проверки. Независимое ревью: существенных замечаний нет.

### Task 3: Вход по логину, согласие, подтверждение почты

**Files:**
- Create: `app/Filament/Pages/Auth/Login.php`, `app/Filament/Pages/Onboarding.php`, `app/Http/Middleware/EnsureOnboardingComplete.php`, `app/Notifications/VerifyEmailNotification.php`, маршрут `email.verify` (signed)
- Test: `tests/Feature/OnboardingTest.php`

**Interfaces:**
- Consumes: `User` из Task 2.
- Produces: порядок после входа: (1) согласие на ПДн → пишет `pd_consent_at = now()`; (2) ввод почты → пишет `pending_email` (или подтверждение предзаполненной `email`) и отправляет письмо; (3) переход по подписанной ссылке ставит `email = pending_email`, `email_verified_at = now()`. Middleware `EnsureOnboardingComplete` пускает в остальную панель только если есть `pd_consent_at` и `email_verified_at`; заблокированный пользователь войти не может. Все пользователи, включая админа и перенесённых, проходят шаги 1–3.

- [x] **Step 1: Failing tests:** `test_login_uses_login_field_not_email`, `test_blocked_user_cannot_login`, `test_user_without_consent_is_redirected_to_onboarding`, `test_consent_stores_timestamp`, `test_email_must_be_confirmed_by_link_before_access`, `test_imported_user_with_old_email_still_must_confirm`, `test_expired_or_tampered_verify_link_is_rejected`.
- [x] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [x] **Step 5: Commit** «Вход, согласие на ПДн, подтверждение почты».

**Результат (2026-11-10):** реализованы вход по логину, запрет доступа заблокированным (включая активные сессии), обязательное согласие с сохранением даты и подтверждение почты по подписанной ссылке на 60 минут. Новый адрес хранится в `pending_email`, старый сохраняется до подтверждения. Проверяются владелец ссылки, согласие, актуальность адреса, срок и подпись; повторное использование запрещено. Онбординг обязателен и для администратора, middleware действует также на Livewire-запросах. Отправка ограничена тремя запросами в минуту с одного IP. Проверено на локальном Sail PHP 8.5: PHP 8.3 не удалось собрать из-за недоступности репозиториев Ubuntu. В режиме `MAIL_MAILER=log` письмо находится в `storage/logs/laravel.log`; для реальной доставки требуется почтовый транспорт. Проверки: полный набор — 40 тестов / 222 проверки; Pint для 10 изменённых PHP-файлов — PASS. Полный Pint выявляет прежние замечания в `app/Events/NodeCreated.php`, `app/Exceptions/InvalidNodeMove.php`, `bootstrap/providers.php`. Независимое ревью: существенных замечаний нет.

### Task 4: Восстановление пароля и профиль

**Files:**
- Create: `app/Filament/Pages/Auth/RequestPasswordReset.php`, `ResetPassword.php`, `app/Filament/Pages/Profile.php`
- Modify: `app/Models/User.php` (`getEmailForPasswordReset()` возвращает только подтверждённую почту)
- Test: `tests/Feature/PasswordResetTest.php`, `tests/Feature/ProfileTest.php`

**Interfaces:**
- Produces: форма сброса принимает **логин**, письмо уходит на подтверждённую почту; если подтверждённой почты нет, ответ тот же («если пользователь существует, письмо отправлено»), но письмо не уходит. Профиль: смена пароля (с текущим паролем) и имени/почты; смена почты заново проходит подтверждение письмом, до этого работает старая.

- [ ] **Step 1: Failing tests:** `test_reset_link_goes_to_verified_email_only`, `test_unknown_login_gives_same_response`, `test_reset_sets_new_password`, `test_profile_password_change_requires_current_password`, `test_changing_email_keeps_old_until_confirmed`.
- [ ] **Step 2–4:** run FAIL → implement → PASS. **Step 5: Commit** «Сброс пароля и профиль».

### Task 5: Управление узлами и пользователями в панели

**Files:**
- Create: `app/Filament/Resources/NodeResource.php`, `UserResource.php` (+ страницы)
- Test: `tests/Feature/Admin/NodeUserResourceTest.php`

**Interfaces:**
- Consumes: `NodeService`, `UserPolicy`, `NodePolicy`.
- Produces: ресурс узлов (дерево/список, создание через `NodeService::create`, видно только своей ветки, создание доступно по `NodePolicy::create`); ресурс пользователей (список только ниже своего узла, создать/править/заблокировать/удалить через политику; поля прав `is_admin`, `can_start_campaigns`, `can_manage_structure` показываются и сохраняются только админу).

- [ ] **Step 1: Failing tests (Livewire):** `test_user_sees_only_own_branch_nodes`, `test_non_admin_cannot_set_flags_even_by_forged_payload`, `test_block_prevents_login`, `test_user_without_manage_structure_cannot_create_node`, `test_superior_creates_user_for_descendant_node_only`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Ресурсы узлов и пользователей».

### Task 6: Справочники

**Files:**
- Create: миграции `dictionaries(id, key unique, name)`, `dictionary_items(id, dictionary_id, label, active bool default true, sort)`; модели; `app/Filament/Resources/DictionaryResource.php`
- Test: `tests/Feature/DictionaryTest.php`

**Interfaces:**
- Produces: `Dictionary::activeItems(): Collection`; ресурс виден только админу; кнопки удаления записи нет, есть «Сделать неактивной»; неактивная запись не предлагается в формах, но остаётся в старых данных.

- [ ] **Step 1: Failing tests:** `test_only_admin_manages_dictionaries`, `test_item_is_deactivated_not_deleted`, `test_inactive_items_excluded_from_active_items`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Справочники».

### Task 7: DSL полей и реестр видов отчётов

**Files:**
- Create: `app/Enums/FieldType.php`, `ReportKind.php`, `app/Reports/Field.php`, `ReportDefinition.php`, `ReportRegistry.php`, миграция `report_types`, `app/Models/ReportType.php`, `app/Console/Commands/SyncReports.php`, `config/reports.php` (список классов-определений)
- Test: `tests/Unit/FieldRulesTest.php`, `tests/Feature/ReportRegistryTest.php`

**Interfaces:**
- Produces:
  - `Field::number|text|radio|multiselect|select|checkbox|date|time|datetime|file(string $name, string $label): Field`; цепочка `required()`, `min(float)`, `max(float)`, `maxLength(int)`, `multiline()`, `options(array $keyToLabel)`, `dictionary(string $dictionaryKey)`, `after(string $date)`/`before(string $date)`, `files(array $mimes, int $maxKb, bool $multiple = false)`, `rules(array|Closure $custom)` (пользовательская проверка); `toRules(): array` — массив правил Laravel.
  - `abstract class ReportDefinition { abstract key(): string; abstract title(): string; abstract kind(): ReportKind; abstract table(): string; abstract fields(): array /* Field[] */; function validate(array $data, array $context = []): array /* может переопределяться */ }`
  - `ReportRegistry::all(): array<string, ReportDefinition>`, `::get(string $key): ReportDefinition`
  - `report_types(id, key unique, kind, title, is_base bool, is_required bool, auto_create bool, excel_enabled bool default true, list_allows_empty bool default true, active bool default true)`; `reports:sync` создаёт недостающие строки и обновляет `title`/`kind`, **не трогая** настройки, выставленные админом.
  - Правила допустимости: `is_base` только для `Simple`/`List`; сложные отчёты `excel_enabled = false` принудительно.

- [ ] **Step 1: Failing tests:** `test_required_number_with_bounds_builds_rules`, `test_dictionary_field_accepts_only_active_item_ids`, `test_custom_rules_closure_is_applied`, `test_sync_creates_report_types_and_keeps_admin_settings`, `test_complex_report_cannot_be_base_or_have_excel`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «DSL полей и реестр отчётов».

### Task 8: Отчёты, сборы (схема), статусы и политика

**Files:**
- Create: миграции `campaigns`, `reports`; модели `Campaign`, `Report`; `app/Enums/ReportStatus.php`; `app/Services/ReportStatusService.php`; `app/Policies/ReportPolicy.php`; событие `ReportReturnedForRework`
- Test: `tests/Unit/ReportStatusTest.php`, `tests/Feature/ReportPolicyTest.php`

**Interfaces:**
- Produces:
  - `campaigns(id, report_type_id, creator_id, creator_node_id, title null, starts_at date, ends_at date, closes_at date, closed_at null, start_status, remind_days_before unsigned null, template_id null, timestamps)`; `Campaign::isClosed(): bool` (`closed_at` не null или `closes_at` в прошлом)
  - `reports(id, report_type_id, campaign_id null, node_id, status, timestamps)`, unique(`report_type_id`,`campaign_id`,`node_id`); у базовых `campaign_id = null`
  - `ReportStatus::label(): string` (русские подписи)
  - `ReportStatusService::transition(Report $r, ReportStatus $to, User $actor): void` — бросает `AuthorizationException`/`DomainException`; разрешены: New→InProgress (автоматически, вызывается из `ReportStore`), InProgress→Rework|Accepted|Blocked, Rework→InProgress (авто) |Blocked, Accepted→InProgress|Rework|Blocked (только создатель сбора), Blocked→InProgress|Rework (вышестоящие); Blocked→New не допускается; `to = Rework` отправляет `ReportReturnedForRework`
  - `ReportPolicy::view/edit(User, Report)`, `setStatus(User, Report, ReportStatus $to)` — матрица из спеки: New/InProgress/Rework — правит узел отчёта; Accepted — только создатель сбора; Blocked — только узлы выше узла отчёта, не сам узел; закрытый сбор — никому из обычных пользователей; «Доработать»/«Принят» ставит любой узел выше. У базовых отчётов «создателя сбора» нет: их правит админ и вышестоящий, поставивший статус.
- Review Focus (1): `test_user_cannot_open_report_of_foreign_branch_by_id`.

- [ ] **Step 1: Failing tests:** `test_new_to_in_progress_on_first_save_only`, `test_rework_returns_to_in_progress_after_owner_saves`, `test_accepted_report_editable_only_by_campaign_creator`, `test_blocked_report_editable_by_ancestors_not_by_owner`, `test_closed_campaign_report_denied_for_everyone_but_admin`, `test_rework_dispatches_event`, `test_user_cannot_open_report_of_foreign_branch_by_id`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Отчёты, сборы, статусы, политика».

### Task 9: Сохранение и форма отчётов, демо-отчёты, файлы

**Files:**
- Create: `app/Services/ReportStore.php`, `app/Filament/Pages/ReportForm.php`, `app/Reports/Demo/DemoSimple.php`, `DemoList.php`, миграции их таблиц, маршрут `reports.files.download`
- Test: `tests/Feature/ReportStoreTest.php`, `tests/Feature/ReportFilesTest.php`

**Interfaces:**
- Consumes: `ReportDefinition`, `ReportPolicy`, `ReportStatusService`.
- Produces:
  - `ReportStore::save(Report $r, array $data, User $actor): void` — проверяет доступ, валидирует по `fields()`, пишет данные в `definition->table()` (простой: одна строка на `report_id`; списочный: строки `report_id` + поля, старые записи заменяются целиком) и переводит статус через `ReportStatusService`. Пустые обязательные поля — `ValidationException`, ничего не сохраняется. Для списочного: нулевой список допустим только при `report_types.list_allows_empty`.
  - `ReportForm` строит форму Filament по `fields()` (для списочного — повторитель); значения справочников хранятся как id записи (`unsignedBigInteger` с FK на `dictionary_items`); файловые поля — пути/метаданные в json-колонке, файлы в приватном диске `reports`.
  - Скачивание файла: маршрут проверяет `ReportPolicy::view`; закрытый сбор — только через архив (Task 16).
  - Демо: `DemoSimple` (число, текст, радио со справочником, дата, файл), `DemoList` («Мероприятия»: название, дата, число участников).
  - Изменение описания, по которому есть данные: `ReportRegistry::assertSafeChange` — запрещает удалять поля и делать существующие обязательными (отдельный тест).

- [ ] **Step 1: Failing tests:** `test_save_rejects_empty_required_and_stores_nothing`, `test_first_save_moves_new_to_in_progress`, `test_list_report_replaces_rows`, `test_empty_list_allowed_only_with_flag`, `test_dictionary_value_stored_as_item_id`, `test_file_rejected_when_too_big_or_wrong_mime`, `test_file_download_denied_outside_branch`, `test_unsafe_definition_change_is_rejected`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Сохранение отчётов, формы, файлы, демо».

### Task 10: Точка расширения для сложных отчётов

**Files:**
- Create: `app/Reports/ComplexReportDefinition.php`, `app/Reports/Demo/DemoComplex.php`, контроллер и view демо-отчёта
- Test: `tests/Feature/ComplexReportTest.php`

**Interfaces:**
- Produces: `abstract class ComplexReportDefinition extends ReportDefinition` с `kind() = Complex`, `abstract routes(): void` (регистрирует маршруты своего контроллера под префиксом `/cabinet/complex/{key}`), `fields()` возвращает `[]`. Такой отчёт получает сборы, статусы, политику и статистику статусов как остальные; свой контроллер обязан использовать `ReportPolicy` и `ReportStatusService`. Excel для него не формируется.

- [ ] **Step 1: Failing tests:** `test_complex_report_route_is_registered`, `test_complex_report_respects_report_policy`, `test_complex_report_appears_in_campaign_stats`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Сложные отчёты».

### Task 11: Базовые отчёты

**Files:**
- Create: `app/Services/BaseReportGate.php`, `app/Listeners/CreateAutoBaseReports.php`, `app/Filament/Pages/BaseReports.php`, действие «Назначить организациям» в `ReportTypeResource`
- Test: `tests/Feature/BaseReportsTest.php`

**Interfaces:**
- Consumes: `NodeCreated`, `report_types.is_base/is_required/auto_create`.
- Produces:
  - `BaseReportGate::blocks(Node $node): bool` — true, если `$node` образовательная организация и у неё есть обязательный базовый отчёт в статусе New (то есть не сохранён). Для органов и филиалов всегда false. `ReportPolicy::edit` для отчётов **сборов** этой организации возвращает false при `blocks`; сами базовые отчёты редактируются всегда; статус Rework/Blocked базового отчёта не блокирует.
  - при `NodeCreated` у организации создаются все базовые отчёты с `auto_create`; при включении `auto_create`/назначении админом — создаются у всех существующих организаций (идемпотентно: unique-ключ).
  - Страница `BaseReports` для вышестоящих: организации ветки × базовые отчёты, статус, фильтр по статусу, проставление «Доработать»/«Принят».

- [ ] **Step 1: Failing tests:** `test_auto_base_report_created_for_new_organization_only`, `test_enabling_auto_create_backfills_existing_organizations_idempotently`, `test_unsaved_required_base_report_blocks_campaign_reports_of_organization`, `test_branch_and_authority_never_blocked`, `test_rework_base_report_does_not_block`, `test_base_report_assignment_by_admin_when_auto_off`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Базовые отчёты».

### Task 12: Запуск и управление сбором

**Files:**
- Create: `app/Services/NodeSelector.php`, `app/Services/CampaignService.php`, `app/Policies/CampaignPolicy.php`, `app/Filament/Resources/CampaignResource.php`
- Test: `tests/Feature/CampaignServiceTest.php`

**Interfaces:**
- Consumes: `Report`, `Campaign`, `NodeType`, `ReportRegistry`.
- Produces:
  - `final class NodeSelection { public function __construct(public array $nodeIds = [], public array $allInBranch = [], public array $organizationsInBranch = [], public array $branchesInBranch = []) }` — соответствуют четырём способам из спеки (по одному / все в ветке / все в ветке кроме органов управления, без филиалов / все филиалы в ветке)
  - `NodeSelector::resolve(User $actor, NodeSelection $s): Collection` /* Node */ — оставляет только узлы ветки актора **без его собственного узла**; бросает `ValidationException`, если итог пуст или указан узел вне ветки
  - `CampaignService::start(User $actor, array $data, NodeSelection $s): Campaign` — `$data`: `report_type_id, starts_at, ends_at, closes_at, start_status, remind_days_before`; требует `can_start_campaigns`; проверяет `starts_at <= ends_at <= closes_at`; тип отчёта не базовый; создаёт `Report` с `start_status` на каждый узел в транзакции; отправляет `NewReportNotification` (Task 14)
  - `addNode(User, Campaign, Node): Report` (не создаёт дубль), `blockAll(User, Campaign): int` (все отчёты → Blocked), `close(User, Campaign): void` (ставит `closed_at`)
  - `CampaignPolicy::manage(User, Campaign)` — создатель и все узлы выше узла создателя; админ

- [ ] **Step 1: Failing tests:** `test_four_selection_methods_resolve_expected_nodes`, `test_selection_excludes_own_node_and_foreign_branches`, `test_start_with_empty_selection_fails_and_creates_nothing`, `test_start_requires_flag`, `test_dates_must_be_ordered`, `test_set_of_nodes_is_frozen_after_start`, `test_add_node_manually_creates_one_report`, `test_block_all_sets_every_report_blocked`, `test_superior_can_manage_subordinate_campaign`, `test_start_status_applies_to_all_reports`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Запуск и управление сбором».

### Task 13: Шаблоны сборов и планировщик

**Files:**
- Create: миграции `campaign_templates(...)`, `campaign_template_nodes(template_id, node_id)`; `app/Models/CampaignTemplate.php`, `app/Enums/Periodicity.php`, `app/Services/ScheduleCalculator.php`; команды `SpawnCampaigns`, `CloseDueCampaigns`; `CampaignTemplateResource`; расписание в `routes/console.php`
- Test: `tests/Unit/ScheduleCalculatorTest.php`, `tests/Feature/SpawnCampaignsTest.php`

**Interfaces:**
- Consumes: `CampaignService`.
- Produces:
  - `campaign_templates(id, creator_id, report_type_id, periodicity, start_weekday null, start_day null, start_month null, duration_days, close_after_days null, start_status, remind_days_before null, last_spawned_for date null, active bool)`
  - `ScheduleCalculator::nextStart(CampaignTemplate $t, CarbonImmutable $after): CarbonImmutable` — еженедельно по дню недели, ежемесячно по числу (если числа нет в месяце — последний день месяца), ежегодно по месяцу и числу (29 февраля — 28 в невисокосный год)
  - `campaigns:spawn` (ежедневно): для каждого активного шаблона, у которого старт наступил, создаёт сбор через `CampaignService::startFromTemplate(CampaignTemplate): ?Campaign`; идемпотентность по `last_spawned_for`; набор узлов — `campaign_template_nodes` (фиксируется при создании шаблона; добавление узла вручную); `closes_at = ends_at + close_after_days`, а если `close_after_days` null — `closes_at` не задан и сбор закрывают вручную (в миграции `campaigns.closes_at` делает nullable)
  - `campaigns:close-due` (ежедневно): проставляет `closed_at` сборам с `closes_at <= today`

- [ ] **Step 1: Failing tests:** `test_weekly_next_start`, `test_monthly_day_31_falls_back_to_last_day_of_month`, `test_yearly_feb_29_in_non_leap_year`, `test_spawn_twice_same_day_creates_one_campaign`, `test_spawn_uses_frozen_template_nodes_and_skips_if_all_nodes_removed`, `test_node_added_to_template_joins_only_later_campaigns`, `test_close_due_closes_only_past_closes_at`, `test_no_close_after_days_means_manual_close`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Шаблоны сборов и планировщик».

### Task 14: Уведомления

**Files:**
- Create: `app/Notifications/NewReportNotification.php`, `ReportReturnedNotification.php`, `DeadlineReminderNotification.php`, `app/Services/ReportRecipients.php`, команда `SendDeadlineReminders`, `config/report-notifications.php`, слушатель `NotifyAboutRework`
- Test: `tests/Feature/NotificationsTest.php`

**Interfaces:**
- Consumes: `Report`, `ReportReturnedForRework`, `Campaign::remind_days_before`.
- Produces:
  - `ReportRecipients::for(Node $node): Collection` — пользователи узла с `email_verified_at` и без блокировки
  - все уведомления `ShouldQueue`, каналы берутся из `config('report-notifications.channels')` (по умолчанию `['mail']`) — так добавляется новый канал
  - `NewReportNotification` — при запуске сбора и при `addNode`; `ReportReturnedNotification` — при переводе в Rework; `DeadlineReminderNotification` — команда `campaigns:remind` (ежедневно): в день `ends_at - remind_days_before` шлёт только узлам, чей отчёт сейчас в статусе New; одно напоминание на отчёт. Уведомления о приёмке нет.

- [ ] **Step 1: Failing tests:** `test_all_verified_users_of_node_get_new_report_mail`, `test_unverified_and_blocked_users_get_nothing`, `test_node_without_verified_users_does_not_fail_start`, `test_rework_notifies_owner_node`, `test_reminder_only_for_new_status`, `test_reminder_sent_once_per_report`, `test_no_mail_on_accept`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Уведомления».

### Task 15: Экраны статистики

**Files:**
- Create: `app/Services/StatusStats.php`, `app/Filament/Pages/CampaignsOverview.php`, `CampaignStats.php`, `CampaignReportsList.php`
- Test: `tests/Feature/StatisticsTest.php`

**Interfaces:**
- Consumes: `Node::scopeInBranch`, `Report`.
- Produces:
  - `StatusStats::forNode(Campaign $c, Node $node): array{rows: list<array{node: Node, counts: array<string,int>}>, total: array<string,int>}` — `rows` — непосредственные подчинённые `$node`, `counts` содержит ключи значений `ReportStatus` и `total`, считает по всей ветке подчинённого; `total` — итог по `$node`; подчинённые без отчётов показываются с нулями
  - `StatusStats::overview(User $viewer): list<array{campaign: Campaign, counts: array}>` — открытые сборы, видимые пользователю (свои и нижестоящих), счётчики по ветке пользователя
  - Страницы: `CampaignsOverview` (одна строка на сбор → ссылка на `CampaignStats`), `CampaignStats` (таблица со ссылкой «Просмотр» на подчинённый узел; число в колонке ведёт на `CampaignReportsList` со статусом), доступны узлам с подчинёнными; для узла без подчинённых и для чужой ветки — 403.

- [ ] **Step 1: Failing tests:** `test_counts_are_summed_over_whole_branch_of_each_child`, `test_children_without_reports_show_zeros`, `test_overview_hides_closed_campaigns`, `test_leaf_node_has_no_stats_screen`, `test_foreign_branch_stats_forbidden`, `test_status_link_lists_reports_with_that_status`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Экраны статистики».

### Task 16: Архив закрытых сборов

**Files:**
- Create: `app/Filament/Pages/Archive.php`, `ArchiveCampaignStats.php`
- Test: `tests/Feature/ArchiveTest.php`

**Interfaces:**
- Consumes: `StatusStats::forNode`, `Campaign::isClosed`.
- Produces: список закрытых сборов, видимых создателю и всем выше по дереву (заполняющие узлы архив не видят); для закрытого сбора доступны статистика и Excel (Task 17), любые записи и смена статусов запрещены (политика отдаёт false независимо от роли, включая создателя); скачивание файлов отчёта закрытого сбора — только отсюда.

- [ ] **Step 1: Failing tests:** `test_archive_lists_closed_campaigns_for_creator_and_ancestors`, `test_filling_node_cannot_open_archive`, `test_archive_is_read_only_for_creator`, `test_closed_campaign_files_downloadable_via_archive_only`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Архив закрытых сборов».

### Task 17: Выгрузка в Excel

**Files:**
- Create: `app/Services/ReportExporter.php`, `app/Filament/Pages/ExcelExport.php`
- Test: `tests/Feature/ExcelExportTest.php`

**Interfaces:**
- Consumes: `ReportRegistry`, `ReportType::excel_enabled`, `Node::scopeInBranch`.
- Produces:
  - `ReportExporter::export(ReportType $type, ?Campaign $campaign, Node $root): BinaryFileResponse` — все сохранённые отчёты ветки `$root` независимо от статуса (то есть кроме статуса New). Простой: колонки «Уровень 1…N» (предки узла от `$root`), «Узел», «Статус», затем поля. Списочный: то же, одна строка на запись. Файловые поля — имена файлов; справочные — подписи записей. Для `Complex` и для `excel_enabled = false` — `DomainException`
  - Страница `ExcelExport`: выбор вида отчёта и сбора (или базового), кнопка «Скачать»; доступна только органам управления; организации и филиалы получают 403; видны только ветка пользователя и сборы, которыми он может управлять или которые идут в его ветке, включая закрытые.

- [ ] **Step 1: Failing tests:** `test_simple_report_one_row_per_node_with_status_column`, `test_list_report_one_row_per_record_with_ancestor_columns`, `test_new_status_reports_not_exported`, `test_export_limited_to_user_branch`, `test_disabled_report_and_complex_report_cannot_be_exported`, `test_organization_user_forbidden_on_export_page`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Выгрузка в Excel».

### Task 18: Перенос организаций и пользователей из Yii1

**Files:**
- Create: `app/Services/Yii1Importer.php`, `app/Console/Commands/ImportYii1.php`, `docs/yii1-import-format.md`
- Test: `tests/Feature/Yii1ImportTest.php`, фикстуры `tests/fixtures/nodes.csv`, `users.csv`

**Interfaces:**
- Consumes: `NodeService`, `User`.
- Produces: `php artisan import:yii1 {nodes.csv} {users.csv} {--dry-run}`. Промежуточный формат (схема Yii1 пока неизвестна, выгрузку в CSV из Yii1 готовят отдельно после получения репозитория): `nodes.csv: old_id,parent_old_id,type,name`; `users.csv: old_id,node_old_id,login,name,email,password_hash`. Узлы вставляются от корней к листьям; дерево, узел-сирота или неизвестный тип — строка попадает в `import-errors.csv`, остальное импортируется. Все пользователи: `pd_consent_at = null`, `email` как в файле (может быть пустой) без `email_verified_at`; если `password_hash` совместим с bcrypt — сохраняется, иначе выдаётся временный пароль в `temp-passwords.csv`. Повторный запуск не создаёт дублей (ключ — `old_id` в служебных колонках `nodes.legacy_id`, `users.legacy_id`).

- [ ] **Step 1: Failing tests:** `test_import_builds_tree_in_any_row_order`, `test_orphan_node_goes_to_error_report`, `test_users_imported_without_consent_and_unverified_email`, `test_user_without_email_is_imported`, `test_incompatible_hash_gets_temporary_password`, `test_second_run_creates_no_duplicates`, `test_dry_run_writes_nothing`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Перенос из Yii1».

### Task 19: Опора для модулей

**Files:**
- Create: `app/Modules/ModuleContract.php`, `ModuleRegistry.php`, `ReportGateway.php`, `config/modules.php`
- Test: `tests/Feature/ModuleSupportTest.php`

**Interfaces:**
- Produces:
  - `interface ModuleContract { public function key(): string; public function title(): string; public function navigation(User $user): array /* список Filament NavigationItem */; public function register(Panel $panel): void; }`
  - `config('modules.enabled')` — список классов; `ModuleRegistry::all()`; панель добавляет `navigation()` модулей в меню и вызывает `register()`
  - `ReportGateway::reportsOf(Campaign $c, User $viewer): Collection` /* Report с учётом ветки */ и `setStatus(Report, ReportStatus, User): void` — тонкая обёртка над политикой и `ReportStatusService`, единственный путь модуля к данным и статусам.
  - Сами модули не реализуются.

- [ ] **Step 1: Failing tests (с тестовым модулем в `tests/Support`):** `test_enabled_module_adds_navigation_item`, `test_module_uses_panel_authentication`, `test_gateway_returns_only_viewer_branch_reports`, `test_gateway_status_change_goes_through_policy`.
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5: Commit** «Опора для модулей».

### Task 20: Первый администратор и документация

**Files:**
- Create: `app/Console/Commands/MakeAdmin.php`, `README.md` (запуск, очереди, планировщик, импорт)
- Test: `tests/Feature/MakeAdminTest.php`

**Interfaces:**
- Produces: `php artisan app:make-admin {login} {--email=}` создаёт пользователя с `is_admin = true`, `node_id = null`, выводит одноразовый пароль; тот же онбординг (Task 3). В README: cron `* * * * * php artisan schedule:run`, `php artisan queue:work`, порядок первого запуска (`migrate`, `reports:sync`, `app:make-admin`, `import:yii1`).

- [ ] **Step 1: Failing tests:** `test_make_admin_creates_user_outside_tree`, `test_make_admin_refuses_duplicate_login`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Полный прогон** `php artisan test` — всё PASS; `./vendor/bin/pint --test` — без замечаний.
- [ ] **Step 6: Commit** «Администратор и README».

---

## Self-Review

- **Покрытие спеки.** Иерархия, пользователи, права, ПДн, почта: Tasks 1–5. Справочники: 6. Виды отчётов, поля, файлы, правила: 7, 9, 10. Статусы и доступ: 8. Базовые отчёты: 11. Сборы, выбор узлов, блокировка: 12. Шаблоны и закрытие по расписанию: 13. Уведомления: 14. Статистика, общий экран: 15. Архив: 16. Excel: 17. Перенос: 18. Опора для модулей: 19. Первый админ: 20.
- **Допущения плана** (решены без заказчика, проверить): часовой пояс `Europe/Samara`; почта у пользователей не уникальна; `closes_at` может быть пустым только у сборов из шаблона без `close_after_days`; структура Excel: колонки «Уровень 1…N»; формат промежуточного CSV для переноса; сброс пароля по логину.
- **Единые имена.** Сбор везде `Campaign` (не `Collection`, чтобы не конфликтовать с Eloquent); `NodeSelection` и `NodeSelector` согласованы в Tasks 12 и 13.
- **Зависимости.** Tasks 1→2→(3,4,5); 7→8→9→(10,11,12); 12→(13,14,15)→16→17; 18 после 1–3; 19 после 8.
