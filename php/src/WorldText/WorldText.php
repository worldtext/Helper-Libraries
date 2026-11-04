<?php

declare(strict_types=1);

namespace WorldText;

use JsonException;
use WorldText\Exception\ApiException;
use WorldText\Exception\AuthenticationException;
use WorldText\Exception\InvalidArgumentException;
use WorldText\Exception\PermissionException;
use WorldText\Exception\WorldTextException;
use WorldText\Model\AddEntriesResult;
use WorldText\Model\Cost;
use WorldText\Model\CreateGroupResult;
use WorldText\Model\Credits;
use WorldText\Model\DeleteResult;
use WorldText\Model\GroupCost;
use WorldText\Model\GroupDetails;
use WorldText\Model\GroupList;
use WorldText\Model\GroupNumbers;
use WorldText\Model\HlrResult;
use WorldText\Model\HostName;
use WorldText\Model\MnpResult;
use WorldText\Model\NumberInfo;
use WorldText\Model\PingResult;
use WorldText\Model\SendResult;
use WorldText\Model\SmsStatus;
use WorldText\Transport\Request;
use WorldText\Transport\Response;
use WorldText\Transport\Transport;
use WorldText\Transport\TransportException;
use WorldText\Transport\TransportFactory;

/**
 * Client for the World Text HTTP API, spec version 3.0.
 *
 * Bearer authentication only: the API has no query-string `id`/`key` scheme.
 * The token is never interpolated into any message, exception, log line or
 * string conversion; {@see Token::redact()} is used everywhere a token could
 * conceivably reach a string, and the credential itself lives in a
 * {@see Credentials} value whose display forms are redacted too.
 *
 * Every method throws a subclass of {@see WorldTextException} on failure.
 *
 *     $wt = new WorldText('wt_live_...');
 *     echo $wt->getCredits()->credits;       // '2950', a string, like the API
 */
final class WorldText
{
    /** Production base URL of the versioned API. */
    public const DEFAULT_BASE_URL = 'https://sms.world-text.com/v2.0';

    /** Per-request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 30.0;

    private const USER_AGENT = 'worldtext-php/1.0.0';

    /** Runtime logging channel, e.g. a PSR-3 logger. */
    public const LOGGER = 'worldtext';

    /**
     * Secret state. It is held in a {@see Credentials} value whose display
     * forms are redacted, so `print_r()` of the client shows no token.
     */
    private readonly Credentials $credentials;

    private Transport $transport;

    private readonly Logger\LoggerInterface $logger;

    /**
     * @param string                         $token     API token. Never log it.
     * @param string                         $baseUrl   Override for testing or a regional host.
     * @param float                          $timeout   Per-request timeout in seconds.
     * @param Transport|null                 $transport Supply one to control how I/O happens;
     *                                                  the best available is chosen otherwise.
     * @param Logger\LoggerInterface|null    $logger    Receives one debug line per request, with
     *                                                  the token redacted.
     *
     * @throws InvalidArgumentException when no token is given.
     */
    public function __construct(
        string $token,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?Transport $transport = null,
        ?Logger\LoggerInterface $logger = null,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('a token is required');
        }

        Token::remember($token);

        $this->credentials = new Credentials($token, rtrim($baseUrl, '/'), $timeout);

        $this->transport = $transport ?? TransportFactory::create(self::USER_AGENT);
        $this->logger = $logger ?? Logger\RuntimeLogger::get(self::LOGGER);
    }

    /**
     * The API token. Private, and only ever used to build a request header.
     */
    private function token(): string
    {
        return $this->credentials->token();
    }

    /**
     * Deliberately shows no token, only its redacted form.
     */
    public function __toString(): string
    {
        return sprintf(
            "WorldText(baseUrl='%s', token=%s)",
            $this->baseUrl(),
            Token::redact($this->token()),
        );
    }

    /**
     * What `print_r()` and `var_dump()` show: no token.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl(),
            'token' => Token::redact($this->token()),
            'transport' => $this->transport->name(),
            'timeout' => (string) $this->timeout(),
        ];
    }

    /**
     * The base URL this client talks to.
     */
    public function baseUrl(): string
    {
        return $this->credentials->baseUrl();
    }

    /**
     * The per-request timeout in seconds.
     */
    public function timeout(): float
    {
        return $this->credentials->timeout();
    }

    /**
     * Name of the transport in use, e.g. "curl" or "stream".
     */
    public function transportName(): string
    {
        return $this->transport->name();
    }

    // ------------------------------------------------------------------ core

    /**
     * Issue an arbitrary request and return the decoded success envelope.
     *
     * Intended for operations this client does not yet wrap, and for testing
     * against a new route; the typed methods above are the normal interface.
     * The usual error hierarchy applies, so the caller never sees a raw
     * response body on failure.
     *
     * @param array<string, scalar|null> $params Sent as a form body for PUT/POST,
     *                                           as the query string otherwise;
     *                                           null values are omitted.
     *
     * @return array<string, mixed>
     *
     * @throws WorldTextException
     */
    public function requestRaw(string $method, string $path, array $params = []): array
    {
        return $this->request($method, $path, $params);
    }

    /**
     * Perform one API call and decode the envelope.
     *
     * Arguments whose value is null are omitted, so an unused optional argument
     * is absent from the request rather than sent as an empty string, which the
     * gateway would treat as a value.
     *
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed> The decoded success envelope.
     *
     * @throws WorldTextException
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $clean = [];
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $clean[(string) $name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        $url = $this->baseUrl() . $path;
        $body = null;
        $headers = [
            'Authorization' => 'Bearer ' . $this->token(),
            'Accept' => 'application/json',
        ];

        if ($method === 'PUT' || $method === 'POST') {
            $body = http_build_query($clean);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif ($clean !== []) {
            $url .= '?' . http_build_query($clean);
        }

        $request = new Request($method, $url, $headers, $body);

        $this->logger->debug(
            '{method} {path} (token {token})',
            [
                'method' => $method,
                'path' => $path,
                'token' => Token::redact($this->token()),
            ],
        );

        try {
            $response = $this->transport->send($request, $this->timeout());
        } catch (TransportException $exc) {
            // Never surface the URL: a caller may have put parameters in it.
            throw new WorldTextException(
                sprintf('transport failure contacting %s: %s', $this->baseUrl(), $exc->getMessage()),
            );
        }

        return $this->decode($response);
    }

    /**
     * Interpret a response: either a success payload or a typed error.
     *
     * @return array<string, mixed>
     *
     * @throws WorldTextException
     */
    private function decode(Response $response): array
    {
        $payload = $this->decodeJson($response);

        $statusFlag = isset($payload['status']) && $payload['status'] !== null
            ? (string) $payload['status']
            : null;

        if ($response->status >= 200 && $response->status < 300
            && ($statusFlag === '0' || $statusFlag === null)) {
            return $payload;
        }

        $code = isset($payload['error']) && $payload['error'] !== null
            ? (string) $payload['error']
            : null;
        $desc = isset($payload['desc']) && $payload['desc'] !== null
            ? (string) $payload['desc']
            : sprintf('HTTP %d', $response->status);
        $message = sprintf('%s (error %s, HTTP %d)', $desc, $code ?? 'none', $response->status);

        // '2'/'3' mean "may be retried"; anything else is permanent.
        $flag = $statusFlag ?? WorldTextException::STATUS_PERMANENT;

        throw match ($response->status) {
            401 => new AuthenticationException($message, $response->status, $code, $desc, $flag),
            403 => new PermissionException(
                $message,
                $response->status,
                $code,
                $desc,
                $flag,
                self::scopeFromHeader($response->header('WWW-Authenticate')),
            ),
            default => new ApiException($message, $response->status, $code, $desc, $flag),
        };
    }

    /**
     * Decode the body as a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException when the body is not a JSON object.
     */
    private function decodeJson(Response $response): array
    {
        if ($response->body === '') {
            return [];
        }

        try {
            $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(
                sprintf('response was not JSON (HTTP %d)', $response->status),
                $response->status,
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                sprintf('response was not a JSON object (HTTP %d)', $response->status),
                $response->status,
            );
        }

        return $decoded;
    }

    /**
     * Pull `scope="http"` out of a `WWW-Authenticate` header, if present.
     *
     * The header looks like:
     * `Bearer error="insufficient_scope", scope="http"`.
     */
    public static function scopeFromHeader(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (preg_match('/\bscope\s*=\s*(?:"([^"]*)"|([^\s,]+))/i', $header, $match) !== 1) {
            return null;
        }

        $scope = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

        return $scope === '' ? null : $scope;
    }

    /**
     * Reject an empty required argument before any I/O happens.
     *
     * @param mixed $value
     */
    private static function required(mixed $value, string $name): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('argument "%s" is required', $name));
        }

        return (string) $value;
    }

    // ----------------------------------------------------------------- admin

    /**
     * Liveness check. Requires the `admin` permission.
     *
     * @throws WorldTextException
     */
    public function ping(): PingResult
    {
        return new PingResult($this->request('GET', '/admin/ping'));
    }

    /**
     * Credit balance. Requires the `admin` permission.
     *
     * @throws WorldTextException
     */
    public function getCredits(): Credits
    {
        return new Credits($this->request('GET', '/admin/credits'));
    }

    /**
     * Name of the host that served the request. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function getHost(): HostName
    {
        return new HostName($this->request('GET', '/admin/host'));
    }

    // ------------------------------------------------------------------- sms

    /**
     * Cost of a single message without sending it. Requires ``http``.
     *
     * @param string $dstaddr Destination number.
     * @param string $message Text to price; the default prices a short message.
     *
     * @throws WorldTextException
     */
    public function getSmsCost(string $dstaddr, string $message = ''): Cost
    {
        return new Cost($this->request('GET', '/sms/cost', [
            'dstaddr' => self::required($dstaddr, 'dstaddr'),
            'message' => $message,
        ]));
    }

    /**
     * Send to one destination. Requires ``http``.
     *
     * `$options` are passed through, so optional fields (`sendid`, `schedule`,
     * `sender`, `udh`) work without this client needing to enumerate them as
     * the API grows.
     *
     * @param string                $dstaddr Destination number.
     * @param string                $message Text to send.
     * @param array<string, scalar> $options Extra form fields.
     *
     * @throws WorldTextException
     */
    public function sendSms(string $dstaddr, string $message, array $options = []): SendResult
    {
        $params = ['dstaddr' => self::required($dstaddr, 'dstaddr'), 'message' => $message];
        $params += $options;

        return new SendResult($this->request('PUT', '/sms/send', $params));
    }

    /**
     * Delivery state of a sent message. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function querySmsStatus(string $msgid): SmsStatus
    {
        return new SmsStatus($this->request('GET', '/sms/query', [
            'msgid' => self::required($msgid, 'msgid'),
        ]));
    }

    // ------------------------------------------------------------------- hlr

    /**
     * HLR lookup. Requires ``http``. Costs a credit.
     *
     * @throws WorldTextException
     */
    public function lookupHlr(string $msisdn): HlrResult
    {
        return new HlrResult($this->request('GET', '/hlr/lookup', [
            'msisdn' => self::required($msisdn, 'msisdn'),
        ]));
    }

    /**
     * Mobile number portability. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function lookupMnp(string $msisdn): MnpResult
    {
        return new MnpResult($this->request('GET', '/hlr/mnp', [
            'msisdn' => self::required($msisdn, 'msisdn'),
        ]));
    }

    /**
     * Country and network for a number. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function getNumberInfo(string $msisdn): NumberInfo
    {
        return new NumberInfo($this->request('GET', '/hlr/info', [
            'msisdn' => self::required($msisdn, 'msisdn'),
        ]));
    }

    // ---------------------------------------------------------------- groups

    /**
     * Create a group. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function createGroup(string $name): CreateGroupResult
    {
        return new CreateGroupResult($this->request('PUT', '/group/create', [
            'name' => self::required($name, 'name'),
        ]));
    }

    /**
     * Delete a group and its contents. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function deleteGroup(string $grpid): DeleteResult
    {
        return new DeleteResult($this->request('DELETE', '/group/destroy', [
            'grpid' => self::required($grpid, 'grpid'),
        ]));
    }

    /**
     * Add one number to a group. Requires ``http``.
     *
     * @param array<string, scalar> $options Extra form fields, e.g. `sendid`.
     *
     * @throws WorldTextException
     */
    public function addGroupEntry(string $grpid, string $number, array $options = []): AddEntriesResult
    {
        $params = [
            'grpid' => self::required($grpid, 'grpid'),
            'number' => self::required($number, 'number'),
        ];
        $params += $options;

        return new AddEntriesResult($this->request('PUT', '/group/entry', $params));
    }

    /**
     * Remove one number from a group. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function deleteGroupEntry(string $grpid, string $number): DeleteResult
    {
        return new DeleteResult($this->request('DELETE', '/group/entry', [
            'grpid' => self::required($grpid, 'grpid'),
            'number' => self::required($number, 'number'),
        ]));
    }

    /**
     * Add several numbers, one per line, as the API expects. Requires ``http``.
     *
     * @param list<string> $numbers Numbers to add.
     *
     * @throws WorldTextException
     */
    public function addGroupEntries(string $grpid, array $numbers): AddEntriesResult
    {
        if (!array_is_list($numbers)) {
            throw new InvalidArgumentException('numbers must be a list of strings');
        }

        foreach ($numbers as $number) {
            if (!is_string($number)) {
                throw new InvalidArgumentException('numbers must be a list of strings');
            }
        }

        return new AddEntriesResult($this->request('PUT', '/group/entries', [
            'grpid' => self::required($grpid, 'grpid'),
            'numbers' => implode("\n", $numbers),
        ]));
    }

    /**
     * Numbers in a group. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function getGroupNumbers(string $grpid): GroupNumbers
    {
        return new GroupNumbers($this->request('GET', '/group/numbers', [
            'grpid' => self::required($grpid, 'grpid'),
        ]));
    }

    /**
     * Numbers in a group with their status. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function getGroupDetails(string $grpid): GroupDetails
    {
        return new GroupDetails($this->request('GET', '/group/details', [
            'grpid' => self::required($grpid, 'grpid'),
        ]));
    }

    /**
     * All groups on the account. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function listGroups(): GroupList
    {
        return new GroupList($this->request('GET', '/group/list'));
    }

    /**
     * Remove every number from a group, keeping the group. Requires ``http``.
     *
     * @throws WorldTextException
     */
    public function deleteGroupContents(string $grpid): DeleteResult
    {
        return new DeleteResult($this->request('DELETE', '/group/contents', [
            'grpid' => self::required($grpid, 'grpid'),
        ]));
    }

    /**
     * Cost of sending to a whole group.
     *
     * Requires ``http``, not `groups`. That is the gateway's actual behaviour, not
     * a documentation slip.
     *
     * @throws WorldTextException
     */
    public function getGroupCost(string $grpid, string $message = ''): GroupCost
    {
        return new GroupCost($this->request('GET', '/group/cost', [
            'grpid' => self::required($grpid, 'grpid'),
            'message' => $message,
        ]));
    }

    /**
     * Send to every number in a group. Requires ``http``, not `groups`.
     *
     * @param array<string, scalar> $options Extra form fields.
     *
     * @throws WorldTextException
     */
    public function sendGroupSms(string $grpid, string $message, array $options = []): SendResult
    {
        $params = ['grpid' => self::required($grpid, 'grpid'), 'message' => $message];
        $params += $options;

        return new SendResult($this->request('PUT', '/group/send', $params));
    }
}