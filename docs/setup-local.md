# Setup locale per SpaceGame

## Prerequisiti

- **PHP ≥ 8.1** con estensioni: `pdo_mysql`, `json`, `mbstring`
- **MySQL ≥ 5.7** o **MariaDB ≥ 10.3**
- **Composer** (per PHPUnit in sviluppo)
- **Node ≥ 18** (solo per test JS)

### Verifica rapida

```bash
php -v
php -m | grep -E "pdo_mysql|json|mbstring"
mysql --version
composer --version
node --version
```

---

## Installazione su Windows (XAMPP)

1. Scarica **XAMPP** per Windows da https://www.apachefriends.org/ (versione PHP 8.2+)
2. Installa XAMPP (es. in `D:\xampp`)
3. Avvia **Apache** e **MySQL** dal pannello di controllo XAMPP
4. Aggiungi `D:\xampp\php` al `PATH` di sistema (per usare `php` e `composer` da terminale)
5. Verifica: `php -v` e `mysql --version`

### Creare i database

Apri phpMyAdmin (http://localhost/phpmyadmin) oppure usa la CLI:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE spacegame CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE spacegame_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'spacegame'@'localhost' IDENTIFIED BY 'spacegame';
GRANT ALL PRIVILEGES ON spacegame.* TO 'spacegame'@'localhost';
GRANT ALL PRIVILEGES ON spacegame_test.* TO 'spacegame'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## Configurazione progetto

```bash
cd D:\CweAgent\projects\SpaceGame

# Copia config di esempio
copy config.local.example.php config.local.php
```

Modifica `config.local.php` con le tue credenziali DB:

```php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'spacegame',
        'user' => 'spacegame',
        'password' => 'spacegame',
        'charset' => 'utf8mb4',
    ],
    'db_test' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'spacegame_test',
        'user' => 'spacegame',
        'password' => 'spacegame',
        'charset' => 'utf8mb4',
    ],
    'cron' => [
        'key' => 'LA_TUA_CHIAVE_SEGRETA_LUNGA_E_CASUALE',
    ],
];
```

---

## Installazione dipendenze e test

```bash
composer install
vendor/bin/phpunit
```

Dovresti vedere i test passare (almeno `BootstrapTest`).

---

## Server di sviluppo

Per testare l'interfaccia web:

```bash
cd D:\CweAgent\projects\SpaceGame\public
php -S localhost:8000
```

Apri http://localhost:8000 nel browser.

---

## Risoluzione problemi

| Problema | Soluzione |
|---|---|
| `php` non trovato | Aggiungi `D:\xampp\php` al PATH |
| `pdo_mysql` mancante | In `php.ini` decommenta `extension=pdo_mysql` |
| `composer` non trovato | Scarica da https://getcomposer.org/ |
| DB connection refused | Verifica che MySQL sia avviato in XAMPP |
| Test falliscono su timezone | Verifica `date.timezone = UTC` in `php.ini` |