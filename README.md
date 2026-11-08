# mon2

Система сбора данных с образовательных организаций (Laravel 12 + Filament 4).

## Запуск (Laravel Sail)

```
cp .env.example .env            # задать WWWUSER/WWWGROUP = id и gid хост-пользователя
composer install                # либо через контейнер sail
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail test
```

- Приложение: http://localhost (кабинет: http://localhost/cabinet/login)
- phpMyAdmin: http://localhost:8080 (пользователь `sail`, пароль `password`)
- БД разработки `edu`, тестовая `testing`.
