# Как работает система сбора отчётности

Кабинет на Laravel и Filament связывает пользователей, образовательные организации и отчёты. Организации вносят данные, вышестоящие органы отслеживают заполнение и обрабатывают отчёты в пределах своих прав.

## Работа в кабинете

1. Войдите по логину. При первом входе пройдите согласие на обработку персональных данных и подтверждение почты, если кабинет направит на эти шаги. Профиль доступен из меню пользователя.
2. В разделах «Организации» и «Пользователи» настройте структуру и учетные записи, если у вашей роли есть доступ. Дерево допускает вложенные органы управления, организации под органами управления и филиалы под организациями. Привязка пользователя к узлу определяет область видимости.
3. В «Видах отчётов» настройте базовый отчёт и назначьте его организациям. Базовый отчёт существует вне сбора. Автоматическое назначение распространяется на существующие и новые организации. Новый обязательный базовый отчёт может блокировать редактирование отчётов по сборам.
4. Для разового сбора выберите вид отчёта, даты и организации. Для повторяющихся сборов используйте шаблон, по которому планировщик создаёт отдельные сборы. При запуске сбора создаются экземпляры отчёта для выбранных узлов.
5. Пользователь организации открывает «Назначенные отчёты», заполняет форму и сохраняет данные. Первое сохранение переводит новый отчёт в «В работе». В этом же разделе видны базовые отчёты и отчёты активных сборов.
6. Проверяющий открывает «Статистику сборов», переходит от сводки к узлу и к конкретному отчёту. Доступные действия зависят от роли, положения в дереве, статуса и состояния сбора. Отчёт можно принять, вернуть на доработку или заблокировать при наличии соответствующих прав. Закрытие сбора прекращает редактирование его отчётов.

## Модель данных

| Сущность | Назначение |
| --- | --- |
| [Node](../app/Models/Node.php) и [User](../app/Models/User.php) | Дерево подразделений, учётные записи и привязка к узлу. |
| [ReportType](../app/Models/ReportType.php) | Зарегистрированный вид отчёта, его ключ, настройки и снимок структуры. Поля формы описаны в PHP-классе вида. |
| [CampaignTemplate](../app/Models/CampaignTemplate.php) и [Campaign](../app/Models/Campaign.php) | Правила периодического запуска и отдельный сбор с датами и автором. |
| [Report](../app/Models/Report.php) | Экземпляр отчёта для узла. Хранит вид, статус и связь со сбором. У базового отчёта связь со сбором отсутствует. |
| [Dictionary](../app/Models/Dictionary.php) и [DictionaryItem](../app/Models/DictionaryItem.php) | Справочники и их элементы для полей выбора. |

Заполненные поля хранятся в таблицах конкретных видов отчёта по `report_id`. Простой формат хранит одну строку, списочный — набор строк, сложный определяет собственную структуру. Схема БД описана в [миграциях](../database/migrations).

## Карта кода для ревью

| Участок | С каких файлов начать |
| --- | --- |
| Точки входа | [AppPanelProvider.php](../app/Providers/Filament/AppPanelProvider.php) регистрирует кабинет, страницы, ресурсы и middleware. [routes/web.php](../routes/web.php) задаёт перенаправление с главной, подтверждение почты, выдачу вложений и подключает маршруты сложных отчётов. |
| Вход и профиль | [Pages/Auth](../app/Filament/Pages/Auth) отвечает за вход и сброс пароля по логину. [Profile.php](../app/Filament/Pages/Profile.php) обрабатывает смену почты, [VerifyEmailController.php](../app/Http/Controllers/VerifyEmailController.php) подтверждает адрес. [Onboarding.php](../app/Filament/Pages/Onboarding.php) показывает шаги первого входа, [EnsureOnboardingComplete.php](../app/Http/Middleware/EnsureOnboardingComplete.php) проверяет их прохождение. |
| Дерево и пользователи | [Resources/Nodes](../app/Filament/Resources/Nodes) и [Resources/Users](../app/Filament/Resources/Users) реализуют экраны управления. [NodeService.php](../app/Services/NodeService.php) меняет дерево с проверкой допустимости. [NodeType.php](../app/Enums/NodeType.php) задаёт допустимых родителей, [NodePolicy.php](../app/Policies/NodePolicy.php) и [UserPolicy.php](../app/Policies/UserPolicy.php) проверяют права. |
| Виды, поля и справочники | [config/reports.php](../config/reports.php) перечисляет определения. [ReportRegistry.php](../app/Reports/ReportRegistry.php) разрешает определение по ключу и проверяет совместимость изменений. [SyncReports.php](../app/Console/Commands/SyncReports.php) синхронизирует определения с БД. [Field.php](../app/Reports/Field.php) задаёт свойства и правила полей, [ReportDefinition.php](../app/Reports/ReportDefinition.php) валидирует данные, [ReportFormSchema.php](../app/Reports/ReportFormSchema.php) превращает определения полей в компоненты формы. Справочники редактируются через [Resources/Dictionaries](../app/Filament/Resources/Dictionaries). |
| Назначение базовых отчётов | [Resources/ReportTypes](../app/Filament/Resources/ReportTypes) предоставляет настройки. [BaseReportAssignment.php](../app/Services/BaseReportAssignment.php) назначает экземпляры, [CreateAutoBaseReports.php](../app/Listeners/CreateAutoBaseReports.php) реагирует на создание узла, [BaseReportGate.php](../app/Services/BaseReportGate.php) проверяет наличие нового обязательного отчёта. |
| Запуск и закрытие сборов | [Resources/Campaigns](../app/Filament/Resources/Campaigns) и [Resources/CampaignTemplates](../app/Filament/Resources/CampaignTemplates) содержат экраны. [NodeSelector.php](../app/Services/NodeSelector.php) выбирает получателей, [CampaignService.php](../app/Services/CampaignService.php) создаёт сбор и отчёты, добавляет узлы, блокирует отчёты и закрывает сбор. [CampaignTemplateService.php](../app/Services/CampaignTemplateService.php) сохраняет шаблоны, [ScheduleCalculator.php](../app/Services/ScheduleCalculator.php) рассчитывает следующий запуск. |
| Заполнение простых и списочных отчётов | [BaseReports.php](../app/Filament/Pages/BaseReports.php) и [CampaignReports.php](../app/Filament/Widgets/CampaignReports.php) показывают назначения. [ReportForm.php](../app/Filament/Pages/ReportForm.php) открывает и сохраняет форму через [ReportStore.php](../app/Services/ReportStore.php). [ReportFiles.php](../app/Services/ReportFiles.php) сохраняет вложения, [ReportFileController.php](../app/Http/Controllers/ReportFileController.php) выдаёт их после проверки доступа. |
| Сложный отчёт | [DemoComplex.php](../app/Reports/Demo/DemoComplex.php) регистрирует собственные маршруты. [DemoComplexController.php](../app/Http/Controllers/DemoComplexController.php), [demo-complex.blade.php](../resources/views/reports/demo-complex.blade.php) и [ComplexReportStore.php](../app/Services/ComplexReportStore.php) реализуют его HTTP-вход, форму и сохранение. |
| Статусы и права | [ReportStatus.php](../app/Enums/ReportStatus.php) задаёт допустимые переходы. [ReportPolicy.php](../app/Policies/ReportPolicy.php) определяет, кто может читать, редактировать и менять статус. [ReportStatusService.php](../app/Services/ReportStatusService.php) проводит явные переходы в транзакции и публикует событие при возврате. |
| Статистика | [CampaignsOverview.php](../app/Filament/Pages/CampaignsOverview.php), [CampaignStats.php](../app/Filament/Pages/CampaignStats.php) и [CampaignReportsList.php](../app/Filament/Pages/CampaignReportsList.php) ведут от списка сборов к сводке и списку отчётов узла. [StatisticsAccess.php](../app/Services/StatisticsAccess.php) задаёт область видимости, [StatusStats.php](../app/Services/StatusStats.php) считает отчёты по статусам. |
| Фоновые задачи | [routes/console.php](../routes/console.php) задаёт ежедневный запуск, закрытие и напоминания. Обработчики находятся в [Console/Commands](../app/Console/Commands). [DeadlineReminders.php](../app/Services/DeadlineReminders.php) отправляет напоминания, [ReportRecipients.php](../app/Services/ReportRecipients.php) находит адресатов, [Listeners](../app/Listeners) и [Notifications](../app/Notifications) связывают события с уведомлениями. |
| Разметка | [resources/views/filament](../resources/views/filament) содержит Blade-шаблоны для отдельных страниц и ресурсов кабинета. Поля, колонки и действия Filament часто заданы прямо в классах страниц или ресурсов. |

## Порядок чтения и тесты

1. Проследите создание сбора от `CreateCampaign` в ресурсе до `CampaignService`. Сопоставьте выбор получателей, создание экземпляров, добавление узла и закрытие с [CampaignServiceTest.php](../tests/Feature/CampaignServiceTest.php) и [SpawnCampaignsTest.php](../tests/Feature/SpawnCampaignsTest.php).
2. Проследите заполнение от `ReportForm` до `ReportStore`. Затем сравните простой и списочный форматы с отдельной реализацией сложного отчёта. См. [ReportStoreTest.php](../tests/Feature/ReportStoreTest.php), [ReportFilesTest.php](../tests/Feature/ReportFilesTest.php) и [ComplexReportTest.php](../tests/Feature/ComplexReportTest.php).
3. Сверьте права показа, записи и переходов в `ReportPolicy` и `ReportStatusService`. Затем проверьте ограничения области видимости в сводках. См. [ReportPolicyTest.php](../tests/Feature/ReportPolicyTest.php), [ReportStatusTest.php](../tests/Feature/ReportStatusTest.php) и [StatisticsTest.php](../tests/Feature/StatisticsTest.php).
4. Для сценариев входа и администрирования начните с [OnboardingTest.php](../tests/Feature/OnboardingTest.php), [PasswordResetTest.php](../tests/Feature/PasswordResetTest.php), [ProfileTest.php](../tests/Feature/ProfileTest.php) и тестов [Admin](../tests/Feature/Admin).

## Локальный запуск

На рабочей копии с уже созданными контейнерами команда `vendor/bin/sail start` запускает их повторно. Для первого запуска и установки зависимостей см. [README.md](../README.md). Статус схемы можно проверить командой `vendor/bin/sail artisan migrate:status --no-interaction`.

Для демонстрации трёх форм есть команда `vendor/bin/sail artisan reports:demo --no-interaction`. Ей нужен действующий администратор. Команда создаёт демонстрационные данные и выводит адреса форм. Шаги добавления своего вида описаны в [add_report.md](../add_report.md).

Очередь писем обрабатывает `vendor/bin/sail artisan queue:work --tries=3 --timeout=60`. При `MAIL_MAILER=log` письма записываются в `storage/logs/laravel.log`. Для локальной работы ежедневных задач можно запустить `vendor/bin/sail artisan schedule:work`. Старт веб-контейнера сам по себе эти процессы не запускает.
