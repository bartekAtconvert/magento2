<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Newsletter\Test\Unit\Block\Adminhtml\Queue;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Newsletter\Block\Adminhtml\Queue\Preview as QueuePreview;
use Magento\Newsletter\Model\Queue;
use Magento\Newsletter\Model\QueueFactory;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\Template;
use Magento\Newsletter\Model\TemplateFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PreviewTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Template|MockObject
     */
    private $templateMock;

    /**
     * @var RequestInterface|MockObject
     */
    private $requestMock;

    /**
     * @var Subscriber|MockObject
     */
    private $subscriberMock;

    /**
     * @var Queue|MockObject
     */
    private $queueMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var QueuePreview
     */
    private $preview;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $eventManager = $this->createMock(ManagerInterface::class);
        $context->expects($this->once())->method('getEventManager')
            ->will($this->returnValue($eventManager));
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $context->expects($this->once())->method('getScopeConfig')
            ->will($this->returnValue($scopeConfig));
        $this->requestMock = $this->createMock(Http::class);
        $context->expects($this->once())->method('getRequest')
            ->will($this->returnValue($this->requestMock));
        $this->storeManagerMock = $this->createPartialMock(
            StoreManager::class,
            ['getStores', 'getDefaultStoreView']
        );
        $context->expects($this->once())->method('getStoreManager')
            ->will($this->returnValue($this->storeManagerMock));
        $appState = $this->createMock(State::class);
        $context->expects($this->once())->method('getAppState')
            ->will($this->returnValue($appState));

        $backendSession = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->getMock();

        $context->expects($this->once())
            ->method('getBackendSession')
            ->willReturn($backendSession);

        $templateFactory = $this->createPartialMock(TemplateFactory::class, ['create']);
        $this->templateMock = $this->createMock(Template::class);
        $templateFactory->expects($this->once())
            ->method('create')
            ->will($this->returnValue($this->templateMock));

        $subscriberFactory = $this->createPartialMock(SubscriberFactory::class, ['create']);
        $this->subscriberMock = $this->createMock(Subscriber::class);
        $subscriberFactory->expects($this->once())
            ->method('create')
            ->will($this->returnValue($this->subscriberMock));

        $queueFactory = $this->createPartialMock(QueueFactory::class, ['create']);
        $this->queueMock = $this->createPartialMock(Queue::class, ['load']);
        $queueFactory->expects($this->any())
            ->method('create')
            ->will($this->returnValue($this->queueMock));

        $this->objectManager = new ObjectManager($this);

        $escaper = $this->objectManager->getObject(Escaper::class);
        $context->expects($this->once())
            ->method('getEscaper')
            ->willReturn($escaper);

        $this->preview = $this->objectManager->getObject(
            QueuePreview::class,
            [
                'context' => $context,
                'templateFactory' => $templateFactory,
                'subscriberFactory' => $subscriberFactory,
                'queueFactory' => $queueFactory
            ]
        );
    }

    public function testToHtmlEmpty()
    {
        /** @var Store $store */
        $store = $this->createPartialMock(Store::class, ['getId']);
        $this->storeManagerMock->expects($this->once())
            ->method('getDefaultStoreView')
            ->will($this->returnValue($store));
        $result = $this->preview->toHtml();
        $this->assertEquals('', $result);
    }

    public function testToHtmlWithId()
    {
        $this->requestMock->expects($this->any())->method('getParam')->will(
            $this->returnValueMap(
                [
                    ['id', null, 1],
                    ['store_id', null, 0]
                ]
            )
        );
        $this->queueMock->expects($this->once())
            ->method('load')
            ->will($this->returnSelf());
        $this->templateMock->expects($this->any())
            ->method('isPlain')
            ->will($this->returnValue(true));
        /** @var Store $store */
        $this->storeManagerMock->expects($this->once())
            ->method('getDefaultStoreView')
            ->will($this->returnValue(null));
        $store = $this->createPartialMock(Store::class, ['getId']);
        $this->storeManagerMock->expects($this->once())
            ->method('getStores')
            ->will($this->returnValue([0 => $store]));
        $result = $this->preview->toHtml();
        $this->assertEquals('<pre></pre>', $result);
    }
}
