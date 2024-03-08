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

    private $resource;

    /**
     * @var string
     */
    private $pattern;
    public $temp;

    protected $mathRandom;
    protected $orderCollection;

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
        $this->resource = $resource;
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
        $this->resource->getConnection()->insert($this->meta->getSequenceTable(), []);
        $this->lastIncrementId = $this->resource->getConnection()->lastInsertId($this->meta->getSequenceTable());
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
            $salesInvoiceTable = $this->resource->getTableName('sales_invoice');
            $collection->getSelect()->joinLeft(
                        [ $salesInvoiceTable => $salesInvoiceTable ],
                        'main_table.entity_id = ' . $salesInvoiceTable . '.order_id',
                        []
                    );
            $collection->addFieldtoFilter($salesInvoiceTable . '.increment_id', $pattern);
        }
        if ($type == 'shipment') {
            $salesShipmentTable = $this->resource->getTableName('sales_shipment');
            $collection->getSelect()->joinLeft(
                        [ $salesShipmentTable => $salesShipmentTable ],
                        'main_table.entity_id = ' . $salesShipmentTable . '.order_id',
                        []
                    );
            $collection->addFieldtoFilter($salesShipmentTable . '.increment_id', $pattern);
        }
        if ($type == 'creditmemo') {
            $salesCreditmemoTable = $this->resource->getTableName('sales_creditmemo');
            $collection->getSelect()->joinLeft(
                        [ $salesCreditmemoTable => $salesCreditmemoTable ],
                        'main_table.entity_id = ' . $salesCreditmemoTable . '.order_id',
                        []
                    );
            $collection->addFieldtoFilter($salesCreditmemoTable . '.increment_id', $pattern);
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
                    } else if ($pattern == 'ms') {
                        // 현재 시간의 밀리초를 가져와서 3자리 숫자로 생성
                        $orderNumber .= sprintf("%03d", substr(microtime(true), -3));
                    } else if ($pattern == 'sec') {
                        // 현재 시간의 초를 가져와서 2자리 숫자로 생성
                        $orderNumber .= sprintf("%02d", date('s'));
                    } else if ($pattern == 'min') {
                        // 현재 시간의 분을 가져와서 2자리 숫자로 생성
                        $orderNumber .= sprintf("%02d", date('i'));
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