<?php

/**
 * This file is part of CaptainHook
 *
 * (c) Sebastian Feldmann <sf@sebastian-feldmann.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CaptainHook\App\Runner;

use CaptainHook\App\Config;
use CaptainHook\App\Config\Mockery as ConfigMockery;
use CaptainHook\App\Console\IO\DefaultIO;
use CaptainHook\App\Console\IO\Mockery as IOMockery;
use CaptainHook\App\Hook\Restriction;
use CaptainHook\App\Hooks;
use CaptainHook\App\Mockery as CHMockery;
use CaptainHook\App\Plugin\DummyConstrainedPlugin;
use CaptainHook\App\Plugin\DummyConstrainedHookPlugin;
use CaptainHook\App\Plugin\DummyConstrainedHookPluginAlt;
use CaptainHook\App\Plugin\DummyPlugin;
use CaptainHook\App\Plugin\DummyHookPlugin;
use CaptainHook\App\Plugin\DummyHookPluginSkipsActions;
use Exception;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class HookTest extends TestCase
{
    use ConfigMockery;
    use IOMockery;
    use CHMockery;

    protected function setUp(): void
    {
        // Ensure the static properties on dummy plugins are all set to their defaults.
        DummyHookPlugin::$beforeHookCalled = 0;
        DummyHookPlugin::$beforeActionCalled = 0;
        DummyHookPlugin::$afterActionCalled = 0;
        DummyHookPlugin::$afterHookCalled = 0;
        DummyHookPluginSkipsActions::$skipStartIn = 'beforeHook';
        DummyHookPluginSkipsActions::$skipStartAt = 1;
        DummyConstrainedHookPlugin::$restriction = null;
        DummyConstrainedHookPluginAlt::$restriction = null;
        DummyConstrainedPlugin::$restriction = null;
    }

    protected function tearDown(): void
    {
        // Reset the static properties on dummy plugins to their defaults.
        DummyHookPlugin::$beforeHookCalled = 0;
        DummyHookPlugin::$beforeActionCalled = 0;
        DummyHookPlugin::$afterActionCalled = 0;
        DummyHookPlugin::$afterHookCalled = 0;
        DummyHookPluginSkipsActions::$skipStartIn = 'beforeHook';
        DummyHookPluginSkipsActions::$skipStartAt = 1;
        DummyConstrainedHookPlugin::$restriction = null;
        DummyConstrainedHookPluginAlt::$restriction = null;
        DummyConstrainedPlugin::$restriction = null;
    }

    /**
     * Tests Hook::getActionRunner
     */
    public function testGetExecMethod(): void
    {
        $php = Hook::getExecMethod('php');
        $cli = Hook::getExecMethod('cli');

        $this->assertEquals('executePhpAction', $php);
        $this->assertEquals('executeCliAction', $cli);
    }

    /**
     * Tests Hook::getActionRunner
     */
    public function testGetRunnerFailure(): void
    {
        $this->expectException(Exception::class);

        Hook::getExecMethod('foo');
    }

    public function testGetName(): void
    {
        $io = $this->createIOMock();
        $config = $this->createConfigMock();
        $repo = $this->createRepositoryMock();

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };

        $this->assertSame('pre-commit', $runner->getName());
    }

    public function testShouldSkipActionsIsFalseByDefault(): void
    {
        $io = $this->createIOMock();
        $config = $this->createConfigMock();
        $repo = $this->createRepositoryMock();

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };

        $this->assertFalse($runner->shouldSkipActions());
    }

    public function testShouldSkipActionsCanBeSetToTrue(): void
    {
        $io = $this->createIOMock();
        $config = $this->createConfigMock();
        $repo = $this->createRepositoryMock();

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };

        $this->assertTrue($runner->shouldSkipActions(true));
        $this->assertTrue($runner->shouldSkipActions());
    }

    public function testShouldSkipActionsCanBeSetToFalse(): void
    {
        $io = $this->createIOMock();
        $config = $this->createConfigMock();
        $repo = $this->createRepositoryMock();

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };

        $runner->shouldSkipActions(true);

        $this->assertFalse($runner->shouldSkipActions(false));
        $this->assertFalse($runner->shouldSkipActions());
    }

    public function testRunHookWithPlugins(): void
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $this->markTestSkipped('not tested on windows');
        }

        $restriction1 = new Restriction(Hooks::POST_CHECKOUT);
        DummyConstrainedPlugin::$restriction = $restriction1;
        DummyConstrainedHookPluginAlt::$restriction = $restriction1;

        $restriction2 = new Restriction(Hooks::PRE_COMMIT);
        DummyConstrainedHookPlugin::$restriction = $restriction2;

        $pluginConfig1 = new Config\Plugin(DummyPlugin::class);
        $pluginConfig2 = new Config\Plugin(DummyHookPlugin::class);
        $pluginConfig3 = new Config\Plugin(DummyConstrainedPlugin::class);
        $pluginConfig4 = new Config\Plugin(DummyConstrainedHookPlugin::class);
        $pluginConfig5 = new Config\Plugin(DummyConstrainedHookPluginAlt::class);

        $config = $this->createConfigMock();
        $config->method('failOnFirstError')->willReturn(true);
        $config->method('getPlugins')->willReturn([
            $pluginConfig1,
            $pluginConfig2,
            $pluginConfig3,
            $pluginConfig4,
            $pluginConfig5,
        ]);

        $io = $this->createIOMock();
        $repo = $this->createRepositoryMock();
        $hookConfig = $this->createHookConfigMock();
        $actionConfig = $this->createActionConfigMock();
        $actionConfig->method('getAction')->willReturn(CH_PATH_FILES . '/bin/success');
        $hookConfig->expects($this->atLeastOnce())->method('isEnabled')->willReturn(true);
        $hookConfig->expects($this->once())->method('getActions')->willReturn([$actionConfig, $actionConfig]);
        $config->expects($this->once())->method('getHookConfig')->willReturn($hookConfig);
        $io->expects($this->atLeast(1))->method('write');

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };
        $runner->run();

        $this->assertSame(2, DummyHookPlugin::$beforeHookCalled);
        $this->assertSame(4, DummyHookPlugin::$beforeActionCalled);
        $this->assertSame(4, DummyHookPlugin::$afterActionCalled);
        $this->assertSame(2, DummyHookPlugin::$afterHookCalled);
    }

    public function testRunHookSkipsActionsFromPluginBeforeHook(): void
    {
        DummyHookPluginSkipsActions::$skipStartAt = 1;

        $pluginConfig = new Config\Plugin(DummyHookPluginSkipsActions::class);

        $config = $this->createConfigMock();
        $config->method('failOnFirstError')->willReturn(true);
        $config->method('getPlugins')->willReturn([$pluginConfig]);

        $io = $this->createIOMock();
        $repo = $this->createRepositoryMock();
        $hookConfig = $this->createHookConfigMock();
        $actionConfig = $this->createActionConfigMock();
        $hookConfig->expects($this->atLeastOnce())->method('isEnabled')->willReturn(true);
        $hookConfig->expects($this->once())->method('getActions')->willReturn([
            $actionConfig,
            $actionConfig,
        ]);
        $config->expects($this->once())->method('getHookConfig')->willReturn($hookConfig);
        $io->expects($this->atLeast(1))->method('write');

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };
        $runner->run();

        $this->assertSame(1, DummyHookPlugin::$beforeHookCalled);
        $this->assertSame(0, DummyHookPlugin::$beforeActionCalled);
        $this->assertSame(0, DummyHookPlugin::$afterActionCalled);
        $this->assertSame(1, DummyHookPlugin::$afterHookCalled);
    }

    public function testRunHookSkipsActionsFromPluginBeforeAction(): void
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $this->markTestSkipped('not tested on windows');
        }

        DummyHookPluginSkipsActions::$skipStartIn = 'beforeAction';
        DummyHookPluginSkipsActions::$skipStartAt = 3;

        $pluginConfig = new Config\Plugin(DummyHookPluginSkipsActions::class);

        $config = $this->createConfigMock();
        $config->method('failOnFirstError')->willReturn(true);
        $config->method('getPlugins')->willReturn([$pluginConfig]);

        $io = $this->createIOMock();
        $repo = $this->createRepositoryMock();
        $hookConfig = $this->createHookConfigMock();
        $actionConfig = $this->createActionConfigMock();
        $actionConfig->method('getAction')->willReturn(CH_PATH_FILES . '/bin/success');
        $hookConfig->expects($this->atLeastOnce())->method('isEnabled')->willReturn(true);
        $hookConfig->expects($this->once())->method('getActions')->willReturn([
            $actionConfig,
            $actionConfig,
            $actionConfig,
            $actionConfig,
            $actionConfig,
        ]);
        $config->expects($this->once())->method('getHookConfig')->willReturn($hookConfig);
        $io->expects($this->atLeast(1))->method('write');

        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };
        $runner->run();

        $this->assertSame(1, DummyHookPlugin::$beforeHookCalled);
        $this->assertSame(3, DummyHookPlugin::$beforeActionCalled);
        $this->assertSame(2, DummyHookPlugin::$afterActionCalled);
        $this->assertSame(1, DummyHookPlugin::$afterHookCalled);
    }

    /**
     * @dataProvider allowFailureDataProvider
     */
    public function testAllowsFailureIfOptionIsEnabled(
        Config\Action $action,
        string $expectedRegexInOutput
    ): void {
        $bufferedOutput = new BufferedOutput();
        $io = new DefaultIO(null, new StringInput(''), $bufferedOutput);
        $config = new Config('');
        $config->getHookConfig(Hooks::PRE_COMMIT)->setEnabled(true);
        $config->getHookConfig(Hooks::PRE_COMMIT)->addAction($action);
        $config->getHookConfig(Hooks::PRE_COMMIT)->addAction(
            new Config\Action('echo hello')
        );
        $repo = $this->createRepositoryMock();
        $runner = new class ($io, $config, $repo) extends Hook {
            protected $hook = Hooks::PRE_COMMIT;
        };

        $runner->run();

        $output = $bufferedOutput->fetch();
        $this->assertMatchesRegularExpression($expectedRegexInOutput, $output);
        $this->assertMatchesRegularExpression('/ - echo hello .*: done/', $output);
    }

    public function allowFailureDataProvider(): Generator
    {
        yield 'actions with allow_failure do not block further actions from being executed' => [
            new Config\Action(
                'thisCommandDoesNotExistAndFails',
                ['allow_failure' => true]
            ),
            '/ - thisCommandDoesNotExistAndFails .*: warning \(failed, but allow_failure enabled\)/',
        ];

        $error = 'This action failed, but that is ok. Just FYI';
        yield 'shows error message if any is set' => [
            new Config\Action(
                'fooBarBaz',
                [
                    'allow_failure' => true,
                    'error' => $error,
                ]
            ),
            '/ - fooBarBaz .*: warning \(failed, but allow_failure enabled\)\n' . $error . '/',
        ];
    }
}
