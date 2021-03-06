<?php
/**
 * Analytics
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @author Marcel Scherello <audioplayer@scherello.de>
 * @copyright 2020 Marcel Scherello
 */

namespace OCA\Analytics\Service;

use Exception;
use OCA\Analytics\Activity\ActivityManager;
use OCA\Analytics\Controller\DatasourceController;
use OCA\Analytics\Controller\StorageController;
use OCA\Analytics\Db\DataloadMapper;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\ILogger;

class DataloadService
{
    private $logger;
    private $StorageController;
    private $DatasourceController;
    private $ActivityManager;
    private $DatasetService;
    private $l10n;
    private $DataloadMapper;

    public function __construct(
        IL10N $l10n,
        ILogger $logger,
        ActivityManager $ActivityManager,
        DatasourceController $DatasourceController,
        DatasetService $DatasetService,
        StorageController $StorageController,
        DataloadMapper $DataloadMapper
    )
    {
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->StorageController = $StorageController;
        $this->ActivityManager = $ActivityManager;
        $this->DatasourceController = $DatasourceController;
        $this->DatasetService = $DatasetService;
        $this->DataloadMapper = $DataloadMapper;
    }

    // Dataloads
    // Dataloads
    // Dataloads

    /**
     * create a new dataload
     *
     * @param int $datasetId
     * @param int $datasourceId
     * @return int
     */
    public function create(int $datasetId, int $datasourceId)
    {
        return $this->DataloadMapper->create($datasetId, $datasourceId);
    }

    /**
     * get all dataloads for a dataset
     *
     * @param int $datasetId
     * @return array
     */
    public function read(int $datasetId)
    {
        return $this->DataloadMapper->read($datasetId);
    }

    /**
     * update dataload
     *
     * @param int $dataloadId
     * @param $name
     * @param $option
     * @param $schedule
     * @return bool
     */
    public function update(int $dataloadId, $name, $option, $schedule)
    {
        return $this->DataloadMapper->update($dataloadId, $name, $option, $schedule);
    }

    /**
     * delete a dataload
     *
     * @param int $dataloadId
     * @return bool
     */
    public function delete(int $dataloadId)
    {
        return $this->DataloadMapper->delete($dataloadId);
    }

    /**
     * execute all dataloads depending on their schedule
     * daily or hourly
     *
     * @param $schedule
     * @return void
     * @throws Exception
     */
    public function executeBySchedule($schedule)
    {
        $schedules = $this->DataloadMapper->getDataloadBySchedule($schedule);
        //$this->logger->debug('DataLoadController 145: execute schedule '.$schedule);
        foreach ($schedules as $dataload) {
            //$this->logger->debug('DataLoadController 147: execute dataload '.$dataload['id']);
            $this->execute($dataload['id']);
        }
    }

    /**
     * execute a dataload from datasource and store into dataset
     *
     * @param int $dataloadId
     * @return DataResponse
     * @throws Exception
     */
    public function execute(int $dataloadId)
    {
        $dataloadMetadata = $this->DataloadMapper->getDataloadById($dataloadId);
        $result = $this->getDataFromDatasource($dataloadId);
        $insert = $update = 0;
        $datasetId = $result['datasetId'];
        $option = json_decode($dataloadMetadata['option'], true);

        if (isset($option['delete']) and $option['delete'] === 'true') {
            $this->StorageController->delete($datasetId, '*', '*');
        }
        if ($result['error'] === 0) {
            foreach ($result['data'] as &$row) {
                if (count($row) === 2) {
                    // if datasource only delivers 2 colums, the value needs to be in the last one
                    $row[2] = $row[1];
                    $row[1] = null;
                }
                $action = $this->StorageController->update($datasetId, $row[0], $row[1], $row[2], $dataloadMetadata['user_id']);
                $insert = $insert + $action['insert'];
                $update = $update + $action['update'];
            }
        }

        $result = [
            'insert' => $insert,
            'update' => $update,
            'error' => $result['error'],
        ];

        if ($result['error'] === 0) $this->ActivityManager->triggerEvent($datasetId, ActivityManager::OBJECT_DATA, ActivityManager::SUBJECT_DATA_ADD_DATALOAD, $dataloadMetadata['user_id']);

        return $result;
    }

    /**
     * get the data from datasource
     * to be used in simulation or execution
     *
     * @param int $dataloadId
     * @return array|NotFoundResponse
     * @throws NotFoundResponse|NotFoundException
     */
    public function getDataFromDatasource(int $dataloadId)
    {
        $dataloadMetadata = $this->DataloadMapper->getDataloadById($dataloadId);
        $datasetMetadata = $this->DatasetService->getOwnDataset($dataloadMetadata['dataset'], $dataloadMetadata['user_id']);

        if (!empty($datasetMetadata)) {
            $option = json_decode($dataloadMetadata['option'], true);
            $option['user_id'] = $dataloadMetadata['user_id'];

            //$this->logger->debug('DataLoadController 187: ' . $dataloadMetadata['option'] . '---' . json_encode($option));
            $result = $this->DatasourceController->read((int)$dataloadMetadata['datasource'], $option);
            $result['datasetId'] = $dataloadMetadata['dataset'];

            if (isset($option['timestamp']) and $option['timestamp'] === 'true') {
                // if datasource should be timestamped/snapshoted
                // shift values by one dimension and stores date in second column
                $result['data'] = array_map(function ($tag) {
                    $columns = count($tag);
                    return array($tag[$columns - 2], $tag[$columns - 2], $tag[$columns - 1]);
                }, $result['data']);
                $result['data'] = $this->replaceDimension($result['data'], 1, date("Y-m-d H:i:s"));
            }

            return $result;
        } else {
            return new NotFoundResponse();
        }
    }

    /**
     * replace all values of one dimension
     *
     * @NoAdminRequired
     * @param $Array
     * @param $Find
     * @param $Replace
     * @return array
     */
    private function replaceDimension($Array, $Find, $Replace)
    {
        if (is_array($Array)) {
            foreach ($Array as $Key => $Val) {
                if (is_array($Array[$Key])) {
                    $Array[$Key] = $this->replaceDimension($Array[$Key], $Find, $Replace);
                } else {
                    if ($Key === $Find) {
                        $Array[$Key] = $Replace;
                    }
                }
            }
        }
        return $Array;
    }
}