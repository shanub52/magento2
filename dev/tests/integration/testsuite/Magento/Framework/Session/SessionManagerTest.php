<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
// @codingStandardsIgnoreStart
namespace {
    $mockPHPFunctions = false;
}

namespace Magento\Framework\Session {

    use Magento\Framework\App\State;
    // @codingStandardsIgnoreEnd

    /**
     * Mock session_status if in test mode, or continue normal execution otherwise
     *
     * @return int Session status code
     */
    function session_status()
    {
        global $mockPHPFunctions;
        if ($mockPHPFunctions) {
            return PHP_SESSION_NONE;
        }
        return call_user_func_array('\session_status', func_get_args());
    }

    function headers_sent()
    {
        global $mockPHPFunctions;
        if ($mockPHPFunctions) {
            return false;
        }
        return call_user_func_array('\headers_sent', func_get_args());
    }

    /**
     * Class to test session manager.
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    class SessionManagerTest extends \PHPUnit_Framework_TestCase
    {
        /**
         * @var \Magento\Framework\Session\SessionManagerInterface
         */
        protected $_model;

        /**
         * @var \Magento\Framework\Session\SidResolverInterface
         */
        protected $_sidResolver;

        /**
         * @var string
         */
        protected $sessionName;

        /**
         * @var \Magento\Framework\ObjectManagerInterface
         */
        protected $objectManager;

        /**
         * @var \Magento\Framework\App\RequestInterface
         */
        private $request;

        /**
         * @var State|\PHPUnit_Framework_MockObject_MockObject
         */
        private $appState;

        protected function setUp()
        {
            $this->sessionName = 'frontEndSession';

            ini_set('session.use_only_cookies', '0');
            ini_set('session.name', $this->sessionName);

            $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

            $this->appState = $this->getMockBuilder(State::class)
                ->setMethods(['getAreaCode'])
                ->disableOriginalConstructor()
                ->getMock();

            /** @var \Magento\Framework\Session\SidResolver $sidResolver */
            $this->_sidResolver = $this->objectManager->create(
                \Magento\Framework\Session\SidResolver::class,
                [
                    'appState' => $this->appState
                ]
            );

            $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);

            /** @var \Magento\Framework\Session\SessionManager _model */
            $this->_model = $this->objectManager->create(
                \Magento\Framework\Session\SessionManager::class,
                [
                    $this->objectManager->get(\Magento\Framework\App\Request\Http::class),
                    $this->_sidResolver,
                    $this->objectManager->get(\Magento\Framework\Session\Config\ConfigInterface::class),
                    $this->objectManager->get(\Magento\Framework\Session\SaveHandlerInterface::class),
                    $this->objectManager->get(\Magento\Framework\Session\ValidatorInterface::class),
                    $this->objectManager->get(\Magento\Framework\Session\StorageInterface::class)
                ]
            );
        }

        public function testSessionNameFromIni()
        {
            $this->_model->start();
            $this->assertSame($this->sessionName, $this->_model->getName());
            $this->_model->destroy();
        }

        public function testSessionUseOnlyCookies()
        {
            $expectedValue = '1';
            $sessionUseOnlyCookies = ini_get('session.use_only_cookies');
            $this->assertSame($expectedValue, $sessionUseOnlyCookies);
        }

        public function testGetData()
        {
            $this->_model->setData(['test_key' => 'test_value']);
            $this->assertEquals('test_value', $this->_model->getData('test_key', true));
            $this->assertNull($this->_model->getData('test_key'));
        }

        public function testGetSessionId()
        {
            $this->assertEquals(session_id(), $this->_model->getSessionId());
        }

        public function testGetName()
        {
            $this->assertEquals(session_name(), $this->_model->getName());
        }

        public function testSetName()
        {
            $this->_model->setName('test');
            $this->assertEquals('test', $this->_model->getName());
        }

        public function testDestroy()
        {
            $data = ['key' => 'value'];
            $this->_model->setData($data);

            $this->assertEquals($data, $this->_model->getData());
            $this->_model->destroy();

            $this->assertEquals([], $this->_model->getData());
        }

        public function testSetSessionId()
        {
            $sessionId = $this->_model->getSessionId();
            $this->appState->expects($this->atLeastOnce())
                ->method('getAreaCode')
                ->willReturn(\Magento\Framework\App\Area::AREA_FRONTEND);
            $this->_model->setSessionId($this->_sidResolver->getSid($this->_model));
            $this->assertEquals($sessionId, $this->_model->getSessionId());

            $this->_model->setSessionId('test');
            $this->assertEquals('test', $this->_model->getSessionId());
        }

        /**
         * @magentoConfigFixture current_store web/session/use_frontend_sid 1
         */
        public function testSetSessionIdFromParam()
        {
            $this->appState->expects($this->atLeastOnce())
                ->method('getAreaCode')
                ->willReturn(\Magento\Framework\App\Area::AREA_FRONTEND);
            $this->assertNotEquals('test_id', $this->_model->getSessionId());
            $this->request->getQuery()->set($this->_sidResolver->getSessionIdQueryParam($this->_model), 'test-id');
            $this->_model->setSessionId($this->_sidResolver->getSid($this->_model));
            $this->assertEquals('test-id', $this->_model->getSessionId());
            /* Use not valid identifier */
            $this->request->getQuery()->set($this->_sidResolver->getSessionIdQueryParam($this->_model), 'test_id');
            $this->_model->setSessionId($this->_sidResolver->getSid($this->_model));
            $this->assertEquals('test-id', $this->_model->getSessionId());
        }

        public function testGetSessionIdForHost()
        {
            $_SERVER['HTTP_HOST'] = 'localhost';
            $this->_model->start();
            $this->assertEmpty($this->_model->getSessionIdForHost('localhost'));
            $this->assertNotEmpty($this->_model->getSessionIdForHost('test'));
            $this->_model->destroy();
        }

        public function testIsValidForHost()
        {
            $_SERVER['HTTP_HOST'] = 'localhost';
            $this->_model->start();

            $reflection = new \ReflectionMethod($this->_model, '_addHost');
            $reflection->setAccessible(true);
            $reflection->invoke($this->_model);

            $this->assertFalse($this->_model->isValidForHost('test.com'));
            $this->assertTrue($this->_model->isValidForHost('localhost'));
            $this->_model->destroy();
        }

        /**
         * @expectedException \Magento\Framework\Exception\SessionException
         * @expectedExceptionMessage Area code not set: Area code must be set before starting a session.
         */
        public function testStartAreaNotSet()
        {
            $scope = $this->objectManager->get('Magento\Framework\Config\ScopeInterface');
            $appState = new \Magento\Framework\App\State($scope);

            /**
             * Must be created by "new" in order to get a real Magento\Framework\App\State object that
             * is not overridden in the TestFramework
             *
             * @var \Magento\Framework\Session\SessionManager _model
             */
            $this->_model = new \Magento\Framework\Session\SessionManager(
                $this->objectManager->get(\Magento\Framework\App\Request\Http::class),
                $this->_sidResolver,
                $this->objectManager->get(\Magento\Framework\Session\Config\ConfigInterface::class),
                $this->objectManager->get(\Magento\Framework\Session\SaveHandlerInterface::class),
                $this->objectManager->get(\Magento\Framework\Session\ValidatorInterface::class),
                $this->objectManager->get(\Magento\Framework\Session\StorageInterface::class),
                $this->objectManager->get(\Magento\Framework\Stdlib\CookieManagerInterface::class),
                $this->objectManager->get(\Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class),
                $appState
            );

            global $mockPHPFunctions;
            $mockPHPFunctions = true;
            $this->_model->start();
        }
    }
}
