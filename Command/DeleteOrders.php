<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Registry;
use Magento\Framework\Console\Cli;

/**
 * A convenience command to delete orders from Magento.
 */
class DeleteOrders extends Command
{
    const ORDERID_ARGUMENT = "increment_id";

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var Registry
     */
    private $registry;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $iid = $input->getArgument(self::ORDERID_ARGUMENT);

        if (!$iid) {
            $output->writeln("<error>Please supply an order increment ID.</error>");
            return Cli::RETURN_FAILURE;
        }

        try {
            // Required to bypass Magento's core protection against deleting orders
            $this->registry->unregister('isSecureArea');
            $this->registry->register('isSecureArea', true);

            $output->writeln("<info>Searching for order with increment ID $iid...</info>");

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $iid, 'eq')
                ->create();

            $orderList = $this->orderRepository->getList($searchCriteria);

            // getTotalCount() is the strict standard for API Search Results
            if ($orderList->getTotalCount() > 0) {
                foreach ($orderList->getItems() as $existingOrder) {
                    // Use the repository to delete, ensuring M2 cleanup hooks fire
                    $this->orderRepository->delete($existingOrder);
                    $output->writeln("<info>Order $iid successfully deleted.</info>");
                }
            } else {
                $output->writeln("<comment>Order with increment ID $iid doesn't exist.</comment>");
            }

            // Clean up the registry to prevent memory pollution
            $this->registry->unregister('isSecureArea');
            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            // Safely catch DB constraint errors and print them cleanly
            $output->writeln("<error>Failed to delete order $iid: " . $e->getMessage() . "</error>");
            $this->registry->unregister('isSecureArea');
            return Cli::RETURN_FAILURE;
        }
    }
}