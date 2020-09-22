<?php

namespace Camiloo\Channelunity\Plugin;

use \Camiloo\Channelunity\Model\Helper;

/**
 * Implement code to skip the CSRF check on our custom route.
 */
class CsrfValidatorSkipPlugin
{

    private $helper;

    public function __construct(
        Helper $helper
    ) {
        $this->helper = $helper;
    }

    /**
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     */
    public function aroundValidate(
        $subject,
        \Closure $proceed,
        $request,
        $action
    ) {
        if (strpos($request->getModuleName(), 'channelunity') !== false) {
            $this->helper->logInfo("Skip CSRF check");
            return; // Skip CSRF check
        }
        $proceed($request, $action); // Proceed Magento 2 core functionality
    }
}
