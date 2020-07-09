<?php
/**
 * @category  Emarsys
 * @package   Emarsys_Emarsys
 * @copyright Copyright (c) 2020 Emarsys. (http://www.emarsys.net/)
 */

namespace Emarsys\Emarsys\Model;

use Emarsys\Emarsys\Helper\Data as EmarsysHelper;
use Emarsys\Emarsys\Helper\Logs as EmarsysHelperLogs;
use Emarsys\Emarsys\Model\Emarsysproductexport as ProductExportModel;
use Emarsys\Emarsys\Model\ProductAsync as ProductAsync;
use Emarsys\Emarsys\Model\ProductExportDataRepository;
use Emarsys\Emarsys\Model\ProductExportQueueRepository;
use Emarsys\Emarsys\Model\ResourceModel\Product as ProductResourceModel;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Serialize\Serializer\Serialize as Serializer;

class ProductExportAsync extends \Magento\Framework\DataObject
{
    public $maxProcesses = 8;
    protected $currentJobs = [];
    protected $signalQueue = [];
    protected $parentPID;

    protected $logsArray = [];
    protected $_credentials = [];
    protected $_websites = [];
    protected $pages = [];
    protected $_mapHeader = [];
    protected $_processedStores = [];

    /**
     * @var ProductAsync
     */
    protected $productAsync;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var EmarsysHelper
     */
    protected $emarsysHelper;

    /**
     * @var EmarsysHelperLogs
     */
    protected $logsHelper;

    /**
     * @var ApiExport
     */
    protected $apiExport;

    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * ProductExportAsync constructor.
     *
     * @param ProductAsync $productAsync
     * @param StoreManagerInterface $storeManager
     * @param EmarsysHelper $emarsysHelper
     * @param EmarsysHelperLogs $logsHelper
     * @param ApiExport $apiExport
     * @param Emarsysproductexport $productExportModel
     * @param ProductResourceModel $productResourceModel
     * @param ProductExportDataRepository $dataRepository
     * @param ProductExportQueueRepository $queueRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Serializer $serializer
     */
    public function __construct(
        ProductAsync $productAsync,
        StoreManagerInterface $storeManager,
        EmarsysHelper $emarsysHelper,
        EmarsysHelperLogs $logsHelper,
        ApiExport $apiExport,
        ProductExportModel $productExportModel,
        ProductResourceModel $productResourceModel,
        ProductExportDataRepository $dataRepository,
        ProductExportQueueRepository $queueRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Serializer $serializer
    ) {
        $this->productAsync = $productAsync;
        $this->storeManager = $storeManager;
        $this->emarsysHelper = $emarsysHelper;
        $this->logsHelper = $logsHelper;
        $this->apiExport = $apiExport;
        $this->productExportModel = $productExportModel;
        $this->productResourceModel = $productResourceModel;
        $this->dataRepository = $dataRepository;
        $this->queueRepository = $queueRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->serializer = $serializer;

        $this->parentPID = getmypid();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        } else {
            declare(ticks=1);
        }
        pcntl_signal(SIGCHLD, [$this, "childSignalHandler"]);
    }

    /**
     * @var ProductExportModel
     */
    protected $productExportModel;

    /**
     * @var ProductResourceModel
     */
    protected $productResourceModel;

    /**
     * @var ProductExportQueueRepository
     */
    protected $queueRepository;

    /**
     * @var ProductExportDataRepository
     */
    protected $dataRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * Run the Daemon
     */
    public function run($mode = EmarsysHelper::ENTITY_EXPORT_MODE_AUTOMATIC)
    {
        echo "Running \n";
        $this->productAsync->truncateExportTable();
        $queueModel = $this->queueRepository->getById(0);
        $this->queueRepository->truncate($queueModel);

        $this->logsArray['job_code'] = 'product';
        $this->logsArray['status'] = 'started';
        $this->logsArray['messages'] = __('Bulk product export started');
        $this->logsArray['created_at'] = date('Y-m-d H:i:s');
        $this->logsArray['auto_log'] = 'Complete';
        $logsArray['run_mode'] = $mode;
        $this->logsArray['executed_at'] = date('Y-m-d H:i:s');
        $this->logsArray['store_id'] = 0;
        $logId = $this->logsHelper->manualLogs($this->logsArray, 1);
        $this->logsArray['id'] = $logId;
        $this->logsArray['log_action'] = 'sync';
        $this->logsArray['action'] = 'synced to emarsys';

        $allStores = $this->storeManager->getStores();
        ksort($allStores);

        /**
         * @var \Magento\Store\Model\Store $store
         */
        foreach ($allStores as $store) {
            $this->setCredentials($store);
        }

        [$size, $maxId, $minId] = $this->productAsync->getSizeAndMaxAndMinId();

        $d = 25;
        $page = (int)ceil(($maxId - $minId)/$d);

        foreach ($this->getCredentials() as $websiteId => $website) {
            $this->productAsync->truncateExportTable();

            $this->queueRepository->truncate($queueModel);
            for ($x = 1; $x < 26; $x++) {
                if ($x == 1) {
                    $from = $minId;
                    $to = ($minId + $page);
                } elseif ($x == 25) {
                    $from = $minId + $page * ($x - 1);
                    $to = $minId + $page * ($x + 1);
                } else {
                    $from = $minId + $page * ($x - 1);
                    $to = $minId + $page * $x;
                }

                $queueModel->setData([
                    'id' => $x,
                    'from' => $from,
                    'to' => $to,
                ]);

                $queueModel->isObjectNew(true);
                $this->queueRepository->save($queueModel);
            }
            $filter = $this->searchCriteriaBuilder
                ->addFilter('status', 'processing', 'neq')
                ->create();

            $list = $this->queueRepository->getList($filter);
            while ($list->getTotalCount()) {
                $jobID = rand(0, 10000000000000);
                $list = $this->queueRepository->getList($filter);
                while (count($this->currentJobs) >= $this->maxProcesses) {
                    $list = $this->queueRepository->getList($filter);
                    echo $list->getTotalCount() . " => " . "Maximum children allowed, waiting...\n";
                    while (!sleep(10)) {
                        $this->spinner();
                    }
                }

                $this->launchJob($jobID, $website, $websiteId);
            }

            //Wait for child processes to finish before exiting here
            echo "Waiting for current jobs to finish. \n";
            while (count($this->currentJobs)) {
                $this->spinner();
            }

            if (!empty($website)) {
                $this->logsArray['emarsys_info'] = __('Starting data uploading');
                $this->logsArray['description'] = __('Starting data uploading');
                $this->logsArray['message_type'] = 'Success';
                $this->logsHelper->manualLogs($this->logsArray);

                $modelData = $this->dataRepository->getById($websiteId);
                [$this->_mapHeader, $this->_processedStores] = $this->serializer->unserialize($modelData->getExportData());

                $csvFilePath = $this->productExportModel->saveToCsv(
                    $websiteId,
                    $this->_mapHeader,
                    $this->_processedStores,
                    $this->logsArray
                );

                $store = reset($this->_credentials[$websiteId]);

                $uploaded = $this->moveFile($store['store'], $csvFilePath);
                if ($uploaded) {
                    $this->logsArray['emarsys_info'] = __('Data for was uploaded');
                    $this->logsArray['description'] = __('Data for was uploaded');
                    $this->logsArray['message_type'] = 'Success';
                } else {
                    $this->logsArray['emarsys_info'] = __('Error during data uploading');
                    $this->logsArray['description'] = __('Error during data uploading');
                    $this->logsArray['message_type'] = 'Error';
                }
                $this->logsHelper->manualLogs($this->logsArray);
            }
        }
    }

    /**
     * Launch a job from the job queue
     */
    protected function launchJob($jobID, $website, $websiteId)
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            //Problem launching the job
            error_log('Could not launch new job, exiting');
            return false;
        } elseif ($pid) {
            // Parent process
            // Sometimes you can receive a signal to the childSignalHandler function before this code executes if
            // the child script executes quickly enough!
            //
            $this->currentJobs[$pid] = $jobID;

            // In the event that a signal for this pid was caught before we get here, it will be in our signalQueue array
            // So let's go ahead and process it now as if we'd just received the signal

            if (isset($this->signalQueue[$pid])) {
                echo "found $pid in the signal queue, processing it now \n";
                $this->childSignalHandler(SIGCHLD, $pid, $this->signalQueue[$pid]);
                unset($this->signalQueue[$pid]);
            }
        } else {
            //Forked child, do your deeds....
            echo $jobID . " => Doing something fun in pid " . getmypid() . "\n";

            $exitStatus = 0;
            try {
                $filter = $this->searchCriteriaBuilder
                    ->addFilter('status', 'processing', 'neq')
                    ->create();

                $list = $this->queueRepository->getList($filter);
                $pages = $list->getItems();
                $page = reset($pages);
                if ($page) {
                    $page->setStatus('processing');
                    $this->queueRepository->save($page);

                    $data = $this->productAsync->consolidatedCatalogExport(getmypid(), $website, $page, null, $this->logsArray);

                    $modelData = $this->dataRepository->getById($websiteId);
                    if (!$modelData->getId()) {
                        $modelData->setId($websiteId)
                            ->setExportData(
                                $this->serializer->serialize($data)
                            );
                        $modelData->isObjectNew(true);
                        $this->dataRepository->save($modelData);
                    }
                }
            } catch (\Exception $e) {
                $exitStatus = $e->getMessage();
            }
            exit($exitStatus);
        }
        return true;
    }

    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        //If no pid is provided, that means we're getting the signal from the system.  Let's figure out
        //which child process ended
        if (!$pid) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        if (is_array($pid) && isset($pid['pid'])) {
            $pid = $pid['pid'];
        }

        //Make sure we get all of the exited children
        while ($pid > 0) {
            if ($pid && isset($this->currentJobs[$pid])) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode != 0) {
                    echo "$pid exited with status " . \json_encode($exitCode) . "\n";
                }
                unset($this->currentJobs[$pid]);
            } elseif ($pid) {
                //Oh no, our job has finished before this parent process could even note that it had been launched!
                //Let's make note of it and handle it when the parent process is ready for it
                echo "..... Adding $pid to the signal queue ..... \n";
                $this->signalQueue[$pid] = $status;
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        return true;
    }

    /**
     * @param \Magento\Store\Model\Store $store
     * @param string $csvFilePath
     * @return bool
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Zend_Http_Client_Exception
     */
    public function moveFile($store, $csvFilePath)
    {
        $result = true;
        $apiExportEnabled = $store->getConfig(EmarsysHelper::XPATH_PREDICT_API_ENABLED);

        $isBig = (filesize($csvFilePath) / pow(1024, 2)) > 100;
        $merchantId = $store->getConfig(EmarsysHelper::XPATH_PREDICT_MERCHANT_ID);
        $websiteId = $store->getWebsiteId();
        $url = $this->emarsysHelper->getEmarsysMediaUrlPath(ProductModel::ENTITY . '/' . $websiteId, $csvFilePath);
        if ($apiExportEnabled && !$isBig) {
            //get token from admin configuration
            $token = $store->getConfig(EmarsysHelper::XPATH_PREDICT_TOKEN);

            //Assign API Credentials
            $this->apiExport->assignApiCredentials($merchantId, $token);

            //Get catalog API Url
            $apiUrl = $this->apiExport->getApiUrl(ProductModel::ENTITY);

            //Export CSV to API
            $apiExportResult = $this->apiExport->apiExport($apiUrl, $csvFilePath);
            if ($apiExportResult['result'] == 1) {
                //successfully uploaded file on Emarsys
                $this->logsArray['emarsys_info'] = __('File uploaded to Emarsys');
                $this->logsArray['description'] = __(
                    'File uploaded to Emarsys. File Name: %1. API Export result: %2',
                    $url,
                    $apiExportResult['resultBody']
                );
                $this->logsArray['message_type'] = 'Success';
                $this->logsHelper->manualLogs($this->logsArray);
                $this->_errorCount = false;
            } else {
                //Failed to export file on Emarsys
                $this->_errorCount = true;
                $msg = isset($apiExportResult['resultBody']) ? $apiExportResult['resultBody'] : '';
                $this->logsArray['emarsys_info'] = __('Failed to upload file on Emarsys');
                $this->logsArray['description'] = __('Failed to upload %1 on Emarsys. %2', $url, $msg);
                $this->logsArray['message_type'] = 'Error';
                $this->logsHelper->manualLogs($this->logsArray);
                $result = false;
            }
        } else {
            $bulkDir = $store->getConfig(EmarsysHelper::XPATH_EMARSYS_FTP_BULK_EXPORT_DIR);
            $outputFile = $bulkDir . 'products_' . $store->getWebsiteId() . '.csv';
            if ($this->emarsysHelper->moveFileToFtp($store, $csvFilePath, $outputFile)) {
                //successfully uploaded the file on ftp
                $this->_errorCount = false;
                $this->logsArray['emarsys_info'] = __('File uploaded to FTP server successfully');
                $this->logsArray['description'] = $url . ' > ' . $outputFile;
                $this->logsArray['message_type'] = 'Success';
                $this->logsHelper->manualLogs($this->logsArray);
            } else {
                //failed to upload file on FTP server
                $this->_errorCount = true;
                $errorMessage = error_get_last();
                $msg = isset($errorMessage['message']) ? $errorMessage['message'] : '';
                $this->logsArray['emarsys_info'] = __('Failed to upload file on FTP server');
                $this->logsArray['description'] = __('Failed to upload %1 on FTP server %2', $url, $msg);
                $this->logsArray['message_type'] = 'Error';
                $this->logsHelper->manualLogs($this->logsArray);
                $result = false;
            }
        }

        $this->emarsysHelper->removeFilesInFolder(
            $this->emarsysHelper->getEmarsysMediaDirectoryPath(ProductModel::ENTITY . '/' . $websiteId)
        );

        return $result;
    }

    /**
     * Gets Store Credentials
     *
     * @param null|int $websiteId
     * @param null|int $storeId
     * @return array|mixed
     */
    public function getCredentials($websiteId = null, $storeId = null)
    {
        $return = $this->_credentials;
        if (!is_null($storeId) && !is_null($websiteId)) {
            $return = null;
            if (isset($this->_credentials[$websiteId][$storeId])) {
                $return = $this->_credentials[$websiteId][$storeId];
            }
        }
        return $return;
    }

    /**
     * Set Store Credential
     *
     * @param \Magento\Store\Model\Store $store
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function setCredentials($store)
    {
        $storeId = $store->getId();
        $websiteId = $this->getWebsiteId($store);
        if (!isset($this->_credentials[$websiteId][$storeId])) {
            if ($store->getConfig(EmarsysHelper::XPATH_EMARSYS_ENABLED)
                && $store->getConfig(EmarsysHelper::XPATH_PREDICT_ENABLE_NIGHTLY_PRODUCT_FEED)
            ) {
                //get method of catalog export from admin configuration
                $merchantId = $store->getConfig(EmarsysHelper::XPATH_PREDICT_MERCHANT_ID);
                if ($store->getConfig(EmarsysHelper::XPATH_PREDICT_API_ENABLED)) {
                    $token = $store->getConfig(EmarsysHelper::XPATH_PREDICT_TOKEN);
                    if ($merchantId == '' || $token == '') {
                        $this->_errorCount = true;
                        $this->logsArray['emarsys_info'] = __('Invalid API credentials');
                        $this->logsArray['description'] = __(
                            'Invalid API credential. Please check your settings and try again'
                        );
                        $this->logsArray['message_type'] = 'Error';
                        $this->logsHelper->logs($this->logsArray);
                        echo $this->logsArray['description'] . "\n";
                        return;
                    }
                    $this->logsArray['emarsys_info'] = __('Set API credentials');
                    $this->logsArray['description'] = __('Set API credentials for store %1', $storeId);
                    $this->logsArray['message_type'] = 'Success';
                    $this->logsHelper->logs($this->logsArray);
                } else {
                    if (!$this->emarsysHelper->checkFtpConnectionByStore($store)) {
                        $this->_errorCount = true;
                        $this->logsArray['emarsys_info'] = __('Failed to connect with FTP server.');
                        $this->logsArray['description'] = __('Failed to connect with FTP server.');
                        $this->logsArray['message_type'] = 'Error';
                        $this->logsHelper->logs($this->logsArray);
                        echo $this->logsArray['description'] . "\n";
                        return;
                    }
                    $this->logsArray['emarsys_info'] = __('Set FTP credentials');
                    $this->logsArray['description'] = __('Set FTP credentials for store %1', $storeId);
                    $this->logsArray['message_type'] = 'Success';
                    $this->logsHelper->logs($this->logsArray);
                    echo $this->logsArray['description'] . "\n";
                }

                $mappedAttributes = $this->productResourceModel->getMappedProductAttribute(
                    $this->emarsysHelper->getFirstStoreIdOfWebsiteByStoreId($storeId)
                );
                $mappingField = 0;
                foreach ($mappedAttributes as $mapAttribute) {
                    $emarsysFieldId = $mapAttribute['emarsys_attr_code'];
                    if ($emarsysFieldId != 0) {
                        $mappingField = 1;
                    }
                }
                if ($mappingField) {
                    $this->_credentials[$websiteId][$storeId]['store'] = $store;
                    $this->_credentials[$websiteId][$storeId]['mapped_attributes_names'] = $mappedAttributes;
                    $this->_credentials[$websiteId][$storeId]['merchant_id'] = $merchantId;
                } else {
                    $this->_errorCount = true;
                    $this->logsArray['emarsys_info'] = __('Catalog Feed Export Mapping Error');
                    $this->logsArray['description'] = __('No default mapping for for the store %1.', $store->getName());
                    $this->logsArray['message_type'] = 'Error';
                    $this->logsHelper->logs($this->logsArray);
                }
            } else {
                $this->_errorCount = true;
                $this->logsArray['emarsys_info'] = __('Catalog Feed Export is Disabled');
                $this->logsArray['description'] = __('Catalog Feed Export is Disabled for the store %1.', $store->getName());
                $this->logsArray['message_type'] = 'Error';
                $this->logsHelper->logs($this->logsArray);
            }
            echo $this->logsArray['description'] . "\n";
        }
    }

    /**
     * Get Grouped WebsiteId
     *
     * @param \Magento\Store\Model\Store $store
     * @return int
     */
    public function getWebsiteId($store)
    {
        $apiUserName = $store->getConfig(EmarsysHelper::XPATH_EMARSYS_API_USER);
        if (!isset($this->_websites[$apiUserName])) {
            $this->_websites[$apiUserName] = $store->getWebsiteId();
        }

        return $this->_websites[$apiUserName];
    }

    public function spinner()
    {
        $spins = [
            '≠=========',
            '=≠========',
            '==≠=======',
            '===≠======',
            '====≠=====',
            '=====≠====',
            '======≠===',
            '=======≠==',
            '========≠=',
            '=========≠',
            '========≠=',
            '=======≠==',
            '======≠===',
            '=====≠====',
            '====≠=====',
            '===≠======',
            '==≠=======',
            '=≠========',
        ];
        foreach ($spins as $spin) {
            echo "\r" . $spin;
            usleep(100000);
        }
    }
}
