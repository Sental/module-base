<?php

declare(strict_types=1);

namespace Reessolutions\Base\Test\Unit\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Reessolutions\Base\Observer\RegisterModuleForHyvaConfig;

/**
 * The observer strips BP from module paths using substr($path, strlen(BP) + 1).
 * BP is a global Magento constant — we define it here if not already set so
 * unit tests can run outside of a full Magento bootstrap.
 */
if (!defined('BP')) {
    define('BP', '/var/www/html');
}

class RegisterModuleForHyvaConfigTest extends TestCase
{
    private ComponentRegistrar&MockObject $componentRegistrar;
    private ModuleListInterface&MockObject $moduleList;
    private RegisterModuleForHyvaConfig $observer;

    protected function setUp(): void
    {
        $this->componentRegistrar = $this->createMock(ComponentRegistrar::class);
        $this->moduleList         = $this->createMock(ModuleListInterface::class);

        $this->observer = new RegisterModuleForHyvaConfig(
            $this->componentRegistrar,
            $this->moduleList
        );
    }

    // -------------------------------------------------------------------------
    // Core behaviour
    // -------------------------------------------------------------------------

    public function testExecuteAddsReessolutionsModulePathsToExtensions(): void
    {
        $this->moduleList->method('getNames')->willReturn([
            'Reessolutions_Base',
            'Reessolutions_ProductVariantUrl',
            'Magento_Catalog',
        ]);

        $this->componentRegistrar
            ->method('getPath')
            ->willReturnMap([
                [ComponentRegistrar::MODULE, 'Reessolutions_Base', BP . '/app/code/Reessolutions/Base'],
                [ComponentRegistrar::MODULE, 'Reessolutions_ProductVariantUrl', BP . '/app/code/Reessolutions/ProductVariantUrl'],
            ]);

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertCount(2, $extensions);
        $this->assertContains(['src' => 'app/code/Reessolutions/Base'], $extensions);
        $this->assertContains(['src' => 'app/code/Reessolutions/ProductVariantUrl'], $extensions);
    }

    public function testExecuteDoesNotAddNonReessolutionsModules(): void
    {
        $this->moduleList->method('getNames')->willReturn([
            'Magento_Catalog',
            'Magento_ConfigurableProduct',
            'Hyva_Theme',
        ]);

        $this->componentRegistrar->expects($this->never())->method('getPath');

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertEmpty($extensions);
    }

    public function testExecutePreservesExistingExtensions(): void
    {
        $existingExtension = ['src' => 'some/other/extension'];

        $this->moduleList->method('getNames')->willReturn([
            'Reessolutions_Base',
        ]);

        $this->componentRegistrar
            ->method('getPath')
            ->willReturn(BP . '/app/code/Reessolutions/Base');

        $config = new DataObject(['extensions' => [$existingExtension]]);
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertCount(2, $extensions);
        $this->assertContains($existingExtension, $extensions);
        $this->assertContains(['src' => 'app/code/Reessolutions/Base'], $extensions);
    }

    public function testExecuteHandlesEmptyModuleList(): void
    {
        $this->moduleList->method('getNames')->willReturn([]);

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertEmpty($extensions);
    }

    // -------------------------------------------------------------------------
    // Path stripping
    // -------------------------------------------------------------------------

    public function testExecuteStripsBasepathFromModulePath(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Reessolutions_Base']);

        $this->componentRegistrar
            ->method('getPath')
            ->willReturn(BP . '/app/code/Reessolutions/Base');

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        // Path must not start with BP
        $this->assertStringNotContainsString(BP, $extensions[0]['src']);

        // Must be relative
        $this->assertSame('app/code/Reessolutions/Base', $extensions[0]['src']);
    }

    public function testExecuteStripsTrailingSlashSeparatorCorrectly(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Reessolutions_Base']);

        // Simulate a path with no trailing slash on BP
        $this->componentRegistrar
            ->method('getPath')
            ->willReturn(BP . '/vendor/reessolutions/module-base');

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertSame('vendor/reessolutions/module-base', $extensions[0]['src']);
    }

    // -------------------------------------------------------------------------
    // Case-insensitive vendor matching
    // -------------------------------------------------------------------------

    public function testVendorMatchingIsCaseInsensitive(): void
    {
        // Module names with different casing of vendor prefix
        $this->moduleList->method('getNames')->willReturn([
            'REESSOLUTIONS_SomeModule',
            'reessolutions_AnotherModule',
            'Reessolutions_NormalModule',
        ]);

        $this->componentRegistrar
            ->method('getPath')
            ->willReturnMap([
                [ComponentRegistrar::MODULE, 'REESSOLUTIONS_SomeModule', BP . '/app/code/Reessolutions/SomeModule'],
                [ComponentRegistrar::MODULE, 'reessolutions_AnotherModule', BP . '/app/code/Reessolutions/AnotherModule'],
                [ComponentRegistrar::MODULE, 'Reessolutions_NormalModule', BP . '/app/code/Reessolutions/NormalModule'],
            ]);

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $extensions = $config->getData('extensions');

        $this->assertCount(3, $extensions);
    }

    public function testVendorMatchingDoesNotMatchPartialVendorName(): void
    {
        // "NotReessolutions_Module" should NOT match even though it contains "reessolutions"
        $this->moduleList->method('getNames')->willReturn([
            'NotReessolutions_Module',
            'SomeReessolutions_Module',
        ]);

        $this->componentRegistrar->expects($this->never())->method('getPath');

        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $this->assertEmpty($config->getData('extensions'));
    }

    // -------------------------------------------------------------------------
    // Config state
    // -------------------------------------------------------------------------

    public function testExecuteSetsExtensionsOnConfigWhenPreviouslyEmpty(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Reessolutions_Base']);

        $this->componentRegistrar
            ->method('getPath')
            ->willReturn(BP . '/app/code/Reessolutions/Base');

        // Config has no extensions key at all
        $config   = new DataObject();
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        $this->assertTrue($config->hasData('extensions'));
    }

    public function testExecuteDoesNotOverwriteExtensionsWithNull(): void
    {
        $this->moduleList->method('getNames')->willReturn([]);

        $existing = [['src' => 'some/path']];
        $config   = new DataObject(['extensions' => $existing]);
        $observer = $this->buildObserver($config);

        $this->observer->execute($observer);

        // No Reessolutions modules found — existing extensions should be unchanged
        $this->assertSame($existing, $config->getData('extensions'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildObserver(DataObject $config): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('config')->willReturn($config);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->with('config')->willReturn($config);

        return $observer;
    }
}