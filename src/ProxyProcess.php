<?php

namespace mpyw\HyperBuiltinServer;

declare(ticks=1);

class ProxyProcess
{
    use LoggerTrait, CommandTrait;

    private $logger;
    private $pid;
    private $port;
    private $directory;
    private $use_ssl;

    public function __construct($directory = '.', $use_ssl = false)
    {
        SignalHandler::enable();
        $this->registerLogger('ProxyProcess');
        $this->directory = $directory;
        $this->use_ssl = $use_ssl;
    }

    public function listen()
    {
        $proxy = $this->getSocketServer($this->use_ssl);
        while (true) {
            $client = @stream_socket_accept($proxy, -1);
            if (!$client) {
                $err = error_get_last();
                if (strpos($err['message'], 'Interrupted system call') !== false) {
                    continue;
                }
                $this->error($err['message']);
                continue;
            }
            $pid = $this->fork();
            if ($pid) {
                continue;
            }
            $process = new BuiltinServerProcess($this->directory);
            if ($server = $process->getSocketClient()) {
                $this->pipe($client, $server);
                fclose($server);
            }
            fclose($client);
            exit;
        }
    }

    public function __destruct()
    {
        if (empty($this->port)) {
            return;
        }
        $search = "php -S localhost:$this->port";
        $outputs = $this->execCommand("ps | grep '$search'");
        foreach ($outputs as $item) {
            if (
                strpos($item, 'grep') === false &&
                strpos($item, 'sh') === false &&
                strpos($item, $search) !== false
            ) {
                $pid = (int)$item;
                $this->execCommand("kill $pid 2>/dev/null");
                $this->info("Killed localhost:$this->port");
                return;
            }
        }
    }

    private function getSocketServer($use_ssl)
    {
        $context = stream_context_create(
            $use_ssl
            ? [
                'ssl' => [
                    'local_cert' => __DIR__ . '/certificate.pem',
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                ],
            ]
            : []
        );
        $port = $use_ssl ? 8081 : 8080;
        $protocol = $use_ssl ? 'ssl' : 'tcp';
        $proxy = @stream_socket_server(
            "$protocol://localhost:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if (!$proxy) {
            $this->critical("$errstr($errno)");
            throw new \RuntimeException;
        }
        return $proxy;
    }

    private function pipe($client, $server)
    {
        $lines = [];
        $length = null;
        while (true) {
            $line = @fgets($client);
            if ($line === false) {
                break;
            }
            if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $matches)) {
                $length = (int)$matches[1];
            }
            $lines[] = $line;
            if ($line === "\r\n") {
                break;
            }
        }
        $body = $length !== null ? fread($client, $length) : '';
        fwrite($server, implode($lines) . $body);
        fwrite($client, stream_get_contents($server));
    }
}
