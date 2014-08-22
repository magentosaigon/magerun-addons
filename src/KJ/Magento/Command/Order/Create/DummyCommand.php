<?php

namespace KJ\Magento\Command\Order\Create;

use N98\Magento\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DummyCommand extends \N98\Magento\Command\AbstractMagentoCommand
{
    /** @var InputInterface $input */
    protected $_input;

    /** @var OutputInterface $input */
    protected $_output;

    protected $_customer;
    protected $_product;
    protected $_quote;

    /* Lazy loading */
    protected $_defaultStoreId;

    /* Supported shipping methods */
    protected $_availableSippingMethods = array('flatrate_flatrate', 'tablerate_bestway');

    protected function configure()
    {
        $this
            ->setName('order:create:dummy')
            ->addArgument('count', InputArgument::REQUIRED, 'Count')
            ->addOption('customer', null, InputOption::VALUE_OPTIONAL, "A customer ID to use for the order")
            ->addOption('product', null, InputOption::VALUE_OPTIONAL, "A product SKU to use for the order")
            ->addOption('store', null, InputOption::VALUE_OPTIONAL, "A store ID to use for the order")
            ->addOption('shipping', null, InputOption::VALUE_OPTIONAL, "A shipping method code to use for the order")
            ->setDescription('(Experimental) Create a dummy order using a random customer, product, and date.')
        ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;
        $this->detectMagento($output, true);
        $this->initMagento();

        for ($i = 1; $i <= $input->getArgument('count'); $i++) {
            echo "$i. ";
            try {
                $this->_createOrder();
            } catch (\Exception $e) {
                $this->_output->writeln("<error>Problem creating order: " . $e->getMessage() . "</error>");
            }
            $this->_resetEverything();
        }

    }

    /**
     * Reset the customer, product, quote objects that have been saved
     * so that new ones can be generated for the next iteration.
     */
    protected function _resetEverything()
    {
        unset($this->_customer);
        unset($this->_product);
        unset($this->_quote);
    }

    protected function _createOrder()
    {
        $customer = $this->getCustomer();
        $this->_output->writeln(sprintf("<info>Using customer: %s (%s)</info>", $customer->getName(), $customer->getEmail()));

        $product = $this->getProduct();
        $this->_output->writeln(sprintf("<info>Using product: %s (%s)</info>", $product->getName(), $product->getId()));

        $createdAt = $this->getCreatedAt();
        $this->_output->writeln(sprintf("<info>Using created_at date: %s</info>", $createdAt));

        $order = $this->createOrderFromQuote();
        if ($order) {
            $this->_output->writeln(sprintf("<info>Created order: %s</info>", $order->getIncrementId()));
        }
    }

    /**
     * @return \Mage_Customer_Model_Customer
     */
    protected function getCustomer()
    {
        if (isset($this->_customer)) {
            return $this->_customer;
        }

        if ($this->_input->getOption('customer')) {
            $customer = \Mage::getModel('customer/customer')->load($this->_input->getOption('customer'));
        } else {
            $customer = $this->_loadRandomCustomer();
        }

        $this->_customer = \Mage::getModel('customer/customer')->load($customer->getId());
        return $this->_customer;
    }

    protected function _loadRandomCustomer()
    {
        /** @var \Mage_Customer_Model_Resource_Customer_Collection $customers */
        $customers = \Mage::getModel('customer/customer')->getCollection()
            ->setPageSize(1);
        $customers->getSelect()->order(new \Zend_Db_Expr('RAND()'));

        /** @var \Mage_Customer_Model_Customer $customer */
        $customer = $customers->getFirstItem();

        return $customer;
    }

    /**
     * @return \Mage_Catalog_Model_Product
     */
    protected function getProduct()
    {
        if (isset($this->_product)) {
            return $this->_product;
        }

        $product_input = $this->_input->getOption('product');

        if ($product_input && !preg_match('/%/', $product_input)) {
            $product = \Mage::getModel('catalog/product')->loadByAttribute('sku', $product_input);
            if (!$product) {
                throw new \Exception("Couldn't find product by SKU: " . $product_input);
            }
            $product = \Mage::getModel('catalog/product')->load($product->getId());
        } else {
            $product = $this->_loadRandomProduct($product_input);
        }

        $parents = \Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
        if (!empty($parents)) {
            throw new \KJ\Magento\Exception\Product\Configurable("Product ({$product->getId()}) is a child of configurable, can't use this.");
        }

        $this->_product = $product;
        return $this->_product;
    }

    protected function _loadRandomProduct($product_input)
    {
        /** @var \Mage_Catalog_Model_Resource_Product_Collection $products */
        $products = \Mage::getModel('catalog/product')->getCollection();

        $products->setPageSize(1);
        $products->getSelect()->order(new \Zend_Db_Expr('RAND()'));

        if ($product_input) {
            $products->getSelect()->where('sku LIKE ?', $product_input);
        }

        if (!$products->getSize()) {
            throw new \Exception('No products are matching the criteria');
        }

        /** @var \Mage_Catalog_Model_Product $firstResult */
        $firstResult = $products->getFirstItem();
        $product = \Mage::getModel('catalog/product')->load($firstResult->getId());

        return $product;
    }

    protected function getCreatedAt()
    {
        $daysAgo = rand(1, 365 * 2);
        $createdAtTimestamp = time() - $daysAgo * 24 * 60 * 60;
        $createdAtString = date('Y-m-d', $createdAtTimestamp);

        return $createdAtString;
    }

    protected function createOrderFromQuote()
    {
        $quote = $this->getQuote();
        $this->addItemToQuote();
        $this->setupBillingAddress();
        $this->setupShippingAddress();
        $this->setupShippingMethod();
        $this->setupPaymentMethod();
        $this->getQuote()->collectTotals()
            ->save();

        $service = \Mage::getModel('sales/service_quote', $quote);
        $order = $service->submitOrder();
        $order->setCreatedAt($this->getCreatedAt());
        $order->save();

        return $order;
    }

    /**
     * @return \Mage_Sales_Model_Quote
     */
    protected function getQuote()
    {
        if (isset($this->_quote)) {
            return $this->_quote;
        }

        /** @var \Mage_Sales_Model_Quote $quote */
        $quote = \Mage::getModel('sales/quote')->assignCustomer($this->getCustomer());
        $storeId = $this->_input->getOption('store') ? $this->_input->getOption('store') : $this->_getDefaultStoreId();
        $store = $quote->getStore()->load($storeId);
        $quote->setStore($store);

        $this->_quote = $quote;
        return $this->_quote;
    }

    protected function addItemToQuote()
    {
        $product = $this->getProduct();
        $quote = $this->getQuote();

        /** @var Mage_Sales_Model_Quote_Item $quoteItem */
        $quoteItem = $quote->addProduct($product);
        if (is_string($quoteItem)) {
            throw new \Exception(sprintf("Error: $quoteItem"));
        }

        $quoteItem->setQuote($quote);
        $quoteItem->checkData();

        return $this;
    }

    protected function setupBillingAddress()
    {
        $quoteBillingAddress = new \Mage_Sales_Model_Quote_Address();
        $quoteBillingAddress->importCustomerAddress($this->getCustomerBillingAddress());
        $this->getQuote()->setBillingAddress($quoteBillingAddress);

        return $this;
    }

    protected function getCustomerShippingAddress()
    {
        if ($this->getCustomer()->getDefaultShippingAddress()) {
            return $this->getCustomer()->getDefaultShippingAddress();
        } elseif ($this->getCustomer()->getDefaultBillingAddress()) {
            return $this->getCustomer()->getDefaultBillingAddress();
        } else {
            return $this->getDefaultAddress();
        }
    }

    protected function getCustomerBillingAddress()
    {
        if ($this->getCustomer()->getDefaultBillingAddress()) {
            return $this->getCustomer()->getDefaultBillingAddress();
        } else if ($this->getCustomer()->getDefaultShippingAddress()) {
            return $this->getCustomer()->getDefaultShippingAddress();
        } else {
            return $this->getDefaultAddress();
        }
    }

    protected function getDefaultAddress()
    {
        $data = array (
            'firstname' => $this->getCustomer()->getFirstname(),
            'lastname' => $this->getCustomer()->getLastname(),
            'street' => array (
                '0' => '123 Abc Road',
            ),
            'city' => 'Los Angeles',
            'region_id' => '12',
            'region' => 'California',
            'postcode' => '91201',
            'country_id' => 'US',
            'telephone' => '888 888 8888',
        );
        $address = \Mage::getModel('customer/address')->setData($data);
        return $address;
    }

    protected function setupShippingAddress()
    {
        $address = new \Mage_Sales_Model_Quote_Address();
        $address->importCustomerAddress($this->getCustomerShippingAddress());
        $this->getQuote()->setShippingAddress($address);

        return $this;
    }

    protected function setupShippingMethod()
    {
        $shipping_method_code = $this->_input->getOption('shipping');

        if ($shipping_method_code && !in_array($shipping_method_code, $this->_availableSippingMethods)) {
            throw new \Exception('Shipping method is not supported.');
        } elseif (!$shipping_method_code) {
            $shipping_method_code = $this->_availableSippingMethods[0];
        }

        $this->getQuote()->getShippingAddress()->setShippingMethod($shipping_method_code)
            ->setCollectShippingRates(true)
            ->collectShippingRates();

        return $this;
    }

    protected function setupPaymentMethod()
    {
        $quotePayment = $this->getQuote()->getPayment();
        $quotePayment->setMethod('checkmo');
        $this->getQuote()->setPayment($quotePayment);

        return $this;

    }

    protected function _getDefaultStoreId()
    {
        if (empty($this->_defaultStoreId)) {
            $this->_defaultStoreId = \Mage::app()
                ->getWebsite(true)
                ->getDefaultGroup()
                ->getDefaultStoreId();
        }

        return $this->_defaultStoreId;
    }
}