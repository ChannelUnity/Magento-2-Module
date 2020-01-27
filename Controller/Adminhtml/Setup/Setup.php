<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Controller\Adminhtml\Setup;

use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Setup extends \Magento\Backend\App\Action
{
    
    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }
    
    public function execute()
    {
        $page_object = $this->pageFactory->create();
        
        $page_object->setActiveMenu('Camiloo_Channelunity::setup');
        $page_object->getConfig()->getTitle()->prepend(__('ChannelUnity Setup'));
        return $page_object;
    }
    
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Camiloo_Channelunity::mainrule');
    }
}
