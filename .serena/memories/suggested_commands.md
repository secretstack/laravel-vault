# Suggested Commands

All PHP commands run inside the `php8.3` container. Prefix: `docker exec -i -w /var/www/html/ibid/laravel-vault php8.3`

## Install dependencies
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 composer install
```

## Run full test suite
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit
```

## Run a specific test suite
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit --testsuite=Unit
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit --testsuite=Feature
```

## Run a single test / filter
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit --filter ClassName::methodName
```

## Syntax-check a file
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 php -l src/Path/To/File.php
```

## Coverage report
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit --coverage-text
```
