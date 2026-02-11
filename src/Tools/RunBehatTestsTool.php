<?php

declare(strict_types=1);

namespace Behat\McpServer\Tools;

use Behat\Behat\ApplicationFactory;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RunBehatTestsTool
{
    private const PROTECTED_OPTIONS = ['--format', '--out', '-f', '-o'];

    /**
     * @param list<string>|null $paths
     * @param array<string, mixed>|null $additionalOptions
     *
     * @return array<string|int, mixed>
     */
    #[McpTool(
        name: 'run-behat-tests',
        description: 'Run all Behat BDD tests in the current project using the default config file. Use the additional parameters if you want to use a different config file or restrict the run to a specific profile, suite, path or scenario',
        annotations: new ToolAnnotations(
            title: 'Run Behat Tests',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false
        )
    )]
    public function runBehatTests(
        #[Schema(description: 'Path to a Behat configuration file (optional)')]
        ?string $config = null,
        #[Schema(description: 'Name of a profile to use (optional)')]
        ?string $profile = null,
        #[Schema(description: 'Name of a suite to use (optional)')]
        ?string $suite = null,
        #[Schema(description: 'List of paths to execute (optional)', items: ['type' => 'string'])]
        ?array $paths = null,
        #[Schema(type: 'object', description: 'Additional command-line options as key-value pairs (optional)', additionalProperties: true)]
        ?array $additionalOptions = null,
    ): array {
        $outputFile = tempnam(sys_get_temp_dir(), 'behat_output_');
        if ($outputFile === false) {
            return ['error' => 'Failed to create temporary output file'];
        }

        $applicationFactory = new ApplicationFactory();
        $application = $applicationFactory->createApplication();
        $application->setAutoExit(false);

        $inputArgs = [
            '--no-colors' => true,
            '--format' => ['json'],
            '--out' => [$outputFile],
        ];

        if ($config !== null) {
            $inputArgs['--config'] = $config;
        }

        if ($profile !== null) {
            $inputArgs['--profile'] = $profile;
        }

        if ($suite !== null) {
            $inputArgs['--suite'] = $suite;
        }

        if ($additionalOptions !== null) {
            foreach ($additionalOptions as $option => $value) {
                if (!in_array($option, self::PROTECTED_OPTIONS, true)) {
                    $inputArgs[$option] = $value;
                }
            }
        }

        if ($paths !== null) {
            $inputArgs['paths'] = $paths;
        }

        $arrayInput = new ArrayInput($inputArgs);
        $bufferedOutput = new BufferedOutput();

        $application->run($arrayInput, $bufferedOutput);

        $result = file_get_contents($outputFile);
        unlink($outputFile);

        if ($result === false) {
            return ['error' => 'Failed to read output file'];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return ['error' => 'Failed to decode JSON output', 'raw' => $result];
        }

        return $decoded;
    }
}
