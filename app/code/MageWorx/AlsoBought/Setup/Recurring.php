<?php
/**
 * Copyright © 2017 MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\AlsoBought\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Catalog recurring setup
 */
class Recurring implements InstallSchemaInterface
{
    /**
     * @var \Magento\Framework\App\ProductMetadata
     */
    protected $productMetadata;

    /**
     * @var \Magento\Framework\DB\AggregatedFieldDataConverter
     */
    private $aggregatedFieldConverter;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $jsonSerializer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $serializableTablesData;

    /**
     * Recurring constructor.
     *
     * @param ObjectManagerInterface $objectManager
     * @param \Magento\Framework\App\ProductMetadata $productMetadata
     * @param \Psr\Log\LoggerInterface $logger
     * @param array $serializableTablesData
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        \Magento\Framework\App\ProductMetadata $productMetadata,
        \Psr\Log\LoggerInterface $logger,
        array $serializableTablesData = []
    ) {
        $this->productMetadata = $productMetadata;
        if ($this->isUsedJsonSerializedValues()) {
            $this->aggregatedFieldConverter = $objectManager->get('Magento\Framework\DB\AggregatedFieldDataConverter');
            $this->jsonSerializer = $objectManager->get('Magento\Framework\Serialize\Serializer\Json');
        }
        $this->logger = $logger;
        /**
         * [
         *      ['table1' => ['col1', 'col2' ... ]
         *      ['table2' => ['col1', 'col2' ... ]
         * ]
         */
        $this->serializableTablesData = $serializableTablesData;
    }

    /**
     * @return bool
     */
    public function isUsedJsonSerializedValues()
    {
        $version = $this->productMetadata->getVersion();
        if (version_compare($version, '2.2.0', '>=') &&
            class_exists('\Magento\Framework\DB\AggregatedFieldDataConverter')
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @throws \Exception
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        $this->logger->debug('Start Also Bought recurring update >>>');
        $time = time();
        $this->logger->debug($time);
        if ($this->aggregatedFieldConverter) {
            try {
                $this->convertTableDataToJson($installer, 'mageworx_alsobought_index', 'entity_id');
            } catch (\Exception $exception) {
                $this->logger->debug('Exception:');
                $this->logger->debug($exception->getMessage());
                throw $exception;
            }
        }

        $this->logger->debug(time());
        $this->logger->debug('Total spend: ' . (time() - $time));
        $this->logger->debug('>>> End Also Bought recurring update');

        $installer->endSetup();
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $tableName
     * @param string $idColumnName
     * @return void
     */
    private function convertTableDataToJson(SchemaSetupInterface $setup, $tableName, $idColumnName)
    {
        $connection          = $setup->getConnection();
        $tableNameReal       = $setup->getTable($tableName);
        $select              = $connection->select();
        $serializableColumns = $this->serializableTablesData[$tableName];
        $columns             = array_merge([$idColumnName], $serializableColumns);
        $select->from($tableNameReal, $columns);
        $data = $connection->fetchAll($select);
        foreach ($data as $datum) {
            foreach ($serializableColumns as $column) {
                if (!isset($datum[$column])) {
                    continue;
                }

                $data = json_decode($datum[$column]);
                if (json_last_error() === JSON_ERROR_NONE) {
                    continue;
                } else {
                    $data     = unserialize($datum[$column]);
                    $dataJson = json_encode($data);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }
                    $connection->update(
                        $tableNameReal,
                        [
                            $column => $dataJson
                        ],
                        $connection->quoteInto($idColumnName . '=?', $datum[$idColumnName])
                    );
                }
            }
        }
    }
}
