<?php

declare(strict_types=1);

namespace Easygento\CreateTaxRatesAndRules\Setup\Patch\Data;

use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Region\Collection as ResourceModelRegionCollection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ResourceModel\Store\Collection as ResourceModelStoreCollection;
use Magento\Tax\Api\Data\TaxClassInterface;
use Magento\Tax\Api\Data\TaxRuleInterface;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\ResourceModel\Calculation;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\Collection as ResourceModelCalculationRateCollection;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as ResourceModelCalculationRateCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\Collection as ResourceModelTaxClassCollection;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as ResourceModelTaxClassCollectionFactory;
use Magento\TaxImportExport\Model\Rate\CsvImportHandler;

/**
 * @license http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @author Phuong LE <phuong.le@menincode.com>
 * @copyright Copyright (c) 2022 Men In Code
 */
class ImportTaxRates extends CsvImportHandler implements DataPatchInterface
{
    /**
     * Csv file name
     */
    public const IMPORT_TAX_CSV_FILE_NAME = 'import_tax_rates.csv';
    /**
     * Csv PATH
     */
    public const IMPORT_TAX_CSV_PATH = 'Setup/Csv/';

    /**
     * @var DirectoryList
     */
    protected DirectoryList $directoryList;
    /**
     * @var Dir
     */
    protected Dir $moduleDir;
    /**
     * @var TaxRateRepositoryInterface
     */
    protected TaxRateRepositoryInterface $taxRateRepository;
    /**
     * @var TaxRuleInterfaceFactory
     */
    protected TaxRuleInterfaceFactory $taxRuleInterfaceFactory;
    /**
     * @var TaxRuleRepositoryInterface
     */
    protected TaxRuleRepositoryInterface $taxRuleRepository;
    /**
     * @var ResourceModelCalculationRateCollectionFactory
     */
    protected ResourceModelCalculationRateCollectionFactory $resourceModelCalculationRateCollectionFactory;
    /**
     * @var ResourceModelTaxClassCollectionFactory
     */
    protected ResourceModelTaxClassCollectionFactory $resourceModelTaxClassCollectionFactory;

    /**
     * @param ResourceModelStoreCollection $storeCollection
     * @param ResourceModelRegionCollection $regionCollection
     * @param CountryFactory $countryFactory
     * @param RateFactory $taxRateFactory
     * @param Csv $csvProcessor
     * @param DirectoryList $directoryList
     * @param Dir $moduleDir
     * @param TaxRateRepositoryInterface $taxRateRepository
     * @param ResourceModelCalculationRateCollectionFactory $resourceModelCalculationRateCollectionFactory
     * @param ResourceModelTaxClassCollectionFactory $resourceModelTaxClassCollectionFactory
     */
    public function __construct(
        ResourceModelStoreCollection $storeCollection,
        ResourceModelRegionCollection $regionCollection,
        CountryFactory $countryFactory,
        RateFactory $taxRateFactory,
        Csv $csvProcessor,
        DirectoryList $directoryList,
        Dir $moduleDir,
        TaxRateRepositoryInterface $taxRateRepository,
        TaxRuleRepositoryInterface $taxRuleRepository,
        TaxRuleInterfaceFactory $taxRuleInterfaceFactory,
        ResourceModelCalculationRateCollectionFactory $resourceModelCalculationRateCollectionFactory,
        ResourceModelTaxClassCollectionFactory $resourceModelTaxClassCollectionFactory
    ) {
        parent::__construct($storeCollection, $regionCollection, $countryFactory, $taxRateFactory, $csvProcessor);

        $this->directoryList = $directoryList;
        $this->moduleDir = $moduleDir;
        $this->taxRateRepository = $taxRateRepository;
        $this->taxRuleRepository = $taxRuleRepository;
        $this->taxRuleInterfaceFactory = $taxRuleInterfaceFactory;
        $this->resourceModelCalculationRateCollectionFactory = $resourceModelCalculationRateCollectionFactory;
        $this->resourceModelTaxClassCollectionFactory = $resourceModelTaxClassCollectionFactory;
    }

    /**
     * {@inheritDoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     *
     * @return void
     * @throws LocalizedException
     */
    public function apply(): void
    {
        $this->deleteDefaultTaxRate();
        $this->importCsvTaxRateFile();
        $this->createTaxRules();
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    private function deleteDefaultTaxRate(): void
    {
        /** @var ResourceModelCalculationRateCollection $collection */
        $collection = $this->resourceModelCalculationRateCollectionFactory->create();
        $collection->addFieldToSelect(['tax_calculation_rate_id', 'tax_country_id']);
        $collection->addFieldToFilter('tax_country_id' , Calculation::USA_COUNTRY_CODE);
        $items = $collection->getItems();
        if (count($items) > 0) {
            foreach ($items as $item) {
                $this->taxRateRepository->deleteById($item->getData('tax_calculation_rate_id'));
            }
        }
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function importCsvTaxRateFile(): void
    {
        $filename = self::IMPORT_TAX_CSV_FILE_NAME;
        $modulePath = $this->moduleDir->getDir('Dnd_Tax');
        $file = $modulePath . DIRECTORY_SEPARATOR . self::IMPORT_TAX_CSV_PATH . $filename;
        $ratesRawData = $this->csvProcessor->getData($file);

        $fileFields = $ratesRawData[0];
        $validFields = $this->_filterFileFields($fileFields);
        $invalidFields = array_diff_key($fileFields, $validFields);
        $ratesData = $this->_filterRateData($ratesRawData, $invalidFields, $validFields);
        $storesCache = $this->_composeStoreCache($validFields);
        $regionsCache = [];
        foreach ($ratesData as $rowIndex => $dataRow) {
            // skip headers
            if ($rowIndex === 0) {
                continue;
            }
            $regionsCache = $this->_importRate($dataRow, $regionsCache, $storesCache);
        }
    }

    /**
     * @return void
     * @throws InputException
     */
    private function createTaxRules(): void
    {
        /** @var ResourceModelTaxClassCollection $collection */
        $collection = $this->resourceModelTaxClassCollectionFactory->create();
        $collection->addFieldToSelect(['class_id', 'class_name']);
        $collection->addFieldToFilter('class_name' , 'Taxable Goods');
        /** @var TaxClassInterface $taxableGoodsClass */
        $taxableGoodsClass =  $collection->setPageSize(1)->getFirstItem();

        /** @var ResourceModelTaxClassCollection $collection */
        $collection = $this->resourceModelTaxClassCollectionFactory->create();
        $collection->addFieldToSelect(['class_id', 'class_name']);
        $collection->addFieldToFilter('class_name' , 'Retail Customer');
        /** @var TaxClassInterface $retailCustomerClass */
        $retailCustomerClass =  $collection->setPageSize(1)->getFirstItem();

        if (!$taxableGoodsClass->getClassId() || !$retailCustomerClass->getClassId()) {
            return;
        }

        /** @var ResourceModelCalculationRateCollection $collection */
        $collection = $this->resourceModelCalculationRateCollectionFactory->create();
        $rateIds = [];
        $rateItems = $collection->getItems();

        /** @var Rate $item */
        foreach ($rateItems as $item) {
            $rateIds[] = $item->getData('tax_calculation_rate_id');
        }

        /** @var TaxRuleInterface $taxRule */
        $taxRule = $this->taxRuleInterfaceFactory->create();
        $taxRule->setCode('PRODUCTS');
        $taxRule->setTaxRateIds($rateIds);
        $taxRule->setProductTaxClassIds([$taxableGoodsClass->getClassId()]);
        $taxRule->setCustomerTaxClassIds([$retailCustomerClass->getClassId()]);
        $taxRule->setPriority(0);
        $taxRule->setPosition(0);
        $this->taxRuleRepository->save($taxRule);
    }
}
