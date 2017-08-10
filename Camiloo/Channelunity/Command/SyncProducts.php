<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Products;
use Magento\Framework\App\State;

/**
 * Sends full product information for each SKU in the Magento catalog to CU.
 * Note this only works for the Default Store View.
 */
class SyncProducts extends Command
{
    private $cuproducts;
    private $helper;
    
    public function __construct(
        Helper $helper,
        Products $cuproducts,
        State $state
    ) {
        parent::__construct();
        $state->setAreaCode("admin");
        $this->helper = $helper;
        $this->cuproducts = $cuproducts;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('channelunity:sync_products')
            ->setDescription('Sync products\' data to ChannelUnity');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $request = new \stdClass;
        $request->RangeFrom = 0;
        $request->StoreviewId = 0;
        
        do {
            $xml = $this->cuproducts->doRead($request, true);
            $request->RangeFrom = $this->cuproducts->getRangeNext();
            $this->helper->logInfo($xml);
            $output->writeln($xml);
        
            // Send to ChannelUnity
            $response = $this->helper->postToChannelUnity($xml, 'ProductData');
            $this->helper->logInfo($response);
            $output->writeln($response);
        } while ($request->RangeFrom > 0);
    }
}
