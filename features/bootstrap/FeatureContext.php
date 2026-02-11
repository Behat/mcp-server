<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterSuite;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

define('MCP_SERVER_BIN_PATH', dirname(__DIR__, 2) . '/bin/behat-mcp-server');

final class FeatureContext implements Context
{
    private string $phpBin;

    private string $workingDir;

    private ?ReactProcess $mcpReactProcess = null;

    private string $mcpServerErrorOutput = '';

    private ?int $mcpHttpPort = null;

    private ?string $mcpHttpHost = null;

    private ?string $mcpSessionId = null;

    /** @var array<string, mixed> */
    private array $mcpHttpResponses = [];

    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    #[BeforeSuite]
    #[AfterSuite]
    public static function cleanTestFolders(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat-mcp-server');
    }

    #[BeforeScenario]
    public function prepareTestFolders(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat-mcp-server' . DIRECTORY_SEPARATOR .
            md5(microtime() . random_int(0, 10000));

        $this->filesystem->mkdir($dir);

        $phpExecutableFinder = new PhpExecutableFinder();
        $php = $phpExecutableFinder->find();
        if ($php === false) {
            throw new RuntimeException('Unable to find the PHP executable.');
        }

        $this->workingDir = $dir;
        $this->phpBin = $php;
    }

    #[AfterScenario]
    public function stopMcpServer(): void
    {
        if (!$this->mcpReactProcess instanceof ReactProcess) {
            return;
        }

        if ($this->mcpReactProcess->stdout instanceof ReadableStreamInterface) {
            $this->mcpReactProcess->stdout->close();
        }

        if ($this->mcpReactProcess->stderr instanceof ReadableStreamInterface) {
            $this->mcpReactProcess->stderr->close();
        }

        exec('pkill -9 -f "behat-mcp-server"');

        $this->mcpReactProcess = null;
        $this->mcpHttpPort = null;
        $this->mcpHttpHost = null;
        $this->mcpHttpResponses = [];
        $this->mcpSessionId = null;
    }

    #[When('I initialise the working directory from the :dir fixtures folder')]
    public function iSetTheWorkingDirectoryToTheFixturesFolder(string $dir): void
    {
        $basePath = dirname(__DIR__, 2) . '/tests/Fixtures/';
        $fullPath = $basePath . $dir;
        if (!is_dir($fullPath)) {
            throw new RuntimeException(sprintf('The directory "%s" does not exist', $fullPath));
        }

        $this->filesystem->mirror($fullPath, $this->workingDir);
    }

    #[Given('I start the MCP server')]
    public function iStartTheMcpServer(): void
    {
        $command = $this->phpBin . ' ' . MCP_SERVER_BIN_PATH;

        $this->mcpReactProcess = new ReactProcess($command, $this->workingDir);
        $this->mcpReactProcess->start(Loop::get());

        $this->mcpServerErrorOutput = '';
        if ($this->mcpReactProcess->stderr instanceof ReadableStreamInterface) {
            $this->mcpReactProcess->stderr->on('data', function (string $chunk): void {
                $this->mcpServerErrorOutput .= $chunk;
            });
        }
    }

    #[When('I send an MCP initialize request')]
    public function iSendAnMcpInitializeRequest(): void
    {
        $this->sendMcpRequest('init-1', 'initialize', [
            'protocolVersion' => '2025-03-26',
            'clientInfo' => ['name' => 'BehatTestClient', 'version' => '1.0'],
            'capabilities' => [],
        ]);
    }

    #[Then('I should receive a successful MCP initialize response')]
    public function iShouldReceiveASuccessfulMcpInitializeResponse(): void
    {
        $response = $this->readMcpResponse('init-1');

        Assert::assertArrayHasKey('result', $response);
        Assert::assertArrayNotHasKey('error', $response);
        Assert::assertEquals('init-1', $response['id']);
        $result = $response['result'];
        Assert::assertIsArray($result);
        Assert::assertArrayHasKey('protocolVersion', $result);
        Assert::assertArrayHasKey('serverInfo', $result);

        $this->sendMcpNotification('notifications/initialized');
    }

    #[When('I call the MCP tool :toolName')]
    public function iCallTheMcpTool(string $toolName): void
    {
        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => [],
        ]);
    }

    #[When('I call the MCP tool :toolName with config :config')]
    public function iCallTheMcpToolWithConfig(string $toolName, string $config): void
    {
        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => ['config' => $config],
        ]);
    }

    #[When('I call the MCP tool :toolName with profile :profile')]
    public function iCallTheMcpToolWithProfile(string $toolName, string $profile): void
    {
        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => ['profile' => $profile],
        ]);
    }

    #[When('I call the MCP tool :toolName with suite :suite')]
    public function iCallTheMcpToolWithSuite(string $toolName, string $suite): void
    {
        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => ['suite' => $suite],
        ]);
    }

    #[When('I call the MCP tool :toolName with paths :paths')]
    public function iCallTheMcpToolWithPaths(string $toolName, string $paths): void
    {
        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => ['paths' => explode(',', $paths)],
        ]);
    }

    #[When('I call the MCP tool :toolName with additional options :options')]
    public function iCallTheMcpToolWithAdditionalOptions(string $toolName, string $options): void
    {
        $additionalOptions = [];
        foreach (explode(',', $options) as $option) {
            [$key, $value] = explode('=', $option, 2);
            $additionalOptions[$key] = $value === 'true' ? true : ($value === 'false' ? false : $value);
        }

        $this->sendMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => ['additionalOptions' => $additionalOptions],
        ]);
    }

    #[Then('I should receive a successful tool response with :tests tests and :failures failures')]
    public function iShouldReceiveASuccessfulToolResponseWithTestsAndFailures(int $tests, int $failures): void
    {
        $response = $this->readMcpResponse('tool-call-1');

        Assert::assertArrayHasKey('result', $response);
        Assert::assertArrayNotHasKey('error', $response);
        Assert::assertEquals('tool-call-1', $response['id']);

        $result = $response['result'];
        Assert::assertIsArray($result);
        Assert::assertArrayHasKey('content', $result);
        $content = $result['content'];
        Assert::assertIsArray($content);
        Assert::assertArrayHasKey(0, $content);
        $firstContent = $content[0];
        Assert::assertIsArray($firstContent);
        Assert::assertArrayHasKey('text', $firstContent);
        $text = $firstContent['text'];
        Assert::assertIsString($text);

        $toolResult = json_decode($text, true);
        Assert::assertIsArray($toolResult);

        Assert::assertEquals($tests, $toolResult['tests']);
        Assert::assertEquals($failures, $toolResult['failed']);
    }

    #[Then('I should receive a successful tool response with :tests tests and :skipped skipped')]
    public function iShouldReceiveASuccessfulDryRunToolResponseWithTests(int $tests, int $skipped): void
    {
        $response = $this->readMcpResponse('tool-call-1');

        Assert::assertArrayHasKey('result', $response);
        Assert::assertArrayNotHasKey('error', $response);
        Assert::assertEquals('tool-call-1', $response['id']);

        $result = $response['result'];
        Assert::assertIsArray($result);
        Assert::assertArrayHasKey('content', $result);
        $content = $result['content'];
        Assert::assertIsArray($content);
        Assert::assertArrayHasKey(0, $content);
        $firstContent = $content[0];
        Assert::assertIsArray($firstContent);
        Assert::assertArrayHasKey('text', $firstContent);
        $text = $firstContent['text'];
        Assert::assertIsString($text);

        $toolResult = json_decode($text, true);
        Assert::assertIsArray($toolResult);

        Assert::assertEquals($tests, $toolResult['tests']);
        Assert::assertEquals($skipped, $toolResult['skipped']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sendMcpNotification(string $method, array $params = []): void
    {
        if (!$this->mcpReactProcess instanceof ReactProcess) {
            throw new RuntimeException('MCP server process is not running');
        }

        $notification = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);

        if ($notification === false) {
            throw new RuntimeException('Failed to encode notification');
        }

        $stdin = $this->mcpReactProcess->stdin;
        if ($stdin instanceof WritableStreamInterface) {
            $stdin->write($notification . "\n");
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sendMcpRequest(string $requestId, string $method, array $params = []): void
    {
        if (!$this->mcpReactProcess instanceof ReactProcess) {
            throw new RuntimeException('MCP server process is not running');
        }

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ]);

        if ($request === false) {
            throw new RuntimeException('Failed to encode request');
        }

        $stdin = $this->mcpReactProcess->stdin;
        if ($stdin instanceof WritableStreamInterface) {
            $stdin->write($request . "\n");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readMcpResponse(string $expectedRequestId): array
    {
        if (!$this->mcpReactProcess instanceof ReactProcess) {
            throw new RuntimeException('MCP server process is not running');
        }

        $loop = Loop::get();
        /** @var Deferred<array<string, mixed>> $deferred */
        $deferred = new Deferred();
        $buffer = '';
        $timeoutSeconds = 5;
        $process = $this->mcpReactProcess;

        $dataListener = function (string $chunk) use (&$buffer, $deferred, $expectedRequestId, &$dataListener, $process): void {
            $buffer .= $chunk;
            if (str_contains($buffer, "\n")) {
                $lines = explode("\n", $buffer);
                $lastLine = array_pop($lines);
                $buffer = $lastLine;

                foreach ($lines as $line) {
                    if (in_array(trim($line), ['', '0'], true)) {
                        continue;
                    }

                    $response = json_decode(trim($line), true);
                    if (is_array($response) && array_key_exists('id', $response) && $response['id'] === $expectedRequestId) {
                        if ($process->stdout instanceof ReadableStreamInterface) {
                            $process->stdout->removeListener('data', $dataListener);
                        }

                        /** @var array<string, mixed> $response */
                        $deferred->resolve($response);

                        return;
                    }
                }
            }
        };

        if ($this->mcpReactProcess->stdout instanceof ReadableStreamInterface) {
            $this->mcpReactProcess->stdout->on('data', $dataListener);
        }

        $promise = timeout($deferred->promise(), $timeoutSeconds, $loop);

        try {
            /** @var array<string, mixed> $result */
            $result = await($promise);

            return $result;
        } catch (TimeoutException) {
            if ($this->mcpReactProcess->stdout instanceof ReadableStreamInterface) {
                $this->mcpReactProcess->stdout->removeListener('data', $dataListener);
            }

            throw new RuntimeException(sprintf("Timeout waiting for MCP response with ID '%s'", $expectedRequestId));
        }
    }

    #[Given('I start the MCP server with HTTP transport')]
    public function iStartTheMcpServerWithHttpTransport(): void
    {
        $this->mcpHttpHost = '127.0.0.1';
        $this->mcpHttpPort = 19876;

        $command = sprintf(
            '%s %s --transport=http --host=%s --port=%d',
            $this->phpBin,
            MCP_SERVER_BIN_PATH,
            $this->mcpHttpHost,
            $this->mcpHttpPort
        );

        $this->mcpReactProcess = new ReactProcess($command, $this->workingDir);
        $this->mcpReactProcess->start(Loop::get());

        $this->mcpServerErrorOutput = '';
        if ($this->mcpReactProcess->stderr instanceof ReadableStreamInterface) {
            $this->mcpReactProcess->stderr->on('data', function (string $chunk): void {
                $this->mcpServerErrorOutput .= $chunk;
            });
        }

        await(sleep(0.5));
    }

    #[When('I send an HTTP MCP initialize request')]
    public function iSendAnHttpMcpInitializeRequest(): void
    {
        $this->sendHttpMcpRequest('init-1', 'initialize', [
            'protocolVersion' => '2025-03-26',
            'clientInfo' => ['name' => 'BehatTestClient', 'version' => '1.0'],
            'capabilities' => [],
        ]);
    }

    #[Then('I should receive a successful HTTP MCP initialize response')]
    public function iShouldReceiveASuccessfulHttpMcpInitializeResponse(): void
    {
        $response = $this->mcpHttpResponses['init-1'] ?? null;

        Assert::assertNotNull($response);
        Assert::assertIsArray($response);
        Assert::assertArrayHasKey('result', $response);
        Assert::assertArrayNotHasKey('error', $response);
        Assert::assertEquals('init-1', $response['id']);
        $result = $response['result'];
        Assert::assertIsArray($result);
        Assert::assertArrayHasKey('protocolVersion', $result);
        Assert::assertArrayHasKey('serverInfo', $result);

        $this->sendHttpMcpNotification('notifications/initialized');
    }

    #[When('I call the HTTP MCP tool :toolName')]
    public function iCallTheHttpMcpTool(string $toolName): void
    {
        $this->sendHttpMcpRequest('tool-call-1', 'tools/call', [
            'name' => $toolName,
            'arguments' => [],
        ]);
    }

    #[Then('I should receive a successful HTTP tool response with :tests tests and :failures failures')]
    public function iShouldReceiveASuccessfulHttpToolResponse(int $tests, int $failures): void
    {
        $response = $this->mcpHttpResponses['tool-call-1'] ?? null;

        Assert::assertNotNull($response);
        Assert::assertIsArray($response);
        Assert::assertArrayHasKey('result', $response);
        Assert::assertArrayNotHasKey('error', $response);
        Assert::assertEquals('tool-call-1', $response['id']);

        $result = $response['result'];
        Assert::assertIsArray($result);
        Assert::assertArrayHasKey('content', $result);
        $content = $result['content'];
        Assert::assertIsArray($content);
        Assert::assertArrayHasKey(0, $content);
        $firstContent = $content[0];
        Assert::assertIsArray($firstContent);
        Assert::assertArrayHasKey('text', $firstContent);
        $text = $firstContent['text'];
        Assert::assertIsString($text);

        $toolResult = json_decode($text, true);
        Assert::assertIsArray($toolResult);

        Assert::assertEquals($tests, $toolResult['tests']);
        Assert::assertEquals($failures, $toolResult['failed']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sendHttpMcpRequest(string $requestId, string $method, array $params = []): void
    {
        $browser = new Browser();
        $url = sprintf('http://%s:%d/mcp', $this->mcpHttpHost, $this->mcpHttpPort);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ]);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode request');
        }

        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($this->mcpSessionId !== null && $method !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->mcpSessionId;
        }

        $response = await($browser->post($url, $headers, $payload));

        if ($method === 'initialize' && $response->hasHeader('Mcp-Session-Id')) {
            $this->mcpSessionId = $response->getHeaderLine('Mcp-Session-Id');
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $this->mcpHttpResponses[$requestId] = $decoded;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sendHttpMcpNotification(string $method, array $params = []): void
    {
        $browser = new Browser();
        $url = sprintf('http://%s:%d/mcp', $this->mcpHttpHost, $this->mcpHttpPort);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode notification');
        }

        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Mcp-Session-Id' => $this->mcpSessionId];

        await($browser->post($url, $headers, $payload));
    }
}
