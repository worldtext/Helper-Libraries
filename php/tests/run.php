<?php

declare(strict_types=1);

namespace WorldText\Tests;

use WorldText\Exception\ApiException;
use WorldText\Exception\AuthenticationException;
use WorldText\Exception\InvalidArgumentException;
use WorldText\Exception\PermissionException;
use WorldText\Exception\WorldTextException;
use WorldText\Logger\LoggerInterface;
use WorldText\Logger\RuntimeLogger;
use WorldText\Model\Model;
use WorldText\Token;
use WorldText\Transport\Request;
use WorldText\Transport\Response;
use WorldText\Transport\Transport;
use WorldText\Transport\TransportFactory;
use WorldText\WorldText;

require __DIR__ . '/../src/WorldText/bootstrap.php';
require __DIR__ . '/Harness.php';
require __DIR__ . '/MockGateway.php';

/**
 * Tests for the PHP client, run against the shared mock gateway.
 *
 * No network, no credentials: the gateway binds 127.0.0.1 on an OS-chosen port
 * and is killed when the run ends.
 */

const USER_AGENT = 'worldtext-php/test';

/**
 * A transport that answers from a table instead of opening a socket, so the
 * error taxonomy can be exercised for cases the mock never produces (5xx,
 * temporary status flags, non-JSON bodies).
 */
final class StubTransport implements Transport
{
    /**
     * @param list<Response|\Throwable> $responses Consumed in order.
     */
    public function __construct(private array $responses)
    {
    }

    public function name(): string
    {
        return 'stub';
    }

    public function send(Request $request, float $timeout): Response
    {
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException('no stubbed response left');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

/**
 * Captures log lines for the token-redaction test.
 */
final class CapturingLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $lines = [];

    public function debug(string $message, array $context = []): void
    {
        // Keep both the template and the filled-in context: a token in either
        // place is a leak, so both must be inspected.
        $this->lines[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
}

/**
 * Look for python3 on PATH and in the usual places.
 *
 * Returns null when it is nowhere to be found, and the caller skips.
 */
function findPython(): ?string
{
    $candidates = ['python3', 'python'];
    $prefixes = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    $prefixes[] = '/usr/local/bin';
    $prefixes[] = '/usr/bin';
    $prefixes[] = '/opt/homebrew/bin';

    foreach ($candidates as $name) {
        foreach ($prefixes as $prefix) {
            $path = rtrim($prefix, '/') . '/' . $name;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------

if (!\function_exists('proc_open')) {
    fwrite(STDERR, "SKIP: proc_open() is disabled; cannot start the mock gateway\n");
    exit(0);
}

$python = findPython();
if ($python === null) {
    fwrite(STDERR, "SKIP: python3 not found; cannot start the mock gateway\n");
    exit(0);
}

$mock = new MockGateway(__DIR__ . '/../../fixtures/mock_gateway.py', $python);
$mock->start();
register_shutdown_function(static fn () => $mock->stop());

/**
 * Builds a client against the mock.
 */
function client(
    MockGateway $mock,
    string $token = 'test-all',
    ?Transport $transport = null,
): WorldText {
    return new WorldText($token, $mock->baseUrl(), 5.0, $transport);
}

printf("mock gateway: %s (transport: %s)\n", $mock->baseUrl(), TransportFactory::create(USER_AGENT)->name());

$suite = new Suite('World Text PHP client');

// ------------------------------------------------------------------ success

$suite->test('credits parse as a string and convert on demand', function () use ($mock): void {
    $credits = client($mock)->getCredits();
    Assert::same('2950', $credits->credits, 'credits');
    Assert::true(is_string($credits->credits), 'credits type');
    Assert::same('2950', $credits->asDecimal(), 'asDecimal');
});

$suite->test('ping and host', function () use ($mock): void {
    $wt = client($mock, 'test-admin');
    Assert::true($wt->ping()->ok, 'ping ok');
    Assert::same('sms1.example', $wt->getHost()->hostname, 'hostname');
});

$suite->test('sms cost', function () use ($mock): void {
    $cost = client($mock, 'test-sms')->getSmsCost('447989000001', 'hi');
    Assert::same('1.0', $cost->credits, 'cost credits');
    Assert::same('1', $cost->asDecimal(), 'cost asDecimal');
});

$suite->test('sendSms parses msgid as a string', function () use ($mock): void {
    $result = client($mock, 'test-sms')->sendSms('447989000001', 'hi');
    Assert::same(1, count($result->messages), 'message count');
    Assert::same('10001', $result->msgid(), 'msgid');
    Assert::true(is_string($result->msgid()), 'msgid type');
    Assert::same('447989000001', $result->messages[0]->dstaddr, 'dstaddr');
    Assert::same('5', $result->messages[0]->status, 'per-message status');
});

$suite->test('querySmsStatus', function () use ($mock): void {
    $status = client($mock, 'test-sms')->querySmsStatus('10001');
    Assert::same('5', $status->state, 'state');
    Assert::true($status->isFinal(), 'isFinal');
});

$suite->test('group lifecycle', function () use ($mock): void {
    $wt = client($mock, 'test-groups');

    Assert::same('4242', $wt->createGroup('g')->grpid, 'createGroup grpid');
    Assert::same('1', $wt->addGroupEntry('4242', '447989000001')->added, 'addGroupEntry added');

    $bulk = $wt->addGroupEntries('4242', ['447989000001', '447989000002']);
    Assert::same('2', $bulk->added, 'addGroupEntries added');
    Assert::same(2, $bulk->addedValue(), 'addGroupEntries numeric added');

    $numbers = $wt->getGroupNumbers('4242')->numbers;
    sort($numbers);
    Assert::same(['447989000001', '447989000002'], $numbers, 'group numbers');

    Assert::same(2, $wt->getGroupDetails('4242')->count(), 'group details count');

    $groups = $wt->listGroups()->groups;
    Assert::same('4242', $groups[0]->grpid, 'listed group id');
    Assert::same('test-group', $groups[0]->name, 'listed group name');
    Assert::same(2, $groups[0]->sizeValue(), 'listed group size');

    $wt->deleteGroupContents('4242');
    $wt->deleteGroup('4242');
});

$suite->test('delete returns a count only when the gateway sends one', function () use ($mock): void {
    $wt = client($mock, 'test-groups');

    // /group/contents reports how many entries it removed.
    $contents = $wt->deleteGroupContents('4242');
    Assert::same('2', $contents->deleted, 'count is a string');
    Assert::same(2, $contents->deletedValue(), 'numeric count');

    // /group/destroy reports nothing beyond status, and that must not throw.
    $group = $wt->deleteGroup('4242');
    Assert::same(null, $group->deleted, 'no count reported');
    Assert::same(['status' => '0'], $group->raw, 'raw envelope');

    // /group/entry echoes the add counters the mock keeps, which is harmless.
    $entry = $wt->deleteGroupEntry('4242', '447989000001');
    Assert::same(null, $entry->deleted, 'no deletion count for one entry');
});

$suite->test('group cost needs the http interface', function () use ($mock): void {
    // Scopes name interfaces, so an email-only credential is refused here even
    // though pricing a send costs nothing.
    Assert::same('2.0', client($mock, 'test-http')->getGroupCost('4242')->credits, 'group cost credits');

    $error = Assert::throws(
        PermissionException::class,
        fn () => client($mock, 'test-email')->getGroupCost('4242'),
        'email credential pricing a group send',
    );
    Assert::same('http', $error->getRequiredScope(), 'required scope');
});

$suite->test('group send needs the http interface', function () use ($mock): void {
    $sent = client($mock, 'test-http')->sendGroupSms('4242', 'hello');
    Assert::same('10002', $sent->msgid(), 'group send msgid');

    Assert::throws(
        PermissionException::class,
        fn () => client($mock, 'test-email')->sendGroupSms('4242', 'hello'),
        'email credential sending to a group',
    );
});

$suite->test('all twenty operations succeed against the gateway', function () use ($mock): void {
    // A wrong verb or route would come back as 400/1201 (unknown command), so
    // a routing mistake fails here rather than in production.
    $wt = client($mock, 'test-all');
    $calls = [
        'ping' => fn () => $wt->ping(),
        'getCredits' => fn () => $wt->getCredits(),
        'getHost' => fn () => $wt->getHost(),
        'getSmsCost' => fn () => $wt->getSmsCost('447989000001'),
        'sendSms' => fn () => $wt->sendSms('447989000001', 'hi'),
        'querySmsStatus' => fn () => $wt->querySmsStatus('10001'),
        'lookupHlr' => fn () => $wt->lookupHlr('447989000001'),
        'lookupMnp' => fn () => $wt->lookupMnp('447989000001'),
        'getNumberInfo' => fn () => $wt->getNumberInfo('447989000001'),
        'createGroup' => fn () => $wt->createGroup('g'),
        'deleteGroup' => fn () => $wt->deleteGroup('4242'),
        'addGroupEntry' => fn () => $wt->addGroupEntry('4242', '447989000001'),
        'deleteGroupEntry' => fn () => $wt->deleteGroupEntry('4242', '447989000001'),
        'addGroupEntries' => fn () => $wt->addGroupEntries('4242', ['447989000001']),
        'getGroupNumbers' => fn () => $wt->getGroupNumbers('4242'),
        'getGroupDetails' => fn () => $wt->getGroupDetails('4242'),
        'listGroups' => fn () => $wt->listGroups(),
        'deleteGroupContents' => fn () => $wt->deleteGroupContents('4242'),
        'getGroupCost' => fn () => $wt->getGroupCost('4242'),
        'sendGroupSms' => fn () => $wt->sendGroupSms('4242', 'hi'),
    ];

    Assert::same(20, count($calls), 'operation count');
    foreach ($calls as $name => $call) {
        $result = $call();
        Assert::true(
            $result instanceof \WorldText\Model\Model,
            $name . ' returns a typed model, got ' . get_debug_type($result),
        );
    }
});

$suite->test('hlr lookups', function () use ($mock): void {
    $wt = client($mock, 'test-hlr');
    Assert::same('UK', $wt->lookupHlr('447989000001')->region, 'hlr region');
    Assert::false($wt->lookupHlr('447989000001')->failed(), 'hlr not failed');
    Assert::same('ported', $wt->lookupMnp('447989000001')->mnpportstatus, 'mnp status');
    Assert::true($wt->lookupMnp('447989000001')->isPorted(), 'mnp isPorted');
    Assert::same('O2-UK', $wt->getNumberInfo('447989000001')->network, 'network');
    Assert::same('234', $wt->getNumberInfo('447989000001')->mcc, 'mcc is a string');
});

$suite->test('group id accepted under both field names', function (): void {
    // The gateway uses "grpid" on some routes and "groupid" on others.
    $viaGrpid = new \WorldText\Model\CreateGroupResult(['status' => '0', 'grpid' => '7']);
    $viaGroupid = new \WorldText\Model\CreateGroupResult(['status' => '0', 'groupid' => '7']);
    Assert::same('7', $viaGrpid->grpid, 'grpid');
    Assert::same('7', $viaGroupid->grpid, 'groupid');

    $group = new \WorldText\Model\Group(['status' => '0', 'groupid' => '9', 'entries' => '3']);
    Assert::same('9', $group->grpid, 'Group via groupid');
    Assert::same('3', $group->size, 'Group size via entries');
});

$suite->test('numbers are never parsed to float', function (): void {
    // A 19-digit id must survive: a float would round it.
    $id = '9007199254740993001';
    $copy = Model::decimal($id);
    Assert::same($id, $copy, 'long id round-trip');
    Assert::same('1', Model::decimal('1.0'), 'trailing zeros dropped');
    Assert::same('0.5', Model::decimal('.5'), 'leading point');
    Assert::same('1500', Model::decimal('1.5e3'), 'exponent');
    Assert::same(null, Model::decimal('abc'), 'non-numeric');
});

// ------------------------------------------------------------------- errors

$suite->test('bad token is 401', function () use ($mock): void {
    $error = Assert::throws(
        AuthenticationException::class,
        fn () => client($mock, 'not-a-token')->getCredits(),
        'bad token',
    );
    Assert::same(401, $error->getHttpStatus(), 'http status');
    Assert::same('1000', $error->getErrorCode(), 'error code');
    Assert::false($error->isRetryable(), '401 is not retryable');
});

$suite->test('wrong interface is 403 and exposes the required scope', function () use ($mock): void {
    $error = Assert::throws(
        PermissionException::class,
        fn () => client($mock, 'test-email')->getCredits(),
        'email credential reading credits',
    );
    Assert::same(403, $error->getHttpStatus(), 'http status');
    // The required interface is surfaced so the caller can say which one to add.
    Assert::same('http', $error->getRequiredScope(), 'required scope');
    Assert::false($error->isRetryable(), '403 is not retryable');
});

$suite->test('missing argument is 400 from the gateway', function () use ($mock): void {
    // requestRaw() sends no dstaddr, so the gateway's own validation (1200) is
    // what is exercised here; the client-side refusal of an empty string is
    // covered further down.
    $error = Assert::throws(
        ApiException::class,
        fn () => (new WorldText('test-sms', $mock->baseUrl(), 5.0))
            ->requestRaw('GET', '/sms/cost', ['message' => 'hi']),
        'no dstaddr',
    );
    Assert::same(400, $error->getHttpStatus(), 'http status');
    Assert::same('1200', $error->getErrorCode(), 'error code');
    Assert::false($error->isRetryable(), '400 is not retryable');
    Assert::contains('Incorrect arguments', $error->getDesc() ?? '', 'gateway description');
});

$suite->test('missing group name is 400 from the gateway', function () use ($mock): void {
    $error = Assert::throws(
        ApiException::class,
        fn () => (new WorldText('test-http', $mock->baseUrl(), 5.0))
            ->requestRaw('PUT', '/group/create', []),
        'no name',
    );
    Assert::same(400, $error->getHttpStatus(), 'http status');
    Assert::same('1200', $error->getErrorCode(), 'error code');
});

$suite->test('all errors share a base class', function () use ($mock): void {
    foreach ([['nope', AuthenticationException::class], ['test-email', PermissionException::class]] as [$token, $type]) {
        $error = Assert::throws(
            WorldTextException::class,
            fn () => client($mock, $token)->getCredits(),
            'credits with ' . $type,
        );
        Assert::true($error instanceof $type, 'concrete type is ' . $type);
    }
});

$suite->test('empty arguments are rejected before any I/O', function (): void {
    // The stub has no response queued: reaching the transport would fail any
    // test expecting InvalidArgumentException, so refusal must be client-side.
    $wt = new WorldText('test-all', 'http://127.0.0.1:1', 1.0, new StubTransport([]));
    Assert::throws(InvalidArgumentException::class, fn () => $wt->sendSms('', 'hi'), 'sendSms');
    Assert::throws(InvalidArgumentException::class, fn () => $wt->lookupHlr(''), 'lookupHlr');
    Assert::throws(InvalidArgumentException::class, fn () => $wt->addGroupEntries('4242', ['ok', 7]), 'bad list');
    Assert::throws(InvalidArgumentException::class, fn () => new WorldText(''), 'no token');
});

$suite->test('retryable covers status 2/3 and 5xx', function (): void {
    $cases = [
        [new Response(503, [], '{"status":"2","error":"1100","desc":"Busy"}'), '5xx'],
        [new Response(200, [], '{"status":"2","error":"1100","desc":"Busy"}'), 'status 2'],
        [new Response(200, [], '{"status":"3","error":"1100","desc":"Deferred"}'), 'status 3'],
    ];
    foreach ($cases as [$response, $what]) {
        $error = Assert::throws(
            ApiException::class,
            fn () => (new WorldText('test-all', 'http://x', 1.0, new StubTransport([$response])))->ping(),
            $what,
        );
        Assert::true($error->isRetryable(), $what . ' is retryable');
        Assert::same('1100', $error->getErrorCode(), $what . ' code');
    }

    // A permanent flag on a 200 stays permanent.
    $error = Assert::throws(
        ApiException::class,
        fn () => (new WorldText('test-all', 'http://x', 1.0, new StubTransport([
            new Response(200, [], '{"status":"1","error":"1100","desc":"No"}'),
        ])))->ping(),
        'status 1',
    );
    Assert::false($error->isRetryable(), 'status 1 is not retryable');
});

$suite->test('non-JSON response is an ApiException, transport failure is retryable', function (): void {
    $bad = Assert::throws(
        ApiException::class,
        fn () => (new WorldText('test-all', 'http://x', 1.0, new StubTransport([
            new Response(502, [], '<html>Bad gateway</html>'),
        ])))->ping(),
        'html body',
    );
    Assert::same(502, $bad->getHttpStatus(), 'http status');

    $down = Assert::throws(
        WorldTextException::class,
        fn () => (new WorldText('test-all', 'http://x', 1.0, new StubTransport([
            new \WorldText\Transport\TransportException('Connection refused'),
        ])))->ping(),
        'connection refused',
    );
    Assert::true($down->isRetryable(), 'transport failure is retryable');
    Assert::same(null, $down->getHttpStatus(), 'no http status');
});

$suite->test('scope is parsed from WWW-Authenticate', function (): void {
    $header = 'Bearer error="insufficient_scope", scope="admin"';
    Assert::same('admin', WorldText::scopeFromHeader($header), 'quoted scope');
    Assert::same('hlr', WorldText::scopeFromHeader('Bearer scope=hlr'), 'bare scope');
    Assert::same(null, WorldText::scopeFromHeader('Bearer error="invalid_token"'), 'no scope');
    Assert::same(null, WorldText::scopeFromHeader(null), 'no header');
});

// -------------------------------------------------------------- token safety

$suite->test('redact never shows the token', function (): void {
    Assert::same('<redacted:11 chars>', Token::redact('supersecret'), 'redacted form');
    Assert::same('<none>', Token::redact(null), 'absent token');
});

$suite->test('client string form redacts the token', function (): void {
    $secret = 'wt_live_supersecretvalue_that_must_never_leak';
    $wt = new WorldText($secret, 'http://x', 1.0, new StubTransport([]));

    Assert::notContains($secret, (string) $wt, 'string form');
    Assert::contains('redacted', (string) $wt, 'string form');
    Assert::notContains($secret, print_r($wt, true), 'print_r');
    Assert::notContains(
        $secret,
        (string) json_encode($wt, JSON_THROW_ON_ERROR),
        'json_encode',
    );

    // The stub transport holds the client, not the reverse, but check the
    // related object graph too: the logger must not cache the token.
    $logger = new CapturingLogger();
    RuntimeLogger::set(WorldText::LOGGER, $logger);
    try {
        $wt = new WorldText($secret, 'http://x', 1.0, new StubTransport([
            new Response(200, [], '{"status":"0","credits":"1"}'),
        ]));
        $wt->getCredits();
    } finally {
        RuntimeLogger::clear(WorldText::LOGGER);
    }
    Assert::same(1, count($logger->lines), 'one log line per request');
    Assert::notContains($secret, $logger->lines[0], 'log line');
});

$suite->test('print_r of a live client leaks no token', function () use ($mock): void {
    $secret = 'wt_live_supersecretvalue_that_must_never_leak';

    // Against a real socket, where the Authorization header is built and the
    // transport buffers the response: the object graph is the fullest it gets.
    $wt = new WorldText($secret, $mock->baseUrl(), 5.0);
    try {
        $wt->getCredits();
    } catch (WorldTextException) {
        // Rejected with 401, which is the point: the request was made.
    }

    foreach ([
        'print_r' => print_r($wt, true),
        'string' => (string) $wt,
        'json' => (string) json_encode($wt, JSON_THROW_ON_ERROR),
    ] as $what => $rendered) {
        Assert::notContains($secret, $rendered, $what);
    }

    // A client must not be serialisable into a queue or cache with the
    // credential attached.
    Assert::throws(
        \LogicException::class,
        static fn () => serialize(new \WorldText\Credentials($secret, 'http://x', 1.0)),
        'serialising credentials',
    );
});

$suite->test('error string form redacts the token', function () use ($mock): void {
    $secret = 'wt_live_supersecretvalue_that_must_never_leak';
    $error = Assert::throws(
        AuthenticationException::class,
        fn () => client($mock, $secret)->getCredits(),
        'unknown token',
    );

    Assert::notContains($secret, $error->getMessage(), 'message');
    Assert::notContains($secret, (string) $error, 'string form');
    Assert::notContains($secret, print_r($error, true), 'print_r');
});

$suite->test('transport failures and token scrubbing keep the token out', function (): void {
    $secret = 'wt_live_supersecretvalue_that_must_never_leak';

    // A closed port: no HTTP response at all.
    $error = Assert::throws(
        WorldTextException::class,
        fn () => (new WorldText($secret, 'http://127.0.0.1:1', 1.0))->getCredits(),
        'connection refused',
    );
    Assert::notContains($secret, $error->getMessage(), 'message');
    Assert::notContains($secret, (string) $error, 'string form');

    // Even a description that somehow carried the token is scrubbed.
    Token::remember($secret);
    $scrubbed = new ApiException('upstream echoed ' . $secret, 500);
    Assert::notContains($secret, $scrubbed->getMessage(), 'scrubbed message');
});

$suite->test('logging redacts the token', function (): void {
    $secret = 'wt_live_supersecretvalue_that_must_never_leak';
    $logger = new CapturingLogger();
    RuntimeLogger::set(WorldText::LOGGER, $logger);

    try {
        // Point at a closed port: the request is logged before it fails.
        $client = new WorldText($secret, 'http://127.0.0.1:1', 1.0);
        try {
            $client->getCredits();
        } catch (WorldTextException) {
            // Expected: nothing listens on port 1.
        }
    } finally {
        RuntimeLogger::clear(WorldText::LOGGER);
    }

    Assert::true($logger->lines !== [], 'expected at least one log record');
    foreach ($logger->lines as $line) {
        Assert::notContains($secret, $line, 'log line');
        Assert::contains('redacted', $line, 'log line carries the redacted form');
    }
});

// --------------------------------------------------------------- transports

$suite->test('both transports agree when available', function () use ($mock): void {
    $available = 0;

    foreach ([\WorldText\Transport\CurlTransport::class, \WorldText\Transport\StreamTransport::class] as $class) {
        if ($class::isAvailable()) {
            $available++;
        }
    }
    Assert::true($available >= 1, 'at least one transport is available');

    if (\WorldText\Transport\CurlTransport::isAvailable()) {
        $curl = client($mock, 'test-all', new \WorldText\Transport\CurlTransport(USER_AGENT));
        Assert::same('2950', $curl->getCredits()->credits, 'curl transport');
    }
    if (\WorldText\Transport\StreamTransport::isAvailable()) {
        $stream = client($mock, 'test-all', new \WorldText\Transport\StreamTransport(USER_AGENT));
        Assert::same('2950', $stream->getCredits()->credits, 'stream transport');
    }
});

// ------------------------------------------------------------------- report

$ok = $suite->run();
$mock->stop();

printf(
    "\n%d passed, %d failed\n",
    $suite->passedCount(),
    count($suite->failures()),
);

exit($ok ? 0 : 1);