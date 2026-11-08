# mon2

Система сбора данных с образовательных организаций (Laravel 12 + Filament 4).

## Запуск на чистом клоне (Laravel Sail)

Требуется Docker с compose. PHP и Composer на хосте не нужны, а расширения вроде ext-gd на хосте
не требуются (зависимости ставятся в контейнере).

```
cp .env.example .env
# в .env выставить WWWUSER и WWWGROUP равными своим id: `id -u` и `id -g`

# первичная установка зависимостей (до этого ./vendor/bin/sail ещё не существует)
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php83-composer:latest composer install --ignore-platform-reqs

./vendor/bin/sail up -d          # первый запуск собирает образ, это долго
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail test
```

- Приложение: http://localhost, кабинет: http://localhost/cabinet/login
- phpMyAdmin: http://localhost:8080 (логин `sail`, пароль `password`; root тоже с паролем `password`)
- БД разработки `edu`, тестовая `testing`; хост БД внутри сети Sail - `mariadb`, снаружи 127.0.0.1:3306.

Все команды `artisan`, `composer` и тесты выполняются только внутри Sail
(`./vendor/bin/sail artisan ...`, `./vendor/bin/sail composer ...`, `./vendor/bin/sail test`):
на хосте нет нужных расширений PHP, а хост БД `mariadb` виден только из сети контейнеров.
