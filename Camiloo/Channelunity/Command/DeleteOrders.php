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
use Symfony\Component\Console\Input\InputArgument;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Registry;

/**
 * A convenience command to delete orders from Magento.
 */
class DeleteOrders extends Command
{
    const ORDERID_ARGUMENT = "increment_id";
    private $orderRepository;
    private $searchCriteriaBuilder;
    private $registry;
    
    public function __construct(
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Registry $registry
    ) {
        parent::__construct();
        
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->registry = $registry;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('channelunity:delete_order')
            ->setDescription('Deletes a given order by increment ID');
        
        $this->setDefinition([
            new InputArgument(self::ORDERID_ARGUMENT, InputArgument::OPTIONAL, "Increment ID")
        ]);
        
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $iid = $input->getArgument(self::ORDERID_ARGUMENT);
        if ($iid) {
            $this->registry->register('isSecureArea', 'true');
            $output->writeln("<info>Deleting order with increment ID $iid</info>");
            
            $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $iid, 'eq')->create();

            $orderList = $this->orderRepository->getList($searchCriteria);
            if ($orderList->getSize() > 0) {
                $olist = $orderList->getItems();
                foreach ($olist as $existingOrder) {
                    $existingOrder->delete();
                    $output->writeln("<info>Order $iid deleted</info>");
                }
            } else {
                $output->writeln("<info>Order with increment ID $iid doesn't exist</info>");
            }
        } else {
            $output->writeln("<info>Please supply an order increment ID</info>");
        }
    }
}
