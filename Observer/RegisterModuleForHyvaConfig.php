<?php

declare(strict_types=1);

namespace Reessolutions\Base\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class RegisterModuleForHyvaConfig implements ObserverInterface
{
    private const VENDOR_NAME = 'reessolutions';
    private $componentRegistrar;
    private $moduleList;

    public function __construct(
        ComponentRegistrar $componentRegistrar,
        ModuleListInterface $moduleList
    )
    {
        $this->componentRegistrar = $componentRegistrar;
        $this->moduleList = $moduleList;
    }

    public function execute(Observer $event)
    {
        $config = $event->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];

        $modules = $this->getModulesByVendor(self::VENDOR_NAME);

        foreach ($modules as $module) {
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $module);
            // Only use the path relative to the Magento base dir
            $extensions[] = ['src' => substr($path, strlen(BP) + 1)];

        }

        $config->setData('extensions', $extensions);
    }

    /**
     * Get enabled modules filtered by vendor name
     * 
     * @param string $vendorName e.g. "Reessolutions"
     * @return array
     */
    private function getModulesByVendor(string $vendorName): array
    {
        $enabledModules = $this->moduleList->getNames();
        
        return array_filter($enabledModules, function ($moduleName) use ($vendorName) {
            // Matches "VendorName_" at the start of the string
            return stripos($moduleName, $vendorName . '_') === 0;
        });
    }
}
