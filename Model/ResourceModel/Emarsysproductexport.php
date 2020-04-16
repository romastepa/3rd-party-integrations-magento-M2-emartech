<?php
/**
 * @category  Emarsys
 * @package   Emarsys_Emarsys
 * @copyright Copyright (c) 2020 Emarsys. (http://www.emarsys.net/)
 */

namespace Emarsys\Emarsys\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Zend_Db_Statement_Interface;

class Emarsysproductexport extends AbstractDb
{
    /**
     * Define main table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('emarsys_product_export', 'entity_id');
    }

    /**
     * Save Products to temp table
     *
     * @param array $products
     * @return Zend_Db_Statement_Interface
     * @throws LocalizedException
     */
    public function saveBulkProducts($products)
    {
        $lines = $bind = [];

        foreach ($products as $row) {
            $line = [];
            foreach ($row as $value) {
                $line[] = '?';
                $bind[] = $value;
            }
            $lines[] = sprintf('(%s)', implode(', ', $line));
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES%s ON DUPLICATE KEY UPDATE %s',
            $this->getMainTable(),
            'entity_id, params',
            implode(', ', $lines),
            '`params` = CONCAT(`params` , \'' . \Emarsys\Emarsys\Model\Emarsysproductexport::EMARSYS_DELIMITER . '\' , VALUES(`params`))'
        );

        return $this->getConnection()->query($sql, $bind);
    }

    /**
     * Truncate a table
     *
     * @return AdapterInterface
     * @throws LocalizedException
     */
    public function truncateTable()
    {
        return $this->getConnection()->truncateTable($this->getMainTable());
    }
}