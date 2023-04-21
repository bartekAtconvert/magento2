<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\CustomerGraphQl\Model\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Test\Fixture\Group as StoreGroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\CustomerGraphQl\Model\Resolver\Customer as CustomerResolver;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\GraphQlCache\Model\Cache\Query\Resolver\Result\Cache\KeyCalculator\ProviderInterface;
use Magento\GraphQlCache\Model\Cache\Query\Resolver\Result\Type as GraphQlResolverCache;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQl\ResolverCacheAbstract;

/**
 * Test for customer resolver cache
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomerTest extends ResolverCacheAbstract
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @var GraphQlResolverCache
     */
    private $graphQlResolverCache;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;

    /**
     * @var Registry
     */
    private $registry;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();

        $this->graphQlResolverCache = $this->objectManager->get(
            GraphQlResolverCache::class
        );

        $this->customerRepository = $this->objectManager->get(
            CustomerRepositoryInterface::class
        );

        $this->customerTokenService = $this->objectManager->get(
            CustomerTokenServiceInterface::class
        );

        $this->storeManager = $this->objectManager->get(
            StoreManagerInterface::class
        );

        $this->websiteRepository = $this->objectManager->get(
            WebsiteRepositoryInterface::class
        );

        // first register secure area so we have permission to delete customer in tests
        $this->registry = $this->objectManager->get(Registry::class);
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // reset secure area to false (was set to true in setUp so we could delete customer in tests)
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', false);

        parent::tearDown();
    }

    /**
     * @param callable $invalidationMechanismCallable
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/Store/_files/second_store.php
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @dataProvider invalidationMechanismProvider
     */
    public function testCustomerResolverCacheAndInvalidation(callable $invalidationMechanismCallable)
    {
        $customer = $this->customerRepository->get('customer@example.com');

        $query = $this->getQuery();
        $token = $this->customerTokenService->createCustomerAccessToken(
            $customer->getEmail(),
            'password'
        );

        $this->mockCustomerUserInfoContext($customer);
        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            ['Authorization' => 'Bearer ' . $token]
        );

        $cacheKey = $this->getResolverCacheKeyForCustomer();
        $cacheEntry = $this->graphQlResolverCache->load($cacheKey);
        $cacheEntryDecoded = json_decode($cacheEntry, true);

        $this->assertEquals(
            $customer->getEmail(),
            $cacheEntryDecoded['email']
        );

        // change customer data and assert that cache entry is invalidated
        $invalidationMechanismCallable($customer);
        $this->customerRepository->save($customer);

        $this->assertFalse(
            $this->graphQlResolverCache->test($cacheKey)
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/two_customers.php
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @return void
     */
    public function testCustomerResolverCacheGeneratesSeparateEntriesForEachCustomer()
    {
        $customer1 = $this->customerRepository->get('customer@example.com');
        $customer2 = $this->customerRepository->get('customer_two@example.com');

        $query = $this->getQuery();

        // query customer1
        $customer1Token = $this->customerTokenService->createCustomerAccessToken(
            $customer1->getEmail(),
            'password'
        );

        $this->mockCustomerUserInfoContext($customer1);
        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            ['Authorization' => 'Bearer ' . $customer1Token]
        );

        $customer1CacheKey = $this->getResolverCacheKeyForCustomer();

        $this->assertIsNumeric(
            $this->graphQlResolverCache->test($customer1CacheKey)
        );

        // query customer2
        $this->mockCustomerUserInfoContext($customer2);
        $customer2Token = $this->customerTokenService->createCustomerAccessToken(
            $customer2->getEmail(),
            'password'
        );

        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            ['Authorization' => 'Bearer ' . $customer2Token]
        );

        $customer2CacheKey = $this->getResolverCacheKeyForCustomer();

        $this->assertIsNumeric(
            $this->graphQlResolverCache->test($customer2CacheKey)
        );

        $this->assertNotEquals(
            $customer1CacheKey,
            $customer2CacheKey
        );

        // change customer 1 and assert customer 2 cache entry is not invalidated
        $customer1->setFirstname('NewFirstName');
        $this->customerRepository->save($customer1);

        $this->assertFalse(
            $this->graphQlResolverCache->test($customer1CacheKey)
        );

        $this->assertIsNumeric(
            $this->graphQlResolverCache->test($customer2CacheKey)
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @return void
     */
    public function testCustomerResolverCacheInvalidatesWhenCustomerGetsDeleted()
    {
        $customer = $this->customerRepository->get('customer@example.com');

        $query = $this->getQuery();
        $token = $this->customerTokenService->createCustomerAccessToken(
            $customer->getEmail(),
            'password'
        );

        $this->mockCustomerUserInfoContext($customer);
        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            ['Authorization' => 'Bearer ' . $token]
        );

        $cacheKey = $this->getResolverCacheKeyForCustomer();

        $this->assertIsNumeric(
            $this->graphQlResolverCache->test($cacheKey)
        );

        $this->assertTagsByCacheKeyAndCustomer($cacheKey, $customer);

        // delete customer and assert that cache entry is invalidated
        $this->customerRepository->delete($customer);

        $this->assertFalse(
            $this->graphQlResolverCache->test($cacheKey)
        );
    }

    /**
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @return void
     */
    #[
        DataFixture(WebsiteFixture::class, ['code' => 'website2'], 'website2'),
        DataFixture(StoreGroupFixture::class, ['website_id' => '$website2.id$'], 'store_group2'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$store_group2.id$', 'code' => 'store2'], 'store2'),
        DataFixture(
            CustomerFixture::class,
            [
                'firstname' => 'Customer1',
                'email' => 'same_email@example.com',
                'store_id' => '1' // default store
            ]
        ),
        DataFixture(
            CustomerFixture::class,
            [
                'firstname' => 'Customer2',
                'email' => 'same_email@example.com',
                'website_id' => '$website2.id$',
            ]
        )
    ]
    public function testCustomerWithSameEmailInTwoSeparateWebsitesKeepsSeparateCacheEntries()
    {
        $website2 = $this->websiteRepository->get('website2');

        $customer1 = $this->customerRepository->get('same_email@example.com');
        $customer2 = $this->customerRepository->get('same_email@example.com', $website2->getId());

        $query = $this->getQuery();

        // query customer1
        $customer1Token = $this->customerTokenService->createCustomerAccessToken(
            $customer1->getEmail(),
            'password'
        );

        $this->mockCustomerUserInfoContext($customer1);
        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            ['Authorization' => 'Bearer ' . $customer1Token]
        );

        $customer1CacheKey = $this->getResolverCacheKeyForCustomer();
        $customer1CacheEntry = $this->graphQlResolverCache->load($customer1CacheKey);
        $customer1CacheEntryDecoded = json_decode($customer1CacheEntry, true);
        $this->assertEquals(
            $customer1->getFirstname(),
            $customer1CacheEntryDecoded['firstname']
        );

        // query customer2
        $this->mockCustomerUserInfoContext($customer2);
        $customer2Token = $this->generateCustomerToken(
            $customer2->getEmail(),
            'password',
            'store2'
        );

        $this->graphQlQueryWithResponseHeaders(
            $query,
            [],
            '',
            [
                'Authorization' => 'Bearer ' . $customer2Token,
                'Store' => 'store2',
            ]
        );

        $customer2CacheKey = $this->getResolverCacheKeyForCustomer();

        $customer2CacheEntry = $this->graphQlResolverCache->load($customer2CacheKey);
        $customer2CacheEntryDecoded = json_decode($customer2CacheEntry, true);
        $this->assertEquals(
            $customer2->getFirstname(),
            $customer2CacheEntryDecoded['firstname']
        );

        // change customer 1 and assert customer 2 cache entry is not invalidated
        $customer1->setFirstname('NewFirstName');
        $this->customerRepository->save($customer1);

        $this->assertFalse(
            $this->graphQlResolverCache->test($customer1CacheKey)
        );

        $this->assertIsNumeric(
            $this->graphQlResolverCache->test($customer2CacheKey)
        );
    }

    public function invalidationMechanismProvider(): array
    {
        return [
            'firstname' => [
                function (CustomerInterface $customer) {
                    $customer->setFirstname('SomeNewFirstName');
                },
            ],
            'is_subscribed' => [
                function (CustomerInterface $customer) {
                    $isCustomerSubscribed = $customer->getExtensionAttributes()->getIsSubscribed();
                    $customer->getExtensionAttributes()->setIsSubscribed(!$isCustomerSubscribed);
                },
            ],
            'store_id' => [
                function (CustomerInterface $customer) {
                    $secondStore = $this->storeManager->getStore('fixture_second_store');
                    $customer->setStoreId($secondStore->getId());
                },
            ],
        ];
    }

    private function assertTagsByCacheKeyAndCustomer(string $cacheKey, CustomerInterface $customer): void
    {
        $lowLevelFrontendCache = $this->graphQlResolverCache->getLowLevelFrontend();
        $cacheIdPrefix = $lowLevelFrontendCache->getOption('cache_id_prefix');
        $metadatas = $lowLevelFrontendCache->getMetadatas($cacheKey);
        $tags = $metadatas['tags'];

        $this->assertEqualsCanonicalizing(
            [
                $cacheIdPrefix . strtoupper(Customer::ENTITY) . '_' . $customer->getId(),
                $cacheIdPrefix . strtoupper(GraphQlResolverCache::CACHE_TAG),
                $cacheIdPrefix . 'MAGE',
            ],
            $tags
        );
    }

    private function getResolverCacheKeyForCustomer(): string
    {
        $resolverMock = $this->getMockBuilder(CustomerResolver::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var ProviderInterface $cacheKeyCalculatorProvider */
        $cacheKeyCalculatorProvider = $this->objectManager->get(ProviderInterface::class);

        $cacheKey = $cacheKeyCalculatorProvider
            ->getKeyCalculatorForResolver($resolverMock)
            ->calculateCacheKey();

        $cacheKeyQueryPayloadMetadata = 'Customer[]';

        $cacheKeyParts = [
            GraphQlResolverCache::CACHE_TAG,
            $cacheKey,
            sha1($cacheKeyQueryPayloadMetadata)
        ];

        // strtoupper is called in \Magento\Framework\Cache\Frontend\Adapter\Zend::_unifyId
        return strtoupper(implode('_', $cacheKeyParts));
    }

    private function getQuery(): string
    {
        return <<<QUERY
        {
          customer {
            id
            firstname
            lastname
            email
            is_subscribed
          }
        }
        QUERY;
    }

    /**
     * Generate customer token
     *
     * @param string $email
     * @param string $password
     * @param string $storeCode
     * @return string
     * @throws \Exception
     */
    private function generateCustomerToken(string $email, string $password, string $storeCode = 'default'): string
    {
        $query = <<<MUTATION
mutation {
	generateCustomerToken(
        email: "{$email}"
        password: "{$password}"
    ) {
        token
    }
}
MUTATION;

        $response = $this->graphQlMutation(
            $query,
            [],
            '',
            [
                'Store' => $storeCode,
            ]
        );

        return $response['generateCustomerToken']['token'];
    }
}
