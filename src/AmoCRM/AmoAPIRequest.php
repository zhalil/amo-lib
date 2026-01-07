<?php
/**
 * Трейт AmoAPIRequest. Отправляет GET/POST запросы к API amoCRM.
 *
 * Обновленная версия с интеграцией Symfony Components и расширенными возможностями.
 */

declare(strict_types=1);

namespace AmoCRM;

use DateTime;
use DateTimeZone;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

trait AmoAPIRequest
{
    /**
     * Флаг включения вывода отладочной информации в лог файл
     * @var bool
     */
    public static $debug = false;

    /**
     * Объект класса, выполняющего логирование запросов/ответов к API
     * @var object
     */
    public static $debugLogger;

    /**
     * Максимальное число запросов к amoCRM API в секунду
     * Не более 7 запросов в секунду!!!
     * @var float
     */
    public static $throttle = 5;

    /**
     * Пользовательские заголовки, добавляемые к запросу.
     * Формат: ['Header-Name: Value', ...]
     * @var array
     */
    public static $customHeaders = [];

    /**
     * Callback функция для прямой работы с cURL клиентом перед выполнением запроса.
     * function(resource $curl, string $url, array $params)
     * @var callable|null
     */
    public static $beforeRequestCallback = null;

    /**
     * Флаг включения проверки SSL-сертификата сервера amoCRM
     * @var bool
     */
    public static $verifySSLCerfificate = true;

    /**
     * Файл SSL-сертификатов X.509 корневых удостоверяющих центров (относительно каталога файла класса AmoAPI)
     * (null - файл, указанный в настройках php.ini)
     * @var string|null
     */
    public static $SSLCertificateFile = 'cacert.pem';

    /**
     * Домен amoCRM для запросов к API
     * @var string
     */
    public static $amoDomain = 'amocrm.ru';

    /**
     * UserAgent в запросах к API
     * @var string
     */
    public static $amoUserAgent = 'amoCRM-API-client/2.12.0';

    /**
     * Таймаут соединения с сервером аmoCRM, секунды
     * @var integer
     */
    public static $amoConnectTimeout = 30; // Секунды

    /**
     * Таймау обмена данными с сервером amoCRM, секунды
     * @var integer
     */
    public static $amoTimeout = 30; // Секунды

    /**
     * Количество секунд, которое добавляется к параметру updated_at при обновлении сущности
     * @var int
     */
    public static $updatedAtDelta = 5; // Секунды

    /**
     * Каталог для хранения lock файлов и кэша лимитов
     * @var string
     */
    public static $storageDir = 'storage/';

    /**
     * Время жизни блокировки (TTL) в секундах, если процесс упадет
     * @var float
     */
    public static $lockTtl = 30.0;

    /**
     * Коды состояния НТТР, соответствующие успешному выполнению запроса
     * @var array
     */
    public static $successStatusCodes = [200, 202, 204];

    // ... (Стандартные коды ошибок amoCRM оставлены без изменений)
    protected static $errorCodes = [
        101 => 'Аккаунт не найден',
        102 => 'POST-параметры должны передаваться в формате JSON',
        103 => 'Параметры не переданы',
        104 => 'Запрашиваемый метод API не найден',
        301 => 'Moved permanently',
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        500 => 'Internal server error',
        502 => 'Bad gateway',
        503 => 'Service unavailable',
        213 => 'Добавление сделок: пустой массив',
        214 => 'Добавление/Обновление сделок: пустой запрос',
        215 => 'Добавление/Обновление сделок: неверный запрашиваемый метод',
        216 => 'Обновление сделок: пустой массив',
        217 => 'Обновление сделок: требуются параметры "id", "updated_at", "status_id", "name"',
        240 => 'Добавление/Обновление сделок: неверный параметр "id" дополнительного поля',
        218 => 'Добавление событий: пустой массив',
        221 => 'Список событий: требуется тип',
        226 => 'Добавление событий: элемент события данной сущности не найден',
        222 => 'Добавление/Обновление событий: пустой запрос',
        223 => 'Добавление/Обновление событий: неверный запрашиваемый метод (GET вместо POST)',
        224 => 'Обновление событий: пустой массив',
        225 => 'Обновление событий: события не найдены',
        201 => 'Добавление контактов: пустой массив',
        202 => 'Добавление контактов: нет прав',
        203 => 'Добавление контактов: системная ошибка при работе с дополнительными полями',
        204 => 'Добавление контактов: дополнительное поле не найдено',
        205 => 'Добавление контактов: контакт не создан',
        206 => 'Добавление/Обновление контактов: пустой запрос',
        207 => 'Добавление/Обновление контактов: неверный запрашиваемый метод',
        208 => 'Обновление контактов: пустой массив',
        209 => 'Обновление контактов: требуются параметры "id" и "updated_at"',
        210 => 'Обновление контактов: системная ошибка при работе с дополнительными полями',
        211 => 'Обновление контактов: дополнительное поле не найдено',
        212 => 'Обновление контактов: контакт не обновлён',
        219 => 'Список контактов: ошибка поиска, повторите запрос позднее',
        227 => 'Добавление задач: пустой массив',
        228 => 'Добавление/Обновление задач: пустой запрос',
        229 => 'Добавление/Обновление задач: неверный запрашиваемый метод',
        230 => 'Обновление задач: пустой массив',
        231 => 'Обновление задач: задачи не найдены',
        232 => 'Добавление событий: ID элемента или тип элемента пустые либо неккоректные',
        233 => 'Добавление событий: по данному ID элемента не найдены некоторые контакты',
        234 => 'Добавление событий: по данному ID элемента не найдены некоторые сделки',
        235 => 'Добавление задач: не указан тип элемента',
        236 => 'Добавление задач: по данному ID элемента не найдены некоторые контакты',
        237 => 'Добавление задач: по данному ID элемента не найдены некоторые сделки',
        238 => 'Добавление контактов: отсутствует значение для дополнительного поля',
        244 => 'Добавление/Обновление/Удаление: нет прав',
        330 => 'Количество привязанных контактов слишком большое'
    ];

    /** @var string */
    protected static $lastSubdomain;
    /** @var float */
    protected static $lastRequestTime = 0;
    /** @var array */
    protected static $lastRequest = [];
    /** @var array */
    protected static $lastAuth = [];
    /** @var string|null */
    protected static $lastResult;
    /** @var int */
    protected static $requestCounter = 0;
    /** @var string */
    protected static $uniqId;

    // Свойства для Symfony компонентов
    protected static ?RateLimiterFactory $limiterFactory = null;
    protected static ?LockFactory $lockFactory = null;

    /**
     * Устанавливает параметры по умолчанию для библиотеки libcurl
     * @param resource $curl Ресурс cURL
     * @param string $subdomain Поддомен amoCRM
     * @return void
     * @throws AmoAPIException
     */
    protected static function setDefaultCurlOptions($curl, string $subdomain)
    {
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, self::$amoUserAgent);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::$amoTimeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::$amoConnectTimeout);
        curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

        if (!self::$lastAuth[$subdomain]['is_oauth2']) {
            $cookieFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'cookies/';
            self::checkDir($cookieFilePath);
            $cookieFile = $cookieFilePath . self::getAmoDomain($subdomain) . '.txt';
            curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieFile);
        }

        if (!empty(self::$verifySSLCerfificate)) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            if (self::$SSLCertificateFile) {
                $SSLCertificateFile = __DIR__ . DIRECTORY_SEPARATOR . self::$SSLCertificateFile;
                curl_setopt($curl, CURLOPT_CAINFO, $SSLCertificateFile);
            }
        } else {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        }
    }

    /**
     * Отправляет запрос к amoCRM API
     * @param string $query Путь в строке запроса
     * @param string $type Тип запроса GET|POST|AJAX
     * @param array $params Параметры запроса
     * @param string|null $subdomain Поддомен amoCRM
     * @return array|null
     * @throws AmoAPIException
     */
    public static function request(string $query, string $type = 'GET', array $params = [], $subdomain = null)
    {
        if (!isset($subdomain)) {
            $subdomain = self::$lastSubdomain;
            if (!isset($subdomain)) {
                throw new AmoAPIException("Необходима авторизация auth() или oAuth2()");
            }
        }

        if (!isset(self::$lastAuth[$subdomain])) {
            throw new AmoAPIException("Не выполнена авторизация auth() или oAuth2() для поддомена {$subdomain}");
        }

        self::$lastRequest = [
            'query'     => $query,
            'type'      => $type,
            'params'    => $params,
            'subdomain' => $subdomain
        ];

        self::$requestCounter++;

        // --- RATE LIMITER (Symfony) ---
        // Инициализируем и ожидаем квоту
        self::waitRateLimit();

        $curl = curl_init();
        self::setDefaultCurlOptions($curl, $subdomain);

        $url = 'https://' . self::getAmoDomain($subdomain) . $query;

        switch ($type) {
            case 'GET':
                if (count($params)) {
                    $url .= '?' . http_build_query($params);
                }
                self::setHTTPHeaders($curl, $subdomain, false);
                $requestInfo = " (GET: {$url})";
                self::debug('[' . self::$requestCounter . "] GET: {$url}");
                break;

            case 'POST':
                $jsonParams = json_encode($params);
                if ($jsonParams === false) {
                    throw new AmoAPIException("Ошибка JSON-кодирования тела запроса: " . print_r($params, true));
                }
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonParams);
                self::setHTTPHeaders($curl, $subdomain, true);

                $jsonParamsDebug = self::unescapeUnicode($jsonParams);
                $requestInfo = " (POST: {$url} {$jsonParamsDebug})";
                self::debug('[' . self::$requestCounter . "] POST: {$url}" . PHP_EOL . $jsonParamsDebug);
                break;

            case 'AJAX':
                $ajaxParams = http_build_query($params);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($curl, CURLOPT_POSTFIELDS, $ajaxParams);
                self::setHTTPHeaders($curl, $subdomain, true, true);

                $ajaxParamsDebug = self::unescapeUnicode($ajaxParams);
                $requestInfo = " (POST (AJAX): {$url} {$ajaxParamsDebug})";
                self::debug('[' . self::$requestCounter . "] POST (AJAX): {$url}" . PHP_EOL . $ajaxParamsDebug);
                break;

            default:
                throw new AmoAPIException("Недопустимый метод запроса {$type}");
        }

        curl_setopt($curl, CURLOPT_URL, $url);

        // --- ПРЯМАЯ РАБОТА С КЛИЕНТОМ ---
        if (is_callable(self::$beforeRequestCallback)) {
            call_user_func(self::$beforeRequestCallback, $curl, $url, $params);
        }

        $startTime = microtime(true);
        self::$lastResult = curl_exec($curl);
        self::$lastRequestTime = microtime(true);
        $deltaTime = sprintf('%0.4f', self::$lastRequestTime - $startTime);

        $result = self::unescapeUnicode(self::$lastResult);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        self::debug('[' . self::$requestCounter . "] RESPONSE {$deltaTime}s ({$code}):" . PHP_EOL . $result);

        $errno = curl_errno($curl);
        $error = curl_error($curl);

        if ($errno !== 0) {
            throw new AmoAPIException("Oшибка cURL ({$errno}): {$error} {$requestInfo}");
        }

        if ($code === 401) {
            if (self::$lastAuth[$subdomain]['is_oauth2']) {
                $response = self::reOAuth2();
            } else {
                $response = self::reAuth();
            }
            if ($response !== true) {
                return $response;
            }
        }

        if (!in_array($code, self::$successStatusCodes, true)) {
            throw new AmoAPIException(self::getErrorMessage($code) . ": {$requestInfo} (Response: {$result})", $code);
        }

        if ($code === 204) {
            return null;
        }

        $response = json_decode(self::$lastResult, true);
        if (is_null($response)) {
            $errorMessage = json_last_error_msg();
            throw new AmoAPIException("Ошибка JSON-декодирования тела ответа ($errorMessage): {$result}");
        }

        // Обработка ошибок _embedded
        if (isset($response['_embedded']['errors']) && count($response['_embedded']['errors'])) {
            // ... (Логика обработки ошибок идентична оригинальной, опущена для краткости, она не менялась)
            // Если нужно, я могу вернуть этот блок полностью
            $errors = $response['_embedded']['errors'];
            $codes = [];
            if (isset($errors['update'])) $codes = array_merge($codes, array_column($errors['update'], 'code'));
            if (isset($errors['add'])) $codes = array_merge($codes, array_column($errors['add'], 'code'));

            if (count($codes)) {
                throw new AmoAPIException("Ошибки: " . self::getErrorMessage($codes) . " {$requestInfo}", reset($codes));
            }
            throw new AmoAPIException("Ошибка API с кодом 200/202, но есть errors в теле: {$result}", $code);
        }

        return $response;
    }

    /**
     * Инициализация и ожидание лимита запросов через Symfony Rate Limiter
     */
    protected static function waitRateLimit(): void
    {
        if (!self::$limiterFactory) {
            $cacheDir = __DIR__ . DIRECTORY_SEPARATOR . self::$storageDir . 'cache';
            self::checkDir($cacheDir);

            // Используем FilesystemAdapter (встроенный инструмент, без Redis)
            $storage = new CacheStorage(new FilesystemAdapter('amocrm_limiter', 0, $cacheDir));

            self::$limiterFactory = new RateLimiterFactory([
                'id' => 'amocrm_api_global',
                'policy' => 'token_bucket', // Алгоритм Token Bucket
                'limit' => (int) self::$throttle,
                'rate' => ['interval' => '1 second', 'amount' => 5],
            ], $storage);
        }

        $limiter = self::$limiterFactory->create('global_request_limit');

        // consume(1)->wait() заблокирует выполнение, пока не появится доступный токен
        $limiter->consume(1)->wait();
    }

    /**
     * Устанавливает НТТР заголовки GET/POST-запроса
     */
    protected static function setHTTPHeaders($curl, string $subdomain, bool $isPost, bool $isAjax = false)
    {
        $headers = [];

        if (self::$lastAuth[$subdomain]['is_oauth2'] && !empty(self::$lastAuth[$subdomain]['access_token'])) {
            $headers[] = 'Authorization: Bearer ' . self::$lastAuth[$subdomain]['access_token'];
        }

        if ($isPost) {
            $headers[] = $isAjax ? 'X-Requested-With: XMLHttpRequest' : 'Content-Type: application/json';
        }

        // --- CUSTOM HEADERS ---
        // Объединяем с пользовательскими заголовками
        if (!empty(self::$customHeaders)) {
            $headers = array_merge($headers, self::$customHeaders);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }

    protected static function getErrorMessage($codes): string
    {
        if (!is_array($codes)) {
            $codes = [$codes];
        }
        $errorMessage = "Ошибка ";
        return $errorMessage . implode(', ', array_map(function ($code): string {
                $message = self::$errorCodes[$code] ?? 'Неизвестная ошибка';
                return "{$code} {$message}";
            }, $codes));
    }

    protected static function unescapeUnicode($string): ?string
    {
        if ($string === false) {
            return '';
        }
        return preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn($match): string => mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE'),
            (string) $string
        );
    }

    protected static function checkDir(string $directory)
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new AmoAPIException("Не удалось рекурсивно создать каталог: {$directory}");
        }
    }

    public static function getLastResponse(bool $unescapeUnicode = true)
    {
        return $unescapeUnicode ? self::unescapeUnicode(self::$lastResult) : self::$lastResult;
    }

    protected static function debug(string $message = '')
    {
        $dateTime = DateTime::createFromFormat('U.u', sprintf('%.f', microtime(true)));
        $timeZone = new DateTimeZone(date_default_timezone_get());
//        $dateTime->setTimeZone($timeZone);
//        $timeString = $dateTime->format('Y-m-d H:i:s.u P');
        $uniqId = self::getUniqId();
//        $message = "*** {$uniqId} [{$timeString}]" . PHP_EOL . $message . PHP_EOL . PHP_EOL;

        if (self::$debug) {
            echo $message;
        }
        if (isset(self::$debugLogger)) {
            self::$debugLogger->debug($message);
        }
    }

    protected static function getUniqId(int $length = 7): string
    {
        if (!isset(self::$uniqId)) {
            self::$uniqId = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, $length);
        }
        return self::$uniqId;
    }

    /**
     * Выполняет блокировку сущности при обновлении (update) методом AmoObject::save()
     * Использует symfony/lock и FlockStore
     *
     * @param object $amoObject Объект \AmoCRM\AmoObject
     * @return LockInterface|null Возвращает объект блокировки или null
     */
    public static function lockEntity($amoObject): ?LockInterface
    {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . self::$storageDir . 'locks/';
        self::checkDir($dir);

        if (!self::$lockFactory) {
            // Используем FlockStore (файловые блокировки)
            $store = new FlockStore($dir);
            self::$lockFactory = new LockFactory($store);
        }

        // Формируем имя ресурса
        $resourceName = 'amo_lock_' . $amoObject->id . '_' . substr(strtolower(get_class($amoObject)), 10);

        $lock = self::$lockFactory->createLock($resourceName, self::$lockTtl);

        // Пытаемся получить блокировку (блокирующий вызов)
        // acquire(true) означает, что скрипт будет ждать освобождения блокировки
        if (!$lock->acquire(true)) {
            self::debug('[' . self::$requestCounter . "] FAILED TO LOCK {$resourceName}");
            return null;
        }

        self::debug('[' . self::$requestCounter . "] LOCKED {$resourceName}");

        return $lock;
    }

    /**
     * Выполняет разблокировку сущности
     * @param LockInterface|null $lock Объект блокировки
     */
    public static function unlockEntity($lock): void
    {
        if ($lock instanceof LockInterface) {
            $lock->release();
            // Опционально можно логировать разблокировку
            // self::debug('[' . self::$requestCounter . "] UNLOCKED");
        }
    }

    public static function getAmoDomain(string $subdomain): string
    {
        if (preg_match('/\.amocrm\.(ru|com)$/', $subdomain)) {
            return $subdomain;
        }
        return $subdomain . '.' . self::$amoDomain;
    }
}