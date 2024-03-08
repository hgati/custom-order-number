<?php

namespace Hgati\CustomOrderNumber\Model;

use Magento\Framework\App\ResourceConnection as AppResource;
use Magento\SalesSequence\Model\Meta;
use Magento\SalesSequence\Model\ResourceModel\Profile;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollection;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Sequence extends \Magento\SalesSequence\Model\Sequence
{

    /**
     * @var string
     */
    private $lastIncrementId;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Meta
     */
    private $meta;

    /**
     * @var Profile
     */
    protected $profile;

    /**
     * @var false|\Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @var string
     */
    private $pattern;
    public $temp;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param Random $mathRandom
     * @param OrderCollection $orderCollection
     * @param Profile $profile
     * @param Meta $meta
     * @param AppResource $resource
     * @param string $pattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Random $mathRandom,
        OrderCollection $orderCollection,
        Profile $profile,
        Meta $meta,
        AppResource $resource,
        $pattern = self::DEFAULT_PATTERN
    ) {

        $this->meta = $meta;
        $this->connection = $resource->getConnection('sales');
        $this->pattern = $pattern;
        $this->profile = $profile;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->mathRandom = $mathRandom;
        $this->orderCollection = $orderCollection;
        parent::__construct($meta, $resource, $pattern);
    }

    /**
     * Retrieve current value
     *
     * @return string
     */

    public function getCurrentValue()
    {
        if (!isset($this->lastIncrementId)) {
            return null;
        }

        $storeId = $this->meta->getData('store_id');
        $metaEntityType = $this->meta->getEntityType();

        if ($metaEntityType == 'order') {
            $pattern = $this->getOrderFormat($storeId);
        }
        if ($metaEntityType == 'invoice') {
            $pattern = $this->getInvoiceFormat($storeId);
        }
        if ($metaEntityType == 'shipment') {
            $pattern = $this->getShipmentFormat($storeId);
        }
        if ($metaEntityType == 'creditmemo') {
            $pattern = $this->getCreditMemoFormat($storeId);
        } else {
            $this->meta->getActiveProfile()->getPrefix();
        }
        $incrementId = sprintf(
            $this->pattern,
            '',
            $this->calculateCurrentValue(),
            $this->meta->getActiveProfile()->getSuffix()
        );

        return $this->generateIncrementId($pattern, $incrementId, $metaEntityType);
    }

    public function getOrderFormat($storeId)
    {
        return $this->scopeConfig->getValue(
            'custom_order_number/general/order',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getInvoiceFormat($storeId)
    {
        return $this->scopeConfig->getValue(
            'custom_order_number/general/invoice',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getShipmentFormat($storeId)
    {
        return $this->scopeConfig->getValue(
            'custom_order_number/general/shipment',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCreditMemoFormat($storeId)
    {
        return $this->scopeConfig->getValue(
            'custom_order_number/general/creditmemo',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve next value
     *
     * @return string
     */
    public function getNextValue()
    {
        $this->connection->insert($this->meta->getSequenceTable(), []);
        $this->lastIncrementId = $this->connection->lastInsertId($this->meta->getSequenceTable());
        return $this->getCurrentValue();
    }

    /**
     * Calculate current value depends on start value
     *
     * @return string
     */
    private function calculateCurrentValue()
    {
        return ($this->lastIncrementId - $this->meta->getActiveProfile()->getStartValue())
         * $this->meta->getActiveProfile()->getStep() + $this->meta->getActiveProfile()->getStartValue();
    }

    public function generateIncrementId($patterns, $incrementId, $type)
    {
        $collection = $this->orderCollection->create();
        $pattern = $this->generatePattern($patterns, $incrementId);
        if ($type == 'order') {
            $collection->addFieldtoFilter('main_table.increment_id', $pattern);
        }
        if ($type == 'invoice') {
            $collection->getSelect()->joinLeft(
                        ['sales_invoice' => 'sales_invoice'],
                        'main_table.entity_id = sales_invoice.order_id',
                        []
                    );
            $collection->addFieldtoFilter('sales_invoice.increment_id', $pattern);
        }
        if ($type == 'shipment') {
            $collection->getSelect()->joinLeft(
                        ['sales_shipment' => 'sales_shipment'],
                        'main_table.entity_id = sales_shipment.order_id',
                        []
                    );
            $collection->addFieldtoFilter('sales_shipment.increment_id', $pattern);
        }
        if ($type == 'creditmemo') {
            $collection->getSelect()->joinLeft(
                        ['sales_creditmemo' => 'sales_creditmemo'],
                        'main_table.entity_id = sales_creditmemo.order_id',
                        []
                    );
            $collection->addFieldtoFilter('sales_creditmemo.increment_id', $pattern);
        }
        if (count($collection)) {
            return $this->generateIncrementId($patterns, $incrementId, $type);
        }

        return $pattern;
    }

    public function generatePattern($patterns, $incrementId)
    {
        if ($patterns) {
            $patterns = str_replace('{', ',{', $patterns);
            $patterns = str_replace('}', '},', $patterns);
            $patterns = explode(',', $patterns);
            $patterns = array_filter($patterns);

            $orderNumber = '';
            foreach ($patterns as $pattern) {
                $pattern = preg_replace('/\s+/', '', $pattern);
                if (str_contains($pattern, '{') || str_contains($pattern, '}')) {
                    $pattern = preg_replace('/(.*){(.*)}(.*)/s', '\2', $pattern);
                    if (str_contains($pattern, 'num')) {
                        $number = str_replace('num', '', $pattern);
                        if (is_numeric($number)) {
                            $orderNumber .= $this->mathRandom->getRandomString($number > 9 ? 9 : $number, '0123456789');
                        }
                    } else if (str_contains($pattern, 'str')) {
                        $string = str_replace('str', '', $pattern);
                        if (is_numeric($string)) {
                            $orderNumber .= $this->mathRandom->getRandomString($string > 9 ? 9 : $string, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
                        }
                    } else if ($pattern == 'increment_id') {
                        $orderNumber .= $incrementId;
                    }
                } else {
                    $orderNumber .= $pattern;
                }
            }

            return $orderNumber;
        } else {
            return $incrementId;
        }
    }
}