# Профиль и управление пользователями — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Выполнить задачи 4–5: профиль, сброс пароля по логину и интерфейсы организаций и пользователей.

**Architecture:** Стандартные страницы и ресурсы Filament 4, существующие NodeService и политики. Password broker Laravel с репозиторием токенов, ключ которого включает ID пользователя и подтверждённый адрес; доставка письма остаётся на реальную почту. Профиль использует существующее подтверждение pending_email.

**Tech Stack:** Laravel 12, Filament 4, Pest 3, MariaDB, Laravel Boost, Sail.

**Spec:** `docs/superpowers/specs/2026-11-10-profile-and-user-management-design.md`

## Global Constraints

- Только задачи 4–5, без новых зависимостей, перемещения/удаления узлов и обязательной смены временного пароля.
- Русский интерфейс, стандартные формы Filament.
- Доступ только в своей ветке, права меняет только администратор; проверка на сервере.
- Старый адрес и доступ действуют до подтверждения нового. Почта не уникальна.
- Временный пароль показывается создателю один раз, хранится только хеш, не записывается в журнал/flash-уведомления.
- Sail и Boost; текущий локальный PHP 8.5, целевой Dockerfile 8.3.

## Review Focus

1. Общая почта: токен одного пользователя не подходит другому; параллельные запросы не перезаписывают токены соседнего аккаунта.
2. Сброс после изменения/снятия подтверждения почты или блокировки не даёт изменить пароль.
3. Подмена node_id и флагов в формах/прямых Livewire-запросах не расширяет доступ.
4. Редактирование пользователя не меняет пароль, подтверждение почты, согласие и блокировку через произвольные поля.
5. Временный пароль отсутствует при повторном открытии страницы и в серверных уведомлениях.

## Task 1: Восстановление доступа (часть задачи 4)

**Files:** `app/Auth/UserPasswordTokenRepository.php`, `app/Auth/UserPasswordBrokerManager.php`, `app/Notifications/ResetPasswordNotification.php`, `app/Filament/Pages/Auth/RequestPasswordReset.php`, `ResetPassword.php`; изменения `User.php`, `AppServiceProvider.php`, `AppPanelProvider.php`; тест `tests/Feature/PasswordResetTest.php`.

**Interfaces:** `User::getEmailForPasswordReset(): ?string` возвращает только подтверждённую почту. `UserPasswordTokenRepository` расширяет штатный DatabaseTokenRepository; ключ — sha256(ID + подтверждённый адрес). `UserPasswordBrokerManager::createTokenRepository(array $config)` подключает этот репозиторий к штатному Password broker. Страницы расширяют базовые страницы Filament; request принимает login, reset — защищённые login/token и новый пароль с подтверждением.

- [x] Написать тесты: подтверждённый адрес получает письмо; неизвестный логин/неподтверждённая почта/блокировка дают одинаковое уведомление без письма; общий адрес не смешивает токены; неверный, просроченный и использованный токены запрещены; смена адреса/блокировка после запроса запрещает сброс.
- [x] Выполнить `vendor/bin/sail artisan test --compact tests/Feature/PasswordResetTest.php`, ожидать FAIL из-за отсутствия реализации.
- [x] Реализовать классы и зарегистрировать страницы в панели; подписанный URL содержит login/token, письма на русском; успешный сброс обновляет пароль и remember_token, вызывает PasswordReset.
- [x] Повторить тесты, ожидать PASS.

## Task 2: Профиль (завершение задачи 4)

**Files:** `app/Filament/Pages/Profile.php`, изменение `AppPanelProvider.php`; тест `tests/Feature/ProfileTest.php`.

**Interfaces:** `Profile extends Filament\Auth\Pages\EditProfile`; форма name/email/password/passwordConfirmation/currentPassword. `handleRecordUpdate(Model $record, array $data): Model` сохраняет только разрешённые поля; новый адрес в pending_email, письмо VerifyEmailNotification на новый адрес. Текущую сессию после смены пароля сохраняет штатный Filament.

- [x] Написать тесты: имя сохраняется; неверный текущий пароль запрещает смену; правильный меняет пароль и сохраняет сессию; новый адрес не заменяет прежний до перехода по ссылке; общая почта разрешена; произвольные флаги/почтовая верификация/узел не меняются.
- [x] Запустить тест файла — FAIL; реализовать профиль с полями Filament и серверным allow-list; повторить — PASS.
- [x] Прогнать весь набор, Pint только изменённых файлов; коммит «Сброс пароля и профиль».

## Task 3: Просмотр и создание узлов (часть задачи 5)

**Files:** `app/Filament/Resources/Nodes/NodeResource.php`, `Nodes/Pages/ListNodes.php`, `CreateNode.php`; дополнить `NodePolicy.php`; тест `tests/Feature/Admin/NodeResourceTest.php`.

**Interfaces:** `NodeResource::getEloquentQuery(): Builder` ограничивает ветку. `CreateNode::handleRecordCreation(array $data): Model` проверяет выбранного родителя через NodePolicy::create и вызывает NodeService::create. В политике viewAny/view для собственной ветки, update/delete запрещены. Таблица name/type/parent, поиск и фильтры parent/type.

- [x] Написать тесты видимости ветки и фильтров, запрета без can_manage_structure, создания корректного узла с path/depth, отказа для чужого родителя/неверного типа, создания корня только администратором, отсутствия редактирования/удаления.
- [x] Запустить файл — FAIL; реализовать ресурс, страницы и политику; повторить — PASS.

## Task 4: Управление пользователями (завершение задачи 5)

**Files:** `app/Filament/Resources/Users/UserResource.php`, `Users/Pages/ListUsers.php`, `CreateUser.php`, `EditUser.php`, `resources/views/filament/resources/users/create-user.blade.php`; адаптировать `UserPolicy.php`; тест `tests/Feature/Admin/UserResourceTest.php`.

**Interfaces:** UserResource query через inBranchOf, формы login/name/node_id и три флага только для администратора. Для coarse create policy параметр Node становится необязательным; перед записью проверка с конкретным узлом. `UserResource::persistUser(array $data, ?User $record = null, ?string $password = null): User` — серверная авторизация и allow-list, узел проверяется повторно на сохранении, обычный пользователь привязан к узлу, администратор вне дерева. Пароль генерирует CreateUser, передаёт в persistUser защищённым отдельным аргументом; нет поля password формы. Блокировка/разблокировка — отдельные действия с UserPolicy::block; удаление — штатный DeleteAction с подтверждением.

- [x] Написать тесты списка нижестоящих, 404 для чужого редактирования, создания пользователя с хешем и пустым онбордингом, недопустимого node_id, флагов администратора и поддельных флагов обычного пользователя, сохранения имени без смены пароля/онбординга, блокировки/разблокировки/удаления и запрета себе/равному уровню.
- [x] Проверить однократное отображение: серверный response эффекта содержит пароль, хеш соответствует; пароль отсутствует в snapshot, уведомлениях и новом открытии страницы. Использовать защищённый Alpine-модальный диалог через эффект JS без сохранения секрета в public Livewire state.
- [x] Запустить файл — FAIL; реализовать ресурс и страницы; повторить — PASS.
- [x] Полный набор тестов, Pint изменённых файлов, независимое ревью всей реализации; исправить существенные замечания через RED→GREEN.
- [x] Отметить задачи 4–5 в исходном плане, коммит «Ресурсы узлов и пользователей». Не сливать и не публиковать ветку без запроса.

## Self-review

Все пункты дизайна сопоставлены задачам; общая почта/токены — Task 1, профиль — Task 2, дерево — Task 3, управление и секрет — Task 4. Задачи 1–2 используют подтверждение из задачи 3 основного плана; Task 4 использует NodePolicy/UserPolicy и NodeService из прежних задач. Новых зависимостей нет. Исполнение последовательно в существующей feature-ветке, сохраняющей Docker mount.

**Уточнение по ревью:** все ветки запроса сброса проходят Timebox; доставка ResetPasswordNotification выполняется через очередь с зашифрованным payload. Для локальной доставки нужен `vendor/bin/sail artisan queue:work --tries=3 --timeout=60`; обработчик запущен в контейнере.

**Результат:** 92 теста / 508 проверок — PASS; Pint всех изменённых PHP-файлов — PASS. Независимое ревью выполнено; проблема времени ответа исправлена через RED→GREEN. Дополнительные тесты проверили актуальность состояния аккаунта при обработке очереди, поддельные действия и заблокированную сессию.
