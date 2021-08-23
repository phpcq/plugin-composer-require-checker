<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

// phpcs:disable PSR12.Files.FileHeader.IncorrectOrder - This is not the file header but psalm annotations
/**
 * @psalm-type TSeverity = TaskReportInterface::SEVERITY_FATAL
 *  |TaskReportInterface::SEVERITY_MAJOR
 *  |TaskReportInterface::SEVERITY_MINOR
 *  |TaskReportInterface::SEVERITY_MARGINAL
 *  |TaskReportInterface::SEVERITY_INFO
 *  |TaskReportInterface::SEVERITY_NONE
 */
return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'composer-require-checker';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeStringOption('config_file', 'Path to configuration file');
        $configOptionsBuilder
            ->describeStringOption('composer_file', 'Path to the composer.json (relative to project root)')
            ->isRequired()
            ->withDefaultValue('composer.json');
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to composer-require-checker.' .
                'For valid flags refer to the composer-require-checker documentation.',
            )
        ;
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $composerJson = $config->getString('composer_file');

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('composer-require-checker', $this->buildArguments($config, $environment))
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformerFactory($composerJson))
            ->build();
    }

    /** @psalm-return array<int, string> */
    private function buildArguments(PluginConfigurationInterface $config, EnvironmentInterface $environment): array
    {
        $arguments   = ['check'];
        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath() . '/';

        if ($config->has('config_file')) {
            $arguments[] = '--config-file=' . $projectRoot . $config->getString('config_file');
        }
        $arguments[] = $projectRoot . $config->getString('composer_file');

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = (string) $value;
            }
        }

        return $arguments;
    }

    private function createOutputTransformerFactory(string $composerFile): OutputTransformerFactoryInterface
    {
        return new class ($composerFile) implements OutputTransformerFactoryInterface {
            /** @var string */
            private $composerFile;

            public function __construct(string $composerFile)
            {
                $this->composerFile = $composerFile;
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->composerFile, $report) implements OutputTransformerInterface {

                    // eg. ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                    private const REGEX_HEADER = '#ComposerRequireChecker [0-9]*\.[0-9]*\.[0-9]*#';

                    private const REGEX_USAGE_SUMMARY =
                        '#check \[--config-file CONFIG-FILE] \[--ignore-parse-errors] \[--] \[<composer-json>]#';

                    /** @var string */
                    private $composerFile;
                    /** @var BufferedLineReader */
                    private $data;
                    /** @var TaskReportInterface */
                    private $report;

                    public function __construct(string $composerFile, TaskReportInterface $report)
                    {
                        $this->composerFile = $composerFile;
                        $this->report       = $report;
                        $this->data         = BufferedLineReader::create();
                    }

                    public function write(string $data, int $channel): void
                    {
                        $this->data->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->process();
                        $this->report->close(0 === $exitCode
                            ? TaskReportInterface::STATUS_PASSED
                            : TaskReportInterface::STATUS_FAILED);
                    }

                    /** @psalm-param TSeverity $severity */
                    private function logDiagnostic(string $message, string $severity): void
                    {
                        $this->report->addDiagnostic($severity, $message)->forFile($this->composerFile)->end()->end();
                    }

                    private function process(): void
                    {
                        // FIXME: this should be a state machine to parse incomplete output but for the moment it works.
                        $unknown = [];
                        while (null !== $line = $this->data->peek()) {
                            if ($this->isLineIgnored($line)) {
                                $this->data->fetch();
                                continue;
                            }

                            // Could be notification of missing dependencies.
                            // In LocateComposerPackageDirectDependenciesSourceFiles.php line 52:
                            if (preg_match('#In LocateComposerPackageDirectDependenciesSourceFiles\.php#', $line)) {
                                $this->data->fetch();
                                $this->processLocateComposerPackageDirectDependenciesSourceFiles();
                                continue;
                            }

                            // Missing dependencies found, parse the table.
                            if (preg_match('#The following unknown symbols were found:#', $line)) {
                                $this->data->fetch();
                                $this->processMissingSymbols();
                                continue;
                            }

                            if (preg_match('#There were no unknown symbols found\.#', $line)) {
                                $this->data->fetch();
                                $this->logDiagnostic($line, 'info');
                                continue;
                            }
                            $unknown[] = $line;
                            $this->data->fetch();
                        }
                        if ([] !== $unknown) {
                            $this->logDiagnostic(
                                'Did not understand the following output from composer-require-checker: ' .
                                implode("\n", $unknown),
                                TaskReportInterface::SEVERITY_MINOR
                            );
                            $this->report
                                ->addAttachment('composer-require-checker.log')
                                    ->fromString($this->data->getData())
                                ->end();
                        }
                    }

                    private function isLineIgnored(string $line): bool
                    {
                        if ('' === $line) {
                            return true;
                        }
                        // If it is the version header, ignore it.
                        if (preg_match(self::REGEX_HEADER, $line)) {
                            return true;
                        }

                        // If it is the usage suffix, ignore it.
                        if (preg_match(self::REGEX_USAGE_SUMMARY, $line)) {
                            $this->data->fetch();
                            return true;
                        }

                        return false;
                    }

                    private function processLocateComposerPackageDirectDependenciesSourceFiles(): void
                    {
                        // Format is:
                        // \n\n<message>\n
                        $error = '';
                        // Buffer up until two empty lines.
                        while (null !== $line = $this->data->fetch()) {
                            if (!$this->isLineIgnored($line)) {
                                $error .= $line;
                            }
                        }
                        if ('' !== $error) {
                            $this->logDiagnostic($error, TaskReportInterface::SEVERITY_FATAL);
                        }
                    }

                    private function processMissingSymbols(): void
                    {
                        /*
                         * The following unknown symbols were found:
                         * +----------------+--------------------+
                         * | unknown symbol | guessed dependency |
                         * +----------------+--------------------+
                         * | DOMDocument    | ext-dom            |
                         * | DOMElement     | ext-dom            |
                         * | DOMNode        | ext-dom            |
                         * +----------------+--------------------+
                        */

                        // Strip table head.
                        foreach (
                            [
                                '#^\+-*\+-*\+$#',
                                '#\|\s*unknown symbol\s*\|\s*guessed dependency\s*\|#',
                                '#^\+-*\+-*\+$#',
                            ] as $regex
                        ) {
                            if (1 !== preg_match($regex, $line = (string) $this->data->fetch())) {
                                throw new RuntimeException('Failed to parse line: ' . $line);
                            }
                        }
                        // List missing dependencies.
                        $dependencies = [];
                        $unknown      = [];
                        while (null !== $line = $this->data->fetch()) {
                            if (preg_match('#^\+-*\+-*\+$#', $line)) {
                                // End of table.
                                break;
                            }
                            if (!preg_match('#\|\s*(?<symbol>.*)\s*\|\s*(?<dependency>.*)?\s*\|#', $line, $matches)) {
                                throw new RuntimeException('Failed to parse line: ' . $line);
                            }
                            $dependency = trim($matches['dependency'] ?? '');
                            $symbol     = trim($matches['symbol'] ?? '');
                            if ('' === $dependency) {
                                $unknown[] = $symbol;
                                continue;
                            }
                            if (!isset($dependencies[$dependency])) {
                                $dependencies[$dependency] = [];
                            }
                            $dependencies[$dependency][] = $symbol;
                        }
                        foreach ($dependencies as $dependency => $symbols) {
                            $this->logDiagnostic(
                                sprintf(
                                    'Missing dependency "%1$s" (used symbols: "%2$s")',
                                    $dependency,
                                    implode('", "', $symbols)
                                ),
                                TaskReportInterface::SEVERITY_MAJOR
                            );
                        }
                        if (!empty($unknown)) {
                            $this->logDiagnostic(
                                sprintf(
                                    'Unknown symbols found: "%1$s" - is there a dependency missing?',
                                    implode('", "', $unknown)
                                ),
                                TaskReportInterface::SEVERITY_FATAL
                            );
                        }
                    }
                };
            }
        };
    }
};
