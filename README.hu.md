# Laravel Observability

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-observability/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-observability/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-observability.svg)](https://packagist.org/packages/intragile/laravel-observability)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-observability.svg)](https://packagist.org/packages/intragile/laravel-observability)
[![License](https://img.shields.io/packagist/l/intragile/laravel-observability.svg)](LICENSE)

**Megfigyelhetőségi csomag Laravelhez, a spatie/laravel-health és a spatie/laravel-prometheus
fölé.** Lokira kész JSON-naplózás SQL-kitakarással, a Spatie-ből hiányzó health checkek, zártan
hibázó basic auth a metrikavégponton, csoportosított Slack-hibaösszesítő, és egy doctor parancs,
amely élesben végigpróbálja az egész láncot.

[English README → README.md](README.md)

A csomag a Spatie-re épül, nem váltja ki. A check-keret, az eredménytár, az értesítések, a
`/prometheus` végpont és az IP-allowlist a Spatie-é marad. A csomag a körülöttük hiányzó részeket
adja, egyetlen config fájlból köti be őket, és megmondja, mit nem tudott bekötni.

```bash
composer require intragile/laravel-observability
php artisan observability:install
php artisan observability:doctor --live
```

## Mit ad a Spatie fölé

| Terület | Amit a Spatie már tud | Amit ez a csomag hozzátesz | Amit szándékosan nem ír újra |
|---|---|---|---|
| JSON-naplózás | – | `LokiJsonFormatter`: soronként egy JSON-objektum, a `level` szóként, minden sorban `service`, `env` és fix mezők | a Monolog-kezelőt; a Lokiba küldés (HTTP push) egy szállító (Promtail, Alloy) vagy külön csomag dolga |
| SQL-kitakarás | – | a query exception üzenetéből eltűnnek a bind-értékek és az SQL-farok, a naplósorban és a hibaösszesítő táblájában is | az alkalmazás exception handlerét (ehhez hookot ad) |
| Prometheus-hitelesítés | csak IP-allowlist; üres listánál mindenkit átenged | zártan hibázó basic auth, jelszó env-ből vagy csatolt secret fájlból, időállandó összehasonlítás | a végpontot, a renderelést és az IP-allowlistet |
| Prometheus-gauge-ok | gauge- és collector-regisztráció | konténermemória-gauge-ok; a Spatie queue-collectorai egy kapcsolóval | az alkalmazásspecifikus gauge-okat |
| Lefutott migrációk | – | `SchemaCompleteCheck` | – |
| Valódi levélküldés élesben | – | `MailRelayCheck`: hibát jelez, ha az éles levelek Mailpitbe, a Mailtrap sandboxába, `log` mailerbe vagy hasonlóba mennek | – |
| Broadcast | – | `BroadcastCheck`: valódi WebSocket oda-vissza út a Reverben át | – |
| Kéréskiszolgálási idő | – | `RecordRequestTiming` middleware + `RequestTimingCheck` | – |
| Sikertelen queue-jobok | a `QueueCheck` csak azt nézi, él-e a worker | `FailedJobsCheck` a `failed_jobs` táblából | a queue-életjelet (`QueueCheck`) és a `ScheduleCheck`-et |
| Mentések frissessége | a `BackupsCheck` minden futáskor végiglistázza a mentési disket, warning szint nélkül | `BackupFreshnessCheck`: egyetlen cache-olvasás, warning- és hibaküszöb | magát a mentést és a laravel-backup saját monitorát |
| Konténermemória | – | cgroup v1/v2 olvasás checkként és gauge-ként; konténeren kívül csendben kimarad | – |
| Artisan rootként | – | élesben nem engedi rootként futtatni az Artisant | – |
| Hibaértesítés | – | csoportosított, kötegelt Slack-összesítő, amely csak sikeres küldés után jelöli elküldöttnek a hibákat | a Laravel saját Slack-értesítő csatornáját |
| Telepítés és diagnosztika | – | `observability:install` és `observability:doctor`; diagnosztikák a `laravel/doctor`-hoz | magát a `laravel/doctor` keretet |

## Követelmények

- PHP 8.3+, Laravel 12 vagy 13.
- [spatie/laravel-health](https://github.com/spatie/laravel-health) 1.37+ (függőségként települ).

### Opcionális csomagok

Ami egy opcionális csomagtól függ, az bekapcsol, ha a csomag telepítve van, és hiba nélkül kimarad,
ha nincs. Az `observability:install` és az `observability:doctor` minden kimaradt részt kiír, az
okával együtt.

| Csomag | Mit kapcsol be | Nélküle |
|---|---|---|
| `spatie/laravel-prometheus` ^1.3 | basic auth a `/prometheus` végponton, konténermemória-gauge-ok, queue-collectorok | nincs metrikavégpont; a Prometheus-sorok `skipped` állapotúak |
| `spatie/laravel-backup` ^9.4 \| ^10.0 | mentési életjel-listener és `BackupFreshnessCheck` | a check nem regisztrálódik |
| `laravel/reverb` ^1.4 **és** `phrity/websocket` ^4.0 | `BroadcastCheck` és az élő broadcast-próba | a check nem regisztrálódik |
| `laravel/doctor` ^0.1 | az observability-diagnosztikák a `php artisan doctor`-ban | ugyanezek a próbák az `observability:doctor`-ral érhetők el |

```bash
composer require spatie/laravel-prometheus spatie/laravel-backup laravel/reverb phrity/websocket laravel/doctor
```

A `spatie/laravel-prometheus` egyik kiadása sem támogatja egyszerre a PHP 8.3-at és a Laravel 13-at,
mert a Laravel 13-as kiadásai PHP 8.4-et kérnek. Ezen a kombináción a csomag nélküle fut, a
metrikasorok `skipped` állapotúak, minden más működik.

## Telepítés

```bash
composer require intragile/laravel-observability
php artisan observability:install
```

A service provider automatikusan regisztrálódik. Az `observability:install`:

1. publikálja a `config/observability.php` fájlt (`observability-config` tag);
2. publikálja a hibariport-migrációt (`observability-migrations` tag), ha a hibaösszesítő be van
   kapcsolva, és a migráció még nincs meg semmilyen időbélyeggel;
3. kiír egy készenléti táblázatot: minden komponenst `ok`, `warning` vagy `skipped` állapottal és
   az okkal;
4. kiírja a két kódrészletet, amelyet kézzel kell beilleszteni: a naplócsatornát és az exception
   hookot.

| Opció | Hatás |
|---|---|
| `--force` | felülírja a korábban publikált fájlokat |
| `--strict` | 1-es kóddal lép ki, ha a készenléti táblázatban warning van |

A készenléti táblázat csak a configot és a telepített csomagokat nézi. Hogy a lánc valóban működik,
azt a `php artisan observability:doctor --live` bizonyítja (lásd [Doctor](#doctor)).

## Strukturált naplózás Lokihoz

Vegyél fel egy csatornát a `config/logging.php`-ba, és tedd bele a stackbe:

```php
'json' => [
    'driver' => 'monolog',
    'handler' => Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => 'php://stderr'],
    'formatter' => \IntrAgile\Observability\Logging\LokiJsonFormatter::class,
],
```

Minden naplóbejegyzésből egy JSON-sor lesz. A Monolog `JsonFormatter` kimenetéhez képest:

- a `level` a szint kisbetűs neve (`error`), mert a Loki szintfelismerése a `level` mezőt olvassa,
  és szót vár; a számérték a `level_value` mezőbe kerül, a `level_name` (`ERROR`) megmarad;
- minden sorba bekerül a `service` és az `env`, utánuk a `logging.extra` fix mezői (ezek a
  törzsmezőket soha nem írják felül);
- a stack trace alapból benne van;
- az SQL-t kitakarja az üzenetből, a `context.exception`-ből és a `previous` lánc minden
  kivételéből.

| Kulcs (`observability.logging.*`) | Env | Alapérték |
|---|---|---|
| `service` | `LOG_SERVICE` | `APP_NAME`, különben `laravel` |
| `include_stacktraces` | `OBSERVABILITY_LOG_STACKTRACES` | `true` |
| `redact_sql` | – | `true` |
| `extra` | – | `[]`, például `['alias' => env('APP_ALIAS')]` |

Egy-egy csatornán a konstruktor `includeStacktraces`, `redactSql`, `service` és `extra`
argumentuma felülírja a configot, a Laravel `formatter_with` kulcsán át.

A Loki-címkéket a konténer címkéiből vedd, ne a JSON-ból: a Monologon kívüli kimenet (php-fpm,
supervisord) nem halad át ezen a formázón.

### SQL-kitakarás

A Laravel `QueryException` üzenete a teljes SQL-t tartalmazza, minden bind-értékkel behelyettesítve.
Ha így kerül a naplóba, fizetések, címek vagy jelszó-hashek jutnak olyan tárba, amelynek többnyire
nincs megőrzési határa. Az `IntrAgile\Observability\Support\SqlMessageRedactor` megtartja az
SQLSTATE kódot, a driver hibaszövegét és a kapcsolat adatait, az SQL-farkat pedig kicseréli:

```text
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'users_email_unique' (Connection: mysql, SQL: [redacted])
```

A driver saját üzenete szándékosan marad, mert nélküle a hiba értelmét veszti. Ebben lehet érték,
mint a fenti `Duplicate entry` példában.

## Health checkek

Ha az `observability.health.register` `true` (ez az alapérték), a bekapcsolt checkek automatikusan
regisztrálódnak a spatie/laravel-health-be. Egy már regisztrált nevű checket a csomag nem bánt, így
ha valamelyiket magad is regisztrálod, a boot nem törik el. Az `OBSERVABILITY_HEALTH_REGISTER=false`
beállítással magad regisztrálhatod őket; minden check fluent setterekkel is működik, például
`FailedJobsCheck::new()->windowHours(12)`.

A Spatie futtatóját a szokásos módon ütemezd, különben az eredmények elavulnak (a doctor szól érte):

```php
// routes/console.php
use Spatie\Health\Commands\RunHealthChecksCommand;

Schedule::command(RunHealthChecksCommand::class)->everyMinute();
```

Az ütemezést a csomagra is bízhatod; ilyenkor minden deploy után bemelegedési idő jár (lásd lent).

Az alábbi kulcsok az `observability.health.checks` alatt vannak, és minden checknek van `enabled`
kulcsa (alapból `true`).

| Check | Mikor jelez | Beállítások és alapértékek |
|---|---|---|
| `SchemaCompleteCheck` | egy migrációs fájl nem futott le, hiányzik a `migrations` tábla, vagy az állapot nem olvasható | `schema_complete.listed` 5 (ennyi függő migrációt nevez meg), `paths` `[]` (további migrációs könyvtárak) |
| `FailedJobsCheck` | az időablakon belül elbukott egy queue-job; a régebbieket megszámolja, de nem ítél róluk | `failed_jobs.window_hours` 24, `listed` 5. A `queue.failed.table` táblát olvassa a `queue.failed.database` kapcsolaton |
| `MailRelayCheck` | figyelt környezetben a mailer `log`/`array`/`null`, az SMTP-host üres, catcher vagy maga a gép, vagy a port catcher-port | `mail_relay.environments` `['production']`, `catcher_hosts` (mailhog, mailpit, sandbox.smtp.mailtrap.io, mailcatcher, maildev, smtp4dev), `catcher_ports` `[1025, 1080, 8025]`, `hold_config_key` `null` |
| `RequestTimingCheck` | a legutóbbi riasztás óta mért leglassabb kérés elérte a küszöböt (ms) | `request_timing.warn_ms` 500, `fail_ms` 1000, `routes` `[]`, `store` `null`, `middleware_groups` `['web', 'api']` |
| `BackupFreshnessCheck` | az utolsó sikeres mentés régebbi a küszöbnél, vagy még egy sem volt | `backup_freshness.warn_hours` 26, `fail_hours` 48, `store` `null` |
| `BroadcastCheck` | a broadcast a timeouton belül nem ér vissza a WebSocketen, vagy lassú az oda-vissza út | `broadcast.connection` `reverb`, `client_url` `OBSERVABILITY_BROADCAST_URL`, `timeout_seconds` 5.0, `warn_ms` 1000 |
| `ContainerMemoryCheck` | az anonim memóriahasználat a cgroup-limit adott százaléka fölött van | `container_memory.root` `/sys/fs/cgroup`, `warn_percent` 90, `fail_percent` 95 |

Megjegyzések az egyes checkekhez:

- **MailRelayCheck.** A catcher-hostok teljes, ponttal vagy kötőjellel határolt címkére illeszkednek,
  így a `mail.example.com` nem catcher. Csak az SMTP transportot vizsgálja; a többiről (SES,
  Postmark) feltételezi, hogy kézbesít. Ha a némaság szándékos, a `hold_config_key` mutasson egy
  config kulcsra: igaz érték esetén a hiba `ok`-ra vált, szabad szöveges értéknél az lesz az indok.
- **RequestTimingCheck.** Ha a check be van kapcsolva, a provider a felsorolt middleware-csoportokba
  teszi a `RecordRequestTiming` middleware-t. Ez `X-Response-Time` fejlécet ad a válaszhoz, a
  leglassabb kérést pedig a cache-ben tartja. Warning vagy hiba után a mérés törlődik, így a
  következő futás csak a riasztás utáni kéréseket látja. Útvonalankénti küszöb a route neve
  szerint adható meg (név nélküli route-nál az útvonal szerint):
  `'routes' => ['reports.export' => ['warn' => 3000, 'fail' => 10000]]`. Azok a kérések, amelyek
  route-csoportig sem jutnak el (például a 404-esek), nem kerülnek mérésre.
- **BackupFreshnessCheck.** Egy listener időbélyeget ír, valahányszor a spatie/laravel-backup
  `BackupWasSuccessful` eseményt küld, így egy szokásos futás egyetlen cache-olvasás. A küszöböket
  másodpercre pontosan veti össze: 26 óra 1 perc már túl van a 26 órás határon. Ha még nincs
  életjel, a mentési diskek legújabb archívuma egyszer feltölti. Tartós cache store-t használj; a
  készenléti táblázat figyelmeztet, ha az életjel-store `array` driverű.
- **BroadcastCheck.** Lásd [Broadcast oda-vissza (Reverb)](#broadcast-oda-vissza-reverb).
- **ContainerMemoryCheck.** cgroup v2-n a `memory.max` és a `memory.stat` (`anon`), v1-en ezek
  megfelelői (`rss`) alapján számol, a page cache nélkül. Ha nincs cgroup-memórialimit (például
  fejlesztői gépen), nem regisztrálódik.

### Ütemezés deploy utáni bemelegedéssel

Közvetlenül a deploy után a heartbeatek (például a Spatie queue- és schedule-checkjeié) még nincsenek
beírva, gyakran azért, mert a cache-prefix a release-szel együtt változik. Az első futások ezért egy
egészséges rendszeren is hibát jeleznek. Az `OBSERVABILITY_HEALTH_SCHEDULE=true` beállítással a
csomag maga ütemezi a `health:check`-et. A deploy első scheduler-tickjétől számítva `warmup_seconds`
ideig kihagyja az ütemezésből. Minden más ütemezett parancs közben is fut.

```dotenv
OBSERVABILITY_HEALTH_SCHEDULE=true
# Opcionális: a bemelegedést minden release-nél újraindítja akkor is, ha a storage túléli a deployt.
OBSERVABILITY_DEPLOY_ID="${GIT_COMMIT_SHA}"
```

A deploy-jelölő helye a `storage/app/observability/health-warmup.json` (`schedule.flag_path`). Ha a
konténerplatform minden deploynál újra létrehozza a storage-ot, deploy-azonosító nem kell. Ha a
jelölő nem írható, a check ennek ellenére ütemezésre kerül, így a figyelés soha nem áll le csendben. Az
`observability.health.schedule` kulcsai: `enabled` `false`, `cron` `* * * * *`, `on_one_server`
`true`, `warmup_seconds` 300, `flag_path` `null`. Bemelegedés alatt a doctor az ütemezés sorát
kihagyottként mutatja, a hátralévő másodpercekkel.

### JSON végpont deploy-kapuhoz

A Spatie `/health-json?fresh` végpontja azzal a paranccsal futtatja a checkeket, amelyik értesítést
is küld. Ha egy deploy-kapu közvetlenül az átállás után ezt pollolja, riasztások mennek ki. Az
`OBSERVABILITY_HEALTH_JSON=true` beállítással a csomag a `HealthJsonController`-t regisztrálja. Ez
ugyanígy viselkedik, de a checkeket `--no-notification`-nel futtatja. A riasztás az ütemezett
futás dolga marad.

```php
// config/observability.php
'json_endpoint' => [
    'enabled' => env('OBSERVABILITY_HEALTH_JSON', false),
    'uri' => 'health-json',
    'middleware' => ['auth.basic'],
    'name' => 'observability.health-json',
],
```

A route alapból middleware nélkül jön létre. Védd le, mert az eredmények megnevezik az
infrastruktúrádat. A Spatie `health.json_results_failure_status` és
`health.oh_dear_endpoint.always_send_fresh_results` beállítása érvényes. Ha a controllert saját
route-on használnád, hagyd kikapcsolva a végpontot, és a route-ot irányítsd az
`IntrAgile\Observability\Http\Controllers\HealthJsonController`-re.

### Értesítések

Az `IntrAgile\Observability\Health\CheckFailedNotification` a Spatie notificationjét terjeszti ki.
Csak a `health.notifications.environments` környezeteiből küld (alapból `['production']`), hacsak a
`HEALTH_NOTIFICATIONS_ENABLED` nem kapcsolja be vagy ki. A nem logikai érték, például egy elgépelés,
nem számít `false`-nak: a csomag figyelmen kívül hagyja. A cím a produkción kívül tartalmazza a
környezetet, valamint a példány nevét és a deploy-azonosítót, például
`[staging] shop-eu [deploy: 4ad9758]`. A `config/health.php`-ban így regisztráld:

```php
'notifications' => [
    'enabled' => true,
    'notifications' => [
        IntrAgile\Observability\Health\CheckFailedNotification::class => ['slack'],
    ],
    // A Spatie throttle-ja továbbra is érvényes.
    'throttle_notifications_for_minutes' => 15,
],
```

A példány neve a `health.notifications.instance` értéke (`APP_ALIAS`, ennek hiányában `APP_NAME`).

## Prometheus

`spatie/laravel-prometheus` kell hozzá. A csomag a `prometheus.middleware` lista végére, a Spatie
`AllowIps` middleware-e után fűzi az `IntrAgile\Observability\Http\Middleware\PrometheusBasicAuth`
middleware-t, így mindkettő érvényesül.

```dotenv
PROMETHEUS_USERNAME=prometheus
PROMETHEUS_PASSWORD=hosszu-veletlen-titok
# vagy Docker/Kubernetes secrettel (a záró újsor nem számít):
PROMETHEUS_PASSWORD_FILE=/run/secrets/prometheus_password
```

A middleware zártan hibázik: ha sem a `PROMETHEUS_PASSWORD`, sem egy olvasható, nem üres
`PROMETHEUS_PASSWORD_FILE` nem ad jelszót, minden kérés 401-et kap, az üres jelszavú is. A
felhasználónevet és a jelszót is `hash_equals` hasonlítja össze. Ugyanezt a hitelesítést állítsd be a
scrape jobban:

```yaml
scrape_configs:
  - job_name: laravel
    metrics_path: /prometheus
    basic_auth:
      username: prometheus
      password_file: /etc/prometheus/laravel_password
    static_configs:
      - targets: ['app.example.com']
```

| Kulcs (`observability.prometheus.*`) | Env | Alapérték |
|---|---|---|
| `basic_auth.enabled` | – | `true` |
| `basic_auth.username` | `PROMETHEUS_USERNAME` | `prometheus` |
| `basic_auth.password` | `PROMETHEUS_PASSWORD` | – |
| `basic_auth.password_file` | `PROMETHEUS_PASSWORD_FILE` | – |
| `container_memory_gauges` | – | `true` |
| `queue_collectors.enabled` | – | `false` |
| `queue_collectors.connection` | – | `null` (az alapértelmezett kapcsolat) |
| `queue_collectors.queues` | – | `['default']` |

Ha a `basic_auth.enabled` `false`, a middleware nem kerül a végpontra, és azt csak a
`prometheus.middleware` és a `prometheus.allowed_ips` védi.

**Gauge-ok.** Memórialimites konténerben a csomag regisztrálja a `container_memory_limit_bytes` és a
`container_memory_used_bytes` gauge-ot. A Spatie a `prometheus.default_namespace` előtagot teszi a név
elé, így alapból `app_container_memory_limit_bytes` és `app_container_memory_used_bytes` néven
jelennek meg. cgroup-limit nélkül nem regisztrálódnak.

**Queue-collectorok.** A `queue_collectors.enabled` a felsorolt queue-kra regisztrálja a Spatie
méret-, várakozó-, késleltetett-, lefoglalt- és legrégebbi várakozó job-collectorát.

## Hibaösszesítő

Minden reportolt kivételt eltárol, és csoportosított összesítőt küld egy Slack incoming webhookra.
Alapból ki van kapcsolva.

```dotenv
OBSERVABILITY_ERROR_DIGEST=true
OBSERVABILITY_SLACK_WEBHOOK=https://hooks.slack.com/services/...
```

1. Kapcsold be, aztán publikáld és futtasd a migrációt (az `observability:install` publikálja, ha az
   összesítő már be van kapcsolva):

   ```bash
   php artisan vendor:publish --tag=observability-migrations
   php artisan migrate
   ```

2. Kösd be a rögzítőt a kivételek reportolásába. A `bootstrap/app.php`-ban:

   ```php
   use Illuminate\Foundation\Configuration\Exceptions;
   use IntrAgile\Observability\ErrorDigest\ExceptionHook;

   ->withExceptions(function (Exceptions $exceptions) {
       ExceptionHook::register($exceptions);
   })
   ```

   Ha az alkalmazásban még klasszikus `App\Exceptions\Handler` van, ott kell bekötni:

   ```php
   use IntrAgile\Observability\ErrorDigest\ErrorRecorder;
   use Throwable;

   public function register(): void
   {
       $this->reportable(function (Throwable $e) {
           app(ErrorRecorder::class)->record($e);
       });
   }
   ```

   A hook nem állítja meg a továbbadást, a szokásos naplózás tehát folytatódik. A rögzítő soha nem
   dob kivételt: ha hiányzik a tábla vagy nem érhető el az adatbázis, warningot naplóz és visszatér,
   egy statikus őr pedig megakadályozza a rekurziót. Amíg az összesítő ki van kapcsolva, semmit nem
   ír.

3. Az ütemezés automatikus. Amíg az összesítő be van kapcsolva, a provider a `schedule` cron
   kifejezéssel, `withoutOverlapping()` és `onOneServer()` mellett ütemezi az
   `observability:error-digest` parancsot. Ehhez futnia kell az ütemezőnek (`schedule:run` vagy
   `schedule:work`).

Minden futás legfeljebb `batch_size` küldetlen sor azonosítóját foglalja le, a sorokat kivételtípus
és kitakart üzenet szerint csoportosítja, és egyetlen üzenetet küld: a `max_groups` legnagyobb
csoportot, mindegyiknél a kötegbeli és az elmúlt 24 órás darabszámmal, és egy „+N further error
type(s)” sort. Pontosan a lefoglalt sorok kapnak elküldött jelölést, **és csak azután, hogy a Slack
2xx státusszal válaszolt**. Ha a Slack nem elérhető, minden sor küldetlen marad, és a következő futás
elküldi. Cache-lock akadályozza meg, hogy két futás ugyanazokat a sorokat küldje el. A
`retention_days`-nél régebbi elküldött sorok törlődnek. Éles környezeten kívül a példánynév elé
kerül a környezet neve, például `[staging] shop`.

| Kulcs (`observability.error_digest.*`) | Env | Alapérték |
|---|---|---|
| `enabled` | `OBSERVABILITY_ERROR_DIGEST` | `false` |
| `webhook_url` | `OBSERVABILITY_SLACK_WEBHOOK` | – |
| `schedule` | – | `*/5 * * * *` |
| `instance` | `APP_ALIAS`, különben `APP_NAME` | `laravel` |
| `max_groups` | – | `10` (legfeljebb 45) |
| `batch_size` | – | `500` |
| `retention_days` | – | `30` |
| `links` | – | `[]`, például `['Grafana' => 'https://grafana.example.com']` (gombként jelenik meg) |

Az üzenetek már kitakarva kerülnek a táblába, így SQL bind-érték oda sem jut. A webhook URL-t
kezeld titokként: aki ismeri, írhat a csatornára.

## Broadcast oda-vissza (Reverb)

`laravel/reverb` és `phrity/websocket` kell hozzá. A `BroadcastCheck` nem hiszi el, hogy egy esemény
kiment: kliensként csatlakozik a Reverbhez, feliratkozik egy egyszer használatos nyilvános csatornára
(`observability-probe.<uuid>`), az alkalmazás broadcasterén át kiküld egy véletlen nonce-ot hordozó
`observability.probe` eseményt, és megvárja, hogy a nonce megérkezzen. A timeout az egész folyamatra
vonatkozik, így a leállt vagy néma Reverb hibát ad, és nem akasztja meg a health-futást.

A WebSocket URL alapból a `broadcasting.connections.reverb.options` értékeiből (host, port, scheme)
áll össze. Ha a kliensek más címen érik el a Reverbet, például reverse proxy vagy TLS-végpont
mögött, add meg a kliens-URL-t; ebből csak a scheme, a host és a port számít (a `https`-ből `wss`,
a `http`-ből `ws` lesz):

```dotenv
OBSERVABILITY_BROADCAST_URL=https://ws.example.com
```

## Root guard

Ha az Artisan rootként fut (például egy `docker exec` root shellből), a cache- és storage-fájlok a
root tulajdonába kerülnek, és a webszerver felhasználója többé nem tudja írni őket. A
`root_guard.environments` listában szereplő környezetekben (alapból `['production']`) az Artisan
nem hajlandó rootként futni, és elmagyarázza, hogyan futtasd a webszerver felhasználójaként.

| Kulcs (`observability.root_guard.*`) | Alapérték |
|---|---|
| `enabled` | `true` |
| `environments` | `['production']` |
| `allow_env` | `ARTISAN_ALLOW_ROOT` |

Szándékos, egyszeri kivételként: `ARTISAN_ALLOW_ROOT=1 php artisan <parancs>`. Rootként futó CI-ban
az `APP_ENV`-et már a `composer install` előtt állítsd nem védett értékre, mert a Composer
`package:discover` hookja is Artisan-hívás. Unit tesztek alatt a guard nem fut.

## Doctor

```bash
php artisan observability:doctor
php artisan observability:doctor --live --strict
php artisan observability:doctor --live --json --timeout=10
```

A `laravel/doctor` a konfigurációt ellenőrzi. Az `observability:doctor` a lánc minden részén átküld
valamit, és azt jelenti, ami a végén kijött. A telepítő készenléti táblázatával kezd, utána futnak a
próbák:

| Próba | Mikor fut | Mit csinál |
|---|---|---|
| Naplózás | mindig | egy query exceptiont tartalmazó szintetikus bejegyzést formáz meg az alapértelmezett log stack minden csatornáján; ellenőrzi, hogy érvényes JSON-e, van-e `level`, `service` és `env`, és ki van-e takarva az SQL-literál |
| Ütemezés | mindig | ellenőrzi, hogy a `health:check`, és bekapcsolt összesítőnél az `observability:error-digest` ütemezve van-e |
| Prometheus | mindig | processzen belüli kéréseket küld minden `prometheus.urls` útvonalra: hitelesítés nélkül, külső címről (401 vagy 403 az elvárt), majd a beállított hitelesítéssel (200 az elvárt), és megkeresi a konténermemória-gauge-ot |
| Hibaösszesítő | mindig | ellenőrzi a webhookot és a riporttáblát; `--send-test` mellett tesztüzenetet küld a Slackre |
| Health checkek | `--live` | minden regisztrált health checket egyszer lefuttat, időméréssel, így kiderül, ha egy check elszáll vagy megakad |
| Broadcast | `--live` | lefuttatja a valódi Reverb oda-vissza próbát |

| Opció | Hatás |
|---|---|
| `--live` | a hálózati hívást végző próbák is lefutnak: minden health check és a broadcast oda-vissza út |
| `--send-test` | tesztüzenetet küld a hibaösszesítő webhookjára (`--live` nélkül is működik) |
| `--json` | JSON-ként írja ki a jelentést: `entries` (component, status, message, duration_ms) és `worst` |
| `--strict` | warningnál is nem nulla kóddal lép ki |
| `--timeout=5` | hálózati hívásonként ennyi másodperc jár; az ennél lassabb health check warningot kap |

| Kilépési kód | Mikor |
|---|---|
| `0` | nincs hiba; warning megengedett, hacsak nincs `--strict` |
| `1` | legalább egy hiba, vagy warning `--strict` mellett |
| `2` | a `--timeout` nem pozitív szám |

Ha egy próba kivételt dob, abból hibás sor lesz, és a futás folytatódik, így egy elromlott komponens
nem takarja el a többit. A jelentésben a kivételüzenetekből az SQL ki van takarva.

### Deploy-kapuként

A migrációk után, a forgalom átkapcsolása előtt futtasd:

```bash
php artisan migrate --force
php artisan observability:doctor --live --strict --json > observability-report.json
```

A nem nulla kilépési kód megállítja a pipeline-t. `--live` és `--send-test` nélkül a parancs nem nyit
WebSocketet, és nem küld HTTP-kérést a processzen kívülre (a Prometheus-próba a HTTP kernelen át,
processzen belül fut), így bármilyen környezetben biztonságos. Bekapcsolt összesítőnél viszont
lekérdezi az adatbázist a riporttábla ellenőrzéséhez.

### laravel/doctor integráció

Ha a `laravel/doctor` telepítve van, az `observability` csoportjában négy diagnosztika jelenik meg:
`LoggingIsStructured`, `PrometheusIsProtected`, `HealthChecksPass` és `ErrorDigestIsDeliverable`.

```bash
php artisan doctor --only=observability
php artisan doctor --only=observability --format=json
```

Ott csak a processzen belüli próbák futnak: nem nyílik WebSocket-kapcsolat, és nem megy ki
Slack-üzenet. A `HealthChecksPass` nem futtatja a checkeket. Azt jelenti, mely checkek vannak
regisztrálva és ütemezve, és a Spatie result store-jából kiolvassa a legutóbbi tárolt futást. Bukott vagy
összeomlott check esetén hibát ad. Figyelmeztet, ha nincs tárolt futás, vagy ha a legutóbbi régebbi a
`health.stored_results.stale_minutes` értékénél (alapból 10). Az élő próbákhoz az
`observability:doctor --live` való.

## Mi marad ki, ha nincs telepítve

| Ha hiányzik | Kimarad | Továbbra is működik |
|---|---|---|
| `spatie/laravel-prometheus` | a basic auth middleware, a memória-gauge-ok, a queue-collectorok, a Prometheus-próba | minden más, a `ContainerMemoryCheck` is |
| `spatie/laravel-backup` | az életjel-listener, a `BackupFreshnessCheck` | minden más |
| `laravel/reverb` vagy `phrity/websocket` | a `BroadcastCheck`, a broadcast-próba | minden más |
| `laravel/doctor` | a `doctor --only=observability` diagnosztikák | az `observability:doctor`, ugyanazokkal a próbákkal |
| cgroup-memórialimit | a `ContainerMemoryCheck`, a memória-gauge-ok | – |

Az alkalmazás mindegyik esetben változatlanul elindul; a készenléti táblázat és a doctor-jelentés
egy `skipped` sorban mutatja az okot.

## Konfiguráció

```bash
php artisan vendor:publish --tag=observability-config
```

| Szakasz | Hol van leírva |
|---|---|
| `logging` | [Strukturált naplózás Lokihoz](#strukturált-naplózás-lokihoz) |
| `root_guard` | [Root guard](#root-guard) |
| `deploy_id` | [Ütemezés deploy utáni bemelegedéssel](#ütemezés-deploy-utáni-bemelegedéssel), [Értesítések](#értesítések) |
| `health.register`, `health.checks.*` | [Health checkek](#health-checkek) |
| `health.schedule` | [Ütemezés deploy utáni bemelegedéssel](#ütemezés-deploy-utáni-bemelegedéssel) |
| `health.json_endpoint` | [JSON végpont deploy-kapuhoz](#json-végpont-deploy-kapuhoz) |
| `health.notifications` | [Értesítések](#értesítések) |
| `health.stored_results` | [laravel/doctor integráció](#laraveldoctor-integráció) |
| `prometheus` | [Prometheus](#prometheus) |
| `error_digest` | [Hibaösszesítő](#hibaösszesítő) |

## Tesztelés

```bash
composer test
OBSERVABILITY_REVERB_TEST=1 composer test   # a valódi Reverb-szerver elleni oda-vissza próbával együtt
```

## Licenc

MIT, lásd [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
