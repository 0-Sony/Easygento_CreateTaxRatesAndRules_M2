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
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\ResourceModel\Calculation;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as ResourceModelCalculationRateCollectionFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\CollectionFactory as ResourceModelCalculationRuleCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as ResourceModelTaxClassCollectionFactory;
use Magento\TaxImportExport\Model\Rate\CsvImportHandler;

/**
 * @license http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @author Phuong LE <phuong.le@menincode.com>
 * @copyright Copyright (c) 2022 Men In Code
 */
class ImportTaxRates extends CsvImportHandler implements DataPatchInterface
{
    public const MODULE_NAME = 'Easygento_CreateTaxRatesAndRules';
    public const IMPORT_TAX_CSV_FILE_NAME = 'import_tax_rates.csv';
    public const IMPORT_TAX_CSV_PATH = 'Setup/Csv/';

    protected DirectoryList $directoryList;
    protected Dir $moduleDir;
    protected TaxRateRepositoryInterface $taxRateRepository;
    protected TaxRuleInterfaceFactory $taxRuleInterfaceFactory;
    protected TaxRuleRepositoryInterface $taxRuleRepository;
    protected ResourceModelCalculationRateCollectionFactory $resourceModelCalculationRateCollectionFactory;
    protected ResourceModelTaxClassCollectionFactory $resourceModelTaxClassCollectionFactory;
    protected ResourceModelCalculationRuleCollectionFactory $resourceModelCalculationRuleCollectionFactory;

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
        ResourceModelTaxClassCollectionFactory $resourceModelTaxClassCollectionFactory,
        ResourceModelCalculationRuleCollectionFactory $resourceModelCalculationRuleCollectionFactory
    ) {
        parent::__construct($storeCollection, $regionCollection, $countryFactory, $taxRateFactory, $csvProcessor);

        $this->directoryList = $directoryList;
        $this->moduleDir = $moduleDir;
        $this->taxRateRepository = $taxRateRepository;
        $this->taxRuleRepository = $taxRuleRepository;
        $this->taxRuleInterfaceFactory = $taxRuleInterfaceFactory;
        $this->resourceModelCalculationRateCollectionFactory = $resourceModelCalculationRateCollectionFactory;
        $this->resourceModelTaxClassCollectionFactory = $resourceModelTaxClassCollectionFactory;
        $this->resourceModelCalculationRuleCollectionFactory = $resourceModelCalculationRuleCollectionFactory;
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
        $this->deleteDefaultTaxRule();
        $this->deleteDefaultTaxRate();
        $this->importCsvTaxRateFile();
        $this->createTaxRules();
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    private function deleteDefaultTaxRule(): void
    {
        $collection = $this->resourceModelCalculationRuleCollectionFactory->create();
        $collection->addFieldToSelect(['tax_calculation_rule_id', 'code']);
        $collection->addFieldToFilter('code' , 'Rule1');
        if (!($collection->getSize() > 0)) {
            return;
        }
        $items = $collection->getItems();
        foreach ($items as $item) {
            $this->taxRuleRepository->deleteById($item->getData('tax_calculation_rule_id'));
        }
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    private function deleteDefaultTaxRate(): void
    {
        $collection = $this->resourceModelCalculationRateCollectionFactory->create();
        $collection->addFieldToSelect(['tax_calculation_rate_id', 'tax_country_id']);
        $collection->addFieldToFilter('tax_country_id' , Calculation::USA_COUNTRY_CODE);
        if (!($collection->getSize() > 0)) {
            return;
        }
        $items = $collection->getItems();
            foreach ($items as $item) {
                $this->taxRateRepository->deleteById($item->getData('tax_calculation_rate_id'));
            }
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function importCsvTaxRateFile(): void
    {
        $filename = self::IMPORT_TAX_CSV_FILE_NAME;
        $modulePath = $this->moduleDir->getDir(self::MODULE_NAME);
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
        $collection = $this->resourceModelTaxClassCollectionFactory->create();
        $collection->addFieldToSelect(['class_id', 'class_name']);
        $collection->addFieldToFilter('class_name' , 'Taxable Goods');
        /** @var TaxClassInterface $taxableGoodsClass */
        $taxableGoodsClass =  $collection->setPageSize(1)->getFirstItem();

        $collection = $this->resourceModelTaxClassCollectionFactory->create();
        $collection->addFieldToSelect(['class_id', 'class_name']);
        $collection->addFieldToFilter('class_name' , 'Retail Customer');
        /** @var TaxClassInterface $retailCustomerClass */
        $retailCustomerClass =  $collection->setPageSize(1)->getFirstItem();

        if (!$taxableGoodsClass->getClassId() || !$retailCustomerClass->getClassId()) {
            return;
        }

        $collection = $this->resourceModelCalculationRateCollectionFactory->create();
        $rateIds = [];
        $rateItems = $collection->getItems();

        /** @var Rate $item */
        foreach ($rateItems as $item) {
            $rateIds[] = $item->getData('tax_calculation_rate_id');
        }

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
