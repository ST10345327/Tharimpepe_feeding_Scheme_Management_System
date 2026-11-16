<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/helpers/Security.php';

class SecurityTransportTest extends TestCase
{
    private $previousTrustedProxies;
    private $previousAllowHttpLocal;
    private $previousServer;

    public function setUp()
    {
        $this->previousTrustedProxies = getenv('FSMS_TRUSTED_PROXIES');
        $this->previousAllowHttpLocal = getenv('FSMS_ALLOW_HTTP_LOCAL');
        $this->previousServer = $_SERVER;
        putenv('FSMS_TRUSTED_PROXIES');
        putenv('FSMS_ALLOW_HTTP_LOCAL');
    }

    public function tearDown()
    {
        $this->restoreEnvironment('FSMS_TRUSTED_PROXIES', $this->previousTrustedProxies);
        $this->restoreEnvironment('FSMS_ALLOW_HTTP_LOCAL', $this->previousAllowHttpLocal);
        $_SERVER = $this->previousServer;
    }

    public function testTrustedLoopbackProxyForwardingHttpsIsRecognized()
    {
        $this->assertTrue(isHttpsTransport([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['127.0.0.1']));
    }

    public function testDirectHttpIsRejectedForWebAndApiRequests()
    {
        $webResponse = $this->runHttpsGate(false, [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'fsms.example',
        ], []);
        $apiResponse = $this->runHttpsGate(true, [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'fsms.example',
        ], []);

        $this->assertStringContains('STATUS=426', $webResponse);
        $this->assertStringContains('HTTPS is required to access this application.', $webResponse);
        $this->assertStringContains('STATUS=426', $apiResponse);
        $this->assertStringContains('HTTPS is required for API traffic', $apiResponse);
    }

    public function testUntrustedClientCannotSpoofForwardedHttps()
    {
        $this->assertFalse(isHttpsTransport([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['127.0.0.1']));
    }

    public function testWebAndApiAcceptTheSameTrustedForwardedHttpsRequest()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'metatron.tail89f605.ts.net',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $trustedProxies = ['127.0.0.1'];

        $this->assertStringContains('ALLOWED', $this->runHttpsGate(false, $server, $trustedProxies));
        $this->assertStringContains('ALLOWED', $this->runHttpsGate(true, $server, $trustedProxies));
    }

    public function testRemoteClientCannotUseLocalHttpOptInBySpoofingHost()
    {
        $response = $this->runHttpsGate(true, [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'localhost',
        ], [], true);

        $this->assertStringContains('STATUS=426', $response);
    }

    public function testForwardedProtocolMustBeExactlyHttps()
    {
        $this->assertFalse(isHttpsTransport([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ], ['127.0.0.1']));
    }

    private function runHttpsGate($jsonResponse, array $server, array $trustedProxies, $allowLocalHttp = false)
    {
        $script = "<?php\n"
            . "\$_SERVER = " . var_export($server, true) . ";\n"
            . "putenv('FSMS_TRUSTED_PROXIES=" . implode(',', $trustedProxies) . "');\n"
            . "putenv('FSMS_ALLOW_HTTP_LOCAL=" . ($allowLocalHttp ? '1' : '') . "');\n"
            . "register_shutdown_function(function () { echo \"\\nSTATUS=\" . http_response_code(); });\n"
            . "require " . var_export(__DIR__ . '/../app/helpers/Security.php', true) . ";\n"
            . 'requireHttpsTransport(' . ($jsonResponse ? 'true' : 'false') . ");\n"
            . "echo 'ALLOWED';\n";
        $scriptPath = tempnam(sys_get_temp_dir(), 'fsms-https-test');
        if ($scriptPath === false || file_put_contents($scriptPath, $script) === false) {
            throw new RuntimeException('Unable to create HTTPS transport test fixture.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, $scriptPath], $descriptors, $pipes);
        if (!is_resource($process)) {
            unlink($scriptPath);
            throw new RuntimeException('Unable to run HTTPS transport test fixture.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($scriptPath);

        if ($exitCode !== 0) {
            throw new RuntimeException('HTTPS transport test fixture failed: ' . $errorOutput);
        }

        return (string)$output;
    }

    private function restoreEnvironment($name, $value)
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}
