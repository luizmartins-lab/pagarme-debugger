<?php
namespace Infracommerce\PagarmeDebugger\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Data extends AbstractHelper
{
    const XML_PATH_SYSTEM_CONFIG = "debugger/pagarme";

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);

        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
    }
    /**
     * Get config from system configs module
     *
     * @param string $path config path
     * @return string value
     */
    public function getStoreConfig($path)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_SYSTEM_CONFIG . '/' . $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check if analyzer payment is enabled
     *
     * @return string
     */
    public function isActive()
    {
        return $this->getStoreConfig("active");
    }
}
