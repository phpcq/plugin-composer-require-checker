<?php

declare(strict_types=1);

namespace Phpcq\ComposerRequireCheckerPluginTest;

use Generator;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Task\PhpTaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\ReportWritingTaskInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_encode;

/**
 * @coversNothing
 */
final class ComposerRequireCheckerPluginTest extends TestCase
{
    private function instantiate(): DiagnosticsPluginInterface
    {
        return include dirname(__DIR__) . '/src/composer-require-checker.php';
    }

    public function testPluginName(): void
    {
        self::assertSame('composer-require-checker', $this->instantiate()->getName());
    }

    public function testPluginDescribesConfig(): void
    {
        $configOptionsBuilder = $this->getMockForAbstractClass(PluginConfigurationBuilderInterface::class);

        $this->instantiate()->describeConfiguration($configOptionsBuilder);

        // We assume it worked out as the plugin did execute correctly.
        $this->addToAssertionCount(1);
    }

    public function testPluginCreatesDiagnosticTasks(): void
    {
        $config = $this->getMockForAbstractClass(PluginConfigurationInterface::class);
        $environment = $this->getMockForAbstractClass(EnvironmentInterface::class);

        $this->instantiate()->createDiagnosticTasks($config, $environment);

        // We assume it worked out as the plugin did execute correctly.
        $this->addToAssertionCount(1);
    }

    public function provideTestReportData(): Generator
    {
        yield 'Broken output' => [
            'diagnostics' => [
                [
                    'severity' => TaskReportInterface::SEVERITY_FATAL,
                    'message' => 'Unable to parse output: Syntax error'
                ]
            ],
            'output' => 'foo bar'
        ];

        yield 'No unknown symbols found' => [
            'diagnostics' => [
                [
                    'severity' => TaskReportInterface::SEVERITY_INFO,
                    'message' => 'There were no unknown symbols found.'
                ]
            ],
            'output' => json_encode(['unknown-symbols' => []])
        ];

        yield 'Unknown symbols detected' => [
            'diagnostics' => [
                [
                    'severity' => TaskReportInterface::SEVERITY_MAJOR,
                    'message' => 'Missing dependency "ext-json" (used symbols: "json_encode", "json_decode")'
                ],
                [
                    'severity' => TaskReportInterface::SEVERITY_MAJOR,
                    'message' => 'Missing dependency "vendor/foo" (used symbols: "Foo\Bar")'
                ],
                [
                    'severity' => TaskReportInterface::SEVERITY_MAJOR,
                    'message' => 'Missing dependency "vendor/bar" (used symbols: "Foo\Bar")'
                ]
            ],
            'output' => json_encode(
                [
                    'unknown-symbols' => [
                        'json_encode' => ['ext-json'],
                        'json_decode' => ['ext-json'],
                        'Foo\Bar' => ['vendor/foo', 'vendor/bar']
                    ]
                ]
            )
        ];
    }

    /** @dataProvider provideTestReportData */
    public function testReport(array $diagnostics, string $output): void
    {
        $config = $this->getMockForAbstractClass(PluginConfigurationInterface::class);
        $environment = $this->getMockForAbstractClass(EnvironmentInterface::class);
        $transformerFactory = null;
        $task = $this->getMockForAbstractClass(ReportWritingTaskInterface::class);

        $taskBuilder = $this->getMockForAbstractClass(PhpTaskBuilderInterface::class);
        $taskBuilder
            ->expects($this->once())
            ->method('build')
            ->withAnyParameters()
            ->willReturn($task);

        $taskBuilder
            ->expects($this->once())
            ->method('withWorkingDirectory')
            ->withAnyParameters()
            ->willReturn($taskBuilder);

        $taskBuilder
            ->expects($this->once())
            ->method('withOutputTransformer')
            ->withAnyParameters()
            ->willReturnCallback(
                function (OutputTransformerFactoryInterface $factory) use (&$transformerFactory, $taskBuilder) {
                    $transformerFactory = $factory;

                    return $taskBuilder;
                }
            );

        $taskFactory = $this->getMockForAbstractClass(TaskFactoryInterface::class);
        $taskFactory
            ->expects($this->once())
            ->method('buildRunPhar')
            ->withAnyParameters()
            ->willReturn($taskBuilder);

        $environment
            ->expects($this->once())
            ->method('getTaskFactory')
            ->willReturn($taskFactory);

        iterator_to_array($this->instantiate()->createDiagnosticTasks($config, $environment));

        self::assertInstanceOf(OutputTransformerFactoryInterface::class, $transformerFactory);

        $report = $this->getMockForAbstractClass(TaskReportInterface::class);
        $report
            ->expects($this->exactly(count($diagnostics)))
            ->method('addDiagnostic')
            ->withConsecutive(... $diagnostics);

        $transformer = $transformerFactory->createFor($report);
        $transformer->write($output, OutputInterface::CHANNEL_STDOUT);
        $transformer->finish(0);
    }
}
