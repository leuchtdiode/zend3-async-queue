<?php
namespace AsyncQueue\Queue;

use AsyncQueue\Item\EntitySaver;
use AsyncQueue\Item\Filter\ProcessAfter as ProcessAfterFilter;
use AsyncQueue\Item\Filter\Status as StatusFilter;
use AsyncQueue\Item\Order\ProcessAfter as ProcessAfterOrder;
use AsyncQueue\Item\ProcessData;
use AsyncQueue\Item\Processor as ItemProcessor;
use AsyncQueue\Item\Provider;
use AsyncQueue\Item\Status;
use Common\Db\FilterChain;
use Common\Db\OrderChain;
use DateTime;
use Exception;
use Psr\Container\ContainerInterface;

class Processor
{
	/**
	 * @var array
	 */
	private $config;

	/**
	 * @var ContainerInterface
	 */
	private $container;

	/**
	 * @var Provider
	 */
	private $itemProvider;

	/**
	 * @var EntitySaver
	 */
	private $entitySaver;

	/**
	 * @param array $config
	 * @param ContainerInterface $container
	 * @param Provider $itemProvider
	 * @param EntitySaver $entitySaver
	 */
	public function __construct(
		array $config,
		ContainerInterface $container,
		Provider $itemProvider,
		EntitySaver $entitySaver
	)
	{
		$this->config       = $config;
		$this->container    = $container;
		$this->itemProvider = $itemProvider;
		$this->entitySaver  = $entitySaver;
	}

	/**
	 * @throws Exception
	 */
	public function process()
	{
		$now = new DateTime();

		$items = $this->itemProvider->filter(
			FilterChain::create()
				->addFilter(StatusFilter::is(Status::PENDING))
				->addFilter(ProcessAfterFilter::before($now)),
			OrderChain::create()
				->addOrder(ProcessAfterOrder::asc())
		);

		foreach ($items as $item)
		{
			$entity = $item->getEntity();

			$type = $item->getType();

			$itemProcessorClass = $this->config['async-queue']['processors'][$type] ?? null;

			if (!$itemProcessorClass || !$this->container->has($itemProcessorClass))
			{
				throw new Exception('Could not find processor class type ' . $type . '. Did you specify in config?');
			}

			$itemProcessor = $this->container->get($itemProcessorClass);

			if (!$itemProcessor instanceof ItemProcessor)
			{
				throw new Exception('Specified item processor ' . $itemProcessorClass . ' does not implement Processor interface');
			}

			$processResult = $itemProcessor->process(
				new ProcessData($item->getPayLoad())
			);

			if (($success = $processResult->isSuccess()) !== null)
			{
				$entity->setStatus(
					$success ? Status::SUCCESS : Status::FAILED
				);
			}
			else if (($retryInSeconds = $processResult->getRetryInSeconds()))
			{
				$processAfter = new DateTime();
				$processAfter->modify('+ ' . $retryInSeconds . ' seconds');

				$entity->setProcessAfter(
					$processAfter
				);
			}

			$this->entitySaver->save($entity);
		}
	}
}