<?php
declare(strict_types=1);

namespace SourceBroker\T3api\Service;

use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\Naming\SerializedNameAnnotationStrategy;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializerInterface;
use SourceBroker\T3api\Domain\Model\AbstractOperation;
use SourceBroker\T3api\Serializer\Accessor\AccessorStrategy;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class Serializer
 */
class SerializerService implements SingletonInterface
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @param ObjectManager $objectManager
     */
    public function injectObjectManager(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param AbstractOperation $operation
     * @param mixed $result
     *
     * @return string
     * @throws Exception
     */
    public function serialize(AbstractOperation $operation, $result)
    {
        return $this->getSerializer($operation)->serialize($result, 'json');
    }

    /**
     * @param AbstractOperation $operation
     *
     * @return SerializerInterface
     * @throws Exception
     */
    protected function getSerializer(AbstractOperation $operation): SerializerInterface
    {
        return SerializerBuilder::create()
            ->setCacheDir($this->getSerializerCacheDirectory())
            ->setDebug(GeneralUtility::getApplicationContext()->isDevelopment())
            ->setSerializationContextFactory(function () use ($operation) {
                $serializationContext = SerializationContext::create()
                    ->setSerializeNull(true);

                if (!empty($operation->getContextGroups())) {
                    $serializationContext->setGroups($operation->getContextGroups());
                }

                return $serializationContext;
            })
            ->configureHandlers(function (HandlerRegistry $registry) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['serializerHandlers'] ?? [] as $handlerClass) {
                    /** @var SubscribingHandlerInterface $handler */
                    $handler = $this->objectManager->get($handlerClass);
                    $registry->registerSubscribingHandler($handler);
                }
            })
            ->configureListeners(function (EventDispatcher $dispatcher) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['serializerSubscribers'] ?? [] as $subscriberClass) {
                    /** @var EventSubscriberInterface $subscriber */
                    $subscriber = $this->objectManager->get($subscriberClass);
                    $dispatcher->addSubscriber($subscriber);
                }
            })
            ->addDefaultHandlers()
            ->setAccessorStrategy($this->objectManager->get(AccessorStrategy::class))
            ->setPropertyNamingStrategy(
                $this->objectManager->get(
                    SerializedNameAnnotationStrategy::class,
                    $this->objectManager->get(IdenticalPropertyNamingStrategy::class)
                )
            )
            // @todo add signal for serializer customization just before build
            ->build();
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getSerializerCacheDirectory(): string
    {
        $cacheDirectory = Environment::getVarPath() . '/cache/code/t3api/jms-serializer';
        if (!is_dir($cacheDirectory)) {
            try {
                GeneralUtility::mkdir_deep($cacheDirectory);
            } catch (\RuntimeException $e) {
                throw new Exception('The directory "' . $cacheDirectory . '" can not be created.', 1313669848, $e);
            }
            if (!is_writable($cacheDirectory)) {
                throw new Exception('The directory "' . $cacheDirectory . '" is not writable.', 1213965200);
            }
        }
        return $cacheDirectory;
    }

    /**
     * @param array $params
     * @throws Exception
     */
    public function clearCache(array $params)
    {
        if (in_array($params['cacheCmd'], ['all', 'system'])) {
            GeneralUtility::flushDirectory($this->getSerializerCacheDirectory(), true, true);
        }
    }
}
