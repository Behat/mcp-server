<?php

declare(strict_types=1);

namespace Behat\McpServer\Command;

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpServerCommand extends Command
{
    public function __construct()
    {
        parent::__construct('mcp:serve');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Start the Behat MCP server')
            ->addOption(
                'transport',
                't',
                InputOption::VALUE_REQUIRED,
                'Transport type (stdio or http)',
                'stdio'
            )
            ->addOption(
                'host',
                'o',
                InputOption::VALUE_REQUIRED,
                'Host for HTTP transport',
                '127.0.0.1'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'Port for HTTP transport',
                '8080'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $server = Server::make()
            ->withServerInfo('Behat MCP Server', '1.0.0')
            ->build();

        $server->discover(
            basePath: dirname(__DIR__),
            scanDirs: ['Tools']
        );

        /** @var string $transportType */
        $transportType = $input->getOption('transport');

        if ($transportType === 'http') {
            /** @var string $host */
            $host = $input->getOption('host');
            /** @var string $portString */
            $portString = $input->getOption('port');
            $port = (int) $portString;

            $transport = new StreamableHttpServerTransport(
                host: $host,
                port: $port,
            );
        } else {
            $transport = new StdioServerTransport();
        }

        $server->listen($transport);

        return Command::SUCCESS;
    }
}
